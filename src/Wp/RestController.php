<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\Db;
use LGMS\Repos\CustomerRepo;
use LGMS\Repos\EntitlementRepo;
use LGMS\Repos\ProductRepo;
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

        register_rest_route( self::NAMESPACE, '/me/create-setup-intent', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'meCreateSetupIntent' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        register_rest_route( self::NAMESPACE, '/me/set-default-payment-method', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'meSetDefaultPaymentMethod' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        register_rest_route( self::NAMESPACE, '/me/payment-methods', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'meGetPaymentMethods' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        register_rest_route( self::NAMESPACE, '/me/delete-payment-method', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'meDeletePaymentMethod' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        register_rest_route( self::NAMESPACE, '/me/invoices', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'meGetInvoices' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        register_rest_route( self::NAMESPACE, '/send-gift-recipient', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'sendGiftRecipient' ],
            'permission_callback' => [ self::class, 'auth' ],
        ] );

        // Buyer self-service gift management — browser calls these with WP nonce;
        // they proxy to Slim /v1/gift-* with shared-secret auth.
        register_rest_route( self::NAMESPACE, '/me/gift-send', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'meGiftSend' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        register_rest_route( self::NAMESPACE, '/me/gift-resend', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'meGiftResend' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        register_rest_route( self::NAMESPACE, '/me/gift-reassign', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'meGiftReassign' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        register_rest_route( self::NAMESPACE, '/me/gift-void', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'meGiftVoid' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        // Public auth endpoint. /auth is the canonical route; /gift-auth is
        // a backward-compat alias from when this only served the gift
        // redemption flow. Both routes hit the same handler — keep the alias
        // so cached front-end JS or any external integration keeps working,
        // and so testers don't see the confusing "/gift-auth" URL when
        // signing up via /lgjoin/.
        $authArgs = [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'giftAuth' ],
            'permission_callback' => '__return_true',
        ];
        register_rest_route( self::NAMESPACE, '/auth',      $authArgs );
        register_rest_route( self::NAMESPACE, '/gift-auth', $authArgs );

        // Logged-in only: dismiss the welcome modal (consume the
        // _lg_pending_welcome meta). Called via AJAX from wp_footer when
        // the user clicks "Got it" on the post-purchase celebration.
        register_rest_route( self::NAMESPACE, '/dismiss-welcome', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'dismissWelcome' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        register_rest_route( self::NAMESPACE, '/affiliate-withdraw', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'affiliateWithdraw' ],
            'permission_callback' => [ self::class, 'authLoggedInUser' ],
        ] );

        // Admin-only: resolve a pending payout (mark paid or denied).
        register_rest_route( self::NAMESPACE, '/admin/payout-resolve', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'payoutResolve' ],
            'permission_callback' => [ self::class, 'authAdmin' ],
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

    // Real client IP behind Cloudflare. Falls back to REMOTE_ADDR if the
    // CF header is missing (origin-direct hit) or invalid.
    private static function clientIp(): string
    {
        $cf = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? (string) $_SERVER['HTTP_CF_CONNECTING_IP'] : '';
        if ( $cf !== '' && filter_var( $cf, FILTER_VALIDATE_IP ) !== false ) {
            return $cf;
        }
        $ra = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return filter_var( $ra, FILTER_VALIDATE_IP ) !== false ? $ra : '';
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

        // Per-IP soft rate limit: protects admin inbox and DB from abuse.
        // Returns the same {ok:true} shape as the honeypot so attackers
        // can't tell they're being throttled.
        $ip = self::clientIp();
        if ( $ip !== '' ) {
            $key  = 'lgms_rr_' . md5( $ip );
            $hits = (int) get_transient( $key );
            if ( $hits >= 5 ) {
                return new WP_REST_Response( [ 'ok' => true ] );
            }
            set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
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
            $windowDays = max( 1, min( 90, (int) get_option( 'lgms_refund_window_days', '30' ) ) );
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
                $items  = (array) ( $sub->items->data ?? [] );
                if ( $items === [] ) {
                    throw new \RuntimeException( 'Subscription has no items.' );
                }
                $itemId = (string) ( $items[0]->id ?? '' );
                if ( $itemId === '' ) {
                    throw new \RuntimeException( 'Could not determine subscription item id.' );
                }

                // Policy: downgrades must wait until period end — no immediate downgrade.
                $currentPriceId = (string) ( $items[0]->price->id ?? '' );
                $currentAmount  = ProductRepo::amountCentsForPrice( $currentPriceId );
                $newAmount      = ProductRepo::amountCentsForPrice( $newPriceId );
                if ( $currentAmount !== null && $newAmount !== null && $newAmount < $currentAmount ) {
                    return new WP_REST_Response( [
                        'ok'    => false,
                        'error' => 'Downgrades take effect at the end of your billing period. Please use "Switch on renewal date" instead.',
                    ], 400 );
                }

                // Upgrade: charge full new-tier price today, new period starts now. No proration credit.
                $updated = $stripe->updateSubscription( $subId, [
                    'items' => [
                        [ 'id' => $itemId, 'price' => $newPriceId ],
                    ],
                    'proration_behavior'   => 'none',
                    'billing_cycle_anchor' => 'now',
                ] );
                $msg = 'Your plan has been upgraded. You have been charged the full new plan price today and your access is updated immediately.';
                self::logAdminAction( $owner['customer_id'], $action, $subId, null, null, $reason, true, null );
                self::sendSelfActionEmail(
                    $owner['customer_id'],
                    'Your plan has been upgraded',
                    '<p>Your plan was upgraded and you have been charged the full new plan price today. Your new billing period starts now.</p><p>Your <a href="' . esc_url( home_url( '/manage-subscription/' ) ) . '">subscription page</a> shows the current state.</p>'
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

    public static function meCreateSetupIntent( \WP_REST_Request $req ): \WP_REST_Response
    {
        $user     = wp_get_current_user();
        $customer = CustomerRepo::findByEmail( (string) $user->user_email );
        if ( ! $customer || empty( $customer['stripe_customer_id'] ) ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'No billing record found for your account.' ], 404 );
        }
        try {
            $stripe = new StripeClient();
            $si     = $stripe->createSetupIntent( [
                'customer'                => (string) $customer['stripe_customer_id'],
                'usage'                   => 'off_session',
                'automatic_payment_methods' => [ 'enabled' => true, 'allow_redirects' => 'never' ],
            ] );
            return new \WP_REST_Response( [ 'ok' => true, 'client_secret' => (string) $si->client_secret ] );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Could not start card update. Please try again.' ], 500 );
        }
    }

    public static function meSetDefaultPaymentMethod( \WP_REST_Request $req ): \WP_REST_Response
    {
        $user  = wp_get_current_user();
        $pmId  = (string) ( $req->get_json_params()['payment_method_id'] ?? '' );
        if ( $pmId === '' ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Missing payment_method_id.' ], 400 );
        }
        $customer = CustomerRepo::findByEmail( (string) $user->user_email );
        if ( ! $customer || empty( $customer['stripe_customer_id'] ) ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'No billing record found.' ], 404 );
        }
        try {
            $stripe = new StripeClient();
            // Defense-in-depth IDOR check: verify the PM is actually attached
            // to this customer before Stripe rejects it. Mirrors the explicit
            // membership check in meDeletePaymentMethod.
            $pms = $stripe->listPaymentMethods( (string) $customer['stripe_customer_id'] );
            $ids = array_map( fn( $p ) => (string) $p->id, $pms );
            if ( ! in_array( $pmId, $ids, true ) ) {
                return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Payment method not found on your account.' ], 404 );
            }
            $stripe->updateCustomer( (string) $customer['stripe_customer_id'], [
                'invoice_settings' => [ 'default_payment_method' => $pmId ],
            ] );
            self::logAdminAction( (int) $customer['id'], 'self_set_default_pm', null, null, null, $pmId, true, null );
            return new \WP_REST_Response( [ 'ok' => true, 'message' => 'Your payment method has been updated.' ] );
        } catch ( \Throwable $e ) {
            self::logAdminAction( (int) $customer['id'], 'self_set_default_pm', null, null, null, $pmId, false, $e->getMessage() );
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Could not save payment method. Please try again.' ], 500 );
        }
    }

    public static function meGetPaymentMethods( \WP_REST_Request $req ): \WP_REST_Response
    {
        $user     = wp_get_current_user();
        $customer = CustomerRepo::findByEmail( (string) $user->user_email );
        if ( ! $customer || empty( $customer['stripe_customer_id'] ) ) {
            return new \WP_REST_Response( [ 'ok' => true, 'payment_methods' => [], 'default_pm' => null ] );
        }
        try {
            $stripe     = new StripeClient();
            $stripeCust = $stripe->retrieveCustomer( (string) $customer['stripe_customer_id'] );
            $defaultPm  = $stripeCust->invoice_settings->default_payment_method ?? null;
            $defaultId  = is_string( $defaultPm ) ? $defaultPm : ( is_object( $defaultPm ) ? (string) $defaultPm->id : null );

            $pms    = $stripe->listPaymentMethods( (string) $customer['stripe_customer_id'] );
            $result = [];
            foreach ( $pms as $pm ) {
                $card     = $pm->card ?? null;
                $result[] = [
                    'id'         => (string) $pm->id,
                    'brand'      => (string) ( $card->brand ?? 'unknown' ),
                    'last4'      => (string) ( $card->last4 ?? '????' ),
                    'exp_month'  => (int) ( $card->exp_month ?? 0 ),
                    'exp_year'   => (int) ( $card->exp_year ?? 0 ),
                    'is_default' => (string) $pm->id === $defaultId,
                ];
            }
            return new \WP_REST_Response( [ 'ok' => true, 'payment_methods' => $result, 'default_pm' => $defaultId ] );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Could not load payment methods.' ], 500 );
        }
    }

    public static function meDeletePaymentMethod( \WP_REST_Request $req ): \WP_REST_Response
    {
        $user  = wp_get_current_user();
        $pmId  = (string) ( $req->get_json_params()['payment_method_id'] ?? '' );
        if ( $pmId === '' ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Missing payment_method_id.' ], 400 );
        }
        $customer = CustomerRepo::findByEmail( (string) $user->user_email );
        if ( ! $customer || empty( $customer['stripe_customer_id'] ) ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'No billing record found.' ], 404 );
        }
        try {
            $stripe = new StripeClient();
            $pms    = $stripe->listPaymentMethods( (string) $customer['stripe_customer_id'] );
            $ids    = array_map( fn( $p ) => (string) $p->id, $pms );

            if ( ! in_array( $pmId, $ids, true ) ) {
                return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Payment method not found on your account.' ], 404 );
            }
            if ( count( $ids ) <= 1 ) {
                return new \WP_REST_Response( [ 'ok' => false, 'error' => 'You cannot remove your only payment method while you have an active subscription.' ], 400 );
            }
            $stripe->detachPaymentMethod( $pmId );
            self::logAdminAction( (int) $customer['id'], 'self_remove_pm', null, null, null, $pmId, true, null );
            return new \WP_REST_Response( [ 'ok' => true, 'message' => 'Payment method removed.' ] );
        } catch ( \Throwable $e ) {
            self::logAdminAction( (int) $customer['id'], 'self_remove_pm', null, null, null, $pmId, false, $e->getMessage() );
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Could not remove payment method. Please try again.' ], 500 );
        }
    }

    /**
     * GET /me/invoices
     *
     * Returns up to 24 recent invoices for the logged-in customer, newest
     * first. Each row includes a billing period label so the customer can
     * identify what each charge was for without decoding Stripe descriptions.
     */
    public static function meGetInvoices( \WP_REST_Request $req ): \WP_REST_Response
    {
        $user     = wp_get_current_user();
        $customer = CustomerRepo::findByEmail( (string) $user->user_email );
        if ( ! $customer || empty( $customer['stripe_customer_id'] ) ) {
            return new \WP_REST_Response( [ 'ok' => true, 'invoices' => [] ] );
        }
        try {
            $stripe   = new StripeClient();
            $invoices = $stripe->listInvoices( (string) $customer['stripe_customer_id'] );
            $result   = [];
            foreach ( $invoices as $inv ) {
                $lines = (array) ( $inv->lines->data ?? [] );
                $line0 = $lines[0] ?? null;

                // Build a human-readable period label ("Dec 1 – Jan 1") when
                // available; fall back to the raw Stripe line description.
                $desc = '';
                if ( $line0 && isset( $line0->period->start, $line0->period->end ) && (int) $line0->period->start > 0 ) {
                    $desc = date( 'M j', (int) $line0->period->start ) . ' – ' . date( 'M j', (int) $line0->period->end );
                } elseif ( $line0 && isset( $line0->description ) ) {
                    // Strip Stripe's "1 × " quantity prefix and " (at $X / mo)" suffix.
                    $desc = preg_replace( '/^\d+ × /', '', (string) $line0->description ) ?? '';
                    $desc = preg_replace( '/ \(at .+\)$/', '', $desc ) ?? $desc;
                }

                $result[] = [
                    'id'          => (string) $inv->id,
                    'date'        => date( 'Y-m-d', (int) ( $inv->created ?? 0 ) ),
                    'amount'      => (int) ( $inv->amount_paid ?? $inv->total ?? 0 ),
                    'currency'    => strtoupper( (string) ( $inv->currency ?? 'usd' ) ),
                    'status'      => (string) ( $inv->status ?? '' ),
                    'description' => $desc,
                    'invoice_pdf' => (string) ( $inv->invoice_pdf ?? '' ),
                    'hosted_url'  => (string) ( $inv->hosted_invoice_url ?? '' ),
                ];
            }
            return new \WP_REST_Response( [ 'ok' => true, 'invoices' => $result ] );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Could not load billing history.' ], 500 );
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

    /**
     * POST /send-gift-recipient
     * Body: { code: {...}, giver_email, giver_name }
     * Auth: X-LGMS-Token shared secret (called by Slim GiftActionController).
     *
     * Sends a single per-recipient email for one code. No buyer summary.
     * Also stamps email_sent_at in the DB so the dashboard bucket is correct.
     */
    public static function sendGiftRecipient( WP_REST_Request $req ): WP_REST_Response
    {
        $body       = (array) $req->get_json_params();
        $code       = (array) ( $body['code']        ?? [] );
        $giverEmail = trim( (string) ( $body['giver_email'] ?? '' ) );
        $giverName  = trim( (string) ( $body['giver_name']  ?? '' ) );

        $recipientEmail = trim( (string) ( $code['recipient_email'] ?? '' ) );
        if ( $recipientEmail === '' || ! is_email( $recipientEmail ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'recipient_email missing or invalid' ], 400 );
        }
        if ( empty( $code['code'] ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'code field required' ], 400 );
        }

        ( new GiftMailer() )->sendOneRecipient( $code, $giverEmail, $giverName ?: 'Looth Member' );

        // Stamp email_sent_at so the dashboard bucket shows the code as Sent.
        if ( ! empty( $code['id'] ) ) {
            try {
                $pdo = Db::pdo();
                $pdo->prepare( 'UPDATE gift_codes SET email_sent_at = NOW() WHERE id = ?' )
                    ->execute( [ (int) $code['id'] ] );
            } catch ( \Throwable $e ) {
                error_log( 'LGMS sendGiftRecipient: failed to stamp email_sent_at: ' . $e->getMessage() );
            }
        }

        return new WP_REST_Response( [ 'ok' => true ] );
    }

    /**
     * POST /me/gift-send
     * Body: { code_id, recipient_email, recipient_name?, message? }
     * Auth: WP nonce (authLoggedInUser).
     *
     * Verifies the logged-in user owns the code, then calls Slim /v1/gift-send.
     */
    public static function meGiftSend( WP_REST_Request $req ): WP_REST_Response
    {
        $body  = (array) $req->get_json_params();
        $extra = [ 'recipient_email' => (string) ( $body['recipient_email'] ?? '' ),
                   'recipient_name'  => (string) ( $body['recipient_name']  ?? '' ),
                   'message'         => (string) ( $body['message']         ?? '' ) ];
        if ( ! empty( $body['acknowledged_recipient_warning'] ) ) {
            $extra['acknowledged_recipient_warning'] = true;
        }
        return self::proxyToSlim( 'gift-send', (int) ( $body['code_id'] ?? 0 ), $extra );
    }

    /**
     * POST /me/gift-resend
     * Body: { code_id }
     */
    public static function meGiftResend( WP_REST_Request $req ): WP_REST_Response
    {
        $body = (array) $req->get_json_params();
        return self::proxyToSlim( 'gift-resend', (int) ( $body['code_id'] ?? 0 ), [] );
    }

    /**
     * POST /me/gift-reassign
     * Body: { code_id, recipient_email, recipient_name?, message? }
     */
    public static function meGiftReassign( WP_REST_Request $req ): WP_REST_Response
    {
        $body  = (array) $req->get_json_params();
        $extra = [ 'recipient_email' => (string) ( $body['recipient_email'] ?? '' ),
                   'recipient_name'  => (string) ( $body['recipient_name']  ?? '' ),
                   'message'         => (string) ( $body['message']         ?? '' ) ];
        if ( ! empty( $body['acknowledged_recipient_warning'] ) ) {
            $extra['acknowledged_recipient_warning'] = true;
        }
        return self::proxyToSlim( 'gift-reassign', (int) ( $body['code_id'] ?? 0 ), $extra );
    }

    /**
     * POST /me/gift-void
     * Body: { code_id }
     */
    public static function meGiftVoid( WP_REST_Request $req ): WP_REST_Response
    {
        $body = (array) $req->get_json_params();
        return self::proxyToSlim( 'gift-void', (int) ( $body['code_id'] ?? 0 ), [] );
    }

    /**
     * Common proxy for all /me/gift-* endpoints.
     *
     * 1. Validates code_id and logged-in user
     * 2. Verifies the code belongs to this buyer via direct DB check
     * 3. Forwards the request to Slim /v1/gift-{action} with shared-secret auth
     */
    private static function proxyToSlim( string $action, int $codeId, array $extra ): WP_REST_Response
    {
        if ( $codeId <= 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'code_id is required.' ], 400 );
        }

        $user  = wp_get_current_user();
        $email = $user ? (string) $user->user_email : '';
        if ( $email === '' ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not determine your email.' ], 403 );
        }

        // Ownership check: gift_code must belong to a customer with this email.
        // Capture customer_id for the audit log row written after the proxy call.
        $customerId = 0;
        try {
            $pdo  = Db::pdo();
            $stmt = $pdo->prepare(
                'SELECT g.purchased_by FROM gift_codes g
                 JOIN customers c ON c.id = g.purchased_by
                 WHERE g.id = ? AND c.email = ? LIMIT 1'
            );
            $stmt->execute( [ $codeId, $email ] );
            $row = $stmt->fetch( PDO::FETCH_ASSOC );
            if ( ! $row ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'Code not found or not yours.' ], 403 );
            }
            $customerId = (int) $row['purchased_by'];
        } catch ( \Throwable $e ) {
            error_log( "LGMS proxyToSlim({$action}): DB error: " . $e->getMessage() );
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Internal error verifying code ownership.' ], 500 );
        }

        // Forward to Slim.
        $secret  = (string) get_option( 'lgms_shared_secret', '' );
        $baseUrl = (string) get_option( 'lgms_billing_base_url', home_url( '/billing' ) );
        $url     = rtrim( $baseUrl, '/' ) . '/v1/' . $action;

        $payload = wp_json_encode( array_merge( $extra, [ 'code_id' => $codeId, 'buyer_email' => $email ] ) );

        // Use raw curl with loopback resolution to bypass Cloudflare — same technique
        // as WpGiftMailer. Slim lives on the same host; CURLOPT_RESOLVE pins the
        // hostname to 127.0.0.1 so the request never leaves the machine.
        $parts  = parse_url( $url );
        $scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
        // Reject anything that isn't http/https. Defense-in-depth against a
        // poisoned lgms_billing_base_url option (gopher://, file://, etc.).
        if ( $scheme !== 'http' && $scheme !== 'https' ) {
            error_log( "LGMS proxyToSlim({$action}): refusing non-http(s) scheme '{$scheme}' in lgms_billing_base_url" );
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Billing service URL is misconfigured.' ], 500 );
        }
        $host = (string) ( $parts['host'] ?? '' );
        $port = (int)    ( $parts['port'] ?? ( $scheme === 'https' ? 443 : 80 ) );

        $ch = curl_init( $url );
        if ( $ch === false ) {
            error_log( "LGMS proxyToSlim({$action}): curl_init failed" );
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not reach billing service.' ], 502 );
        }

        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-LGMS-Token: ' . $secret,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RESOLVE    => $host !== '' ? [ "{$host}:{$port}:127.0.0.1" ] : [],
        ] );

        $rawBody  = curl_exec( $ch );
        $httpCode = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curlErr  = curl_error( $ch );
        curl_close( $ch );

        if ( $rawBody === false || $curlErr !== '' ) {
            error_log( "LGMS proxyToSlim({$action}): curl error: {$curlErr}" );
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not reach billing service. Please try again.' ], 502 );
        }

        $body = json_decode( (string) $rawBody, true );
        if ( ! is_array( $body ) ) {
            error_log( "LGMS proxyToSlim({$action}): non-JSON response (HTTP {$httpCode}): " . substr( (string) $rawBody, 0, 300 ) );
            $body = [ 'ok' => false, 'error' => 'Unexpected response from billing service.' ];
        }

        $success = ( $httpCode >= 200 && $httpCode < 300 ) && ! empty( $body['ok'] );
        self::logAdminAction(
            $customerId,
            'self_' . str_replace( '-', '_', $action ),
            null,
            null,
            null,
            (string) $codeId,
            $success,
            $success ? null : (string) ( $body['error'] ?? "HTTP {$httpCode}" )
        );

        return new WP_REST_Response( $body, $httpCode >= 100 ? $httpCode : 500 );
    }

    /**
     * POST /gift-auth  (public — no nonce required)
     * Body: { email, password, subscribe_weekly? }
     *
     * Logs in an existing user or creates a new looth1 (starter-tier) account.
     * - Wrong password  → 401 with forgot:true hint
     * - subscribe_weekly → added to FluentCRM list 7 (Non Member Weekly Email)
     * Returns { ok, nonce, name, email } on success.
     *
     * Role model: every account created through this endpoint starts as
     * looth1. Arbiter promotes to looth2/3 when a paid entitlement is
     * recorded post-Stripe. Existing accounts are not demoted on login —
     * Arbiter is the sole writer of tier roles.
     */
    public static function giftAuth( WP_REST_Request $req ): WP_REST_Response
    {
        $body        = (array) $req->get_json_params();
        $email       = strtolower( trim( (string) ( $body['email']    ?? '' ) ) );
        $password    = (string) ( $body['password']          ?? '' );
        $subWeekly   = (bool)   ( $body['subscribe_weekly']  ?? true );
        $displayName = trim( (string) ( $body['display_name'] ?? '' ) );

        // Rate limit BEFORE any DB lookup. Two layers:
        //   1. Per-IP: 20/hr — defeats simple botnets and account-creation spam.
        //   2. Per-email: 5 failed attempts / 15min — defeats distributed
        //      credential stuffing across many IPs against one account.
        // Per-IP increments unconditionally; per-email only increments on
        // failed-password (so legitimate users typing right aren't penalized).
        $ip = self::clientIp();
        if ( $ip !== '' ) {
            $ipKey  = 'lgms_ga_ip_' . md5( $ip );
            $ipHits = (int) get_transient( $ipKey );
            if ( $ipHits >= 20 ) {
                return new WP_REST_Response( [
                    'ok'           => false,
                    'error'        => 'Too many attempts. Please wait an hour and try again, or use the password reset link.',
                    'rate_limited' => true,
                ], 429 );
            }
            set_transient( $ipKey, $ipHits + 1, HOUR_IN_SECONDS );
        }
        if ( $email !== '' && is_email( $email ) ) {
            $emailKey  = 'lgms_ga_em_' . md5( $email );
            $emailHits = (int) get_transient( $emailKey );
            if ( $emailHits >= 5 ) {
                return new WP_REST_Response( [
                    'ok'           => false,
                    'error'        => 'Too many failed attempts for this account. Please wait 15 minutes or reset your password.',
                    'rate_limited' => true,
                ], 429 );
            }
        }

        if ( $email === '' || ! is_email( $email ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Please enter a valid email address.' ], 400 );
        }
        if ( strlen( $password ) < 8 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Password must be at least 8 characters.' ], 400 );
        }

        $existing = get_user_by( 'email', $email );

        if ( $existing ) {
            $autoProvisioned = (bool) get_user_meta( $existing->ID, 'lg_auto_provisioned', true );
            $redemptionCode  = strtoupper( trim( (string) ( $body['redemption_code'] ?? '' ) ) );

            if ( $autoProvisioned && $redemptionCode !== '' && self::redemptionCodeProves( $redemptionCode, $email ) ) {
                // First-claim: this user was auto-provisioned (no real
                // password yet), and the caller proved access by passing a
                // gift code that was just redeemed under this email. Treat
                // the supplied password as the new account password.
                wp_set_password( $password, $existing->ID );
                delete_user_meta( $existing->ID, 'lg_auto_provisioned' );
                $user = get_user_by( 'id', $existing->ID );
                if ( $displayName !== '' && ( empty( $user->display_name ) || $user->display_name === $user->user_login ) ) {
                    $parts = preg_split( '/\\s+/', $displayName, 2 );
                    wp_update_user( [
                        'ID'           => $user->ID,
                        'display_name' => $displayName,
                        'first_name'   => $parts[0] ?? '',
                        'last_name'    => $parts[1] ?? '',
                        'nickname'     => $displayName,
                    ] );
                    $user = get_user_by( 'id', $user->ID );
                }
            } else {
                // Login flow.
                if ( ! wp_check_password( $password, $existing->user_pass, $existing->ID ) ) {
                    // Bump per-email failure counter so credential stuffing
                    // hits the throttle before exhausting common passwords.
                    $emailKey  = 'lgms_ga_em_' . md5( $email );
                    $emailHits = (int) get_transient( $emailKey );
                    set_transient( $emailKey, $emailHits + 1, 15 * MINUTE_IN_SECONDS );
                    return new WP_REST_Response( [ 'ok' => false, 'error' => 'Incorrect password.', 'forgot' => true ], 401 );
                }
                $user = $existing;
                // (Previously demoted looth1 → customer here when no paid
                // role was present. Removed: looth1 is now the starter
                // tier in our role model, not a lapsed-paid sentinel, so
                // logging in via gift-auth must not silently downgrade
                // their access. Arbiter is the sole writer of tier roles.)
            }
        } else {
            // login_only=true: caller is the inline login modal — they
            // expect this email to belong to an existing user, so refuse
            // to fall through into the account-creation path. Returning a
            // dedicated error lets the modal say "No account found" instead
            // of the generic "Login failed".
            if ( ! empty( $body['login_only'] ) ) {
                return new WP_REST_Response( [
                    'ok'        => false,
                    'error'     => 'No account found for that email.',
                    'no_account'=> true,
                ], 404 );
            }
            // No account on file — require consent before creating one. The
            // first call returns needs_consent so the client can show a modal;
            // the client re-submits with confirmed_consent=true to actually
            // create the account.
            $confirmed = (bool) ( $body['confirmed_consent'] ?? false );
            if ( ! $confirmed ) {
                return new WP_REST_Response( [
                    'ok'            => false,
                    'needs_consent' => true,
                    'email'         => $email,
                ], 200 );
            }

            $base = sanitize_user( strstr( $email, '@', true ), true );
            $base = $base !== '' ? $base : 'user';
            $username = username_exists( $base ) ? ( $base . '_' . substr( md5( $email ), 0, 6 ) ) : $base;

            $userId = wp_create_user( $username, $password, $email );
            if ( is_wp_error( $userId ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => $userId->get_error_message() ], 500 );
            }
            // looth1 is now the starter tier — every account that comes through
            // gift-auth lands here. Arbiter will upgrade to looth2/3 once a
            // paid entitlement is recorded (post-Stripe, via /v1/return or
            // the cron-driven reconcile-pending sweep). The bbp_participant
            // role is removed and the BP footprint erased so the user starts
            // clean; BB chrome reflects their actual tier as Arbiter resolves.
            $user = get_user_by( 'id', $userId );
            $user->set_role( 'looth1' );
            $user->remove_role( 'bbp_participant' );
            self::eraseBuddypressFootprint( (int) $userId );

            // Set display_name + first/last from the optional param so
            // the activity feed and member chrome show a recognizable
            // name, not the email-derived username.
            if ( $displayName !== '' ) {
                $parts = preg_split( '/\\s+/', $displayName, 2 );
                wp_update_user( [
                    'ID'           => $userId,
                    'display_name' => $displayName,
                    'first_name'   => $parts[0] ?? '',
                    'last_name'    => $parts[1] ?? '',
                    'nickname'     => $displayName,
                ] );
            }
        }

        // Weekly email opt-in — FluentCRM list 7 "Non Member Weekly Email Subscriber".
        if ( $subWeekly && function_exists( 'FluentCrmApi' ) ) {
            $name   = trim( (string) ( $user->display_name ?: $user->user_login ) );
            $parts  = explode( ' ', $name, 2 );
            $result = FluentCrmApi( 'contacts' )->createOrUpdate( [
                'email'      => $email,
                'first_name' => $parts[0],
                'last_name'  => $parts[1] ?? '',
                'status'     => 'subscribed',
            ] );
            $contact = isset( $result->model ) ? $result->model : $result;
            if ( $contact && method_exists( $contact, 'attachLists' ) ) {
                $contact->attachLists( [ 7 ] );
            }
        }

        // Log the user in.
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        return new WP_REST_Response( [
            'ok'    => true,
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'name'  => $user->display_name ?: $user->user_login,
            'email' => $user->user_email,
        ] );
    }

    /**
     * Wipe any BuddyPress / BuddyBoss tables that hold rows for this user
     * so customer-only accounts leave no member-directory footprint. Safe
     * to call repeatedly — uses TRUNCATE-style targeted DELETEs.
     *
     * Public so TestChecklist::wipeEmail can reuse it as part of the
     * tester-fixture teardown flow.
     */
    public static function eraseBuddypressFootprint( int $userId ): void
    {
        if ( $userId <= 0 ) {
            return;
        }
        global $wpdb;
        $tables = [
            'bp_activity'                  => 'user_id',
            'bp_friends'                   => 'initiator_user_id',
            'bp_friends_initiated_by'      => 'friend_user_id',
            'bp_groups_members'            => 'user_id',
            'bp_messages_recipients'       => 'user_id',
            'bp_notifications'             => 'user_id',
            'bp_xprofile_data'             => 'user_id',
            'bp_user_blogs'                => 'user_id',
            'bp_invitations'               => 'inviter_id',
        ];
        foreach ( $tables as $table => $col ) {
            $full = $wpdb->prefix . $table;
            // Skip silently if the table doesn't exist on this install.
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
                continue;
            }
            $wpdb->delete( $full, [ $col => $userId ] );
        }
        // Last-activity is a single-row meta row in usermeta on newer BP.
        delete_user_meta( $userId, 'last_activity' );
        delete_user_meta( $userId, 'bp_latest_update' );
        delete_user_meta( $userId, 'total_friend_count' );
        delete_user_meta( $userId, 'total_group_count' );
    }

    /**
     * Returns true when the given $code was redeemed within the last
     * 10 minutes AND its recipient_email matches $email. Used to gate
     * password-set on auto-provisioned WP users in giftAuth().
     */
    private static function redemptionCodeProves( string $code, string $email ): bool
    {
        if ( $code === '' || $email === '' ) {
            return false;
        }
        try {
            // Proof is valid when the gift code is stamped to this email AND
            // is either (a) just-redeemed within the last 10 min, OR (b)
            // not yet redeemed (i.e. about to be — gift-auth runs before
            // the redeem in the new flow). Either case demonstrates the
            // caller has access to the recipients gift email.
            $stmt = \LGMS\Db::pdo()->prepare(
                'SELECT 1 FROM gift_codes
                  WHERE code = ?
                    AND LOWER(recipient_email) = LOWER(?)
                    AND voided_at IS NULL
                    AND (
                        ( redeemed_at IS NOT NULL AND redeemed_at >= (NOW() - INTERVAL 10 MINUTE) )
                        OR redeemed_at IS NULL
                    )
                  LIMIT 1'
            );
            $stmt->execute( [ $code, $email ] );
            return (bool) $stmt->fetchColumn();
        } catch ( \Throwable $e ) {
            error_log( 'gift-auth redemption proof: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * POST /dismiss-welcome
     *
     * Consumes the _lg_pending_welcome meta on the current user. Called
     * from the wp_footer welcome modal's "Got it" button. Idempotent —
     * deleting an absent meta is a no-op.
     */
    public static function dismissWelcome( WP_REST_Request $req ): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ( $userId <= 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'not_logged_in' ], 401 );
        }
        delete_user_meta( $userId, '_lg_pending_welcome' );
        return new WP_REST_Response( [ 'ok' => true ] );
    }

    /** POST /affiliate-withdraw — sends withdrawal request email to admin. */
    public static function affiliateWithdraw( WP_REST_Request $req ): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ( $userId <= 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'not_logged_in' ], 401 );
        }

        // Verify this user is actually an affiliate.
        try {
            $stmt = \LGMS\Db::pdo()->prepare(
                'SELECT a.id, a.slug, a.label,
                        a.commission_pct, a.commission_pct_annual, a.retention_bonus_pct,
                        COUNT(DISTINCT cl.id) AS clicks,
                        COUNT(DISTINCT cv.id) AS conversions,
                        COUNT(DISTINCT CASE WHEN cv.retention_bonus_eligible_at IS NOT NULL THEN cv.id END) AS retention_eligible,
                        COALESCE(SUM(db.amount_cents), 0) AS total_debits_cents
                 FROM affiliates a
                 LEFT JOIN affiliate_clicks      cl ON cl.affiliate_id = a.id
                 LEFT JOIN affiliate_conversions cv ON cv.affiliate_id = a.id
                 LEFT JOIN affiliate_debits      db ON db.affiliate_id = a.id
                 WHERE a.wp_user_id = ?
                 GROUP BY a.id LIMIT 1'
            );
            $stmt->execute( [ $userId ] );
            $aff = $stmt->fetch( \PDO::FETCH_ASSOC );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'db_error' ], 500 );
        }

        if ( ! $aff ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'not_an_affiliate' ], 403 );
        }

        $user    = get_userdata( $userId );
        $email   = $user ? $user->user_email : 'unknown';
        $debits  = (int) $aff['total_debits_cents'];
        $retElig = (int) $aff['retention_eligible'];

        // Reject if there's already an unresolved request — prevents the
        // affiliate from double-submitting while admin is still on the
        // earlier one.
        try {
            $pending = \LGMS\Db::pdo()->prepare(
                'SELECT id FROM lg_affiliate_payouts WHERE affiliate_id = ? AND status = "requested" LIMIT 1'
            );
            $pending->execute( [ (int) $aff['id'] ] );
            if ( $pending->fetchColumn() ) {
                return new WP_REST_Response( [
                    'ok'    => false,
                    'error' => 'You already have a pending request. We\'ll be in touch soon.',
                ], 409 );
            }
        } catch ( \Throwable $_ ) {}

        // Snapshot the estimated balance at request time. Stored on the
        // payout row so admins can see what the affiliate was expecting
        // even if conversion counts shift between request and resolve.
        $est           = \LGMS\Wp\Shortcodes::affiliateEarningsEstimate(
            (int) $aff['id'],
            (float) ( $aff['commission_pct']      ?? 0 ),
            (float) ( $aff['retention_bonus_pct'] ?? 0 )
        );
        $balanceCents  = max( 0, $est['gross_cents'] + $est['retention_cents'] - $debits - $est['paid_out_cents'] );

        $requestId = 0;
        try {
            $ins = \LGMS\Db::pdo()->prepare(
                'INSERT INTO lg_affiliate_payouts (affiliate_id, requested_cents, status) VALUES (?, ?, "requested")'
            );
            $ins->execute( [ (int) $aff['id'], $balanceCents ] );
            $requestId = (int) \LGMS\Db::pdo()->lastInsertId();
        } catch ( \Throwable $e ) {
            error_log( 'affiliate-withdraw INSERT failed: ' . $e->getMessage() );
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'db_error' ], 500 );
        }

        $debitsFmt   = number_format( $debits / 100, 2 );
        $balanceFmt  = number_format( $balanceCents / 100, 2 );
        $resolveUrl  = home_url( '/affiliate-earnings/' );

        $subject = "[Looth] Affiliate withdrawal request — {$aff['label']} (\${$balanceFmt})";
        $body    = "Affiliate withdrawal request #{$requestId}\n\n"
                 . "Affiliate:        {$aff['label']} (/{$aff['slug']})\n"
                 . "WP User:          {$email}\n"
                 . "Clicks:           {$aff['clicks']}\n"
                 . "Conversions:      {$aff['conversions']}\n"
                 . "Retention eligible: {$retElig}\n"
                 . "Refund debits:    -\${$debitsFmt}\n"
                 . "Estimated balance: \${$balanceFmt}  ← what the affiliate sees\n\n"
                 . "Mark paid / denied on the portal:\n  {$resolveUrl}\n\n"
                 . "Or run `php bin/poll-retention.php` on the billing server for exact amounts.\n";

        wp_mail( get_option( 'admin_email' ), $subject, $body );

        return new WP_REST_Response( [
            'ok'              => true,
            'request_id'      => $requestId,
            'requested_cents' => $balanceCents,
        ] );
    }

    /**
     * POST /admin/payout-resolve  (manage_options + nonce)
     * Body: { id, status: "paid" | "denied", paid_cents?, method?, notes? }
     *
     * Flips a requested payout to paid or denied. For paid, paid_cents is
     * required (defaults to the requested amount if omitted) and gets
     * subtracted from the affiliate's future estimated balance. method +
     * notes are free-form admin context (e.g. "venmo · sent 2026-05-13").
     */
    public static function payoutResolve( WP_REST_Request $req ): WP_REST_Response
    {
        $body   = (array) $req->get_json_params();
        $id     = (int) ( $body['id'] ?? 0 );
        $status = sanitize_text_field( (string) ( $body['status'] ?? '' ) );

        if ( $id <= 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Bad id.' ], 400 );
        }
        if ( ! in_array( $status, [ 'paid', 'denied' ], true ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Bad status.' ], 400 );
        }

        $pdo  = \LGMS\Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, affiliate_id, requested_cents, status FROM lg_affiliate_payouts WHERE id = ? LIMIT 1'
        );
        $stmt->execute( [ $id ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        if ( ! $row ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Not found.' ], 404 );
        }
        if ( $row['status'] !== 'requested' ) {
            return new WP_REST_Response( [
                'ok'    => false,
                'error' => 'Already resolved (status = ' . $row['status'] . ').',
            ], 409 );
        }

        $paidCents = null;
        $method    = null;
        $notes     = trim( (string) ( $body['notes'] ?? '' ) );
        $notes     = $notes !== '' ? sanitize_textarea_field( mb_substr( $notes, 0, 2000 ) ) : null;

        if ( $status === 'paid' ) {
            $paidCents = (int) ( $body['paid_cents'] ?? $row['requested_cents'] );
            if ( $paidCents < 0 ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'paid_cents must be ≥ 0.' ], 400 );
            }
            $method = trim( (string) ( $body['method'] ?? '' ) );
            $method = $method !== '' ? sanitize_text_field( mb_substr( $method, 0, 64 ) ) : null;
        }

        try {
            $upd = $pdo->prepare(
                'UPDATE lg_affiliate_payouts
                 SET status = ?, paid_cents = ?, method = ?, notes = ?, resolved_at = NOW(), resolved_by = ?
                 WHERE id = ?'
            );
            $upd->execute( [ $status, $paidCents, $method, $notes, get_current_user_id() ?: null, $id ] );
            return new WP_REST_Response( [
                'ok'         => true,
                'id'         => $id,
                'status'     => $status,
                'paid_cents' => $paidCents,
            ] );
        } catch ( \Throwable $e ) {
            error_log( 'payoutResolve UPDATE failed: ' . $e->getMessage() );
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'db_error' ], 500 );
        }
    }
}
