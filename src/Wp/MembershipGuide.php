<?php

declare(strict_types=1);

namespace LGMS\Wp;

/**
 * The /membership-guide/ page.
 *
 * Three responsibilities:
 *
 *   1. [lg_membership_guide]  shortcode renders the full page from
 *                             templates/page/membership-guide.php, pulling
 *                             dynamic content (preview cards, elders, urls,
 *                             screenshots) from wp_options.
 *
 *   2. Settings → Membership Guide  admin dashboard for editing those
 *                             options. Image fields use the WP media library
 *                             picker; we store attachment IDs.
 *
 *   3. admin-post action  lgms_guide_download_notes  serves the markdown
 *                             build-notes file at docs/membership-guide-build-notes.md
 *                             so future chats / new contributors can read
 *                             the canonical decisions.
 *
 * See docs/membership-guide-build-notes.md for the full architecture.
 */
final class MembershipGuide
{
    private const OPT_PREVIEW       = 'lgms_guide_preview_cards';
    private const OPT_STARTER       = 'lgms_guide_starter_cards';
    private const OPT_ELDERS        = 'lgms_guide_elders';
    private const OPT_LOOTHALONG    = 'lgms_guide_loothalong_url';
    private const OPT_FEED_VIDEO    = 'lgms_guide_feed_video_url';
    private const OPT_FEED_POSTER   = 'lgms_guide_feed_poster_id';
    private const OPT_ARCHIVE_DEMO  = 'lgms_guide_archive_demo_url';
    private const OPT_FORUMS_DEMO    = 'lgms_guide_forums_demo_url';
    private const OPT_FORUMS_IMAGE   = 'lgms_guide_forums_image_url';
    private const OPT_SCREENSHOTS    = 'lgms_guide_screenshots';
    private const OPT_RECURRING      = 'lgms_guide_recurring_shows';

    /**
     * Default elder roster, used to seed the option on first load.
     * After that the admin UI is a full repeater — names, bios, and
     * URLs are all editable there. Edit this list only to change the
     * out-of-the-box defaults for fresh installs.
     */
    private const ELDER_DEFAULTS = [
        [
            'name'        => 'Ian Davlin',
            'bio'         => 'Ian Davlin is the founder of The Looth Group, a community built for people who do serious guitar work. He created the platform to bring together the most experienced makers in the world and make their knowledge accessible to working builders and repairers everywhere.',
            'archive_url' => '',
            'ig_url'      => '',
        ],
        [
            'name'        => 'Dan Erlewine',
            'bio'         => 'Dan Erlewine is one of the most celebrated figures in guitar repair and restoration, with a career spanning more than 50 years. Author of the Guitar Player Repair Guide and a longtime collaborator with StewMac, Dan has built instruments for Jerry Garcia and Albert King and trained a generation of luthiers and repair technicians through his writing and video work.',
            'archive_url' => '',
            'ig_url'      => '',
        ],
        [
            'name'        => 'Michael Bashkin',
            'bio'         => 'Michael Bashkin began building guitars in 1994 while studying forestry at Colorado State University, bringing a wood biologist\'s understanding of acoustics to his craft. He opened his shop in Fort Collins, Colorado in 1998, where he builds a small number of meticulously handcrafted acoustic guitars each year. Bashkin is also the host of the Luthier on Luthier podcast, and is widely regarded as one of the most thoughtful voices in the independent lutherie community.',
            'archive_url' => '',
            'ig_url'      => '',
        ],
        [
            'name'        => 'James Rodaman',
            'bio'         => 'James Rodaman is a luthier and repair specialist known for exceptional instrument work and innovative custom tooling for the lutherie trade. He is recognized in the community for his machine-shop expertise and his dedication to advancing the craft through mentorship and community building.',
            'archive_url' => '',
            'ig_url'      => '',
        ],
        [
            'name'        => 'Doug Proper',
            'bio'         => 'Doug Proper owns and operates The Guitar Specialist, widely regarded as one of the premier guitar repair and restoration shops in the United States. Originally pursuing a career as a jazz guitarist, Doug turned to repair work during his college years and built a clientele that includes Paul Simon, John Scofield, and John Abercrombie. He is a proud member of the Guild of American Luthiers and the Association of Stringed Instrument Artisans.',
            'archive_url' => '',
            'ig_url'      => '',
        ],
        [
            'name'        => 'Brock Poling',
            'bio'         => 'Brock Poling is a luthier and guitar builder who joined StewMac in the early 2000s, eventually serving as Vice President of Marketing at the company that supplies tools and materials to luthiers worldwide. A StewMac customer himself before joining the team, Brock has collaborated on-camera with Dan Erlewine in StewMac\'s instructional content series.',
            'archive_url' => '',
            'ig_url'      => '',
        ],
        [
            'name'        => 'Massimiliano Montorosso',
            'bio'         => 'Massimiliano "Max" Montorosso is an Italian luthier based in Abano Terme, Italy, building custom acoustic, electric, and harp guitars under the Maxmonte Guitars brand. His work blends time-honored luthiery techniques — hide glue, hand-carved braces, hand-planed joints — with a distinctly modern sensibility, resulting in instruments of exceptional tonal character available through select dealers in North America and Europe.',
            'archive_url' => '',
            'ig_url'      => '',
        ],
    ];

    private const SECTION_SLUGS = [ 'events', 'archive', 'feed', 'forums', 'looths', 'loothalong' ];

    public static function register(): void
    {
        add_shortcode( 'lg_membership_guide', [ self::class, 'render' ] );
        add_shortcode( 'lg_elder_bio',        [ self::class, 'renderElderBio' ] );
        add_action( 'admin_menu',                          [ self::class, 'adminMenu' ] );
        add_action( 'admin_post_lgms_guide_save',          [ self::class, 'handleSave' ] );
        add_action( 'admin_post_lgms_guide_download_notes',[ self::class, 'handleDownloadNotes' ] );
        add_action( 'admin_enqueue_scripts',               [ self::class, 'enqueueAdminAssets' ] );

        // Front-end inline editor for elders (admin-only).
        add_action( 'wp_ajax_lgms_save_elder',             [ self::class, 'handleAjaxSaveElder' ] );
        // Front-end inline editor for the Recurring Shows carousel (admin-only).
        add_action( 'wp_ajax_lgms_save_show',              [ self::class, 'handleAjaxSaveShow' ] );
        add_action( 'wp_ajax_lgms_delete_show',            [ self::class, 'handleAjaxDeleteShow' ] );

        // Some plugin in the stack (BB / Search-Filter / Elementor combo) reorders
        // WP's rewrite rules during regeneration so the single-name POST rule lands
        // BEFORE the multi-segment PAGE rule. Result: any /page-slug/ URL is treated
        // as a missing post and 404s after every permalink flush. We re-pin the page
        // rule above the post rule on every regeneration. Late priority so we run
        // after whatever else is messing with the array.
        add_filter( 'rewrite_rules_array', [ self::class, 'pinPageRuleAbovePostRule' ], 9999 );
    }

    /**
     * Ensure the canonical page rewrite rule fires BEFORE the post rule.
     * Idempotent — does nothing if order is already correct or either rule
     * is missing.
     */
    public static function pinPageRuleAbovePostRule( array $rules ): array
    {
        $pageKey = '(.?.+?)(?:/([0-9]+))?/?$';
        $postKey = '([^/]+)(?:/([0-9]+))?/?$';
        if ( ! isset( $rules[ $pageKey ], $rules[ $postKey ] ) ) {
            return $rules;
        }
        $keys     = array_keys( $rules );
        $pageIdx  = array_search( $pageKey, $keys, true );
        $postIdx  = array_search( $postKey, $keys, true );
        if ( $pageIdx === false || $postIdx === false || $pageIdx < $postIdx ) {
            return $rules; // already correct, leave alone
        }
        // Pull the page rule out, reinsert immediately before the post rule.
        $pageVal = $rules[ $pageKey ];
        unset( $rules[ $pageKey ] );
        $reordered = [];
        foreach ( $rules as $k => $v ) {
            if ( $k === $postKey ) {
                $reordered[ $pageKey ] = $pageVal;
            }
            $reordered[ $k ] = $v;
        }
        return $reordered;
    }

    /* ------------------------------------------------------------------
     * Front-end render
     * ----------------------------------------------------------------*/

    public static function render( $atts = [] ): string
    {
        shortcode_atts( [], (array) $atts, 'lg_membership_guide' );

        $template = LGMS_PLUGIN_DIR . 'templates/page/membership-guide.php';
        if ( ! file_exists( $template ) ) {
            return '<p><em>Membership guide template missing.</em></p>';
        }

        // Variables the template expects.
        $isMember         = is_user_logged_in();
        $bodyClass        = $isMember ? 'is-member' : 'is-anon';
        $previewCards     = self::getPreviewCards();
        $starterCards     = self::getStarterCards();
        $elders           = self::getElders();
        $loothalongUrl    = (string) get_option( self::OPT_LOOTHALONG,    '' );
        $feedVideoUrl     = (string) get_option( self::OPT_FEED_VIDEO,    '' );
        $feedPosterUrl    = self::posterUrl( (int) get_option( self::OPT_FEED_POSTER, 0 ) );
        $archiveDemoUrl   = (string) get_option( self::OPT_ARCHIVE_DEMO,  '' );
        $forumsDemoUrl    = (string) get_option( self::OPT_FORUMS_DEMO,   '' );
        $forumsImageUrl   = (string) get_option( self::OPT_FORUMS_IMAGE,  '' );
        $forumCounts      = self::getForumCounts();
        $screenshots      = self::getScreenshots();
        $recurringShows   = self::getRecurringShows();

        ob_start();
        require $template;
        return (string) ob_get_clean();
    }

