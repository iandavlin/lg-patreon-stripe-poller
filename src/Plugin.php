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
        Wp\Pages::ensureAll();

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

        // Front-end shortcodes (gift redemption etc.).
        add_action( 'init', [ Wp\Shortcodes::class, 'register' ] );

        // Conditionally enqueue the shortcode stylesheet only on pages
        // that actually contain one of our shortcodes.
        add_action( 'wp_enqueue_scripts', [ self::class, 'maybeEnqueueShortcodeStyles' ] );

        // Admin screens.
        if ( is_admin() ) {
            Admin::boot();
            Wp\UserProfile::boot();
        }
    }

    public static function maybeEnqueueShortcodeStyles(): void
    {
        global $post;
        if ( ! $post || ! is_singular() ) {
            return;
        }
        // Single source of truth: any tag listed in Pages::PAGES gets the
        // shortcode stylesheet auto-enqueued on its hosting page.
        foreach ( Wp\Pages::PAGES as $info ) {
            $tag = $info['shortcode'] ?? '';
            if ( $tag !== '' && has_shortcode( (string) $post->post_content, $tag ) ) {
                wp_enqueue_style(
                    'lg-shortcodes',
                    LGPO_PLUGIN_URL . 'assets/lg-shortcodes.css',
                    [],
                    LGPO_VERSION
                );
                return;
            }
        }
    }
}
