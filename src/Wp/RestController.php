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
        $body     = (array) $req->get_json_params();
        $toEmail  = trim( (string) ( $body['to_email'] ?? '' ) );
        $toName   = trim( (string) ( $body['to_name'] ?? '' ) );
        $codes    = (array) ( $body['codes'] ?? [] );

        if ( $toEmail === '' || $codes === [] ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'to_email and codes required' ], 400 );
        }

        ( new GiftMailer() )->send( $toEmail, $toName ?: 'Looth Member', $codes );

        return new WP_REST_Response( [ 'ok' => true ] );
    }

    public static function refundRequest(WP_REST_Request $req): WP_REST_Response
    {
        $body     = (array) $req->get_json_params();
        $name     = trim( (string) ( $body['name']     ?? '' ) );
        $email    = trim( (string) ( $body['email']    ?? '' ) );
        $reasons  = (array)        ( $body['reasons']  ?? [] );
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

        if ( $subId === '' || strpos( $subId, 'sub_' ) !== 0 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Valid sub_id required.' ], 400 );
        }

        $context = [ 'sub_id' => $subId, 'refund' => $refund ? 'yes' : 'no', 'immediate' => $immediate ? 'yes' : 'no', 'reason' => $reason ];
        $result  = [ 'ok' => true, 'sub_id' => $subId, 'actions' => [] ];

        try {
            $stripe = new StripeClient();

            // 1. Cancel (or schedule cancellation at period end)
            if ( $immediate ) {
                $sub = $stripe->cancelSubscription( $subId );
                $result['actions'][] = 'canceled (immediate)';
            } else {
                $sub = $stripe->updateSubscription( $subId, [ 'cancel_at_period_end' => true ] );
                $result['actions'][] = 'cancel_at_period_end set';
            }
            $result['status'] = (string) ( $sub->status ?? 'unknown' );

            // 2. Optionally refund the latest paid invoice's payment intent.
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
                        $refundObj = $stripe->createRefund( $refundParams );
                        $result['actions'][] = 'refunded ' . (string) ( $refundObj->id ?? 'unknown' ) . ' (' . (int) ( $refundObj->amount ?? 0 ) . ' cents)';
                        $result['refund_id'] = (string) ( $refundObj->id ?? '' );
                    }
                }
            }

            return new WP_REST_Response( $result );
        } catch ( \Throwable $e ) {
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
            $fresh = CustomerRepo::findById( $customerId );
            return new WP_REST_Response( [
                'ok'           => true,
                'customer_id'  => $customerId,
                'blocked'      => $fresh !== null && ! empty( $fresh['blocked_at'] ),
                'blocked_at'   => $fresh['blocked_at']   ?? null,
                'block_reason' => $fresh['block_reason'] ?? null,
            ] );
        } catch ( \Throwable $e ) {
            AdminAlerts::sendFailureAlert( 'block-customer', [
                'customer_id' => $customerId,
                'blocked'     => $blocked ? 'yes' : 'no',
                'reason'      => $reason,
            ], $e );
            return new WP_REST_Response( [ 'ok' => false, 'error' => $e->getMessage() ], 500 );
        }
    }
}
