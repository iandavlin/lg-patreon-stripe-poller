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
     * Role assigned on first purchase to gift-only buyers (no existing WP
     * user, not subscribing to a membership). Reuses the legacy WooCommerce
     * `customer` role rather than minting a new one — semantically right
     * (they ARE a customer who bought something), low-privilege (just
     * `read`), and avoids polluting the role registry.
     *
     * NOT looth1 — that's reserved for lapsed members on this site.
     *
     * Capability `manage_gift_codes` gates [lg_my_gifts] and the dashboard
     * REST endpoints. Granted to `customer` (new gift-only buyers), every
     * looth tier (active members and lapsed members who may have gifts on
     * record from when they were active), and `administrator`.
     */
    public const GIFT_ROLE          = 'customer';
    public const GIFT_CAP           = 'manage_gift_codes';
    public const GIFT_CAPABLE_ROLES = [ 'customer', 'looth1', 'looth2', 'looth3', 'looth4', 'administrator' ];

    public static function activate(): void
    {
        Schema::apply();
        self::registerGiftCapability();
        Wp\Pages::ensureAll();

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    /**
     * Grant manage_gift_codes to every role that might own gift codes:
     *   - customer:     new gift-only buyers (assigned in Phase C)
     *   - looth1:       lapsed members (may have legacy gift codes)
     *   - looth2/3/4:   active members (may also gift)
     *   - administrator
     *
     * Idempotent — add_cap silently no-ops if the cap is already set.
     * Re-runnable from the "Re-create membership pages" admin button.
     */
    public static function registerGiftCapability(): void
    {
        foreach ( self::GIFT_CAPABLE_ROLES as $roleName ) {
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

        // Self-heal BuddyBoss public-content allowlist daily so our shortcode
        // pages (my-gifts, lggift-buy, etc.) bypass BB's bpnoaccess gate
        // without needing manual setting changes after cutover or page renames.
        add_action( 'init', [ self::class, 'maybeRefreshBbAllowlist' ], 20 );

        // Block bbPress from auto-adding bbp_participant to gift-only buyers.
        // The "customer" role is for users who only need to manage their gift
        // dashboard — they shouldn't appear in forums or get participant caps.
        add_filter( 'bbp_allow_global_access', [ self::class, 'denyGlobalAccessForCustomers' ] );

        // Strip bbPress interaction caps from customer-only users so they
        // can't reply / post / edit even if some hook grants them the role.
        add_filter( 'user_has_cap', [ self::class, 'stripForumCapsForCustomers' ], 10, 4 );

        // Mask customer-only users as logged-out to BuddyPress / BuddyBoss
        // so no avatar, profile menu, or member-directory entry appears for
        // them. They still have a real WP session for the gift dashboard.
        add_filter( 'bp_loggedin_user_id',   [ self::class, 'maskCustomerBpUserId' ], 10, 1 );
        add_filter( 'bp_displayed_user_id',  [ self::class, 'maskCustomerBpUserId' ], 10, 1 );
        add_action( 'bp_pre_user_query',     [ self::class, 'excludeCustomersFromBpQueries' ], 10, 1 );

        // Body class so theme/CSS rules can branch on customer-only state.
        add_filter( 'body_class',            [ self::class, 'addCustomerBodyClass' ], 10, 1 );

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

    /**
     * Filter for bbp_allow_global_access — returns false for users whose only
     * role is "customer" (gift-only buyers), so bbPress doesn\'t auto-add
     * bbp_participant on every page load.
     */
    public static function denyGlobalAccessForCustomers( $allow )
    {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return $allow;
        }
        $roles = (array) $user->roles;
        if ( in_array( 'customer', $roles, true )
            && ! array_intersect( [ 'administrator', 'editor', 'looth1', 'looth2', 'looth3', 'looth4' ], $roles ) ) {
            return false;
        }
        return $allow;
    }

    /**
     * Filter for user_has_cap — for customer-only users (gift buyers with
     * no other tier/staff role), force every bbPress interaction cap to
     * false. Read caps are left alone so the forum content stays browsable.
     */
    public static function stripForumCapsForCustomers( $allcaps, $caps, $args, $user )
    {
        if ( ! $user || empty( $user->ID ) ) {
            return $allcaps;
        }
        $roles = (array) $user->roles;
        if ( ! in_array( 'customer', $roles, true ) ) {
            return $allcaps;
        }
        if ( array_intersect( [ 'administrator', 'editor', 'looth1', 'looth2', 'looth3', 'looth4',
                                'bbp_keymaster', 'bbp_moderator' ], $roles ) ) {
            return $allcaps;
        }

        // Forum-interaction caps to suppress. Read caps deliberately omitted
        // (read_forum / read_topic / read_reply) so customers can still see
        // public threads — they just can't act on them.
        static $strip = [
            'participate'             => 1,
            'publish_topics'          => 1,
            'edit_topics'             => 1,
            'edit_others_topics'      => 1,
            'publish_replies'         => 1,
            'edit_replies'            => 1,
            'edit_others_replies'     => 1,
            'delete_topics'           => 1,
            'delete_others_topics'    => 1,
            'delete_replies'          => 1,
            'delete_others_replies'   => 1,
            'moderate'                => 1,
            'throttle'                => 1,
            'view_trash'              => 1,
            'spectate'                => 1,
            'assign_topic_tags'       => 1,
            'edit_topic_tags'         => 1,
            'delete_topic_tags'       => 1,
            'manage_topic_tags'       => 1,
            'mark_as_spam'            => 1,
        ];
        foreach ( $strip as $cap => $_ ) {
            $allcaps[ $cap ] = false;
        }
        return $allcaps;
    }

    /**
     * True if the given user has only the customer role (no admin/editor/
     * looth tier and no bbPress staff role). Cached per-request.
     */
    public static function isCustomerOnly( int $userId ): bool
    {
        static $cache = [];
        if ( isset( $cache[ $userId ] ) ) {
            return $cache[ $userId ];
        }
        if ( $userId <= 0 ) {
            return $cache[ $userId ] = false;
        }
        $user = get_userdata( $userId );
        if ( ! $user ) {
            return $cache[ $userId ] = false;
        }
        $roles = (array) $user->roles;
        $only  = in_array( 'customer', $roles, true )
            && ! array_intersect( [ 'administrator', 'editor', 'looth1', 'looth2', 'looth3', 'looth4',
                                    'bbp_keymaster', 'bbp_moderator' ], $roles );
        return $cache[ $userId ] = (bool) $only;
    }

    /**
     * Filter for bp_loggedin_user_id / bp_displayed_user_id — for
     * customer-only users return 0 so BP renders the guest UI everywhere.
     * The real WP user object is untouched, so wp-admin and our gift
     * dashboard still see them as logged in.
     */
    public static function maskCustomerBpUserId( $userId )
    {
        $userId = (int) $userId;
        return self::isCustomerOnly( $userId ) ? 0 : $userId;
    }

    /**
     * Action for bp_pre_user_query — append every customer-only user id
     * to the query exclude list so they never surface in member
     * directories, "active members" widgets, or member counts.
     */
    public static function excludeCustomersFromBpQueries( $query ): void
    {
        global $wpdb;
        $cap_key = $wpdb->get_blog_prefix() . 'capabilities';
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
              WHERE meta_key = %s
                AND meta_value LIKE %s
                AND meta_value NOT LIKE %s
                AND meta_value NOT LIKE %s
                AND meta_value NOT LIKE %s
                AND meta_value NOT LIKE %s
                AND meta_value NOT LIKE %s
                AND meta_value NOT LIKE %s
                AND meta_value NOT LIKE %s
                AND meta_value NOT LIKE %s",
            $cap_key,
            '%"customer"%',
            '%"administrator"%',
            '%"editor"%',
            '%"looth1"%',
            '%"looth2"%',
            '%"looth3"%',
            '%"looth4"%',
            '%"bbp_keymaster"%',
            '%"bbp_moderator"%'
        ) );
        if ( ! $ids ) {
            return;
        }
        $existing = isset( $query->query_vars['exclude'] ) ? (array) $query->query_vars['exclude'] : [];
        $query->query_vars['exclude'] = array_values( array_unique( array_map( 'intval', array_merge( $existing, $ids ) ) ) );
    }

    /**
     * Add a body class for customer-only users so the theme / CSS can hide
     * BB-specific chrome (avatar menu, member nav, etc.).
     */
    public static function addCustomerBodyClass( array $classes ): array
    {
        if ( self::isCustomerOnly( get_current_user_id() ) ) {
            $classes[] = 'lg-customer-only';
        }
        return $classes;
    }

    /**
     * Idempotent BuddyBoss allowlist refresher. Runs at most once every
     * 6 hours via a transient lock so we don't write the option on every
     * pageload but still catch new pages within a reasonable window.
     */
    public static function maybeRefreshBbAllowlist(): void
    {
        if ( get_transient( 'lgms_bb_allowlist_synced' ) ) {
            return;
        }
        if ( class_exists( '\\LGMS\\Wp\\Pages' ) ) {
            \LGMS\Wp\Pages::ensureBuddyBossAllowlist();
        }
        set_transient( 'lgms_bb_allowlist_synced', 1, 6 * HOUR_IN_SECONDS );
    }
}