    /**
     * [lg_elder_bio name="Dan Erlewine"]
     * Renders a full bio page for a single elder. Designed to be used as
     * the sole content of an auto-seeded elder WP page.
     */
    public static function renderElderBio( $atts = [] ): string
    {
        $atts = shortcode_atts( [ 'name' => '' ], (array) $atts, 'lg_elder_bio' );
        $targetName = sanitize_text_field( $atts['name'] );
        if ( $targetName === '' ) {
            return '<p><em>Elder name not specified.</em></p>';
        }

        $elders = self::getElders();
        $elder  = null;
        foreach ( $elders as $e ) {
            if ( strtolower( $e['name'] ) === strtolower( $targetName ) ) {
                $elder = $e;
                break;
            }
        }
        if ( $elder === null ) {
            return '<p><em>Elder "' . esc_html( $targetName ) . '" not found.</em></p>';
        }

        $template = LGMS_PLUGIN_DIR . 'templates/page/elder-bio.php';
        if ( ! file_exists( $template ) ) {
            return '<p><em>Elder bio template missing.</em></p>';
        }

        ob_start();
        require $template;
        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * Option accessors with sensible defaults
     * ----------------------------------------------------------------*/

    public static function getPreviewCards(): array
    {
        return self::getCardSet( self::OPT_PREVIEW );
    }

    public static function getStarterCards(): array
    {
        return self::getCardSet( self::OPT_STARTER );
    }

    private static function getCardSet( string $optionKey ): array
    {
        $raw = get_option( $optionKey, [] );
        if ( is_string( $raw ) ) {
            $raw = json_decode( $raw, true ) ?: [];
        }
        $out = [];
        for ( $i = 0; $i < 4; $i++ ) {
            $row = $raw[ $i ] ?? [];
            $out[] = [
                'thumb_id' => $row['thumb_id'] ?? 0, // mixed: int attachment id OR string URL
                'kind'     => (string) ( $row['kind']  ?? '' ),
                'title'    => (string) ( $row['title'] ?? '' ),
                'url'      => (string) ( $row['url']   ?? '' ),
            ];
        }
        return $out;
    }

    public static function getElders(): array
    {
        $raw = get_option( self::OPT_ELDERS, null );
        if ( is_string( $raw ) ) {
            $raw = json_decode( $raw, true ) ?: [];
        }
        // First-time use: seed from defaults.
        if ( $raw === null || $raw === [] ) {
            $out = [];
            foreach ( self::ELDER_DEFAULTS as $d ) {
                $out[] = [
                    'name'        => $d['name'],
                    'avatar_id'   => 0,
                    'ig_url'      => $d['ig_url'],
                    'bio'         => $d['bio'],
                    'archive_url' => $d['archive_url'],
                    'bio_page_id' => 0,
                ];
            }
            return $out;
        }
        $out = [];
        foreach ( (array) $raw as $row ) {
            if ( ! is_array( $row ) ) continue;
            $name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
            if ( $name === '' ) continue;
            $out[] = [
                'name'        => $name,
                'avatar_id'   => $row['avatar_id'] ?? 0, // mixed: int|string
                'ig_url'      => (string) ( $row['ig_url']      ?? '' ),
                'fb_url'      => (string) ( $row['fb_url']      ?? '' ),
                'yt_url'      => (string) ( $row['yt_url']      ?? '' ),
                'tw_url'      => (string) ( $row['tw_url']      ?? '' ),
                'website_url' => (string) ( $row['website_url'] ?? '' ),
                'bio'         => (string) ( $row['bio']         ?? '' ),
                'archive_url' => (string) ( $row['archive_url'] ?? '' ),
                'speciality'  => (string) ( $row['speciality']  ?? '' ),
                'bio_page_id' => (int)    ( $row['bio_page_id'] ?? 0 ),
            ];
        }
        return $out;
    }

    public static function getScreenshots(): array
    {
        $raw = get_option( self::OPT_SCREENSHOTS, [] );
        if ( is_string( $raw ) ) {
            $raw = json_decode( $raw, true ) ?: [];
        }
        $out = [];
        foreach ( self::SECTION_SLUGS as $slug ) {
            $list = (array) ( $raw[ $slug ] ?? [] );
            $clean = [];
            foreach ( $list as $v ) {
                if ( is_numeric( $v ) && (int) $v > 0 ) {
                    $clean[] = (int) $v;
                } elseif ( is_string( $v ) && filter_var( $v, FILTER_VALIDATE_URL ) ) {
                    $clean[] = $v;
                }
            }
            $out[ $slug ] = $clean;
        }
        return $out;
    }

    /**
     * Resolve a stored image value (attachment ID OR URL string) to a usable URL.
     * Returns '' if the value can't be turned into one.
     */
    public static function resolveImage( $value, string $size = 'medium' ): string
    {
        if ( is_numeric( $value ) && (int) $value > 0 ) {
            $u = wp_get_attachment_image_url( (int) $value, $size );
            return $u ?: '';
        }
        if ( is_string( $value ) && $value !== '' && filter_var( $value, FILTER_VALIDATE_URL ) ) {
            return $value;
        }
        return '';
    }

    /** Back-compat alias used by the page template. */
    public static function thumbUrl( $value, string $size = 'medium' ): string
    {
        return self::resolveImage( $value, $size );
    }

    private static function posterUrl( $value ): string
    {
        return self::resolveImage( $value, 'large' );
    }

    /**
     * Accepts an int attachment ID or a URL string. Returns int|string.
     * Returns 0 for empty / invalid input.
     *
     * @param mixed $raw
     * @return int|string
     */
    private static function sanitizeImageValue( $raw )
    {
        if ( is_numeric( $raw ) ) {
            $i = (int) $raw;
            return $i > 0 ? $i : 0;
        }
        if ( is_string( $raw ) ) {
            $u = trim( $raw );
            if ( $u !== '' && filter_var( $u, FILTER_VALIDATE_URL ) ) {
                return esc_url_raw( $u );
            }
        }
        return 0;
    }

    /** BB xprofile field IDs we care about. Found via wp_bp_xprofile_fields. */
    private const BB_FIELD_SOCIAL  = 266; // socialnetworks (facebook/instagram/twitter/reddit/youTube)
    private const BB_FIELD_WEBSITE = 272; // web

    /**
     * Resolve the WP_User behind an elder. Tries, in order:
     *   1. user_nicename = sanitize_title(name)        // "michael-bashkin"
     *   2. user_login    = no-space lowercase          // "michaelbashkin"
     *   3. display_name LIKE %name%                    // catches "Michael Bashkin Bashkin Guitars"
     *   4. display_name has BOTH first AND last name   // catches reordered or suffixed names
     *   5. user_nicename or user_login LIKE %lastname% // last-resort fuzzy
     *
     * Cached per request.
     */
    public static function getElderUser( array $elder ): ?\WP_User
    {
        static $cache = [];
        $name = trim( (string) ( $elder['name'] ?? '' ) );
        if ( $name === '' ) return null;
        if ( array_key_exists( $name, $cache ) ) return $cache[ $name ];

        global $wpdb;
        $slug    = sanitize_title( $name );
        $nospace = strtolower( str_replace( ' ', '', $name ) );
        $parts   = preg_split( '/\s+/', $name );
        $first   = $parts[0]   ?? '';
        $last    = end( $parts ) ?: '';

        // 1 + 2: exact login / nicename. Login takes priority because it's the
        // canonical "real" account (e.g. iandavlin) — slug-style nicenames are
        // often secondary/duplicate accounts (ian-davlin → "Farts" test user).
        $u = get_user_by( 'login', $nospace );
        if ( ! $u ) $u = get_user_by( 'slug', $slug );
        if ( $u ) return $cache[ $name ] = $u;

        // 3: display_name contains the full name verbatim.
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE display_name LIKE %s ORDER BY ID ASC LIMIT 1",
            '%' . $wpdb->esc_like( $name ) . '%'
        ) );
        if ( $id ) return $cache[ $name ] = get_userdata( $id );

