<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\Db;
use LGMS\Repos\CustomerRepo;
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

        $customer     = CustomerRepo::findByEmail( $email );
        $customerLine = '(no customer record found for this email)';
        $subsLines    = '';
        if ( $customer ) {
            $customerLine = sprintf(
                'Customer ID: %d | Stripe customer: %s',
                (int) $customer['id'],
                ! empty( $customer['stripe_customer_id'] ) ? $customer['stripe_customer_id'] : '(none)'
            );
            $stmt = Db::pdo()->prepare(
                "SELECT stripe_subscription_id, status, current_period_end FROM subscriptions
                 WHERE customer_id = ? AND status IN ('active','trialing','past_due')
                 ORDER BY id DESC"
            );
            $stmt->execute( [ (int) $customer['id'] ] );
            $rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
            if ( $rows ) {
                $lines = [];
                foreach ( $rows as $r ) {
                    $lines[] = sprintf( '  - %s (%s, ends %s)', $r['stripe_subscription_id'], $r['status'], $r['current_period_end'] ?? 'n/a' );
                }
                $subsLines = "\nActive subscriptions:\n" . implode( "\n", $lines );
            } else {
                $subsLines = "\nActive subscriptions: none";
            }
        }

        $reasonBlock = "  - " . implode( "\n  - ", $reasons );
        $bodyText  = "A customer has submitted a refund request.\n\n";
        $bodyText .= "Name:  {$name}\nEmail: {$email}\n\n";
        $bodyText .= "Reasons:\n{$reasonBlock}\n\n";
        $bodyText .= "Comments:\n" . ( $comments !== '' ? $comments : '(none)' ) . "\n\n";
        $bodyText .= "---\n{$customerLine}{$subsLines}\n\n";
        $bodyText .= "To process: open the customer in the Stripe Dashboard and refund the relevant charge.\n";
        $bodyText .= "Our system will catch the resulting charge.refunded event and revoke access automatically.\n";

        $to = (string) get_option( 'lgms_refund_email', '' );
        if ( $to === '' ) { $to = (string) get_option( 'admin_email' ); }
        $subject = "Refund request from {$name} <{$email}>";
        $headers = [ 'Reply-To: ' . $name . ' <' . $email . '>' ];

        $sent = wp_mail( $to, $subject, $bodyText, $headers );
        if ( ! $sent ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not send your request. Please try again or email us directly.' ], 500 );
        }
        return new WP_REST_Response( [ 'ok' => true ] );
    }
}
