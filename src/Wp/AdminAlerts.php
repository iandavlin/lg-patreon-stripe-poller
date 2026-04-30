<?php

declare(strict_types=1);

namespace LGMS\Wp;

/**
 * Sends failure-alert emails to the admin when Stripe-touching admin
 * actions (cancel, refund, block) throw. Best-effort; never throws.
 */
final class AdminAlerts
{
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
