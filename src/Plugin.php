<?php

declare(strict_types=1);

namespace LGMS;

/**
 * Plugin lifecycle + boot. Thin coordinator — real work is in subsystems.
 */
final class Plugin
{
    public const CRON_HOOK     = 'lgms_poll_tick';
    public const CRON_SCHEDULE = 'hourly'; // WP built-in

    public static function activate(): void
    {
        Schema::apply();

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    public static function deactivate(): void
    {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function boot(): void
    {
        // Cron handler — Stripe poll + sync sweep.
        add_action( self::CRON_HOOK, [ Tick::class, 'run' ] );

        // REST endpoints for Slim to trigger immediate syncs.
        add_action( 'rest_api_init', [ Wp\RestController::class, 'register' ] );

        // Admin screens.
        if ( is_admin() ) {
            Admin::boot();
        }
    }
}
