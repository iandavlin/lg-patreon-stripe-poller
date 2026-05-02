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

    /**
     * Role for non-member buyers who came in via a gift purchase. Granted on
     * first purchase if no existing WP user matches their email; persists across
     * future purchases (so they keep one account, not one per purchase).
     *
     * Capability `manage_gift_codes` is what gates [lg_my_gifts] and the
     * dashboard REST endpoints. Members (looth2/looth3) inherit this cap as
     * well so a member who buys gifts has the same dashboard access without
     * needing the looth1 role attached.
     */
    public const GIFT_ROLE = 'looth1';
    public const GIFT_CAP  = 'manage_gift_codes';

    public static function activate(): void
    {
        Schema::apply();
        self::registerGiftRole();
        Wp\Pages::ensureAll();

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    /**
     * Register the looth1 role + the manage_gift_codes capability on member
     * roles. Idempotent — uses add_role/add_cap which silently no-op when the
     * role/cap already exists. Called from activate(); also re-runnable by
     * admins via the "Re-create membership pages" button (which is misnamed
     * but hits the same activation path).
     */
    public static function registerGiftRole(): void
    {
        // 1. The looth1 role itself for non-member gift buyers.
        if ( ! get_role( self::GIFT_ROLE ) ) {
            add_role( self::GIFT_ROLE, 'Looth Gift Buyer', [
                'read'                => true,           // required for any logged-in front-end access
                self::GIFT_CAP        => true,
            ] );
        } else {
            // Role exists — make sure cap is set.
            $role = get_role( self::GIFT_ROLE );
            if ( $role && ! $role->has_cap( self::GIFT_CAP ) ) {
                $role->add_cap( self::GIFT_CAP );
            }
        }

        // 2. Members get the same cap so a member who also gifts has dashboard
        // access without needing the looth1 role attached.
        foreach ( [ 'looth2', 'looth3', 'administrator' ] as $roleName ) {
            $role = get_role( $roleName );
            if ( $role && ! $role->has_cap( self::GIFT_CAP ) ) {
                $role->add_cap( self::GIFT_CAP );
            }
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

        // No-cache headers on shortcode-hosting pages so CF / browsers don't
        // serve stale 404s for newly-created pages and don't cache the
        // query-string-driven welcome / regional-fail content.
        add_action( 'template_redirect', [ self::class, 'maybeSendNoCacheHeaders' ], 0 );

        // Admin screens.
        if ( is_admin() ) {
            Admin::boot();
            Wp\UserProfile::boot();
        }
    }

    /**
     * Send Cache-Control: no-cache headers on any front-end page hosting one
     * of our shortcodes. Stops CF from caching 404 responses (the source of
     * many of our "page suddenly works after CF TTL expires" issues) and
     * stops it from caching the query-string-driven welcome / regional-fail
     * variants as if they were one canonical URL.
     */
    public static function maybeSendNoCacheHeaders(): void
    {
        if ( is_admin() || ! is_singular( 'page' ) ) {
            return;
        }
        $post = get_queried_object();
        if ( ! $post instanceof \WP_Post ) {
            return;
        }
        foreach ( Wp\Pages::PAGES as $info ) {
            $tag = $info['shortcode'] ?? '';
            if ( $tag !== '' && has_shortcode( (string) $post->post_content, $tag ) ) {
                nocache_headers();
                return;
            }
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
