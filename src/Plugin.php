<?php

declare(strict_types=1);

namespace LGMS;

/**
 * Plugin lifecycle + boot. Thin coordinator — real work is in subsystems.
 */
final class Plugin
{
    public const CRON_HOOK     = 'lgms_poll_tick';
    // Custom 5-minute interval registered below in registerCronSchedule().
    // Was 'hourly' before the orphaned-checkout reconcile sweep landed —
    // the sweep wants a tighter latency floor so a stranded customer is
    // recovered within ~5 minutes of bailing.
    public const CRON_SCHEDULE = 'lgms_5min';

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
        // Register a custom 5-minute cron interval (WP only ships hourly,
        // twicedaily, daily). Used by the reconcile-pending sweep.
        add_filter( 'cron_schedules', [ self::class, 'registerCronSchedule' ] );

        // Self-heal the scheduled event's interval. If a previous version
        // of the plugin scheduled the tick on 'hourly', migrate to the new
        // 5-minute schedule without forcing a deactivate/reactivate.
        add_action( 'init', [ self::class, 'maybeRescheduleCron' ], 99 );

        // Deferred rewrite flush — when activation or Pages::ensureAll()
        // mutates page state, they set the 'lgms_pending_rewrite_flush'
        // transient instead of flushing immediately. Flushing here at
        // 'init' priority 9999 ensures every plugin's rewrite rules
        // are registered before WP serializes the rules option, avoiding
        // the partial-rules race that produces unexplained 404s.
        add_action( 'init', static function (): void {
            if ( get_transient( 'lgms_pending_rewrite_flush' ) ) {
                delete_transient( 'lgms_pending_rewrite_flush' );
                flush_rewrite_rules( false );
            }
        }, 9999 );

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

        // Membership guide page + admin dashboard.
        add_action( 'init', [ Wp\MembershipGuide::class, 'register' ] );
        add_action( 'init', [ Wp\UpcomingEvents::class,  'register' ] );

        // Welcome modal: print celebratory modal in the footer when the
        // current user has just been upgraded into a paid tier (looth2+).
        // Triggered by the _lg_pending_welcome user meta which Arbiter
        // sets on the upgrade transition. Modal is single-use; dismiss
        // hits a REST endpoint that clears the meta.
        add_action( 'wp_footer', [ self::class, 'maybePrintWelcomeModal' ] );

        // Conditionally enqueue the shortcode stylesheet only on pages
        // that actually contain one of our shortcodes.
        add_action( 'wp_enqueue_scripts', [ self::class, 'maybeEnqueueShortcodeStyles' ] );

        // No-cache headers on shortcode-hosting pages so CF / browsers don't
        // serve stale 404s for newly-created pages and don't cache the
        // query-string-driven welcome / regional-fail content.
        add_action( 'template_redirect', [ self::class, 'maybeSendNoCacheHeaders' ], 0 );

