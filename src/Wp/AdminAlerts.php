<?php

declare(strict_types=1);

namespace LGMS\Wp;

/**
 * Sends failure-alert emails to the admin when Stripe-touching admin
 * actions (cancel, refund, block) throw. Best-effort; never throws.
 */
final class AdminAlerts
{
    /**
     * Email the admin when Stripe fires charge.dispute.created (chargeback).
     * Best-effort; never throws.
     */
    public static function sendDisputeAlert(
        string $chargeId,
        string $disputeId,
        ?array $customer,
        int $amountCents,
        string $currency
    ): void {
        try {
            $to = (string) get_option( 'lgms_refund_email', '' );
            if ( $to === '' ) { $to = (string) get_option( 'admin_email' ); }
            if ( $to === '' ) { return; }

            $site     = (string) get_bloginfo( 'name' );
            $key      = (string) get_option( 'lgms_stripe_secret_key', '' );
            $mode     = strpos( $key, 'sk_test_' ) === 0 ? '/test' : '';
            $dispUrl  = 'https://dashboard.stripe.com' . $mode . '/disputes/' . rawurlencode( $disputeId );
            $amtLabel = '$' . number_format( $amountCents / 100, 2 ) . ' ' . $currency;

            $who = $customer
                ? 'Customer #' . (int) $customer['id'] . ' &lt;' . esc_html( (string) $customer['email'] ) . '&gt;'
                : '(unknown — Stripe charge: ' . esc_html( $chargeId ) . ')';

            $html  = '<p><strong>A chargeback has been filed against ' . esc_html( $site ) . '.</strong></p>';
            $html .= '<p><strong>Amount:</strong> ' . esc_html( $amtLabel ) . '<br>';
            $html .= '<strong>Customer:</strong> ' . $who . '<br>';
            $html .= '<strong>Dispute ID:</strong> <a href="' . esc_url( $dispUrl ) . '">' . esc_html( $disputeId ) . '</a></p>';
            $html .= '<p>The customer\'s access has <strong>not</strong> been automatically revoked. Review in Stripe, then revoke access from the WP admin user profile if appropriate.</p>';
            $html .= '<p><a href="' . esc_url( $dispUrl ) . '">View dispute in Stripe &rarr;</a></p>';

            wp_mail( $to, "[{$site}] Chargeback filed — {$amtLabel}", $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
        } catch ( \Throwable $_ ) {
            // Swallow — alert is best-effort.
        }
    }

    public static function sendFailureAlert( string $action, array $context, \Throwable $error ): void
    {
        try {
            $to = (string) get_option( 'lgms_refund_email', '' );
            if ( $to === '' ) {
                $to = (string) get_option( 'admin_email' );
            }
            if ( $to === '' ) {
                return;
            }

            $site    = (string) get_bloginfo( 'name' );
            $subject = "[{$site}] Membership admin action failed: {$action}";

            $rows = '';
            foreach ( $context as $k => $v ) {
                $rows .= '<tr><td style="padding:4px 12px 4px 0;color:#666;vertical-align:top;">' . esc_html( (string) $k ) . '</td><td>' . esc_html( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ) . '</td></tr>';
            }

            $html  = '<p>An admin-triggered membership action failed and needs manual follow-up.</p>';
            $html .= '<p><strong>Action:</strong> ' . esc_html( $action ) . '</p>';
            $html .= '<p><strong>Error:</strong> ' . esc_html( $error->getMessage() ) . '</p>';
            $html .= '<p><strong>Context:</strong></p><table cellpadding="0" cellspacing="0">' . $rows . '</table>';
            $html .= '<p style="color:#666;font-size:0.9em;">Stack trace (top frame): ' . esc_html( $error->getFile() ) . ':' . (int) $error->getLine() . '</p>';

            wp_mail( $to, $subject, $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
        } catch ( \Throwable $_ ) {
            // Swallow — alert is best-effort.
        }
    }
}