        // 4: display_name contains first AND last separately.
        if ( $first && $last && $first !== $last ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->users}
                 WHERE display_name LIKE %s AND display_name LIKE %s
                 ORDER BY ID ASC LIMIT 1",
                '%' . $wpdb->esc_like( $first ) . '%',
                '%' . $wpdb->esc_like( $last )  . '%'
            ) );
            if ( $id ) return $cache[ $name ] = get_userdata( $id );
        }

        // 5: nicename / login contain the last name (catches "the-guitar-specialist"
        // patterns where the elder has a shop-style nicename).
        if ( $last && strlen( $last ) >= 4 ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->users}
                 WHERE user_nicename LIKE %s OR user_login LIKE %s
                 ORDER BY ID ASC LIMIT 1",
                '%' . $wpdb->esc_like( strtolower( $last ) ) . '%',
                '%' . $wpdb->esc_like( strtolower( $last ) ) . '%'
            ) );
            if ( $id ) return $cache[ $name ] = get_userdata( $id );
        }

        return $cache[ $name ] = null;
    }

    /**
     * Rewrite any dev/staging host on a URL → loothgroup.com so that on dev,
     * media stored in the DB resolves against prod's actual filesystem.
     */
    private static function liveify( string $url ): string
    {
        return (string) preg_replace(
            '#^https?://(?:dev|staging)\.loothgroup\.com#i',
            'https://loothgroup.com',
            $url
        );
    }

    /**
     * Resolve an avatar URL for an elder. Order of resolution:
     *   1. BuddyBoss avatar (bp_core_fetch_avatar) — works on prod, often
     *      a placeholder on dev because the avatars/{user_id}/ dir doesn't sync.
     *   2. The user's most recent BB profile-photo attachment (post_title
     *      ends in "-bpfull") — DB-backed so it works on dev.
     *   3. Patreon-imported avatar URL stored in usermeta `patreon-avatar-url`.
     *   4. Manually configured avatar_id (attachment ID or URL string).
     *
     * Every URL is run through liveify() so dev hosts swap to loothgroup.com.
     *
     * @param array  $elder  { name, avatar_id, ... }
     * @param string $size   'thumb' (50px) or 'full' (150px)
     */
    public static function getElderAvatar( array $elder, string $size = 'thumb' ): string
    {
        static $cache = [];
        $key = ( $elder['name'] ?? '' ) . '|' . $size;
        if ( isset( $cache[ $key ] ) ) return $cache[ $key ];

        $user = self::getElderUser( $elder );

        // 1. BuddyBoss avatar.
        if ( $user && function_exists( 'bp_core_fetch_avatar' ) ) {
            $url = bp_core_fetch_avatar( [
                'item_id' => $user->ID,
                'object'  => 'user',
                'type'    => $size,
                'html'    => false,
                'no_grav' => true,
            ] );
            $isPlaceholder = $url === '' || ! is_string( $url )
                || strpos( $url, 'mystery' ) !== false
                // BB's site-wide default avatar lives at /avatars/0/<hash>.jpg
                // (user_id 0). Treat that as "no real avatar set" and fall through.
                || preg_match( '#/avatars/0/#', $url )
                || preg_match( '#gravatar\.com/avatar/(?:\?|[^/?]*\?[^?]*\bf=y)#i', $url );
            if ( ! $isPlaceholder ) {
                return $cache[ $key ] = self::liveify( $url );
            }
        }

        if ( $user ) {
            global $wpdb;

            // 2. Most-recent BB profile-photo attachment authored by this user.
            //    Pattern: post_title ends in "-bpfull" (BB's upload naming).
            $guid = $wpdb->get_var( $wpdb->prepare(
                "SELECT guid FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                   AND post_author = %d
                   AND post_title LIKE %s
                 ORDER BY ID DESC LIMIT 1",
                $user->ID, '%-bpfull'
            ) );
            if ( $guid ) return $cache[ $key ] = self::liveify( $guid );

            // 3. Patreon-imported avatar URL.
            $patreon = (string) get_user_meta( $user->ID, 'patreon-avatar-url', true );
            if ( $patreon !== '' ) return $cache[ $key ] = self::liveify( $patreon );
        }

        // 4. Manual avatar_id from the admin (attachment ID or URL).
        $manual = self::resolveImage( $elder['avatar_id'] ?? 0, $size === 'full' ? 'medium' : 'thumbnail' );
        return $cache[ $key ] = $manual;
    }

    /**
     * Pull an elder's external links from BuddyBoss xprofile, with manual
     * admin-entered fields as fallback. Returns:
     *
     *   [
     *     'instagram'   => string|null,
     *     'facebook'    => string|null,
     *     'twitter'     => string|null,
     *     'youtube'     => string|null,
     *     'reddit'      => string|null,
     *     'website'     => string|null,
     *     'archive_url' => string|null,  // The Looth archive filter URL (admin-set)
     *     'profile_url' => string|null,  // Their BuddyBoss profile page on loothgroup.com
     *   ]
     */
    public static function getElderLinks( array $elder ): array
    {
        static $cache = [];
        $name = (string) ( $elder['name'] ?? '' );
        if ( isset( $cache[ $name ] ) ) return $cache[ $name ];

        $out = [
            'instagram'   => null,
            'facebook'    => null,
            'twitter'     => null,
            'youtube'     => null,
            'reddit'      => null,
            'website'     => null,
            'archive_url' => ! empty( $elder['archive_url'] ) ? $elder['archive_url'] : null,
            'profile_url' => null,
        ];

        $user = self::getElderUser( $elder );
        if ( $user ) {
            global $wpdb;
            $table = $wpdb->prefix . 'bp_xprofile_data';

            // Social Media field (serialized array).
            $raw = $wpdb->get_var( $wpdb->prepare(
                "SELECT value FROM {$table} WHERE field_id = %d AND user_id = %d",
                self::BB_FIELD_SOCIAL, $user->ID
            ) );
            if ( $raw ) {
                $social = maybe_unserialize( $raw );
                if ( is_array( $social ) ) {
                    foreach ( $social as $platform => $url ) {
                        $url = trim( (string) $url );
                        if ( $url === '' ) continue;
                        // Upgrade insecure http:// → https:// for known-safe domains
                        // so they don't trigger mixed-content warnings.
                        $url = preg_replace(
                            '#^http://(www\.)?(instagram|facebook|twitter|youtube|reddit)\.com#i',
                            'https://${1}${2}.com',
                            $url
                        );
                        // Normalize key (BB stores 'youTube' with capital T).
                        $key = strtolower( $platform );
                        if ( array_key_exists( $key, $out ) ) {
                            $out[ $key ] = $url;
                        }
                    }
                }
            }

            // Website field (plain string).
            $web = $wpdb->get_var( $wpdb->prepare(
                "SELECT value FROM {$table} WHERE field_id = %d AND user_id = %d",
                self::BB_FIELD_WEBSITE, $user->ID
            ) );
            if ( $web ) $out['website'] = trim( $web );

            // BuddyBoss profile page.
            if ( function_exists( 'bp_core_get_user_domain' ) ) {
                $profile = bp_core_get_user_domain( $user->ID );
                if ( $profile ) {
                    $out['profile_url'] = preg_replace(
                        '#^https?://(?:dev|staging)\.loothgroup\.com#i',
                        'https://loothgroup.com',
                        $profile
                    );
                }
            }
        }

        // Admin-entered overrides win over BB. Anything the admin sets in the
        // edit modal replaces whatever BB returned. Empty admin fields fall
        // through to BB (already populated above).
        $overrides = [
            'instagram' => $elder['ig_url']      ?? '',
            'facebook'  => $elder['fb_url']      ?? '',
            'youtube'   => $elder['yt_url']      ?? '',
            'twitter'   => $elder['tw_url']      ?? '',
            'website'   => $elder['website_url'] ?? '',
        ];
        foreach ( $overrides as $key => $val ) {
            $val = trim( (string) $val );
            if ( $val !== '' ) $out[ $key ] = $val;
        }

        return $cache[ $name ] = $out;
    }

    /**
     * Live bbPress topic + reply counts, cached in a 6-hour transient.
     * Returns [ 'topics' => int, 'replies' => int ].
     */
    public static function getForumCounts(): array
    {
        $cached = get_transient( 'lgms_forum_counts' );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        global $wpdb;
        $counts = [
            'topics'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='topic'  AND post_status='publish'" ),
            'replies' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='reply'  AND post_status='publish'" ),
        ];
        set_transient( 'lgms_forum_counts', $counts, 6 * HOUR_IN_SECONDS );
        return $counts;
    }

    /* ------------------------------------------------------------------
     * Admin dashboard
     * ----------------------------------------------------------------*/

    public static function adminMenu(): void
    {
        add_options_page(
            'Membership Guide',
            'Membership Guide',
            'manage_options',
            'lgms-guide',
            [ self::class, 'renderAdmin' ]
        );
    }

    public static function enqueueAdminAssets( string $hook ): void
    {
        if ( strpos( $hook, 'lgms-guide' ) === false ) {
            return;
        }
        wp_enqueue_media();
    }

    public static function renderAdmin(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $preview        = self::getPreviewCards();
        $starter        = self::getStarterCards();
        $elders         = self::getElders();
        $loothalongUrl  = (string) get_option( self::OPT_LOOTHALONG, '' );
        $feedVideoUrl   = (string) get_option( self::OPT_FEED_VIDEO,    '' );
        $feedPosterId   = (int)    get_option( self::OPT_FEED_POSTER,  0 );
        $archiveDemoUrl  = (string) get_option( self::OPT_ARCHIVE_DEMO,  '' );
        $forumsDemoUrl   = (string) get_option( self::OPT_FORUMS_DEMO,   '' );
        $forumsImageUrl  = (string) get_option( self::OPT_FORUMS_IMAGE,  '' );
        $screenshots     = self::getScreenshots();
        $notice         = isset( $_GET['saved'] ) ? 'Saved.' : '';

        $downloadUrl = wp_nonce_url(
            admin_url( 'admin-post.php?action=lgms_guide_download_notes' ),
            'lgms_guide_notes'
        );

        ?>
        <div class="wrap">
            <h1>Membership Guide</h1>
            <p>Edit the dynamic content on <code><?php echo esc_html( home_url( '/membership-guide/' ) ); ?></code>. Image fields open the media library; the rest are plain text.</p>

            <p>
                <a href="<?php echo esc_url( $downloadUrl ); ?>" class="button">
                    &darr; Download build notes
                </a>
                <span class="description" style="margin-left:10px;">
                    Hand to a future chat / new contributor — explains every option, every section, and how to extend.
                </span>
            </p>

            <?php if ( $notice !== '' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'lgms_guide_save' ); ?>
                <input type="hidden" name="action" value="lgms_guide_save">

                <h2>Loothalong Zoom URL</h2>
                <p>The 24/7 channel. Only logged-in members ever see this URL.</p>
                <input type="url" name="loothalong_url" value="<?php echo esc_attr( $loothalongUrl ); ?>" class="regular-text" placeholder="https://us02web.zoom.us/…">

                <hr>

                <h2>Feed Demo Clip</h2>
                <p>Muted autoplay loop demonstrating the feed. Accepts a GIF or MP4 URL. MP4 also takes an optional poster image.</p>
                <p><label><strong>URL (GIF or MP4):</strong> <input type="url" name="feed_video_url" value="<?php echo esc_attr( $feedVideoUrl ); ?>" class="regular-text" placeholder="https://…/feed.gif"></label></p>
                <p>
                    <strong>Poster image (MP4 only):</strong>
                    <?php self::renderImagePicker( 'feed_poster_id', $feedPosterId ); ?>
                </p>

                <hr>

                <h2>Archive Demo Clip</h2>
                <p>How-to demo for the Archive section. Accepts a GIF or MP4 URL.</p>
                <p><label><strong>URL (GIF or MP4):</strong> <input type="url" name="archive_demo_url" value="<?php echo esc_attr( $archiveDemoUrl ); ?>" class="regular-text" placeholder="https://…/archive.gif"></label></p>

                <hr>

                <h2>Forums Demo Clip</h2>
                <p>How-to demo for the Forums section. Accepts a YouTube URL, GIF, or MP4.</p>
                <p><label><strong>URL:</strong> <input type="url" name="forums_demo_url" value="<?php echo esc_attr( $forumsDemoUrl ); ?>" class="regular-text" placeholder="https://youtu.be/…"></label></p>

                <h2>Forums Screenshot</h2>
                <p>Static preview image of the forum listing shown above the how-to demo.</p>
                <p><label><strong>Image URL:</strong> <input type="url" name="forums_image_url" value="<?php echo esc_attr( $forumsImageUrl ); ?>" class="regular-text" placeholder="https://…/forum-screenshot.jpg"></label></p>

                <hr>

                <h2>Public Preview Cards <small style="font-weight:400;color:#888;">(shown only to logged-out visitors — sales funnel)</small></h2>
                <p>Pick 4 pieces of public content (article / show / loothprint / interview) to feature at the top for anonymous visitors.</p>
                <table class="widefat striped" style="max-width:1100px;">
                    <thead>
                        <tr><th>Thumb</th><th>Kind</th><th>Title</th><th>URL</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $preview as $i => $row ) : ?>
                        <tr>
                            <td><?php self::renderImagePicker( "preview[{$i}][thumb_id]", $row['thumb_id'], 'small' ); ?></td>
                            <td><input type="text" name="preview[<?php echo $i; ?>][kind]" value="<?php echo esc_attr( $row['kind'] ); ?>" placeholder="Article / Live Show / Loothprint" class="regular-text"></td>
                            <td><input type="text" name="preview[<?php echo $i; ?>][title]" value="<?php echo esc_attr( $row['title'] ); ?>" class="regular-text"></td>
                            <td><input type="url"  name="preview[<?php echo $i; ?>][url]"   value="<?php echo esc_attr( $row['url'] ); ?>" class="regular-text"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <hr>

                <h2>Start Here Cards <small style="font-weight:400;color:#888;">(shown only to logged-in members — onboarding)</small></h2>
                <p>Pick 4 pieces of must-read / must-watch content for new members. Same shape as Preview cards, but the audience is people who just joined.</p>
                <table class="widefat striped" style="max-width:1100px;">
                    <thead>
                        <tr><th>Thumb</th><th>Kind</th><th>Title</th><th>URL</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $starter as $i => $row ) : ?>
                        <tr>
                            <td><?php self::renderImagePicker( "starter[{$i}][thumb_id]", $row['thumb_id'], 'small' ); ?></td>
                            <td><input type="text" name="starter[<?php echo $i; ?>][kind]" value="<?php echo esc_attr( $row['kind'] ); ?>" placeholder="Article / Show / Guide" class="regular-text"></td>
                            <td><input type="text" name="starter[<?php echo $i; ?>][title]" value="<?php echo esc_attr( $row['title'] ); ?>" class="regular-text"></td>
                            <td><input type="url"  name="starter[<?php echo $i; ?>][url]"   value="<?php echo esc_attr( $row['url'] ); ?>" class="regular-text"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <hr>

                <h2>Council of Elders</h2>
                <p>Add, edit, or remove elders. Bio pages are <strong>auto-created / synced on every save</strong> at <code>/elder-{slug}/</code> and added to the BuddyBoss public-content allowlist automatically.</p>

                <style>
                .lgms-elder-card { background:#fff; border:1px solid #ddd; border-radius:6px; padding:16px 18px; margin-bottom:12px; max-width:1100px; }
                .lgms-elder-card-header { display:grid; grid-template-columns:auto 1fr auto; gap:16px; align-items:start; margin-bottom:12px; }
                .lgms-elder-card-fields { display:grid; grid-template-columns:1fr 1fr; gap:10px 16px; }
                .lgms-elder-card-fields label { display:block; font-size:13px; color:#555; margin-bottom:2px; }
                .lgms-elder-card-footer { margin-top:8px; font-size:12px; color:#888; }
                .lgms-elder-card-footer a { color:#2271b1; }
                .lgms-elder-bio-row { margin-top:4px; }
                .lgms-elder-bio-row label { display:block; font-size:13px; color:#555; margin-bottom:2px; }
                .lgms-elder-bio-row textarea { width:100%; min-height:80px; font-size:13px; line-height:1.5; }
                </style>

                <div id="lgms-elders-body">
                <?php foreach ( $elders as $i => $row ) :
                    $bioSlug   = 'elder-' . sanitize_title( $row['name'] );
                    $bioPageId = (int) ( $row['bio_page_id'] ?? 0 );
                    $bioPageUrl = home_url( '/' . $bioSlug . '/' );
                    $editUrl    = $bioPageId > 0 ? get_edit_post_link( $bioPageId ) : '';
                ?>
                <div class="lgms-elder-card lgms-elder-row" data-index="<?php echo $i; ?>">
                    <input type="hidden" name="elders[<?php echo $i; ?>][bio_page_id]" value="<?php echo $bioPageId; ?>">

                    <div class="lgms-elder-card-header">
                        <!-- Avatar -->
                        <div><?php self::renderImagePicker( "elders[{$i}][avatar_id]", $row['avatar_id'], 'small' ); ?></div>

                        <!-- Fields grid -->
                        <div class="lgms-elder-card-fields">
                            <div>
                                <label>Display name</label>
                                <input type="text" name="elders[<?php echo $i; ?>][name]" value="<?php echo esc_attr( $row['name'] ); ?>" class="regular-text" placeholder="Display name" style="width:100%;">
                            </div>
                            <div>
                                <label>Instagram URL</label>
                                <input type="url" name="elders[<?php echo $i; ?>][ig_url]" value="<?php echo esc_attr( $row['ig_url'] ); ?>" class="regular-text" placeholder="https://instagram.com/…" style="width:100%;">
                            </div>
                            <div style="grid-column:1/-1;">
                                <label>Archive filter URL <small style="color:#aaa;">(link to their content in the archive)</small></label>
                                <input type="url" name="elders[<?php echo $i; ?>][archive_url]" value="<?php echo esc_attr( $row['archive_url'] ?? '' ); ?>" class="large-text" placeholder="https://loothgroup.com/archive/?author=…" style="width:100%;">
                            </div>
                            <div style="grid-column:1/-1;" class="lgms-elder-bio-row">
                                <label>Bio <small style="color:#aaa;">(shown on the elder's public bio page)</small></label>
                                <textarea name="elders[<?php echo $i; ?>][bio]" class="large-text"><?php echo esc_textarea( $row['bio'] ?? '' ); ?></textarea>
                            </div>
                        </div>

                        <!-- Remove -->
                        <div>
                            <button type="button" class="button-link lgms-elder-remove" style="color:#dc2626;white-space:nowrap;">✕ Remove</button>
                        </div>
                    </div>

                    <div class="lgms-elder-card-footer">
                        <?php if ( $bioPageId > 0 ) : ?>
                            Bio page:
                            <a href="<?php echo esc_url( $bioPageUrl ); ?>" target="_blank"><?php echo esc_html( $bioPageUrl ); ?></a>
                            <?php if ( $editUrl ) : ?>
                                &nbsp;·&nbsp; <a href="<?php echo esc_url( $editUrl ); ?>">Edit page in WP admin</a>
                            <?php endif; ?>
                        <?php else : ?>
                            Bio page: <em>will be created on next save</em>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>

                <p style="margin-top:8px;">
                    <button type="button" class="button" id="lgms-elder-add">+ Add elder</button>
                    <span class="description" style="margin-left:10px;">Bio pages are auto-created when you save.</span>
                </p>

                <!-- Template card used by the Add button. JS replaces __I__ with a fresh index. -->
                <template id="lgms-elder-template">
                    <div class="lgms-elder-card lgms-elder-row" data-index="__I__">
                        <input type="hidden" name="elders[__I__][bio_page_id]" value="0">
                        <div class="lgms-elder-card-header">
                            <div>
                                <span class="lgms-img-picker" data-target="elders[__I__][avatar_id]" style="display:inline-block;">
                                    <span class="lgms-img-preview" style="display:inline-block;width:60px;height:60px;background:#eee;border:1px solid #ddd;vertical-align:middle;"></span>
                                    <input type="hidden" class="lgms-img-value" name="elders[__I__][avatar_id]" value="0">
                                    <button type="button" class="button lgms-img-pick">Pick from library</button>
                                    <button type="button" class="button-link lgms-img-clear" style="display:none;">clear</button>
                                    <br>
                                    <input type="url" class="lgms-img-url-input" placeholder="…or paste an image URL" value="" style="width:200px;margin-top:4px;font-size:12px;">
                                </span>
                            </div>
                            <div class="lgms-elder-card-fields">
                                <div>
                                    <label>Display name</label>
                                    <input type="text" name="elders[__I__][name]" value="" class="regular-text" placeholder="Display name" style="width:100%;">
                                </div>
                                <div>
                                    <label>Instagram URL</label>
                                    <input type="url" name="elders[__I__][ig_url]" value="" class="regular-text" placeholder="https://instagram.com/…" style="width:100%;">
                                </div>
                                <div style="grid-column:1/-1;">
                                    <label>Archive filter URL</label>
                                    <input type="url" name="elders[__I__][archive_url]" value="" class="large-text" placeholder="https://loothgroup.com/archive/?author=…" style="width:100%;">
                                </div>
                                <div style="grid-column:1/-1;" class="lgms-elder-bio-row">
                                    <label>Bio</label>
                                    <textarea name="elders[__I__][bio]" class="large-text"></textarea>
                                </div>
                            </div>
                            <div>
                                <button type="button" class="button-link lgms-elder-remove" style="color:#dc2626;white-space:nowrap;">✕ Remove</button>
                            </div>
                        </div>
                        <div class="lgms-elder-card-footer"><em>Bio page will be created on next save.</em></div>
                    </div>
                </template>

                <hr>

                <h2>Section Screenshots</h2>
                <p>Add as many or as few screenshots per section as you want. They render as a horizontal slider with click-to-zoom lightbox.</p>
                <?php foreach ( self::SECTION_SLUGS as $slug ) : ?>
                    <h3 style="margin-top:1.2em;text-transform:capitalize;"><?php echo esc_html( $slug ); ?></h3>
                    <?php self::renderImageListPicker( "screenshots[{$slug}]", $screenshots[ $slug ] ?? [] ); ?>
                <?php endforeach; ?>

                <p style="margin-top:2em;"><?php submit_button( 'Save changes', 'primary', 'submit', false ); ?></p>
            </form>
        </div>

        <?php self::printAdminScript(); ?>
        <?php
    }

    /**
     * @param mixed $value Either an int attachment ID or a URL string.
     */
    private static function renderImagePicker( string $name, $value, string $size = 'thumbnail' ): void
    {
        $isUrl    = is_string( $value ) && $value !== '' && ! is_numeric( $value );
        $hidden   = $isUrl ? $value : (string) (int) $value;
        $url      = self::resolveImage( $value, $size );
        $hasValue = $url !== '' || $isUrl;
        ?>
        <span class="lgms-img-picker" data-target="<?php echo esc_attr( $name ); ?>" style="display:inline-block;">
            <span class="lgms-img-preview" style="display:inline-block;width:60px;height:60px;background:#eee;border:1px solid #ddd;vertical-align:middle;<?php echo $url ? "background:url('" . esc_url( $url ) . "') center/cover;" : ''; ?>"></span>
            <input type="hidden" class="lgms-img-value" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $hidden ); ?>">
            <button type="button" class="button lgms-img-pick">Pick from library</button>
            <button type="button" class="button-link lgms-img-clear" <?php echo $hasValue ? '' : 'style="display:none;"'; ?>>clear</button>
            <br>
            <input type="url" class="lgms-img-url-input" placeholder="…or paste an image URL" value="<?php echo esc_attr( $isUrl ? (string) $value : '' ); ?>" style="width:280px;margin-top:4px;font-size:12px;">
        </span>
        <?php
    }

    private static function renderImageListPicker( string $name, array $values ): void
    {
        ?>
        <div class="lgms-img-list" data-name="<?php echo esc_attr( $name ); ?>">
            <div class="lgms-img-list-items" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
                <?php foreach ( $values as $v ) :
                    $url   = self::resolveImage( $v, 'thumbnail' );
                    if ( ! $url ) continue;
                    $store = is_numeric( $v ) ? (string) (int) $v : (string) $v;
                ?>
                <span class="lgms-img-list-item" style="position:relative;display:inline-block;">
                    <input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $store ); ?>">
                    <img src="<?php echo esc_url( $url ); ?>" style="width:80px;height:80px;object-fit:cover;border:1px solid #ddd;border-radius:4px;display:block;">
                    <button type="button" class="lgms-img-list-remove" style="position:absolute;top:-6px;right:-6px;background:#dc2626;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;line-height:18px;">&times;</button>
                </span>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button lgms-img-list-add">+ Add from library</button>
            <button type="button" class="button lgms-img-list-add-url">+ Add by URL</button>
        </div>
        <?php
    }

    private static function printAdminScript(): void
    {
        ?>
        <script>
        jQuery(function($){
            // Single-image pickers — Pick from library
            $(document).on('click', '.lgms-img-pick', function(e){
                e.preventDefault();
                var wrap = $(this).closest('.lgms-img-picker');
                var frame = wp.media({ title: 'Pick image', multiple: false, library: { type: 'image' } });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    wrap.find('.lgms-img-value').val(att.id);
                    wrap.find('.lgms-img-url-input').val(''); // library wins → clear URL field
                    wrap.find('.lgms-img-preview').css('background', "url('" + att.url + "') center/cover");
                    wrap.find('.lgms-img-clear').show();
                });
                frame.open();
            });
            // Single-image pickers — paste URL
            $(document).on('input', '.lgms-img-url-input', function(){
                var wrap = $(this).closest('.lgms-img-picker');
                var url  = $(this).val().trim();
                if (url) {
                    wrap.find('.lgms-img-value').val(url);
                    wrap.find('.lgms-img-preview').css('background', "url('" + url + "') center/cover");
                    wrap.find('.lgms-img-clear').show();
                } else {
                    wrap.find('.lgms-img-value').val('0');
                    wrap.find('.lgms-img-preview').css('background', '#eee');
                    wrap.find('.lgms-img-clear').hide();
                }
            });
            $(document).on('click', '.lgms-img-clear', function(e){
                e.preventDefault();
                var wrap = $(this).closest('.lgms-img-picker');
                wrap.find('.lgms-img-value').val('0');
                wrap.find('.lgms-img-url-input').val('');
                wrap.find('.lgms-img-preview').css('background', '#eee');
                $(this).hide();
            });

            // Multi-image lists — Add from library
            $(document).on('click', '.lgms-img-list-add', function(e){
                e.preventDefault();
                var wrap = $(this).closest('.lgms-img-list');
                var name = wrap.data('name');
                var frame = wp.media({ title: 'Pick screenshots', multiple: true, library: { type: 'image' } });
                frame.on('select', function(){
                    frame.state().get('selection').each(function(att){
                        var a = att.toJSON();
                        appendListItem(wrap, name, a.id, a.url);
                    });
                });
                frame.open();
            });
            // Multi-image lists — Add by URL
            $(document).on('click', '.lgms-img-list-add-url', function(e){
                e.preventDefault();
                var wrap = $(this).closest('.lgms-img-list');
                var name = wrap.data('name');
                var url  = window.prompt('Paste image URL:');
                if (!url) return;
                url = url.trim();
                if (!/^https?:\/\//i.test(url)) {
                    alert('Must be a full URL starting with http:// or https://');
                    return;
                }
                appendListItem(wrap, name, url, url);
            });
            function appendListItem(wrap, name, value, previewUrl) {
                var html = '<span class="lgms-img-list-item" style="position:relative;display:inline-block;">' +
                           '<input type="hidden" name="' + name + '[]" value="' + $('<div>').text(value).html() + '">' +
                           '<img src="' + previewUrl + '" style="width:80px;height:80px;object-fit:cover;border:1px solid #ddd;border-radius:4px;display:block;">' +
                           '<button type="button" class="lgms-img-list-remove" style="position:absolute;top:-6px;right:-6px;background:#dc2626;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;line-height:18px;">&times;</button>' +
                           '</span>';
                wrap.find('.lgms-img-list-items').append(html);
            }
            $(document).on('click', '.lgms-img-list-remove', function(e){
                e.preventDefault();
                $(this).closest('.lgms-img-list-item').remove();
            });

            // Council of Elders repeater
            function nextElderIndex() {
                var max = -1;
                $('#lgms-elders-body .lgms-elder-row').each(function(){
                    var n = parseInt($(this).attr('data-index'), 10);
                    if (!isNaN(n)) max = Math.max(max, n);
                });
                return max + 1;
            }
            $('#lgms-elder-add').on('click', function(e){
                e.preventDefault();
                var i    = nextElderIndex();
                var tpl  = document.getElementById('lgms-elder-template');
                var html = tpl.innerHTML.replace(/__I__/g, i);
                var div  = document.createElement('div');
                div.innerHTML = html;
                var card = div.firstElementChild;
                $('#lgms-elders-body').append(card);
            });
            $(document).on('click', '.lgms-elder-remove', function(e){
                e.preventDefault();
                $(this).closest('.lgms-elder-row').remove();
            });
        });
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Elder bio-page sync
     * ----------------------------------------------------------------*/

    /**
     * Ensure every elder has a published WP page at /elder-{slug}/ hosting
     * [lg_elder_bio name="…"]. Creates missing pages, stores the page ID
     * back into the option, and adds each slug to the BuddyBoss allowlist.
     *
     * Called automatically from handleSave() whenever the elder roster is
     * saved. Safe to call multiple times — existing pages are left alone.
     */
    public static function syncElderPages(): void
    {
        $elders  = self::getElders();
        $changed = false;

        foreach ( $elders as &$elder ) {
            $name    = $elder['name'];
            $slug    = 'elder-' . sanitize_title( $name );
            $pageId  = (int) ( $elder['bio_page_id'] ?? 0 );

            // Validate stored ID.
            if ( $pageId > 0 && ! ( get_post( $pageId ) instanceof \WP_Post ) ) {
                $pageId = 0;
            }

            // If we have a valid ID but the elder was renamed, sync the page's
            // slug + title to match the new name, and re-allowlist the new slug.
            if ( $pageId > 0 ) {
                $existing = get_post( $pageId );
                $needSlug  = $existing->post_name  !== $slug;
                $needTitle = $existing->post_title !== $name;
                $needBody  = strpos( (string) $existing->post_content, 'name="' . $name . '"' ) === false;
                if ( $needSlug || $needTitle || $needBody ) {
                    wp_update_post( [
                        'ID'           => $pageId,
                        'post_name'    => $slug,
                        'post_title'   => $name,
                        'post_content' => '[lg_elder_bio name="' . esc_attr( $name ) . '"]',
                    ] );
                    $changed = true; // bio_page_id stays the same; flag a rewrite flush
                }
            }

            // Try to find by slug if we don't have a valid ID.
            if ( $pageId === 0 ) {
                $existing = get_page_by_path( $slug, OBJECT, 'page' );
                if ( $existing instanceof \WP_Post ) {
                    $pageId = $existing->ID;
                }
            }

            // Create the page if it still doesn't exist.
            if ( $pageId === 0 ) {
                $newId = wp_insert_post( [
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_name'    => $slug,
                    'post_title'   => $name,
                    'post_content' => '[lg_elder_bio name="' . esc_attr( $name ) . '"]',
                    'post_author'  => get_current_user_id() ?: 1,
                ], true );

                if ( ! is_wp_error( $newId ) && (int) $newId > 0 ) {
                    update_post_meta( (int) $newId, '_wp_page_template', 'page-fullwidth.php' );
                    $pageId = (int) $newId;
                }
            }

            if ( $pageId > 0 && (int) ( $elder['bio_page_id'] ?? 0 ) !== $pageId ) {
                $elder['bio_page_id'] = $pageId;
                $changed = true;
            }

            // Keep BuddyBoss allowlist current.
            if ( $pageId > 0 ) {
                self::ensureElderPageAllowlisted( $slug );
            }
        }
        unset( $elder );

        if ( $changed ) {
            update_option( self::OPT_ELDERS, $elders );
            set_transient( 'lgms_pending_rewrite_flush', 1, HOUR_IN_SECONDS );
        }
    }

    /**
     * Append a single elder page slug to the BuddyBoss public-content
     * allowlist. Idempotent — no-op if the slug is already present.
     */
    private static function ensureElderPageAllowlisted( string $slug ): void
    {
        $option = (string) get_option( 'bp-enable-private-network-public-content', '' );
        $entry  = '/' . trim( $slug, '/' ) . '/';
        if ( str_contains( $option, $entry ) ) {
            return;
        }
        update_option(
            'bp-enable-private-network-public-content',
            $option . ( $option === '' ? '' : "\n" ) . $entry
        );
        wp_cache_delete( 'alloptions', 'options' );
    }

    /* ------------------------------------------------------------------
     * Save handler
     * ----------------------------------------------------------------*/

    public static function handleSave(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_guide_save' );

        update_option( self::OPT_LOOTHALONG,   esc_url_raw( (string) ( $_POST['loothalong_url']   ?? '' ) ) );
        update_option( self::OPT_FEED_VIDEO,   esc_url_raw( (string) ( $_POST['feed_video_url']   ?? '' ) ) );
        update_option( self::OPT_FEED_POSTER,  self::sanitizeImageValue( $_POST['feed_poster_id']  ?? 0 ) );
        update_option( self::OPT_ARCHIVE_DEMO, esc_url_raw( (string) ( $_POST['archive_demo_url'] ?? '' ) ) );
        update_option( self::OPT_FORUMS_DEMO,  esc_url_raw( (string) ( $_POST['forums_demo_url']  ?? '' ) ) );
        update_option( self::OPT_FORUMS_IMAGE, esc_url_raw( (string) ( $_POST['forums_image_url'] ?? '' ) ) );

        // Preview cards
        $previewIn  = (array) ( $_POST['preview'] ?? [] );
        $previewOut = [];
        for ( $i = 0; $i < 4; $i++ ) {
            $row = $previewIn[ $i ] ?? [];
            $previewOut[] = [
                'thumb_id' => self::sanitizeImageValue( $row['thumb_id'] ?? 0 ),
                'kind'     => sanitize_text_field( (string) ( $row['kind']  ?? '' ) ),
                'title'    => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
                'url'      => esc_url_raw(         (string) ( $row['url']   ?? '' ) ),
            ];
        }
        update_option( self::OPT_PREVIEW, $previewOut );

        // Starter cards (member-only "start here")
        $starterIn  = (array) ( $_POST['starter'] ?? [] );
        $starterOut = [];
        for ( $i = 0; $i < 4; $i++ ) {
            $row = $starterIn[ $i ] ?? [];
            $starterOut[] = [
                'thumb_id' => self::sanitizeImageValue( $row['thumb_id'] ?? 0 ),
                'kind'     => sanitize_text_field( (string) ( $row['kind']  ?? '' ) ),
                'title'    => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
                'url'      => esc_url_raw(         (string) ( $row['url']   ?? '' ) ),
            ];
        }
        update_option( self::OPT_STARTER, $starterOut );

        // Elders
        $eldersIn  = (array) ( $_POST['elders'] ?? [] );
        $eldersOut = [];
        foreach ( $eldersIn as $row ) {
            $name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
            if ( $name === '' ) continue;
            $eldersOut[] = [
                'name'        => $name,
                'avatar_id'   => self::sanitizeImageValue( $row['avatar_id'] ?? 0 ),
                'ig_url'      => esc_url_raw( (string) ( $row['ig_url']      ?? '' ) ),
                'fb_url'      => esc_url_raw( (string) ( $row['fb_url']      ?? '' ) ),
                'yt_url'      => esc_url_raw( (string) ( $row['yt_url']      ?? '' ) ),
                'tw_url'      => esc_url_raw( (string) ( $row['tw_url']      ?? '' ) ),
                'website_url' => esc_url_raw( (string) ( $row['website_url'] ?? '' ) ),
                'bio'         => wp_kses_post( (string) ( $row['bio']         ?? '' ) ),
                'archive_url' => esc_url_raw( (string) ( $row['archive_url'] ?? '' ) ),
                'speciality'  => sanitize_text_field( (string) ( $row['speciality'] ?? '' ) ),
                'bio_page_id' => (int) ( $row['bio_page_id'] ?? 0 ),
            ];
        }
        update_option( self::OPT_ELDERS, $eldersOut );
        // Auto-create / reconcile bio pages for any elders that don't have one yet.
        self::syncElderPages();

        // Screenshots
        $shotsIn  = (array) ( $_POST['screenshots'] ?? [] );
        $shotsOut = [];
        foreach ( self::SECTION_SLUGS as $slug ) {
            $items = (array) ( $shotsIn[ $slug ] ?? [] );
            $clean = [];
            foreach ( $items as $v ) {
                $sanitized = self::sanitizeImageValue( $v );
                if ( $sanitized !== 0 && $sanitized !== '' ) {
                    $clean[] = $sanitized;
                }
            }
            $shotsOut[ $slug ] = $clean;
        }
        update_option( self::OPT_SCREENSHOTS, $shotsOut );

        wp_safe_redirect( add_query_arg( [ 'page' => 'lgms-guide', 'saved' => 1 ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    /* ------------------------------------------------------------------
     * Recurring Shows (16:9 carousel under the Events section)
     * ----------------------------------------------------------------*/

    /**
     * Returns the saved recurring-show cards.
     * Each card: [ 'title' => string, 'thumb_url' => string, 'archive_url' => string ]
     */
    public static function getRecurringShows(): array
    {
        $raw = get_option( self::OPT_RECURRING, [] );
        if ( is_string( $raw ) ) $raw = json_decode( $raw, true ) ?: [];
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) continue;
            $out[] = [
                'title'       => (string) ( $row['title']       ?? '' ),
                'thumb_url'   => (string) ( $row['thumb_url']   ?? '' ),
                'archive_url' => (string) ( $row['archive_url'] ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * AJAX: create or update a single recurring-show card.
     * POST: index (-1 = create new), title, thumb_url, archive_url, nonce.
     */
    public static function handleAjaxSaveShow(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
        check_ajax_referer( 'lgms_show_edit', 'nonce' );

        $shows = self::getRecurringShows();
        $idx   = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
        $card = [
            'title'       => sanitize_text_field( (string) ( $_POST['title']       ?? '' ) ),
            'thumb_url'   => esc_url_raw(         (string) ( $_POST['thumb_url']   ?? '' ) ),
            'archive_url' => esc_url_raw(         (string) ( $_POST['archive_url'] ?? '' ) ),
        ];
        if ( $idx < 0 || ! isset( $shows[ $idx ] ) ) {
            $shows[]      = $card;
            $idx          = count( $shows ) - 1;
        } else {
            $shows[ $idx ] = $card;
        }
        update_option( self::OPT_RECURRING, $shows );
        wp_send_json_success( [ 'index' => $idx ] );
    }

    /** AJAX: delete a recurring-show card by index. */
    public static function handleAjaxDeleteShow(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
        check_ajax_referer( 'lgms_show_edit', 'nonce' );

        $idx   = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
        $shows = self::getRecurringShows();
        if ( ! isset( $shows[ $idx ] ) ) wp_send_json_error( [ 'message' => 'not found' ], 404 );

        array_splice( $shows, $idx, 1 );
        update_option( self::OPT_RECURRING, $shows );
        wp_send_json_success();
    }

    /**
     * Render the inline editor modal for a single recurring-show card.
     * Emits nothing for non-admins. Triggers: any element with class
     * `lgms-show-edit-btn` and data-index attribute (or data-index="-1" to
     * create a new card).
     */
    public static function renderShowEditModal( array $shows ): void
    {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $payload = [];
        foreach ( $shows as $i => $s ) {
            $payload[ $i ] = [
                'title'       => (string) ( $s['title']       ?? '' ),
                'thumb_url'   => (string) ( $s['thumb_url']   ?? '' ),
                'archive_url' => (string) ( $s['archive_url'] ?? '' ),
            ];
        }
        $nonce = wp_create_nonce( 'lgms_show_edit' );
        $ajax  = admin_url( 'admin-ajax.php' );
        ?>
        <style>
        .lgms-show-edit-btn { position:absolute; top:6px; right:6px; width:28px; height:28px; border-radius:50%; background:rgba(43,35,24,0.85); color:#ECB351; border:none; cursor:pointer; font-size:14px; line-height:28px; padding:0; opacity:0.65; transition:opacity 0.15s, transform 0.15s; z-index:5; display:flex; align-items:center; justify-content:center; }
        .lgms-show-edit-btn:hover { opacity:1; transform:scale(1.1); }
        .lgms-show-add-card { display:flex; align-items:center; justify-content:center; min-height:160px; background:rgba(0,0,0,0.04); border:2px dashed #c5beae; border-radius:10px; cursor:pointer; color:#888; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; transition:background 0.15s, color 0.15s; }
        .lgms-show-add-card:hover { background:#ECB351; color:#2B2318; border-color:#ECB351; }
        </style>
        <div class="lgms-eed" id="lgms-sed">
          <div class="lgms-eed-card" role="dialog" aria-modal="true">
            <h3 id="lgms-sed-title">Edit recurring show</h3>
            <input type="hidden" id="lgms-sed-index" value="-1">
            <label>Title</label>
            <input type="text" id="lgms-sed-name" placeholder="e.g. Workshop Wednesdays">
            <label>Thumbnail image URL <span style="font-weight:400;text-transform:none;color:#aaa;">(16:9 ratio looks best)</span></label>
            <div class="row">
              <span class="preview" id="lgms-sed-preview" style="width:96px;height:54px;border-radius:6px;"></span>
              <input type="url" id="lgms-sed-thumb" placeholder="https://...">
            </div>
            <label>Archive filter URL <span style="font-weight:400;text-transform:none;color:#aaa;">(takes the viewer to recordings of this show)</span></label>
            <input type="url" id="lgms-sed-archive" placeholder="https://loothgroup.com/archive/?...">
            <div class="lgms-eed-actions">
              <button type="button" id="lgms-sed-delete" style="margin-right:auto;background:transparent;color:#dc2626;border:none;cursor:pointer;font-size:12px;font-weight:700;text-transform:uppercase;display:none;">Delete</button>
              <span class="lgms-eed-status" id="lgms-sed-status"></span>
              <button type="button" class="cancel" id="lgms-sed-cancel">Cancel</button>
              <button type="button" class="save"   id="lgms-sed-save">Save</button>
            </div>
          </div>
        </div>
        <script>
        (function(){
            var data    = <?php echo wp_json_encode( $payload ); ?>;
            var ajax    = <?php echo wp_json_encode( $ajax ); ?>;
            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

            var modal   = document.getElementById('lgms-sed');
            var $       = function(id){ return document.getElementById(id); };
            var fIndex  = $('lgms-sed-index');
            var fName   = $('lgms-sed-name');
            var fThumb  = $('lgms-sed-thumb');
            var fArc    = $('lgms-sed-archive');
            var fPrev   = $('lgms-sed-preview');
            var status  = $('lgms-sed-status');
            var btnSave = $('lgms-sed-save');
            var btnDel  = $('lgms-sed-delete');

            function open(idx) {
                fIndex.value = idx;
                if (idx >= 0 && data[idx]) {
                    var d = data[idx];
                    fName.value  = d.title;
                    fThumb.value = d.thumb_url;
                    fArc.value   = d.archive_url;
                    fPrev.style.backgroundImage = d.thumb_url ? "url('"+d.thumb_url+"')" : '';
                    btnDel.style.display = 'inline-block';
                } else {
                    fName.value  = ''; fThumb.value = ''; fArc.value = '';
                    fPrev.style.backgroundImage = '';
                    btnDel.style.display = 'none';
                }
                status.textContent = '';
                status.className = 'lgms-eed-status';
                btnSave.disabled = false;
                btnDel.disabled = false;
                modal.classList.add('open');
            }
            function close(){ modal.classList.remove('open'); }

            document.querySelectorAll('.lgms-show-edit-btn').forEach(function(btn){
                btn.addEventListener('click', function(e){
                    e.preventDefault(); e.stopPropagation();
                    open(parseInt(btn.getAttribute('data-index'), 10));
                });
            });

            fThumb.addEventListener('input', function(){
                if (fThumb.value) fPrev.style.backgroundImage = "url('"+fThumb.value.replace(/'/g,"\\'")+"')";
            });

            $('lgms-sed-cancel').addEventListener('click', close);
            modal.addEventListener('click', function(e){ if (e.target === modal) close(); });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && modal.classList.contains('open')) close();
            });

            btnSave.addEventListener('click', function(){
                btnSave.disabled = true;
                status.textContent = 'Saving…';
                var body = new URLSearchParams();
                body.append('action','lgms_save_show');
                body.append('nonce', nonce);
                body.append('index',       fIndex.value);
                body.append('title',       fName.value);
                body.append('thumb_url',   fThumb.value);
                body.append('archive_url', fArc.value);
                fetch(ajax, { method:'POST', credentials:'same-origin', body:body })
                  .then(function(r){ return r.json(); })
                  .then(function(j){
                      if (j && j.success) {
                          status.textContent = 'Saved. Reloading…';
                          setTimeout(function(){ location.reload(); }, 350);
                      } else {
                          status.textContent = (j && j.data && j.data.message) ? j.data.message : 'Save failed.';
                          status.className = 'lgms-eed-status error';
                          btnSave.disabled = false;
                      }
                  });
            });

            btnDel.addEventListener('click', function(){
                if (!confirm('Delete this show card?')) return;
                btnDel.disabled = true; btnSave.disabled = true;
                status.textContent = 'Deleting…';
                var body = new URLSearchParams();
                body.append('action','lgms_delete_show');
                body.append('nonce', nonce);
                body.append('index', fIndex.value);
                fetch(ajax, { method:'POST', credentials:'same-origin', body:body })
                  .then(function(r){ return r.json(); })
                  .then(function(j){
                      if (j && j.success) { status.textContent = 'Deleted.'; setTimeout(function(){ location.reload(); }, 350); }
                      else { status.textContent = 'Delete failed.'; status.className = 'lgms-eed-status error'; btnDel.disabled = false; btnSave.disabled = false; }
                  });
            });
        })();
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * AJAX: front-end elder editor (admin-only)
     * ----------------------------------------------------------------*/

    /**
     * POST endpoint hit by the in-page edit modal on /membership-guide/ and
     * /elder-{slug}/ pages. Updates a single elder row in the option, then
     * re-runs syncElderPages so a renamed elder gets its bio-page slug
     * updated in the same shot.
     */
    public static function handleAjaxSaveElder(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
        }
        check_ajax_referer( 'lgms_elder_edit', 'nonce' );

        $idx = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
        $elders = self::getElders();
        if ( ! isset( $elders[ $idx ] ) ) {
            wp_send_json_error( [ 'message' => 'elder not found' ], 404 );
        }

        $elders[ $idx ]['name']        = sanitize_text_field( (string) ( $_POST['name']        ?? $elders[ $idx ]['name'] ) );
        $elders[ $idx ]['speciality']  = sanitize_text_field( (string) ( $_POST['speciality']  ?? '' ) );
        $elders[ $idx ]['bio']         = wp_kses_post(        (string) ( $_POST['bio']         ?? '' ) );
        $elders[ $idx ]['ig_url']      = esc_url_raw(         (string) ( $_POST['ig_url']      ?? '' ) );
        $elders[ $idx ]['fb_url']      = esc_url_raw(         (string) ( $_POST['fb_url']      ?? '' ) );
        $elders[ $idx ]['yt_url']      = esc_url_raw(         (string) ( $_POST['yt_url']      ?? '' ) );
        $elders[ $idx ]['tw_url']      = esc_url_raw(         (string) ( $_POST['tw_url']      ?? '' ) );
        $elders[ $idx ]['website_url'] = esc_url_raw(         (string) ( $_POST['website_url'] ?? '' ) );
        $elders[ $idx ]['archive_url'] = esc_url_raw(         (string) ( $_POST['archive_url'] ?? '' ) );

        // Only overwrite avatar_id when a non-empty URL is supplied.
        $avatarUrl = trim( (string) ( $_POST['avatar_url'] ?? '' ) );
        if ( $avatarUrl !== '' ) {
            $elders[ $idx ]['avatar_id'] = esc_url_raw( $avatarUrl );
        } elseif ( isset( $_POST['clear_avatar'] ) && $_POST['clear_avatar'] === '1' ) {
            $elders[ $idx ]['avatar_id'] = 0;
        }

        update_option( self::OPT_ELDERS, $elders );
        self::syncElderPages();

        $bioSlug = 'elder-' . sanitize_title( $elders[ $idx ]['name'] );
        wp_send_json_success( [
            'name'    => $elders[ $idx ]['name'],
            'bio_url' => home_url( '/' . $bioSlug . '/' ),
        ] );
    }

    /**
     * Render the inline edit modal (CSS + HTML + JS) once per page.
     * Called from the membership-guide template and the elder-bio template
     * — only emits anything for users with manage_options capability.
     *
     * Each "edit" trigger on the page just needs class="lgms-elder-edit-btn"
     * and data-index="N" — the JS reads the index, fetches the elder data
     * from the embedded JSON, and populates the modal.
     */
    public static function renderEditModal( array $elders ): void
    {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Per-elder payload. Includes admin-stored overrides (raw values that
        // populate the form) and BB-resolved values (used as placeholders so
        // the admin can see what's coming from BuddyBoss when their override
        // is blank).
        $payload = [];
        foreach ( $elders as $i => $e ) {
            $links = self::getElderLinks( $e );
            $payload[ $i ] = [
                'name'        => (string) ( $e['name']        ?? '' ),
                'speciality'  => (string) ( $e['speciality']  ?? '' ),
                'bio'         => (string) ( $e['bio']         ?? '' ),
                // Admin override values (what's in the field).
                'ig_url'      => (string) ( $e['ig_url']      ?? '' ),
                'fb_url'      => (string) ( $e['fb_url']      ?? '' ),
                'yt_url'      => (string) ( $e['yt_url']      ?? '' ),
                'tw_url'      => (string) ( $e['tw_url']      ?? '' ),
                'website_url' => (string) ( $e['website_url'] ?? '' ),
                'archive_url' => (string) ( $e['archive_url'] ?? '' ),
                'avatar'      => self::getElderAvatar( $e, 'full' ),
                // BB-effective values (used as placeholders to hint the admin).
                'bb' => [
                    'instagram' => $links['instagram'] ?? '',
                    'facebook'  => $links['facebook']  ?? '',
                    'youtube'   => $links['youtube']   ?? '',
                    'twitter'   => $links['twitter']   ?? '',
                    'website'   => $links['website']   ?? '',
                ],
            ];
        }
        $nonce  = wp_create_nonce( 'lgms_elder_edit' );
        $ajax   = admin_url( 'admin-ajax.php' );
        ?>
        <style>
        .lgms-elder-edit-btn { position:absolute; top:6px; right:6px; width:24px; height:24px; border-radius:50%; background:rgba(43,35,24,0.85); color:#ECB351; border:none; cursor:pointer; font-size:13px; line-height:24px; padding:0; display:flex; align-items:center; justify-content:center; opacity:0.55; transition:opacity 0.15s, transform 0.15s; z-index:5; }
        .lgms-elder-edit-btn:hover { opacity:1; transform:scale(1.1); }
        .lgms-mg .elder { position:relative; }   /* anchor for ?:btn */
        .lgms-eed { position:fixed; inset:0; background:rgba(20,15,10,0.85); display:none; align-items:center; justify-content:center; z-index:99999; padding:24px; }
        .lgms-eed.open { display:flex; }
        .lgms-eed-card { width:min(620px,96vw); max-height:92vh; overflow:auto; background:#FAF6EE; border-radius:10px; padding:24px 28px; box-shadow:0 12px 40px rgba(0,0,0,0.5); font-family:Arial,sans-serif; color:#2B2318; }
        .lgms-eed-card h3 { font-family:Georgia,serif; font-size:22px; margin:0 0 16px; color:#2B2318; }
        .lgms-eed-card label { display:block; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#5C4E3A; margin:14px 0 4px; }
        .lgms-eed-card input[type=text],
        .lgms-eed-card input[type=url],
        .lgms-eed-card textarea { width:100%; padding:8px 10px; border:1px solid #d6cfc1; border-radius:5px; font-size:14px; font-family:inherit; background:#fff; color:#2B2318; box-sizing:border-box; }
        .lgms-eed-card textarea { min-height:140px; resize:vertical; line-height:1.5; }
        .lgms-eed-card .row { display:flex; gap:12px; margin-top:18px; align-items:center; }
        .lgms-eed-card .row .preview { width:48px; height:48px; border-radius:50%; background:#EAE5DC center/cover no-repeat; flex-shrink:0; border:1px solid #d6cfc1; }
        .lgms-eed-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:22px; padding-top:16px; border-top:1px solid #EAE5DC; }
        .lgms-eed-actions button { padding:8px 18px; border-radius:6px; border:none; cursor:pointer; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; }
        .lgms-eed-actions .cancel { background:transparent; color:#5C4E3A; }
        .lgms-eed-actions .save   { background:#ECB351; color:#2B2318; }
        .lgms-eed-actions .save:disabled { opacity:0.5; cursor:wait; }
        .lgms-eed-status { font-size:12px; color:#888; flex:1; align-self:center; }
        .lgms-eed-status.error { color:#dc2626; }
        </style>

        <div class="lgms-eed" id="lgms-eed">
          <div class="lgms-eed-card" role="dialog" aria-modal="true">
            <h3>Edit elder profile</h3>
            <input type="hidden" id="lgms-eed-index" value="">
            <label>Display name</label>
            <input type="text" id="lgms-eed-name">
            <label>Speciality / tagline <span style="font-weight:400;text-transform:none;color:#aaa;">(shown under the name)</span></label>
            <input type="text" id="lgms-eed-spec" placeholder="Master maker &amp; senior mentor">
            <label>Bio <span style="font-weight:400;text-transform:none;color:#aaa;">(HTML allowed: &lt;a&gt;, &lt;em&gt;, &lt;strong&gt;)</span></label>
            <textarea id="lgms-eed-bio"></textarea>
            <p style="margin:18px 0 0;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#888;font-weight:700;">Social &amp; web — admin entry overrides BuddyBoss</p>
            <label>Website</label>
            <input type="url" id="lgms-eed-web">
            <label>Instagram</label>
            <input type="url" id="lgms-eed-ig">
            <label>Facebook</label>
            <input type="url" id="lgms-eed-fb">
            <label>YouTube</label>
            <input type="url" id="lgms-eed-yt">
            <label>X / Twitter</label>
            <input type="url" id="lgms-eed-tw">
            <label>Archive filter URL</label>
            <input type="url" id="lgms-eed-archive">
            <label>Avatar override URL <span style="font-weight:400;text-transform:none;color:#aaa;">(leave blank to use BuddyBoss avatar)</span></label>
            <div class="row">
                <span class="preview" id="lgms-eed-preview"></span>
                <input type="url" id="lgms-eed-avatar" placeholder="https://...">
            </div>
            <div class="lgms-eed-actions">
              <span class="lgms-eed-status" id="lgms-eed-status"></span>
              <button type="button" class="cancel" id="lgms-eed-cancel">Cancel</button>
              <button type="button" class="save"   id="lgms-eed-save">Save</button>
            </div>
          </div>
        </div>

        <script>
        (function(){
            var data    = <?php echo wp_json_encode( $payload ); ?>;
            var ajax    = <?php echo wp_json_encode( $ajax ); ?>;
            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

            var modal   = document.getElementById('lgms-eed');
            var $       = function(id){ return document.getElementById(id); };
            var fIndex  = $('lgms-eed-index');
            var fName   = $('lgms-eed-name');
            var fSpec   = $('lgms-eed-spec');
            var fBio    = $('lgms-eed-bio');
            var fWeb    = $('lgms-eed-web');
            var fIg     = $('lgms-eed-ig');
            var fFb     = $('lgms-eed-fb');
            var fYt     = $('lgms-eed-yt');
            var fTw     = $('lgms-eed-tw');
            var fArc    = $('lgms-eed-archive');
            var fAv     = $('lgms-eed-avatar');
            var fPrev   = $('lgms-eed-preview');
            var status  = $('lgms-eed-status');
            var btnSave = $('lgms-eed-save');

            // For each social field, show the admin-stored override as the value
            // and the BB-resolved value as a placeholder hint. If both are empty,
            // show "https://..." as the placeholder.
            function setField(input, value, bbHint) {
                input.value = value || '';
                input.placeholder = bbHint ? ('BuddyBoss: ' + bbHint) : 'https://...';
            }

            function open(idx) {
                var d = data[idx]; if (!d) return;
                var bb = d.bb || {};
                fIndex.value = idx;
                fName.value  = d.name;
                fSpec.value  = d.speciality || '';
                fBio.value   = d.bio;
                setField(fWeb, d.website_url, bb.website);
                setField(fIg,  d.ig_url,      bb.instagram);
                setField(fFb,  d.fb_url,      bb.facebook);
                setField(fYt,  d.yt_url,      bb.youtube);
                setField(fTw,  d.tw_url,      bb.twitter);
                fArc.value   = d.archive_url;
                fAv.value    = '';
                fPrev.style.backgroundImage = d.avatar ? "url('"+d.avatar+"')" : '';
                status.textContent = '';
                status.className = 'lgms-eed-status';
                btnSave.disabled = false;
                modal.classList.add('open');
            }
            function close(){ modal.classList.remove('open'); }

            // Wire the trigger buttons (cards on the guide, button on bio page).
            document.querySelectorAll('.lgms-elder-edit-btn').forEach(function(btn){
                btn.addEventListener('click', function(e){
                    e.preventDefault(); e.stopPropagation();
                    open(parseInt(btn.getAttribute('data-index'), 10));
                });
            });

            // Live preview on avatar URL change.
            fAv.addEventListener('input', function(){
                if (fAv.value) fPrev.style.backgroundImage = "url('"+fAv.value.replace(/'/g,"\\'")+"')";
            });

            $('lgms-eed-cancel').addEventListener('click', close);
            modal.addEventListener('click', function(e){ if (e.target === modal) close(); });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && modal.classList.contains('open')) close();
            });

            btnSave.addEventListener('click', function(){
                btnSave.disabled = true;
                status.textContent = 'Saving…';
                status.className = 'lgms-eed-status';
                var body = new URLSearchParams();
                body.append('action', 'lgms_save_elder');
                body.append('nonce', nonce);
                body.append('index',       fIndex.value);
                body.append('name',        fName.value);
                body.append('speciality',  fSpec.value);
                body.append('bio',         fBio.value);
                body.append('website_url', fWeb.value);
                body.append('ig_url',      fIg.value);
                body.append('fb_url',      fFb.value);
                body.append('yt_url',      fYt.value);
                body.append('tw_url',      fTw.value);
                body.append('archive_url', fArc.value);
                body.append('avatar_url',  fAv.value);
                fetch(ajax, { method:'POST', credentials:'same-origin', body:body })
                  .then(function(r){ return r.json(); })
                  .then(function(j){
                      if (j && j.success) {
                          status.textContent = 'Saved. Reloading…';
                          setTimeout(function(){ location.reload(); }, 400);
                      } else {
                          status.textContent = (j && j.data && j.data.message) ? j.data.message : 'Save failed.';
                          status.className = 'lgms-eed-status error';
                          btnSave.disabled = false;
                      }
                  })
                  .catch(function(err){
                      status.textContent = 'Network error: ' + err;
                      status.className = 'lgms-eed-status error';
                      btnSave.disabled = false;
                  });
            });
        })();
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Notes download
     * ----------------------------------------------------------------*/

    public static function handleDownloadNotes(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_guide_notes' );

        $path = LGMS_PLUGIN_DIR . 'docs/membership-guide-build-notes.md';
        if ( ! file_exists( $path ) ) {
            wp_die( 'Build notes file missing on disk.' );
        }
        header( 'Content-Type: text/markdown; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="membership-guide-build-notes.md"' );
        header( 'Content-Length: ' . filesize( $path ) );
        readfile( $path );
        exit;
    }
}