        // Admin screens.
        if ( is_admin() ) {
            add_action( 'admin_notices', [ self::class, 'renderDisputeNotices' ] );
            add_action( 'admin_init',    [ self::class, 'handleDisputeDismiss' ] );
            Admin::boot();
            MemberTools::boot();
            Wp\UserProfile::boot();

            // Quick links on the Plugins admin page row for this plugin.
            $pluginFile = defined( 'LGPO_PLUGIN_FILE' )
                ? plugin_basename( LGPO_PLUGIN_FILE )
                : 'lg-patreon-stripe-poller/lg-patreon-onboard.php';
            add_filter( "plugin_action_links_{$pluginFile}", [ self::class, 'pluginActionLinks' ] );
        }
    }

    /**
     * Adds Settings + Member Tools links to the Plugins-page row so admins
     * don't have to dig through Settings/Tools menus to find them.
     */
    public static function pluginActionLinks( array $links ): array
    {
        $extra = [
            '<a href="' . esc_url( admin_url( 'options-general.php?page=lg-member-sync' ) ) . '">Settings</a>',
            '<a href="' . esc_url( admin_url( 'tools.php?page=lg-member-tools' ) ) . '">Member tools</a>',
        ];
        return array_merge( $extra, $links );
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
    /**
     * Filter for cron_schedules — register a 5-minute interval.
     */
    public static function registerCronSchedule( array $schedules ): array
    {
        if ( ! isset( $schedules['lgms_5min'] ) ) {
            $schedules['lgms_5min'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => 'Every 5 minutes (LGMS reconcile sweep)',
            ];
        }
        return $schedules;
    }

    /**
     * Reschedule the tick event if it was previously scheduled on a
     * different interval. WP's wp_schedule_event() is idempotent on the
     * schedule NAME — once scheduled on 'hourly', it stays 'hourly' until
     * we explicitly clear and re-schedule. This runs on every init pass
     * (cheap) but only does work when the scheduled interval differs from
     * the desired CRON_SCHEDULE constant.
     */
    public static function maybeRescheduleCron(): void
    {
        $next     = wp_next_scheduled( self::CRON_HOOK );
        $current  = wp_get_schedule( self::CRON_HOOK );
        $expected = self::CRON_SCHEDULE;

        if ( $next === false ) {
            // Not scheduled at all — schedule fresh.
            wp_schedule_event( time() + 60, $expected, self::CRON_HOOK );
            return;
        }

        if ( $current === $expected ) {
            return; // already correct
        }

        wp_unschedule_event( $next, self::CRON_HOOK );
        wp_schedule_event( time() + 60, $expected, self::CRON_HOOK );
    }

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

    /**
     * wp_footer handler: render the post-upgrade welcome modal exactly
     * once per upgrade event. Cheap on the common case — bails before
     * doing any work if the meta isn't set.
     */
    /**
     * Render a dismissible red admin notice for each open chargeback dispute.
     * Only visible to administrators. Persists until explicitly dismissed.
     */
    public static function renderDisputeNotices(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $alerts = (array) get_option( 'lgms_dispute_alerts', [] );
        if ( $alerts === [] ) {
            return;
        }
        foreach ( $alerts as $disputeId => $alert ) {
            $nonce      = wp_create_nonce( 'lgms_dismiss_dispute_' . $disputeId );
            $dismissUrl = esc_url( add_query_arg( [
                'lgms_dismiss_dispute' => $disputeId,
                '_lgms_nonce'          => $nonce,
            ] ) );
            $amtLabel   = '$' . number_format( (int) ( $alert['amount'] ?? 0 ) / 100, 2 ) . ' ' . esc_html( (string) ( $alert['currency'] ?? '' ) );
            $email      = esc_html( (string) ( $alert['customer_email'] ?? 'unknown' ) );
            $date       = esc_html( (string) ( $alert['created_at'] ?? '' ) );
            $stripeUrl  = esc_url( (string) ( $alert['stripe_url'] ?? '' ) );
            ?>
            <div class="notice notice-error" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5em;padding:0.8em 1em;">
                <p style="margin:0;">
                    <strong>⚠ Chargeback filed:</strong>
                    <?php echo $amtLabel; ?> from <?php echo $email; ?> on <?php echo $date; ?>.
                    <?php if ( $stripeUrl !== '' ) : ?>
                        <a href="<?php echo $stripeUrl; ?>" target="_blank" rel="noopener" style="margin-left:0.5em;">View in Stripe &rarr;</a>
                    <?php endif; ?>
                    <span style="color:#888;font-size:0.85em;margin-left:0.75em;">Access not auto-revoked — review and decide.</span>
                </p>
                <a href="<?php echo $dismissUrl; ?>" class="button button-secondary" style="flex-shrink:0;">Dismiss</a>
            </div>
            <?php
        }
    }

    /**
     * Handle the dismiss-dispute GET action. Nonce-verified, admin-only.
     * Removes the alert from the stored array and redirects to clean the URL.
     */
    public static function handleDisputeDismiss(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $disputeId = sanitize_text_field( (string) ( $_GET['lgms_dismiss_dispute'] ?? '' ) );
        if ( $disputeId === '' ) {
            return;
        }
        $nonce = (string) ( $_GET['_lgms_nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'lgms_dismiss_dispute_' . $disputeId ) ) {
            return;
        }
        $alerts = (array) get_option( 'lgms_dispute_alerts', [] );
        unset( $alerts[ $disputeId ] );
        update_option( 'lgms_dispute_alerts', $alerts, false );

        wp_safe_redirect( remove_query_arg( [ 'lgms_dismiss_dispute', '_lgms_nonce' ] ) );
        exit;
    }

    public static function maybePrintWelcomeModal(): void
    {
        if ( ! is_user_logged_in() ) {
            return;
        }
        if ( is_admin() ) {
            return; // never show in wp-admin
        }
        if ( wp_doing_ajax() ) {
            return; // do not corrupt XHR responses
        }
        $userId = get_current_user_id();
        $tier   = (string) get_user_meta( $userId, '_lg_pending_welcome', true );
        if ( $tier === '' ) {
            return;
        }

        $tierLabel = [
            'looth2' => 'Looth LITE',
            'looth3' => 'Looth PRO',
            'looth4' => 'Looth Premium Plus',
        ][ $tier ] ?? 'Looth';

        $endpoint = esc_url_raw( rest_url( 'lg-member-sync/v1/dismiss-welcome' ) );
        $nonce    = wp_create_nonce( 'wp_rest' );
        $titleEsc = esc_html( "🎉 Welcome to {$tierLabel}!" );
        $bodyEsc  = esc_html( 'Your membership is active. You now have full access to forums, archives, member events, and more.' );
        $closeEsc = esc_attr__( 'Close', 'lg-patreon-stripe-poller' );

        ?>
        <div id="lg-welcome-modal" class="lg-welcome-modal" role="dialog" aria-modal="true" aria-labelledby="lg-welcome-title">
            <div class="lg-welcome-modal__backdrop" data-lg-welcome-dismiss></div>
            <div class="lg-welcome-modal__card">
                <button type="button" class="lg-welcome-modal__close" data-lg-welcome-dismiss aria-label="<?php echo $closeEsc; ?>">&times;</button>
                <h3 id="lg-welcome-title" class="lg-welcome-modal__title"><?php echo $titleEsc; ?></h3>
                <p class="lg-welcome-modal__body"><?php echo $bodyEsc; ?></p>
            </div>
        </div>
        <style>
            .lg-welcome-modal { position: fixed; inset: 0; z-index: 2147483600; display: flex; align-items: center; justify-content: center; padding: 1em; opacity: 0; pointer-events: auto; transition: opacity .25s ease; }
            .lg-welcome-modal.is-visible { opacity: 1; }
            .lg-welcome-modal__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.55); }
            .lg-welcome-modal__card { position: relative; background: #fff; color: #1f1d1a; border: 2px solid var(--lg-amber, #ECB351); border-radius: 12px; padding: 1.8em 1.6em; max-width: 440px; width: 100%; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,0.45); transform: translateY(16px); transition: transform .3s cubic-bezier(.2,.8,.2,1); }
            .lg-welcome-modal.is-visible .lg-welcome-modal__card { transform: translateY(0); }
            .lg-welcome-modal__close { position: absolute; top: .55em; right: .55em; width: 2em; height: 2em; padding: 0; background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 50%; font-size: 1.3em; line-height: 1; cursor: pointer; color: #333; display: flex; align-items: center; justify-content: center; transition: background .15s, color .15s; }
            .lg-welcome-modal__close:hover { color: #000; background: #f3f3f3; }
            .lg-welcome-modal__title { margin: 0 0 .55em; font-size: 1.25em; font-weight: 700; line-height: 1.3; }
            .lg-welcome-modal__body { margin: 0; font-size: .95em; line-height: 1.5; color: #444; }
        </style>
        <script>
        (function(){
            var modal = document.getElementById('lg-welcome-modal');
            if ( ! modal ) return;
            // Move out of any positioned ancestor (BB themes set transforms
            // on .site that trap fixed-position children).
            if ( modal.parentNode !== document.body ) document.body.appendChild( modal );
            requestAnimationFrame( function(){ modal.classList.add('is-visible'); } );

            function dismiss() {
                modal.classList.remove('is-visible');
                setTimeout( function(){ modal.parentNode && modal.parentNode.removeChild(modal); }, 250 );
                // Fire-and-forget — even if this fails the modal is gone
                // locally; worst case it shows again on next page load.
                try {
                    fetch(<?php echo wp_json_encode( $endpoint ); ?>, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-WP-Nonce': <?php echo wp_json_encode( $nonce ); ?>, 'Content-Type': 'application/json' },
                        body: '{}'
                    });
                } catch (e) {}
            }
            modal.querySelectorAll('[data-lg-welcome-dismiss]').forEach(function(el){
                el.addEventListener('click', dismiss);
            });
        })();
        </script>
        <?php
    }
}
