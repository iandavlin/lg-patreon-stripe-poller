<?php
/**
 * Sync Cron
 *
 * Manages WP Cron scheduling for automated Patreon member sync.
 * Only active when lgpo_auto_sync_enabled is checked.
 * Supports daily (default), twicedaily, and hourly frequencies.
 *
 * @package LG_Patreon_Onboard
 */

defined( 'ABSPATH' ) || exit;

class LGPO_Sync_Cron {

    /** WP Cron hook name. */
    private const CRON_HOOK = 'lgpo_patreon_auto_sync';

    /**
     * Boot: register the cron action and manage scheduling.
     */
    public static function init(): void {
        add_action( self::CRON_HOOK, [ 'LGPO_Sync_Engine', 'run' ] );
        self::maybe_manage_schedule();
    }

    /**
     * Schedule, reschedule, or unschedule based on admin settings.
     */
    private static function maybe_manage_schedule(): void {
        $enabled   = get_option( 'lgpo_auto_sync_enabled', '' );
        $frequency = get_option( 'lgpo_sync_frequency', 'daily' );
        $next      = wp_next_scheduled( self::CRON_HOOK );

        // Auto sync disabled — unschedule if running
        if ( ! $enabled ) {
            if ( $next ) {
                wp_unschedule_event( $next, self::CRON_HOOK );
            }
            return;
        }

        // Auto sync enabled — check if we need to (re)schedule
        if ( $next ) {
            $current_schedule = wp_get_schedule( self::CRON_HOOK );
            if ( $current_schedule === $frequency ) {
                return; // Already correct
            }
            // Frequency changed, reschedule
            wp_unschedule_event( $next, self::CRON_HOOK );
        }

        wp_schedule_event( time(), $frequency, self::CRON_HOOK );
        error_log( "LGPO Sync: Cron scheduled — {$frequency}." );
    }

    /**
     * Unschedule on plugin deactivation.
     */
    public static function deactivate(): void {
        $next = wp_next_scheduled( self::CRON_HOOK );
        if ( $next ) {
            wp_unschedule_event( $next, self::CRON_HOOK );
        }
    }
}
