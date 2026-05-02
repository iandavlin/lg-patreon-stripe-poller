<?php

declare(strict_types=1);

namespace LGMS\Wp;

/**
 * Auto-seeded WordPress pages for every membership shortcode.
 *
 * Single source of truth: PAGES maps shortcode_tag → page metadata. On plugin
 * activation, ensureAll() inspects the WP database and creates any missing
 * pages with the standard `[lg_member_nav][shortcode]` content. Same logic
 * also appends each slug to the BuddyBoss public-content allowlist so anon
 * visitors aren't redirected to wp-login.php.
 *
 * Why this exists:
 *   - Previously, every new shortcode required manual page creation in WP
 *     admin (or a wp-cli step) on dev AND prod, plus a separate manual
 *     allowlist edit. Pages didn't travel with code, so dev and prod drifted.
 *   - This class ensures the plugin is self-contained: install it, the pages
 *     exist, the allowlist is correct, the customer-facing flow works.
 *
 * What we explicitly do NOT do:
 *   - Update existing pages. If a page exists with the target slug, we leave
 *     it alone — admins may have customized the layout / surrounding content.
 *     The shortcode is the contract; the page wrapping it is the admin's.
 *   - Skip the manage-subscription page from BB allowlist. The shortcode
 *     itself shows "Please sign in" to anon visitors, so making it public
 *     defeats its purpose. See PROD-CUTOVER.md for the full rationale.
 */
final class Pages
{
    /**
     * Page registry. Each entry = one shortcode-hosting page.
     *
     * Fields:
     *   slug          required. The page's post_name and URL path.
     *   title         required. Page title (visible in admin and as <title>).
     *   shortcode     required. The body shortcode tag (without brackets) that
     *                 the page should host. Used to detect existing pages.
     *   public        bool. true → append to BuddyBoss allowlist on activation.
     *                 false → keep gated behind login (e.g. manage-subscription).
     *   include_nav   bool. Default true. Prepends [lg_member_nav] to content.
     *   template      string|null. _wp_page_template meta value. Defaults to
     *                 page-fullwidth.php — matches the existing public pages.
     */
    public const PAGES = [
        'lg_join' => [
            'slug'      => 'lgjoin',
            'title'     => 'Join',
            'shortcode' => 'lg_join',
            'public'    => true,
            'template'  => 'page-fullwidth-content.php',
        ],
        'lg_gift' => [
            'slug'      => 'lggift-buy',
            'title'     => 'Gift Memberships',
            'shortcode' => 'lg_gift',
            'public'    => true,
            'template'  => 'page-fullwidth-content.php',
        ],
        'lg_redeem_gift' => [
            'slug'      => 'lggift',
            'title'     => 'Redeem a Gift',
            'shortcode' => 'lg_redeem_gift',
            'public'    => true,
            'template'  => 'page-fullwidth.php',
        ],
        'lg_manage_subscription' => [
            'slug'      => 'manage-subscription',
            'title'     => 'Manage Subscription',
            'shortcode' => 'lg_manage_subscription',
            'public'    => false,
            'template'  => 'page-fullwidth.php',
        ],
        'lg_refund_request' => [
            'slug'      => 'request-refund',
            'title'     => 'Request a Refund',
            'shortcode' => 'lg_refund_request',
            'public'    => true,
            'template'  => 'page-fullwidth.php',
        ],
        'lg_regional_fail' => [
            'slug'      => 'regional-pricing-not-available',
            'title'     => 'Regional pricing not available',
            'shortcode' => 'lg_regional_fail',
            'public'    => true,
            'template'  => 'page-fullwidth.php',
        ],
        'lg_subscription_success' => [
            'slug'      => 'welcome',
            'title'     => 'Welcome',
            'shortcode' => 'lg_subscription_success',
            'public'    => true,
            'template'  => 'page-fullwidth.php',
        ],
    ];

    /**
     * Run the full auto-seed pass.
     *
     * @return array{created: list<string>, skipped: list<string>, allowlisted: list<string>}
     */
    public static function ensureAll(): array
    {
        $created     = [];
        $skipped     = [];
        $allowlisted = [];

        foreach ( self::PAGES as $tag => $info ) {
            if ( self::pageHostingShortcodeExists( $info['shortcode'] ) ) {
                $skipped[] = $tag . ' (page hosting [' . $info['shortcode'] . '] already exists)';
                continue;
            }
            if ( self::slugTaken( $info['slug'] ) ) {
                $skipped[] = $tag . ' (slug "' . $info['slug'] . '" taken by another page)';
                continue;
            }
            $id = self::createPage( $info );
            if ( $id > 0 ) {
                $created[] = $tag . ' (id=' . $id . ' slug=' . $info['slug'] . ')';
            } else {
                $skipped[] = $tag . ' (insert failed)';
            }
        }

        $allowlisted = self::ensureBuddyBossAllowlist();

        // Permalink + object cache hygiene — same flush we documented in PROD-CUTOVER.md
        // for the BuddyBoss allowlist.
        flush_rewrite_rules( false );
        wp_cache_flush();

        return compact( 'created', 'skipped', 'allowlisted' );
    }

    /**
     * Append public-flagged page slugs to the BuddyBoss public-content allowlist.
     * Idempotent — slugs already present are left alone.
     *
     * @return list<string> Slugs that were added this run.
     */
    public static function ensureBuddyBossAllowlist(): array
    {
        $option = (string) get_option( 'bp-enable-private-network-public-content', '' );
        $added  = [];

        foreach ( self::PAGES as $info ) {
            if ( empty( $info['public'] ) ) {
                continue;
            }
            $entry = '/' . trim( $info['slug'], '/' ) . '/';
            if ( str_contains( $option, $entry ) ) {
                continue;
            }
            $option   .= ( $option === '' ? '' : "\n" ) . $entry;
            $added[]   = $entry;
        }

        if ( $added !== [] ) {
            update_option( 'bp-enable-private-network-public-content', $option );
        }

        return $added;
    }

    private static function pageHostingShortcodeExists( string $shortcodeTag ): bool
    {
        global $wpdb;
        $needle = '[' . $shortcodeTag;
        $sql    = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page'
               AND post_status IN ('publish', 'draft', 'private')
               AND post_content LIKE %s
             LIMIT 1",
            '%' . $wpdb->esc_like( $needle ) . '%'
        );
        return (int) $wpdb->get_var( $sql ) > 0;
    }

    private static function slugTaken( string $slug ): bool
    {
        $existing = get_page_by_path( $slug, OBJECT, 'page' );
        return $existing instanceof \WP_Post;
    }

    private static function createPage( array $info ): int
    {
        $includeNav = $info['include_nav'] ?? true;
        $body       = ( $includeNav ? '[lg_member_nav]' : '' ) . '[' . $info['shortcode'] . ']';

        $id = wp_insert_post( [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_name'    => $info['slug'],
            'post_title'   => $info['title'],
            'post_content' => $body,
            'post_author'  => 0,
        ], true );

        if ( is_wp_error( $id ) || (int) $id <= 0 ) {
            return 0;
        }

        $template = $info['template'] ?? 'page-fullwidth.php';
        if ( $template ) {
            update_post_meta( (int) $id, '_wp_page_template', $template );
        }

        return (int) $id;
    }
}
