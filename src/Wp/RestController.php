<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\Db;
use LGMS\Repos\CustomerRepo;
use LGMS\Repos\EntitlementRepo;
use LGMS\Stripe\Client as StripeClient;
use LGMS\Sync;
use LGMS\Tick;
use PDO;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoints, all auth'd by shared secret in X-LGMS-Token header:
 *
 *   POST /wp-json/lg-member-sync/v1/run-now
 *     Runs the full Tick (Stripe poll + sync sweep).
 *
 *   POST /wp-json/lg-member-sync/v1/sync-customer
 *     Body: { customer_id }. Runs Sync::customer($id) only.
 *     Used by Slim's /v1/return for fast on-checkout provisioning.
 *
 *   POST /wp-json/lg-member-sync/v1/send-gift-codes
 *     Body: { to_email, to_name, codes: [{code, tier, duration_days}] }.
 *     Creates/updates FluentCRM contact and sends gift code email.
 *     Used by Slim's /v1/return after generating gift codes.
 */
final class RestController
{
    public const NAMESPACE = 'lg-member-sync/v1';

    public static function register(): void
    {
        register_rest_route( self::NAMESPACE, '/run-now', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'runNow' ],
            'permission_callback' => [ self::class, 'auth' ],
        ] );

        register_rest_route( self::NAMESPACE, '/sync-customer', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'syncCustomer' ],
            'permission_callback' => [ self::class, 'auth' ],
        ] );

        register_rest_route( self::NAMESPACE, '/send-gift-codes', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'sendGiftCodes' ],
            'permission_callback' => [ self::class, 'auth' ],
        ] );

        register_rest_route( self::NAMESPACE, '/refund-request', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'refundRequest' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/admin/cancel-subscription', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'adminCancelSubscription' ],
            'permission_callback' => [ self::class, 'authAdmin' ],
        ] );

        register_rest_route( self::NAMESPACE, '/admin/block-customer', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'adminBlockCustomer' ],
            'permission_callback' => [ self::class, 'authAdmin' ],
        ] );

        register_rest_route( self::NAMESPACE, '/admin/refund-gift-purchase', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'adminRefundGiftPurchase' ],
            'permission_callback' => [ self::class, 'authAdmin' ],
        ] );

        // Customer self-service: cancel + switch plan, gated by WP login and
        // the customer's ownership of the subscription (verified per call).
        register_rest_route( self::NAMESPACE, '/me/cancel-subscription', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'meCancelSubscription' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        register_rest_route( self::NAMESPACE, '/me/switch-plan', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'meSwitchPlan' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );
    }

    public static function authLoggedInUser(WP_REST_Request $req): bool
    {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        $nonce = (string) $req->get_header( 'x-wp-nonce' );
        return $nonce !== '' && wp_verify_nonce( $nonce, 'wp_rest' ) !== false;
    }

    /**
     * Authorize admin-only endpoints. Requires manage_options capability AND
     * a valid REST nonce (X-WP-Nonce header).
     */
    public static function authAdmin(WP_REST_Request $req): bool
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        $nonce = (string) $req->get_header( 'x-wp-nonce' );
        return $nonce !== '' && wp_verify_nonce( $nonce, 'wp_rest' ) !== false;
    }

    public static function auth(WP_REST_Request $req): bool
    {
        $expected = (string) get_option( 'lgms_shared_secret', '' );
        if ( $expected === '' ) {
            return false;
        }
        $given = (string) $req->get_header( 'x-lgms-token' );
        return $given !== '' && hash_equals( $expected, $given );
    }

    public static function runNow(WP_REST_Request $req): WP_REST_Response
    {
        Tick::run();
        return new WP_REST_Response( [ 'ok' => true ] );
    }

    public static function syncCustomer(WP_REST_Request $req): WP_REST_Response
    {
        $body       = (array) $req->get_json_params();
        $customerId = (int) ( $body['customer_id'] ?? 0 );
        if ( $customerId <= 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'customer_id required' ], 400 );
        }
        return new WP_REST_Response( Sync::customer( $customerId ) );
    }

    public static function sendGiftCodes(WP_REST_Request $req): WP_REST_Response
    {
        $body          = (array) $req->get_json_params();
        $toEmail       = trim( (string) ( $body['to_email'] ?? '' ) );
        $toName        = trim( (string) ( $body['to_name'] ?? '' ) );
        $codes         = (array) ( $body['codes'] ?? [] );
        $dashboardMode = ! empty( $body['dashboard_mode'] );

        if ( $toEmail === '' || $codes === [] ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'to_email and codes required' ], 400 );
        }

        ( new GiftMailer() )->send( $toEmail, $toName ?: 'Looth Member', $codes, $dashboardMode );

        // Stamp the buyer's WP user (if any) so the "My Gifts" nav item shows
        // up immediately on their next page load instead of waiting for the
        // lazy-prime DB miss in Pages::currentUserHasGiftCodes().
        $buyer = get_user_by( 'email', $toEmail );
        if ( $buyer instanceof \WP_User ) {
            \LGMS\Wp\Pages::markHasGifts( $buyer->ID );
        }

        return new WP_REST_Response( [ 'ok' => true ] );
    }

    public static function refundRequest(WP_REST_Request $req): WP_REST_Response
    {
        $body     = (array) $req->get_json_params();
        $name     = trim( (string) ( $body['name']     ?? '' ) );
        $email    = trim( (string) ( $body['email']    ?? '' ) );
        $reasons  = (array)        ( $body['reasons']  ?? [] );
        $items    = (array)        ( $body['items']    ?? [] );
        $comments = trim( (string) ( $body['comments'] ?? '' ) );
        $honeypot = trim( (string) ( $body['website']  ?? '' ) );

        if ( $honeypot !== '' ) {
            return new WP_REST_Response( [ 'ok' => true ] );
        }
        if ( $name === '' || $email === '' || ! is_email( $email ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Name and a valid email are required.' ], 400 );
        }
        if ( $reasons === [] ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Please select at least one reason.' ], 400 );
        }

        $reasons  = array_values( array_filter( array_map( 'sanitize_text_field', $reasons ) ) );
        $comments = sanitize_textarea_field( $comments );
        $name     = sanitize_text_field( $name );

        // Detect Stripe mode from the configured key so dashboard URLs land
        // in the matching environment (test/live).
        $stripeKey = (string) get_option( 'lgms_stripe_secret_key', '' );
        $modeSeg   = ( strpos( $stripeKey, 'sk_test_' ) === 0 ) ? '/test' : '';
        $stripeBase = 'https://dashboard.stripe.com' . $modeSeg;

        $customer       = CustomerRepo::findByEmail( $email );
        $customerHtml   = '<em>(no customer record found for this email)</em>';
        $subsHtml       = '';
        if ( $customer ) {
            $stripeCustId = ! empty( $customer['stripe_customer_id'] ) ? (string) $customer['stripe_customer_id'] : '';
            if ( $stripeCustId !== '' ) {
                $custUrl = $stripeBase . '/customers/' . rawurlencode( $stripeCustId );
                $customerHtml = sprintf(
                    'Customer ID: %d &nbsp;|&nbsp; Stripe customer: <a href="%s">%s</a>',
                    (int) $customer['id'],
                    esc_url( $custUrl ),
                    esc_html( $stripeCustId )
                );
            } else {
                $customerHtml = sprintf( 'Customer ID: %d &nbsp;|&nbsp; Stripe customer: (none)', (int) $customer['id'] );
            }

            $stmt = Db::pdo()->prepare(
                "SELECT stripe_subscription_id, status, current_period_end FROM subscriptions
                 WHERE customer_id = ? AND status IN ('active','trialing','past_due')
                 ORDER BY id DESC"
            );
            $stmt->execute( [ (int) $customer['id'] ] );
            $rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
            if ( $rows ) {
                $items = [];
                foreach ( $rows as $r ) {
                    $subUrl = $stripeBase . '/subscriptions/' . rawurlencode( (string) $r['stripe_subscription_id'] );
                    $items[] = sprintf(
                        '<li><a href="%s">%s</a> &mdash; %s, ends %s</li>',
                        esc_url( $subUrl ),
                        esc_html( (string) $r['stripe_subscription_id'] ),
                        esc_html( (string) $r['status'] ),
                        esc_html( (string) ( $r['current_period_end'] ?? 'n/a' ) )
                    );
                }
                $subsHtml = '<p><strong>Active subscriptions:</strong></p><ul>' . implode( '', $items ) . '</ul>';
            } else {
                $subsHtml = '<p><strong>Active subscriptions:</strong> none</p>';
            }
        }

        $reasonItems = '';
        foreach ( $reasons as $r ) {
            $reasonItems .= '<li>' . esc_html( $r ) . '</li>';
        }
        $commentsHtml = $comments !== '' ? nl2br( esc_html( $comments ) ) : '<em>(none)</em>';

        // Build a list of customer-selected items with eligibility info.
        $itemsHtml = '';
        if ( $items !== [] ) {
            $windowDays = max( 1, (int) get_option( 'lgms_refund_window_days', '30' ) );
            $cutoffTs   = time() - ( $windowDays * 86400 );
            $rows = [];
            foreach ( $items as $token ) {
                if ( ! is_string( $token ) || strpos( $token, ':' ) === false ) {
                    continue;
                }
                [ $kind, $id ] = explode( ':', $token, 2 );
                $kind = sanitize_text_field( $kind );
                $id   = sanitize_text_field( $id );
                if ( $kind === 'subscription' ) {
                    $stmt = Db::pdo()->prepare(
                        "SELECT current_period_start, status FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1"
                    );
                    $stmt->execute( [ $id ] );
                    $row = $stmt->fetch( PDO::FETCH_ASSOC );
                    if ( $row ) {
                        $chargedAt = (string) ( $row['current_period_start'] ?? '' );
                        $eligible  = $chargedAt && strtotime( $chargedAt ) >= $cutoffTs;
                        $url       = $stripeBase . '/subscriptions/' . rawurlencode( $id );
                        $rows[] = '<li>Subscription <a href="' . esc_url( $url ) . '">' . esc_html( $id ) . '</a> &mdash; status ' . esc_html( (string) $row['status'] ) . ', last charged ' . esc_html( $chargedAt ) . ' &mdash; <strong>' . ( $eligible ? '<span style="color:#080;">within ' . $windowDays . '-day window</span>' : '<span style="color:#b00;">outside window</span>' ) . '</strong></li>';
                    } else {
                        $rows[] = '<li>Subscription ' . esc_html( $id ) . ' (not found in DB)</li>';
                    }
                } elseif ( $kind === 'gift_purchase' ) {
                    $stmt = Db::pdo()->prepare(
                        "SELECT MIN(created_at) AS purchased_at, COUNT(*) AS qty,
                                SUM(redeemed_at IS NOT NULL) AS redeemed,
                                SUM(voided_at IS NOT NULL) AS voided
                         FROM gift_codes WHERE stripe_session_id = ?"
                    );
                    $stmt->execute( [ $id ] );
                    $row = $stmt->fetch( PDO::FETCH_ASSOC );
                    if ( $row && (int) $row['qty'] > 0 ) {
                        $purchasedAt = (string) ( $row['purchased_at'] ?? '' );
                        $eligible    = $purchasedAt && strtotime( $purchasedAt ) >= $cutoffTs;
                        $rows[] = '<li>Gift purchase <code>' . esc_html( substr( $id, 0, 24 ) ) . '...</code> &mdash; ' . (int) $row['qty'] . ' codes, ' . (int) $row['redeemed'] . ' redeemed, ' . (int) $row['voided'] . ' voided, purchased ' . esc_html( $purchasedAt ) . ' &mdash; <strong>' . ( $eligible ? '<span style="color:#080;">within ' . $windowDays . '-day window</span>' : '<span style="color:#b00;">outside window</span>' ) . '</strong></li>';
                    } else {
                        $rows[] = '<li>Gift session ' . esc_html( $id ) . ' (not found in DB)</li>';
                    }
                }
            }
            if ( $rows !== [] ) {
                $itemsHtml = '<p><strong>Customer requested refund of:</strong></p><ul>' . implode( '', $rows ) . '</ul>';
            }
        }

        // If this customer is linked to a WP user, surface the admin profile
        // link -- the membership section there has Cancel & Refund / Block
        // buttons that don't require the Stripe Dashboard.
        $wpProfileLink = '';
        if ( $customer ) {
            $stmt = Db::pdo()->prepare(
                'SELECT wp_user_id FROM wp_user_bridge WHERE customer_id = ? LIMIT 1'
            );
            $stmt->execute( [ (int) $customer['id'] ] );
            $row = $stmt->fetch( PDO::FETCH_ASSOC );
            if ( $row && (int) $row['wp_user_id'] > 0 ) {
                $editUrl = admin_url( 'user-edit.php?user_id=' . (int) $row['wp_user_id'] . '#lgms-membership' );
                $wpProfileLink = '<p><strong>One-click action:</strong> <a href="' . esc_url( $editUrl ) . '">Open this customer in WP admin</a> -- the Membership section at the bottom has Cancel &amp; Refund and Block buttons that handle Stripe for you.</p>';
            }
        }

        $modeLabel = $modeSeg === '/test' ? ' (Stripe TEST mode)' : '';

        $html  = '<p>A customer has submitted a refund request' . $modeLabel . '.</p>';
        $html .= '<p><strong>Name:</strong> ' . esc_html( $name ) . '<br>';
        $html .= '<strong>Email:</strong> <a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></p>';
        $html .= $itemsHtml;
        $html .= '<p><strong>Reasons:</strong></p><ul>' . $reasonItems . '</ul>';
        $html .= '<p><strong>Comments:</strong><br>' . $commentsHtml . '</p>';
        $html .= '<hr>';
        $html .= $wpProfileLink;
        $html .= '<p>' . $customerHtml . '</p>';
        $html .= $subsHtml;
        $html .= '<p style="color:#666;font-size:0.9em;">Or, to process directly in Stripe: click a subscription above to open it in the Stripe Dashboard, then refund the relevant charge. Our system will catch the resulting <code>charge.refunded</code> event and revoke access automatically.</p>';

        $to = (string) get_option( 'lgms_refund_email', '' );
        if ( $to === '' ) { $to = (string) get_option( 'admin_email' ); }
        $subject = "Refund request from {$name} <{$email}>";
        $headers = [
            'Reply-To: ' . $name . ' <' . $email . '>',
            'Content-Type: text/html; charset=UTF-8',
        ];

        $sent = wp_mail( $to, $subject, $html, $headers );
        if ( ! $sent ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not send your request. Please try again or email us directly.' ], 500 );
        }
        return new WP_REST_Response( [ 'ok' => true ] );
    }

    /**
     * POST /admin/cancel-subscription
     * Body: { sub_id: string, refund: bool, reason?: string, immediate?: bool }
     *
     * Cancels a Stripe subscription and (optionally) refunds its latest paid
     * invoice. On Stripe failure: returns 500 with the error and emails an
     * admin alert. Webhooks/poller pick up the state change and revoke access.
     */
    public static function adminCancelSubscription(WP_REST_Request $req): WP_REST_Response
    {
        $body      = (array) $req->get_json_params();
        $subId     = trim( (string) ( $body['sub_id']    ?? '' ) );
        $refund    = (bool)        ( $body['refund']    ?? false );
        $reason    = trim( (string) ( $body['reason']    ?? '' ) );
        $immediate = array_key_exists( 'immediate', $body ) ? (bool) $body['immediate'] : true;
        $autoBlock = (bool) ( $body['auto_block'] ?? false );

        if ( $subId === '' || strpos( $subId, 'sub_' ) !== 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Valid sub_id required.' ], 400 );
        }

        // Resolve customer_id from sub_id so log rows are correctly anchored.
        $stmt = Db::pdo()->prepare( 'SELECT customer_id FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1' );
        $stmt->execute( [ $subId ] );
        $customerId = (int) ( $stmt->fetchColumn() ?: 0 );

        $action  = $refund ? 'cancel_and_refund' : 'cancel_subscription';
        $context = [ 'sub_id' => $subId, 'refund' => $refund ? 'yes' : 'no', 'immediate' => $immediate ? 'yes' : 'no', 'reason' => $reason ];
        $result  = [ 'ok' => true, 'sub_id' => $subId, 'actions' => [] ];
        $refundId = null;
        $refundAmt = null;

        try {
            $stripe = new StripeClient();

            if ( $immediate ) {
                $sub = $stripe->cancelSubscription( $subId );
                $result['actions'][] = 'canceled (immediate)';
            } else {
                $sub = $stripe->updateSubscription( $subId, [ 'cancel_at_period_end' => true ] );
                $result['actions'][] = 'cancel_at_period_end set';
            }
            $result['status'] = (string) ( $sub->status ?? 'unknown' );

            if ( $refund ) {
                $inv = $stripe->latestPaidInvoiceForSubscription( $subId );
                if ( $inv === null ) {
                    $result['actions'][] = 'refund skipped: no paid invoice';
                } else {
                    $pi = (string) ( $inv->payment_intent ?? '' );
                    if ( $pi === '' ) {
                        $result['actions'][] = 'refund skipped: invoice has no payment_intent';
                    } else {
                        $refundParams = [ 'payment_intent' => $pi ];
                        if ( $reason !== '' ) {
                            $refundParams['reason']   = 'requested_by_customer';
                            $refundParams['metadata'] = [ 'admin_reason' => substr( $reason, 0, 500 ) ];
                        }
                        $refundObj  = $stripe->createRefund( $refundParams );
                        $refundId   = (string) ( $refundObj->id ?? '' );
                        $refundAmt  = (int) ( $refundObj->amount ?? 0 );
                        $result['actions'][]   = 'refunded ' . $refundId . ' (' . $refundAmt . ' cents)';
                        $result['refund_id']   = $refundId;
                    }
                }
            }

            self::logAdminAction( $customerId, $action, $subId, $refundId, $refundAmt, $reason, true, null );

            // Auto-block the customer from re-subscribing if requested.
            // Only meaningful when refund=true (otherwise the admin would just
            // use the dedicated block button). We swallow errors here so the
            // main cancel/refund result is still reported.
            if ( $refund && $autoBlock && $customerId > 0 ) {
                try {
                    $blockReason = $reason !== '' ? "Auto-blocked after refund: {$reason}" : "Auto-blocked after refund of {$subId}";
                    Db::pdo()->prepare( 'UPDATE customers SET blocked_at = NOW(), block_reason = ? WHERE id = ?' )
                        ->execute( [ $blockReason, $customerId ] );
                    self::logAdminAction( $customerId, 'auto_block_after_refund', $subId, $refundId, null, $blockReason, true, null );
                    $result['actions'][] = 'customer auto-blocked from re-subscribing';
                } catch ( \Throwable $blockErr ) {
                    self::logAdminAction( $customerId, 'auto_block_after_refund', $subId, null, null, '', false, $blockErr->getMessage() );
                    $result['actions'][] = 'auto-block failed: ' . $blockErr->getMessage();
                }
            }

            return new WP_REST_Response( $result );
        } catch ( \Throwable $e ) {
            self::logAdminAction( $customerId, $action, $subId, $refundId, $refundAmt, $reason, false, $e->getMessage() );
            AdminAlerts::sendFailureAlert( 'cancel-subscription', $context, $e );
            return new WP_REST_Response( [ 'ok' => false, 'error' => $e->getMessage(), 'partial' => $result['actions'] ], 500 );
        }
    }

    /**
     * POST /admin/block-customer
     * Body: { customer_id: int, blocked: bool, reason?: string }
     *
     * Sets/unsets blocked_at + block_reason on the customers row. Existing
     * entitlements are NOT touched -- cancel/refund those separately.
     */
    public static function adminBlockCustomer(WP_REST_Request $req): WP_REST_Response
    {
        $body       = (array) $req->get_json_params();
        $customerId = (int)  ( $body['customer_id'] ?? 0 );
        $blocked    = (bool) ( $body['blocked']     ?? false );
        $reason     = trim( (string) ( $body['reason'] ?? '' ) );

        if ( $customerId <= 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'customer_id required' ], 400 );
        }

        $existing = CustomerRepo::findById( $customerId );
        if ( $existing === null ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Customer not found' ], 404 );
        }

        $action = $blocked ? 'block' : 'unblock';
        try {
            if ( $blocked ) {
                $stmt = Db::pdo()->prepare(
                    'UPDATE customers SET blocked_at = NOW(), block_reason = ? WHERE id = ?'
                );
                $stmt->execute( [ $reason !== '' ? $reason : null, $customerId ] );
            } else {
                $stmt = Db::pdo()->prepare(
                    'UPDATE customers SET blocked_at = NULL, block_reason = NULL WHERE id = ?'
                );
                $stmt->execute( [ $customerId ] );
            }
            self::logAdminAction( $customerId, $action, null, null, null, $reason, true, null );
            $fresh = CustomerRepo::findById( $customerId );
            return new WP_REST_Response( [
                'ok'           => true,
                'customer_id'  => $customerId,
                'blocked'      => $fresh !== null && ! empty( $fresh['blocked_at'] ),
                'blocked_at'   => $fresh['blocked_at']   ?? null,
                'block_reason' => $fresh['block_reason'] ?? null,
            ] );
        } catch ( \Throwable $e ) {
            self::logAdminAction( $customerId, $action, null, null, null, $reason, false, $e->getMessage() );
            AdminAlerts::sendFailureAlert( 'block-customer', [
                'customer_id' => $customerId,
                'blocked'     => $blocked ? 'yes' : 'no',
                'reason'      => $reason,
            ], $e );
            return new WP_REST_Response( [ 'ok' => false, 'error' => $e->getMessage() ], 500 );
        }
    }

    /**
     * POST /admin/refund-gift-purchase
     * Body: { stripe_session_id: string, reason?: string }
     *
     * Refunds the original gift purchase via Stripe and immediately voids
     * unredeemed codes locally (instead of waiting for the charge.refunded
     * webhook/poller). Already-redeemed codes are left alone but reported
     * back so the admin can decide whether to revoke recipient access.
     */
    public static function adminRefundGiftPurchase(WP_REST_Request $req): WP_REST_Response
    {
        $body      = (array) $req->get_json_params();
        $sessionId = trim( (string) ( $body['stripe_session_id'] ?? '' ) );
        $reason    = trim( (string) ( $body['reason'] ?? '' ) );

        if ( $sessionId === '' || strpos( $sessionId, 'cs_' ) !== 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Valid stripe_session_id required.' ], 400 );
        }

        // Resolve customer_id (the buyer) from any gift_code in this batch.
        $stmt = Db::pdo()->prepare( 'SELECT purchased_by FROM gift_codes WHERE stripe_session_id = ? LIMIT 1' );
        $stmt->execute( [ $sessionId ] );
        $customerId = (int) ( $stmt->fetchColumn() ?: 0 );
        if ( $customerId <= 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'No gift codes found for that session.' ], 404 );
        }

        $action  = 'refund_gift_purchase';
        $context = [ 'session_id' => $sessionId, 'reason' => $reason ];
        $refundId = null;
        $refundAmt = null;
        $result   = [ 'ok' => true, 'session_id' => $sessionId, 'actions' => [] ];

        try {
            $stripe  = new StripeClient();
            $session = $stripe->retrieveCheckoutSession( $sessionId );
            $pi      = (string) ( $session->payment_intent ?? '' );

            if ( $pi !== '' ) {
                $refundParams = [ 'payment_intent' => $pi ];
                if ( $reason !== '' ) {
                    $refundParams['reason']   = 'requested_by_customer';
                    $refundParams['metadata'] = [ 'admin_reason' => substr( $reason, 0, 500 ) ];
                }
                $refundObj = $stripe->createRefund( $refundParams );
                $refundId  = (string) ( $refundObj->id ?? '' );
                $refundAmt = (int) ( $refundObj->amount ?? 0 );
                $result['refund_id']  = $refundId;
                $result['actions'][]  = 'stripe refund ' . $refundId . ' (' . $refundAmt . ' cents)';
            } else {
                $result['actions'][] = 'no payment_intent on session; nothing to refund in Stripe';
            }

            // Void unredeemed codes locally (poller would do this on next tick;
            // doing it inline closes the race so codes can't be redeemed
            // between the Stripe refund and the next poll).
            $voidStmt = Db::pdo()->prepare(
                "UPDATE gift_codes SET voided_at = NOW()
                 WHERE stripe_session_id = ? AND redeemed_at IS NULL AND voided_at IS NULL"
            );
            $voidStmt->execute( [ $sessionId ] );
            $voided = $voidStmt->rowCount();
            $result['voided_unredeemed'] = $voided;
            $result['actions'][] = "voided {$voided} unredeemed code(s)";

            // Report redeemed codes so the admin can decide whether to revoke.
            $redStmt = Db::pdo()->prepare(
                "SELECT gc.id, gc.code, gc.redeemed_at, c.email AS recipient_email
                 FROM gift_codes gc
                 LEFT JOIN customers c ON c.id = gc.redeemed_by
                 WHERE gc.stripe_session_id = ? AND gc.redeemed_at IS NOT NULL
                 ORDER BY gc.id"
            );
            $redStmt->execute( [ $sessionId ] );
            $redeemed = $redStmt->fetchAll( PDO::FETCH_ASSOC );
            $result['already_redeemed'] = $redeemed;
            if ( $redeemed !== [] ) {
                $result['actions'][] = count( $redeemed ) . ' code(s) already redeemed; flagged for admin review (entitlements not auto-revoked).';
            }

            self::logAdminAction( $customerId, $action, $sessionId, $refundId, $refundAmt, $reason, true, null );
            return new WP_REST_Response( $result );
        } catch ( \Throwable $e ) {
            self::logAdminAction( $customerId, $action, $sessionId, $refundId, $refundAmt, $reason, false, $e->getMessage() );
            AdminAlerts::sendFailureAlert( 'refund-gift-purchase', $context, $e );
            return new WP_REST_Response( [ 'ok' => false, 'error' => $e->getMessage(), 'partial' => $result['actions'] ], 500 );
        }
    }

    /**
     * POST /me/cancel-subscription
     * Body: { sub_id: string, immediate?: bool }
     *
     * Customer-initiated cancel. Verifies ownership before calling Stripe.
     * Default is cancel-at-period-end so the customer keeps the access they
     * already paid for; passing immediate=true cuts access right away.
     */
    public static function meCancelSubscription(WP_REST_Request $req): WP_REST_Response
    {
        $body      = (array) $req->get_json_params();
        $subId     = trim( (string) ( $body['sub_id'] ?? '' ) );
        $immediate = (bool) ( $body['immediate'] ?? false );

        if ( $subId === '' || strpos( $subId, 'sub_' ) !== 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Valid sub_id required.' ], 400 );
        }

        // Verify ownership: sub must belong to the customer with this email.
        $owner = self::resolveOwnedSub( $subId );
        if ( $owner === null ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Subscription not found or not yours.' ], 403 );
        }

        try {
            $stripe = new StripeClient();
            if ( $immediate ) {
                $sub = $stripe->cancelSubscription( $subId );
                $msg = 'Your subscription has been canceled. Access will end shortly.';
            } else {
                $sub = $stripe->updateSubscription( $subId, [ 'cancel_at_period_end' => true ] );
                $msg = 'Your subscription will end at the close of the current billing period.';
            }
            self::logAdminAction( $owner['customer_id'], 'self_cancel' . ( $immediate ? '_immediate' : '_at_period_end' ), $subId, null, null, '', true, null );
            self::sendSelfActionEmail(
                $owner['customer_id'],
                'Your subscription has been canceled',
                $immediate
                    ? '<p>You canceled your subscription. Access will end shortly.</p><p>Sorry to see you go &mdash; if you change your mind, you can resubscribe any time.</p>'
                    : '<p>You scheduled your subscription to end at the close of your current billing period. You will keep your access until then, and you will not be charged again.</p><p>If you change your mind, you can resume from <a href="' . esc_url( home_url( '/manage-subscription/' ) ) . '">your account</a> any time before the period ends.</p>'
            );
            return new WP_REST_Response( [ 'ok' => true, 'message' => $msg, 'status' => (string) ( $sub->status ?? 'unknown' ) ] );
        } catch ( \Throwable $e ) {
            self::logAdminAction( $owner['customer_id'], 'self_cancel', $subId, null, null, '', false, $e->getMessage() );
            AdminAlerts::sendFailureAlert( 'self-cancel-subscription', [ 'sub_id' => $subId, 'customer_id' => $owner['customer_id'], 'immediate' => $immediate ? 'yes' : 'no' ], $e );
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not cancel right now. Our team has been notified -- please email us if this persists.' ], 500 );
        }
    }

    /**
     * POST /me/switch-plan
     * Body: { sub_id: string, new_price_id: string }
     *
     * Customer-initiated plan change. Verifies ownership, fetches the sub
     * from Stripe to get the subscription_item ID, then updates with the
     * new price. Stripe handles proration automatically.
     */
    public static function meSwitchPlan(WP_REST_Request $req): WP_REST_Response
    {
        $body       = (array) $req->get_json_params();
        $subId      = trim( (string) ( $body['sub_id']       ?? '' ) );
        $newPriceId = trim( (string) ( $body['new_price_id'] ?? '' ) );
        $timingRaw  = trim( (string) ( $body['timing']       ?? 'now' ) );
        $timing     = $timingRaw === 'period_end' ? 'period_end' : 'now';

        if ( $subId === '' || strpos( $subId, 'sub_' ) !== 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Valid sub_id required.' ], 400 );
        }
        if ( $newPriceId === '' || strpos( $newPriceId, 'price_' ) !== 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Valid new_price_id required.' ], 400 );
        }

        $owner = self::resolveOwnedSub( $subId );
        if ( $owner === null ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Subscription not found or not yours.' ], 403 );
        }

        if ( $owner['stripe_price_id'] === $newPriceId ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'You are already on this plan.' ], 400 );
        }

        // Guard: refuse if subscription is past_due. Adding more billing on
        // top of an unpaid invoice compounds the problem; customer should
        // fix the payment method first.
        if ( ( $owner['status'] ?? '' ) === 'past_due' ) {
            return new WP_REST_Response( [
                'ok'    => false,
                'error' => 'Your subscription has a payment issue right now. Please update your payment method via the billing portal first, then try again.',
            ], 409 );
        }

        // Guard: rate limit. Refuse if customer changed plans within the cooldown window.
        $cooldownHours = max( 0, (int) get_option( 'lgms_plan_switch_cooldown_hours', '24' ) );
        if ( $cooldownHours > 0 ) {
            $stmt = Db::pdo()->prepare(
                "SELECT created_at FROM admin_action_log
                 WHERE customer_id = ? AND action LIKE 'self\\_switch\\_plan%' AND success = 1
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute( [ (int) $owner['customer_id'] ] );
            $row = $stmt->fetch( PDO::FETCH_ASSOC );
            if ( $row ) {
                $lastTs   = strtotime( (string) $row['created_at'] );
                $sinceHrs = ( time() - $lastTs ) / 3600;
                if ( $lastTs && $sinceHrs < $cooldownHours ) {
                    $remaining = (int) ceil( $cooldownHours - $sinceHrs );
                    return new WP_REST_Response( [
                        'ok'    => false,
                        'error' => "You changed plans recently. Please wait about {$remaining} more hour" . ( $remaining === 1 ? '' : 's' ) . ' before changing again, or contact support if you need to switch sooner.',
                    ], 429 );
                }
            }
        }

        $action = 'self_switch_plan_' . $timing;
        $reason = "from {$owner['stripe_price_id']} to {$newPriceId} (timing={$timing})";

        try {
            $stripe = new StripeClient();
            $sub    = $stripe->retrieveSubscription( $subId );

            if ( $timing === 'now' ) {
                // Immediate switch + invoice for prorated difference.
                $items  = (array) ( $sub->items->data ?? [] );
                if ( $items === [] ) {
                    throw new \RuntimeException( 'Subscription has no items.' );
                }
                $itemId = (string) ( $items[0]->id ?? '' );
                if ( $itemId === '' ) {
                    throw new \RuntimeException( 'Could not determine subscription item id.' );
                }
                $updated = $stripe->updateSubscription( $subId, [
                    'items' => [
                        [ 'id' => $itemId, 'price' => $newPriceId ],
                    ],
                    'proration_behavior' => 'always_invoice',
                ] );
                $msg = 'Your plan has been updated and you have been billed the prorated difference for the rest of this period.';
                self::logAdminAction( $owner['customer_id'], $action, $subId, null, null, $reason, true, null );
                self::sendSelfActionEmail(
                    $owner['customer_id'],
                    'Your plan has changed',
                    '<p>Your plan was switched and you have been billed the prorated difference for the rest of this billing period.</p><p>Your <a href="' . esc_url( home_url( '/manage-subscription/' ) ) . '">subscription page</a> shows the current state.</p>'
                );
                return new WP_REST_Response( [
                    'ok'      => true,
                    'message' => $msg,
                    'status'  => (string) ( $updated->status ?? 'unknown' ),
                ] );
            }

            // Guard: refuse if a Subscription Schedule is already attached
            // (e.g. customer already scheduled a switch for next period).
            if ( ! empty( $sub->schedule ) ) {
                return new WP_REST_Response( [
                    'ok'    => false,
                    'error' => 'You already have a pending plan change scheduled for your next renewal. Please wait for it to take effect, or contact support if you need to change it.',
                ], 409 );
            }

            // timing === 'period_end': use Subscription Schedule to defer change.
            $schedule = $stripe->createSubscriptionSchedule( [ 'from_subscription' => $subId ] );

            // Re-emit existing phases verbatim, then append the new phase.
            $phases = [];
            foreach ( (array) ( $schedule->phases ?? [] ) as $p ) {
                $items = [];
                foreach ( (array) ( $p->items ?? [] ) as $it ) {
                    $price = $it->price ?? null;
                    $priceId = is_string( $price ) ? $price : ( is_object( $price ) ? (string) $price->id : '' );
                    if ( $priceId === '' ) {
                        continue;
                    }
                    $items[] = [
                        'price'    => $priceId,
                        'quantity' => (int) ( $it->quantity ?? 1 ),
                    ];
                }
                $phases[] = [
                    'items'      => $items,
                    'start_date' => (int) $p->start_date,
                    'end_date'   => (int) $p->end_date,
                ];
            }
            $phases[] = [
                'items'      => [ [ 'price' => $newPriceId, 'quantity' => 1 ] ],
                'iterations' => 1,
            ];

            $stripe->updateSubscriptionSchedule( (string) $schedule->id, [
                'phases'       => $phases,
                'end_behavior' => 'release',
            ] );

            $msg = 'Plan switch scheduled. Your current plan continues until your next renewal, then your new plan kicks in -- no charge today.';
            self::logAdminAction( $owner['customer_id'], $action, $subId, null, null, $reason, true, null );
            self::sendSelfActionEmail(
                $owner['customer_id'],
                'Your plan change is scheduled',
                '<p>You scheduled a plan change for your next renewal date. Your current plan continues until then, and you will be billed the new amount on your next renewal.</p><p>If you change your mind, contact support before the renewal date and we can adjust.</p>'
            );
            return new WP_REST_Response( [
                'ok'          => true,
                'message'     => $msg,
                'schedule_id' => (string) $schedule->id,
            ] );
        } catch ( \Throwable $e ) {
            self::logAdminAction( $owner['customer_id'], $action, $subId, null, null, $reason, false, $e->getMessage() );
            AdminAlerts::sendFailureAlert( 'self-switch-plan', [ 'sub_id' => $subId, 'customer_id' => $owner['customer_id'], 'new_price_id' => $newPriceId, 'timing' => $timing ], $e );
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not switch plans right now. Our team has been notified -- please email us if this persists.' ], 500 );
        }
    }

    /**
     * Returns the {customer_id, stripe_price_id} for a subscription if it
     * belongs to the currently logged-in user, or null otherwise.
     */
    private static function resolveOwnedSub( string $subId ): ?array
    {
        $user = wp_get_current_user();
        if ( ! $user || $user->ID <= 0 ) {
            return null;
        }
        $email = (string) $user->user_email;
        if ( $email === '' ) {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            "SELECT s.customer_id, s.stripe_price_id, s.status
             FROM subscriptions s
             JOIN customers c ON c.id = s.customer_id
             WHERE s.stripe_subscription_id = ? AND c.email = ?
             LIMIT 1"
        );
        $stmt->execute( [ $subId, $email ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        return $row ?: null;
    }

    /**
     * Send a confirmation email to a customer after a self-service action
     * (cancel, switch plan). Best-effort: never throws so a mail failure
     * does not break the action result the customer just saw succeed.
     */
    private static function sendSelfActionEmail( int $customerId, string $subject, string $bodyHtml ): void
    {
        try {
            $stmt = Db::pdo()->prepare( 'SELECT email, name FROM customers WHERE id = ? LIMIT 1' );
            $stmt->execute( [ $customerId ] );
            $row = $stmt->fetch( PDO::FETCH_ASSOC );
            if ( ! $row || empty( $row['email'] ) ) {
                return;
            }
            $email = (string) $row['email'];
            $name  = trim( (string) ( $row['name'] ?? '' ) );
            $hello = $name !== '' ? "Hi " . esc_html( $name ) . "," : "Hi,";
            $site  = (string) get_bloginfo( 'name' );
            $html  = '<p>' . $hello . '</p>' . $bodyHtml . '<p style="color:#666;font-size:0.9em;">Sent automatically by ' . esc_html( $site ) . ' &mdash; reply to this email if you have questions.</p>';
            wp_mail(
                $email,
                '[' . $site . '] ' . $subject,
                $html,
                [ 'Content-Type: text/html; charset=UTF-8' ]
            );
        } catch ( \Throwable $_ ) {
            // Swallow.
        }
    }

    /**
     * Append a row to admin_action_log. Best-effort: never throws (we don't
     * want logging failures to break the actual action).
     */
    private static function logAdminAction(
        int $customerId,
        string $action,
        ?string $subId,
        ?string $refundId,
        ?int $refundAmount,
        string $reason,
        bool $success,
        ?string $errorMessage
    ): void {
        try {
            if ( $customerId <= 0 ) {
                return;
            }
            $stmt = Db::pdo()->prepare(
                'INSERT INTO admin_action_log
                    (customer_id, actor_wp_user, action, sub_id, refund_id, refund_amount, reason, success, error_message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute( [
                $customerId,
                get_current_user_id() ?: null,
                $action,
                $subId ?: null,
                $refundId ?: null,
                $refundAmount,
                $reason !== '' ? $reason : null,
                $success ? 1 : 0,
                $errorMessage,
            ] );
        } catch ( \Throwable $_ ) {
            // Swallow — logging is best-effort.
        }
    }
}
