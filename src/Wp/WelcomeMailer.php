<?php

declare(strict_types=1);

namespace LGMS\Wp;

/**
 * Sends the post-upgrade welcome email exactly once per WP user.
 *
 * Triggered from Arbiter::sync at the same point that sets the
 * _lg_pending_welcome meta (the "user just upgraded into a paid tier"
 * transition). The modal handles users who return to the site; this
 * email handles users who don't — the typical path when they backed
 * out of the Stripe modal mid-charge and the cron-driven reconcile
 * sweep (or the checkout.session.completed webhook) provisions their
 * account server-side without them being present.
 *
 * Idempotency: a separate `_lg_welcome_email_sent_at` user meta acts
 * as a delivered-at sentinel. If it's already set we silently bail.
 * Resetting that meta is the supported way to re-fire the email
 * (e.g. for support recoveries).
 */
final class WelcomeMailer
{
    /**
     * Send the welcome email if we haven't already for this user.
     * Returns true if a message was dispatched, false if skipped.
     */
    public static function sendIfNeeded(int $wpUserId, string $tier): bool
    {
        if ( $wpUserId <= 0 ) {
            return false;
        }

        $alreadySent = (string) get_user_meta( $wpUserId, '_lg_welcome_email_sent_at', true );
        if ( $alreadySent !== '' ) {
            return false;
        }

        $user = get_user_by( 'id', $wpUserId );
        if ( ! $user || empty( $user->user_email ) ) {
            return false;
        }

        $tierLabel = self::tierLabel( $tier );
        $name      = trim( (string) ( $user->display_name ?: $user->first_name ?: $user->user_login ) );
        $loginUrl  = wp_login_url( home_url( '/manage-subscription/' ) );
        $manageUrl = home_url( '/manage-subscription/' );
        $homeUrl   = home_url( '/' );

        $template = LGMS_PLUGIN_DIR . 'templates/email/welcome-membership.html.php';
        if ( ! file_exists( $template ) ) {
            error_log( "LGMS WelcomeMailer: template missing at {$template}" );
            return false;
        }

        ob_start();
        // Variables in scope for the template:
        //   $name, $tierLabel, $loginUrl, $manageUrl, $homeUrl
        require $template;
        $body = (string) ob_get_clean();

        $subject = sprintf( 'Welcome to %s — your membership is active', $tierLabel );
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: The Looth Group <noreply@loothgroup.com>',
        ];

        $sent = wp_mail( $user->user_email, $subject, $body, $headers );
        if ( $sent ) {
            update_user_meta( $wpUserId, '_lg_welcome_email_sent_at', gmdate( 'c' ) );
        } else {
            error_log( "LGMS WelcomeMailer: wp_mail returned false for user {$wpUserId}" );
        }
        return (bool) $sent;
    }

    private static function tierLabel( string $tier ): string
    {
        return [
            'looth2' => 'Looth LITE',
            'looth3' => 'Looth PRO',
            'looth4' => 'Looth Premium Plus',
        ][ $tier ] ?? 'Looth';
    }
}
