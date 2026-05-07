<?php

declare(strict_types=1);

namespace LGMS;

final class Admin
{
    private const OPT_GROUP = 'lgms_settings';
    private const OPT_PAGE  = 'lg-member-sync';

    public static function boot(): void
    {
        add_action( 'admin_menu',  [ self::class, 'menu' ] );
        add_action( 'admin_init',  [ self::class, 'registerSettings' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueScripts' ] );
        add_action( 'admin_post_lgms_rerun_pages',       [ self::class, 'handleRerunPages' ] );
        add_action( 'admin_post_lgms_save_welcome_mosaic', [ self::class, 'handleSaveMosaic' ] );
        add_action( 'admin_post_lgms_create_affiliate',          [ self::class, 'handleCreateAffiliate' ] );
        add_action( 'admin_post_lgms_update_affiliate_commission', [ self::class, 'handleUpdateAffiliateCommission' ] );
        add_action( 'wp_ajax_lgms_search_posts', [ self::class, 'ajaxSearchPosts' ] );
        add_action( 'wp_ajax_lgms_search_users', [ self::class, 'ajaxSearchUsers' ] );
        add_action( 'admin_post_lgms_create_affiliate_user', [ self::class, 'handleCreateAffiliateUser' ] );
    }

    public static function menu(): void
    {
        add_options_page(
            'LG Member Sync',
            'LG Member Sync',
            'manage_options',
            self::OPT_PAGE,
            [ self::class, 'render' ],
        );
    }

    public static function registerSettings(): void
    {
        $fields = [
            'lgms_db_host'                    => '127.0.0.1',
            'lgms_db_port'                    => '3306',
            'lgms_db_name'                    => 'lg_membership',
            'lgms_db_user'                    => 'lg_membership',
            'lgms_db_pass'                    => '',
            'lgms_stripe_secret_key'          => '',
            'lgms_shared_secret'              => '',
            'lgms_refund_email'               => '',
            'lgms_refund_window_days'         => '30',
            'lgms_plan_switch_cooldown_hours' => '24',
        ];
        foreach ( $fields as $key => $_default ) {
            register_setting( self::OPT_GROUP, $key, [
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        }
    }

    public static function enqueueScripts( string $hook ): void
    {
        if ( $hook !== 'settings_page_' . self::OPT_PAGE ) {
            return;
        }
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'settings';
        if ( $tab !== 'welcome_email' ) {
            return;
        }
        wp_enqueue_media();
    }

    // -------------------------------------------------------------------------
    // admin-post handlers
    // -------------------------------------------------------------------------

    public static function handleRerunPages(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_rerun_pages' );

        $result = Wp\Pages::ensureAll();
        $msg = sprintf(
            'created=%d skipped=%d allowlisted=%d',
            count( $result['created'] ),
            count( $result['skipped'] ),
            count( $result['allowlisted'] )
        );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::OPT_PAGE, 'lgms_pages' => rawurlencode( $msg ) ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public static function handleSaveMosaic(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_save_welcome_mosaic' );

        $raw = isset( $_POST['mosaic_ids'] ) && is_array( $_POST['mosaic_ids'] )
            ? array_map( 'absint', $_POST['mosaic_ids'] )
            : [];

        $ids = array_values( array_filter( $raw ) );
        update_option( 'lgms_welcome_mosaic_ids', wp_json_encode( $ids ) );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::OPT_PAGE, 'tab' => 'welcome_email', 'lgms_mosaic_saved' => '1' ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // AJAX: post search for mosaic picker
    // -------------------------------------------------------------------------

    public static function ajaxSearchPosts(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'forbidden', 403 );
        }
        check_ajax_referer( 'lgms_mosaic_search' );

        $q = sanitize_text_field( (string) ( $_GET['q'] ?? '' ) );
        if ( strlen( $q ) < 2 ) {
            wp_send_json_success( [] );
        }

        $results = get_posts( [
            'post_type'      => [ 'post-type-videos', 'post-imgcap', 'post-regular', 'loothprint' ],
            'post_status'    => 'publish',
            's'              => $q,
            'posts_per_page' => 12,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ] );

        $out = [];
        foreach ( $results as $id ) {
            $thumb = get_the_post_thumbnail_url( $id, 'medium' );
            $out[] = [
                'id'    => $id,
                'title' => get_the_title( $id ),
                'thumb' => $thumb ?: '',
            ];
        }

        wp_send_json_success( $out );
    }

    public static function ajaxSearchUsers(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'forbidden', 403 );
        }
        check_ajax_referer( 'lgms_user_search' );

        $q = sanitize_text_field( (string) ( $_GET['q'] ?? '' ) );
        if ( strlen( $q ) < 2 ) {
            wp_send_json_success( [] );
        }

        $users = get_users( [
            'search'         => '*' . $q . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
            'number'         => 12,
            'fields'         => [ 'ID', 'user_login', 'user_email', 'display_name' ],
        ] );

        $out = [];
        foreach ( $users as $u ) {
            $out[] = [
                'id'      => $u->ID,
                'name'    => $u->display_name ?: $u->user_login,
                'email'   => $u->user_email,
                'login'   => $u->user_login,
                'avatar'  => get_avatar_url( $u->ID, [ 'size' => 32 ] ),
                'roles'   => implode( ', ', (array) ( get_userdata( $u->ID )->roles ?? [] ) ),
            ];
        }

        wp_send_json_success( $out );
    }

    public static function handleCreateAffiliateUser(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_create_affiliate_user' );

        $affId    = (int)    ( $_POST['affiliate_id'] ?? 0 );
        $name     = sanitize_text_field( (string) ( $_POST['new_user_name']  ?? '' ) );
        $email    = sanitize_email( (string)         ( $_POST['new_user_email'] ?? '' ) );
        $role     = sanitize_text_field( (string) ( $_POST['new_user_role']  ?? 'subscriber' ) );

        $err = '';
        $notice = '';

        if ( $affId <= 0 || $email === '' || $name === '' ) {
            $err = 'Name, email, and affiliate are all required.';
        } elseif ( email_exists( $email ) ) {
            $err = "A user with email {$email} already exists. Use the search field to link them instead.";
        } else {
            $userId = wp_create_user(
                sanitize_user( strtolower( str_replace( ' ', '.', $name ) ) ),
                wp_generate_password( 24, true, true ),
                $email
            );
            if ( is_wp_error( $userId ) ) {
                $err = $userId->get_error_message();
            } else {
                $u = get_user_by( 'id', $userId );
                $u->set_role( $role );
                wp_update_user( [ 'ID' => $userId, 'display_name' => $name, 'first_name' => explode( ' ', $name )[0] ] );
                wp_send_new_user_notifications( $userId, 'user' );
                // Link to affiliate.
                Db::pdo()->prepare( 'UPDATE affiliates SET wp_user_id = ? WHERE id = ?' )
                    ->execute( [ $userId, $affId ] );
                $notice = "Created WP user for {$name} ({$email}) and linked to affiliate.";
            }
        }

        $args = [ 'page' => self::OPT_PAGE, 'tab' => 'affiliates' ];
        if ( $notice !== '' ) $args['lgms_aff_ok']  = rawurlencode( $notice );
        if ( $err    !== '' ) $args['lgms_aff_err'] = rawurlencode( $err );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public static function render(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'settings';
        $tabs = [
            'settings'      => 'Settings',
            'member_tools'  => 'Member Tools',
            'welcome_email' => 'Welcome Email',
            'affiliates'    => 'Affiliates',
        ];
        ?>
        <div class="wrap">
            <h1>LG Member Sync</h1>

            <nav class="nav-tab-wrapper" style="margin-bottom:1.5em;">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::OPT_PAGE, 'tab' => $slug ], admin_url( 'options-general.php' ) ) ); ?>"
                       class="nav-tab<?php echo $tab === $slug ? ' nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            match ( $tab ) {
                'member_tools'  => MemberTools::renderContent(),
                'welcome_email' => self::renderWelcomeEmailTab(),
                'affiliates'    => self::renderAffiliatesTab(),
                default         => self::renderSettingsTab(),
            };
            ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Settings tab
    // -------------------------------------------------------------------------

    private static function renderSettingsTab(): void
    {
        $probe = '<em>not tested</em>';
        try {
            $pdo   = Db::pdo();
            $row   = $pdo->query( 'SELECT VERSION() AS v' )->fetch();
            $probe = sprintf( '✓ connected (MySQL %s)', esc_html( (string) ( $row['v'] ?? '?' ) ) );
        } catch ( \Throwable $e ) {
            $probe = '✗ ' . esc_html( $e->getMessage() );
        }

        $nextRun        = wp_next_scheduled( Plugin::CRON_HOOK );
        $nextRunDisplay = $nextRun ? gmdate( 'c', $nextRun ) . ' UTC' : '<em>not scheduled</em>';
        $pagesNotice    = isset( $_GET['lgms_pages'] ) ? rawurldecode( (string) $_GET['lgms_pages'] ) : '';
        ?>

        <h2>DB connection</h2>
        <p><strong>Probe:</strong> <?php echo $probe; ?></p>
        <p><strong>Cron next run:</strong> <?php echo $nextRunDisplay; ?></p>

        <h2>Membership pages</h2>
        <p class="description">
            Auto-creates the WP pages hosting <code>[lg_join]</code>, <code>[lg_gift]</code>, <code>[lg_redeem_gift]</code>, <code>[lg_manage_subscription]</code>, <code>[lg_refund_request]</code>, <code>[lg_regional_fail]</code>, and <code>[lg_subscription_success]</code>, and adds public-facing slugs to the BuddyBoss allowlist. Runs automatically on plugin activation; click below if you've edited the page registry and want to re-sync without deactivate/reactivate.
        </p>
        <?php if ( $pagesNotice !== '' ) : ?>
            <div class="notice notice-success is-dismissible"><p>Pages re-synced: <code><?php echo esc_html( $pagesNotice ); ?></code></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'lgms_rerun_pages' ); ?>
            <input type="hidden" name="action" value="lgms_rerun_pages">
            <p><button type="submit" class="button">Re-create / sync membership pages</button></p>
        </form>

        <form method="post" action="options.php">
            <?php settings_fields( self::OPT_GROUP ); ?>

            <h2>DB connection</h2>
            <table class="form-table">
                <tr><th><label>Host</label></th><td><input type="text" name="lgms_db_host" value="<?php echo esc_attr( get_option( 'lgms_db_host', '127.0.0.1' ) ); ?>" class="regular-text"></td></tr>
                <tr><th><label>Port</label></th><td><input type="text" name="lgms_db_port" value="<?php echo esc_attr( get_option( 'lgms_db_port', '3306' ) ); ?>" class="small-text"></td></tr>
                <tr><th><label>Database</label></th><td><input type="text" name="lgms_db_name" value="<?php echo esc_attr( get_option( 'lgms_db_name', 'lg_membership' ) ); ?>" class="regular-text"></td></tr>
                <tr><th><label>User</label></th><td><input type="text" name="lgms_db_user" value="<?php echo esc_attr( get_option( 'lgms_db_user', 'lg_membership' ) ); ?>" class="regular-text"></td></tr>
                <tr><th><label>Password</label></th><td><input type="password" name="lgms_db_pass" value="<?php echo esc_attr( get_option( 'lgms_db_pass', '' ) ); ?>" class="regular-text" autocomplete="off"></td></tr>
            </table>

            <h2>Stripe</h2>
            <table class="form-table">
                <tr><th><label>Secret key</label></th><td><input type="password" name="lgms_stripe_secret_key" value="<?php echo esc_attr( get_option( 'lgms_stripe_secret_key', '' ) ); ?>" class="regular-text" autocomplete="off" placeholder="sk_test_... or sk_live_..."></td></tr>
            </table>

            <h2>Refund requests</h2>
            <p class="description">Settings for the <code>[lg_refund_request]</code> form.</p>
            <table class="form-table">
                <tr><th><label>Refund email</label></th><td><input type="email" name="lgms_refund_email" value="<?php echo esc_attr( get_option( 'lgms_refund_email', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"> <span class="description">Leave blank to use the WordPress admin email.</span></td></tr>
                <tr><th><label>Refund window (days)</label></th><td><input type="number" name="lgms_refund_window_days" value="<?php echo esc_attr( get_option( 'lgms_refund_window_days', '30' ) ); ?>" class="small-text" min="1" max="365"> <span class="description">Number of days after a charge that a customer is eligible for an automated refund.</span></td></tr>
                <tr><th><label>Plan-switch cooldown (hours)</label></th><td><input type="number" name="lgms_plan_switch_cooldown_hours" value="<?php echo esc_attr( get_option( 'lgms_plan_switch_cooldown_hours', '24' ) ); ?>" class="small-text" min="0" max="720"> <span class="description">Minimum hours between customer-initiated plan changes. Set to 0 to disable.</span></td></tr>
            </table>

            <h2>Slim ↔ plugin shared secret</h2>
            <p class="description">Used to authenticate Slim's calls to <code>/wp-json/lg-member-sync/v1/sync-customer</code>. Set the same value on Slim's <code>LGMS_SHARED_SECRET</code> in <code>.env</code>.</p>
            <table class="form-table">
                <tr><th><label>Shared secret</label></th><td><input type="password" name="lgms_shared_secret" value="<?php echo esc_attr( get_option( 'lgms_shared_secret', '' ) ); ?>" class="regular-text" autocomplete="off"></td></tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Welcome Email tab
    // -------------------------------------------------------------------------

    private static function renderWelcomeEmailTab(): void
    {
        if ( isset( $_GET['lgms_mosaic_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Mosaic images saved.</p></div>
        <?php endif;

        $saved = json_decode( (string) get_option( 'lgms_welcome_mosaic_ids', '[]' ), true );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        $saved = array_pad( array_values( $saved ), 6, 0 );
        ?>

        <p class="description" style="margin-bottom:1.5em;">
            Choose up to 6 posts (videos, articles, loothprints) whose featured images appear in the welcome email mosaic.
            Search by title — the image preview updates as you pick.
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'lgms_save_welcome_mosaic' ); ?>
            <input type="hidden" name="action" value="lgms_save_welcome_mosaic">

            <div id="lgms-mosaic-slots" style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;max-width:760px;margin-bottom:2em;">
                <?php for ( $i = 0; $i < 6; $i++ ) :
                    $postId = (int) ( $saved[ $i ] ?? 0 );
                    $title  = $postId ? get_the_title( $postId ) : '';
                    $thumb  = $postId ? ( get_the_post_thumbnail_url( $postId, 'medium' ) ?: '' ) : '';
                    ?>
                    <div class="lgms-slot" style="border:1px solid #ddd;border-radius:4px;padding:12px;background:#fff;">
                        <p style="margin:0 0 6px;font-weight:600;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.05em;">Slot <?php echo $i + 1; ?></p>

                        <div class="lgms-thumb-wrap" style="height:90px;background:#f0f0f0;border-radius:3px;margin-bottom:8px;overflow:hidden;display:flex;align-items:center;justify-content:center;">
                            <?php if ( $thumb ) : ?>
                                <img src="<?php echo esc_url( $thumb ); ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
                            <?php else : ?>
                                <span style="color:#aaa;font-size:12px;">No image</span>
                            <?php endif; ?>
                        </div>

                        <input type="hidden" name="mosaic_ids[]" class="lgms-post-id" value="<?php echo esc_attr( (string) $postId ); ?>">

                        <div style="position:relative;">
                            <input type="text"
                                   class="lgms-search-input widefat"
                                   placeholder="Search…"
                                   value="<?php echo esc_attr( $title ); ?>"
                                   autocomplete="off"
                                   style="margin-bottom:4px;">
                            <div class="lgms-results" style="display:none;position:absolute;z-index:999;left:0;right:0;background:#fff;border:1px solid #ddd;border-radius:3px;max-height:180px;overflow-y:auto;box-shadow:0 3px 8px rgba(0,0,0,.12);"></div>
                        </div>

                        <button type="button" class="lgms-clear button button-small" style="margin-top:4px;width:100%;">Clear</button>
                    </div>
                <?php endfor; ?>
            </div>

            <?php submit_button( 'Save mosaic' ); ?>
        </form>

        <script>
        (function () {
            var nonce = <?php echo wp_json_encode( wp_create_nonce( 'lgms_mosaic_search' ) ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

            document.querySelectorAll('.lgms-slot').forEach(function (slot) {
                var input   = slot.querySelector('.lgms-search-input');
                var results = slot.querySelector('.lgms-results');
                var idField = slot.querySelector('.lgms-post-id');
                var thumb   = slot.querySelector('.lgms-thumb-wrap');
                var clear   = slot.querySelector('.lgms-clear');
                var timer   = null;

                function setPost(id, title, thumbUrl) {
                    idField.value = id;
                    input.value   = title;
                    results.style.display = 'none';
                    results.innerHTML = '';
                    thumb.innerHTML = thumbUrl
                        ? '<img src="' + thumbUrl + '" style="width:100%;height:100%;object-fit:cover;" alt="">'
                        : '<span style="color:#aaa;font-size:12px;">No image</span>';
                }

                input.addEventListener('input', function () {
                    clearTimeout(timer);
                    var q = input.value.trim();
                    if (q.length < 2) { results.style.display = 'none'; return; }
                    timer = setTimeout(function () {
                        var url = ajaxUrl + '?action=lgms_search_posts&_ajax_nonce=' + nonce + '&q=' + encodeURIComponent(q);
                        fetch(url)
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                results.innerHTML = '';
                                if (!data.success || !data.data.length) {
                                    results.style.display = 'none';
                                    return;
                                }
                                data.data.forEach(function (post) {
                                    var li = document.createElement('div');
                                    li.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 8px;cursor:pointer;border-bottom:1px solid #f0f0f0;';
                                    li.innerHTML = (post.thumb
                                        ? '<img src="' + post.thumb + '" style="width:36px;height:36px;object-fit:cover;border-radius:2px;flex-shrink:0;" alt="">'
                                        : '<div style="width:36px;height:36px;background:#eee;border-radius:2px;flex-shrink:0;"></div>')
                                        + '<span style="font-size:13px;line-height:1.3;">' + post.title + '</span>';
                                    li.addEventListener('mousedown', function (e) {
                                        e.preventDefault();
                                        setPost(post.id, post.title, post.thumb);
                                    });
                                    results.appendChild(li);
                                });
                                results.style.display = 'block';
                            });
                    }, 300);
                });

                input.addEventListener('blur', function () {
                    setTimeout(function () { results.style.display = 'none'; }, 150);
                });

                clear.addEventListener('click', function () {
                    setPost(0, '', '');
                    thumb.innerHTML = '<span style="color:#aaa;font-size:12px;">No image</span>';
                });
            });
        }());
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Affiliates tab
    // -------------------------------------------------------------------------

    public static function handleCreateAffiliate(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_create_affiliate' );

        $slug      = sanitize_text_field( (string) ( $_POST['slug']      ?? '' ) );
        $label     = sanitize_text_field( (string) ( $_POST['label']     ?? '' ) );
        $wpUserRef = sanitize_text_field( (string) ( $_POST['wp_user']   ?? '' ) );

        // Allow letters, digits, hyphens only.
        $slug = strtolower( (string) preg_replace( '/[^a-z0-9-]/i', '-', $slug ) );
        $slug = trim( $slug, '-' );

        // Resolve wp_user field: accepts numeric ID or email/login.
        $wpUserId = null;
        if ( $wpUserRef !== '' ) {
            if ( is_numeric( $wpUserRef ) ) {
                $wpUserId = (int) $wpUserRef;
            } else {
                $u = get_user_by( 'email', $wpUserRef ) ?: get_user_by( 'login', $wpUserRef );
                $wpUserId = $u ? $u->ID : null;
            }
        }

        $notice = '';
        $err    = '';

        if ( $slug === '' ) {
            $err = 'Slug is required.';
        } else {
            try {
                $pdo = Db::pdo();
                $pdo->prepare( 'INSERT INTO affiliates (slug, label, wp_user_id) VALUES (?, ?, ?)' )
                    ->execute( [ $slug, $label !== '' ? $label : $slug, $wpUserId ] );
                $notice = "Created affiliate: {$slug}";
            } catch ( \Throwable $e ) {
                if ( str_contains( $e->getMessage(), 'Duplicate' ) ) {
                    $err = "Slug '{$slug}' already exists.";
                } else {
                    $err = $e->getMessage();
                }
            }
        }

        $args = [ 'page' => self::OPT_PAGE, 'tab' => 'affiliates' ];
        if ( $notice !== '' ) $args['lgms_aff_ok']  = rawurlencode( $notice );
        if ( $err    !== '' ) $args['lgms_aff_err'] = rawurlencode( $err );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
        exit;
    }

    public static function handleUpdateAffiliateCommission(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_update_affiliate_commission' );

        $id         = (int) ( $_POST['affiliate_id'] ?? 0 );
        $pct        = (float) ( $_POST['commission_pct']         ?? 0 );
        $pctAnn     = (float) ( $_POST['commission_pct_annual']  ?? 0 );
        $bonus      = (float) ( $_POST['retention_bonus_pct']    ?? 0 );
        $wpUserRef  = sanitize_text_field( (string) ( $_POST['wp_user'] ?? '' ) );

        $wpUserId = null;
        if ( $wpUserRef !== '' ) {
            if ( is_numeric( $wpUserRef ) ) {
                $wpUserId = (int) $wpUserRef;
            } else {
                $u = get_user_by( 'email', $wpUserRef ) ?: get_user_by( 'login', $wpUserRef );
                $wpUserId = $u ? $u->ID : null;
            }
        }

        $notice = '';
        $err    = '';

        if ( $id <= 0 ) {
            $err = 'Invalid affiliate.';
        } else {
            try {
                Db::pdo()->prepare(
                    'UPDATE affiliates SET commission_pct = ?, commission_pct_annual = ?, retention_bonus_pct = ?, wp_user_id = ? WHERE id = ?'
                )->execute( [ $pct, $pctAnn, $bonus, $wpUserId, $id ] );
                $notice = 'Commission rates updated.';
            } catch ( \Throwable $e ) {
                $err = $e->getMessage();
            }
        }

        $args = [ 'page' => self::OPT_PAGE, 'tab' => 'affiliates' ];
        if ( $notice !== '' ) $args['lgms_aff_ok']  = rawurlencode( $notice );
        if ( $err    !== '' ) $args['lgms_aff_err'] = rawurlencode( $err );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
        exit;
    }

    /**
     * Renders a user search + "create new user" widget.
     * $linkedUser = currently linked WP_User object or null.
     * $affId      = affiliate ID (0 when creating a new affiliate).
     */
    private static function renderUserSearchField( string $fieldName, ?\WP_User $linkedUser, string $nonce, int $affId ): void
    {
        $uid   = $linkedUser ? (int) $linkedUser->ID : 0;
        $uName = $linkedUser ? esc_html( $linkedUser->display_name ?: $linkedUser->user_login ) : '';
        $uEmail= $linkedUser ? esc_html( $linkedUser->user_email ) : '';
        $uAv   = $linkedUser ? esc_url( get_avatar_url( $uid, [ 'size' => 32 ] ) ) : '';
        $roles = wp_roles()->get_names();
        $uid_field = esc_attr( $fieldName );
        ?>
        <div class="lgms-user-search" style="max-width:420px;">
            <input type="hidden" name="<?php echo $uid_field; ?>" id="lgms-us-val-<?php echo $uid_field; ?>"
                   value="<?php echo $uid > 0 ? $uid : ''; ?>">

            <?php if ( $uid > 0 ) : ?>
            <div id="lgms-us-linked-<?php echo $uid_field; ?>"
                 style="display:flex;align-items:center;gap:.6em;padding:.5em;background:#f0f6ff;border:1px solid #b8d0f0;border-radius:4px;margin-bottom:.5em;">
                <img src="<?php echo $uAv; ?>" width="32" height="32" style="border-radius:50%;">
                <span><strong><?php echo $uName; ?></strong><br><small style="color:#666;"><?php echo $uEmail; ?></small></span>
                <button type="button" onclick="lgmsUserSearchClear('<?php echo $uid_field; ?>')"
                        style="margin-left:auto;background:none;border:none;cursor:pointer;color:#dc2626;font-size:1.1em;" title="Unlink">✕</button>
            </div>
            <?php else : ?>
            <div id="lgms-us-linked-<?php echo $uid_field; ?>" style="display:none;"></div>
            <?php endif; ?>

            <div id="lgms-us-search-wrap-<?php echo $uid_field; ?>" <?php echo $uid > 0 ? 'style="display:none;"' : ''; ?>>
                <input type="text" id="lgms-us-q-<?php echo $uid_field; ?>"
                       placeholder="Search by name, email, or username…"
                       autocomplete="off"
                       style="width:100%;margin-bottom:.35em;"
                       oninput="lgmsUserSearch('<?php echo $uid_field; ?>', this.value)">
                <div id="lgms-us-results-<?php echo $uid_field; ?>"
                     style="border:1px solid #ddd;border-radius:4px;background:#fff;display:none;max-height:220px;overflow-y:auto;"></div>

                <details style="margin-top:.8em;">
                    <summary style="cursor:pointer;color:#2271b1;font-size:.9em;">Create new WP user instead</summary>
                    <?php if ( $affId > 0 ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          style="margin-top:.6em;padding:.8em;background:#fafafa;border:1px solid #eee;border-radius:4px;">
                        <?php wp_nonce_field( 'lgms_create_affiliate_user' ); ?>
                        <input type="hidden" name="action"       value="lgms_create_affiliate_user">
                        <input type="hidden" name="affiliate_id" value="<?php echo $affId; ?>">
                        <table style="border-collapse:collapse;width:100%;">
                            <tr>
                                <td style="padding:.3em .6em .3em 0;white-space:nowrap;"><label>Display name</label></td>
                                <td><input type="text" name="new_user_name" class="regular-text" required placeholder="Dan Smith"></td>
                            </tr>
                            <tr>
                                <td style="padding:.3em .6em .3em 0;white-space:nowrap;"><label>Email</label></td>
                                <td><input type="email" name="new_user_email" class="regular-text" required placeholder="dan@example.com"></td>
                            </tr>
                            <tr>
                                <td style="padding:.3em .6em .3em 0;white-space:nowrap;"><label>Role</label></td>
                                <td>
                                    <select name="new_user_role">
                                        <?php foreach ( $roles as $roleKey => $roleLabel ) : ?>
                                            <option value="<?php echo esc_attr( $roleKey ); ?>"
                                                <?php selected( $roleKey, 'subscriber' ); ?>>
                                                <?php echo esc_html( $roleLabel ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description" style="margin:.3em 0 0;">User will receive a password-setup email.</p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button( 'Create user & link', 'secondary', 'submit', false ); ?>
                    </form>
                    <?php else : ?>
                    <p class="description" style="margin-top:.4em;">Save the affiliate first, then use Edit rates to create a new user.</p>
                    <?php endif; ?>
                </details>
            </div>
        </div>

        <script>
        (function() {
            var timers = {};
            window.lgmsUserSearch = function(field, q) {
                clearTimeout(timers[field]);
                var res = document.getElementById('lgms-us-results-' + field);
                if (q.length < 2) { res.style.display = 'none'; return; }
                timers[field] = setTimeout(function() {
                    fetch(ajaxurl + '?action=lgms_search_users&q=' + encodeURIComponent(q) + '&_ajax_nonce=<?php echo esc_js( $nonce ); ?>')
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (!data.success || !data.data.length) {
                                res.innerHTML = '<div style="padding:.5em .8em;color:#888;font-size:.9em;">No users found</div>';
                                res.style.display = 'block'; return;
                            }
                            res.innerHTML = data.data.map(function(u) {
                                return '<div style="display:flex;align-items:center;gap:.6em;padding:.45em .7em;cursor:pointer;border-bottom:1px solid #f0f0f0;" ' +
                                    'onmousedown="lgmsUserPick(\'' + field + '\',' + u.id + ',\'' +
                                    u.name.replace(/'/g,"\\'") + '\',\'' + u.email.replace(/'/g,"\\'") + '\',\'' +
                                    u.avatar + '\',\'' + u.roles.replace(/'/g,"\\'") + '\')">' +
                                    '<img src="' + u.avatar + '" width="28" height="28" style="border-radius:50%;flex-shrink:0;">' +
                                    '<span><strong>' + u.name + '</strong> <span style="color:#888;font-size:.85em;">(' + u.roles + ')</span><br>' +
                                    '<small style="color:#666;">' + u.email + '</small></span></div>';
                            }).join('');
                            res.style.display = 'block';
                        });
                }, 280);
            };
            window.lgmsUserPick = function(field, id, name, email, avatar, roles) {
                document.getElementById('lgms-us-val-' + field).value = id;
                document.getElementById('lgms-us-linked-' + field).innerHTML =
                    '<div style="display:flex;align-items:center;gap:.6em;padding:.5em;background:#f0f6ff;border:1px solid #b8d0f0;border-radius:4px;">' +
                    '<img src="' + avatar + '" width="32" height="32" style="border-radius:50%;">' +
                    '<span><strong>' + name + '</strong> <span style="color:#888;font-size:.85em;">(' + roles + ')</span><br>' +
                    '<small style="color:#666;">' + email + '</small></span>' +
                    '<button type="button" onclick="lgmsUserSearchClear(\'' + field + '\')" ' +
                    'style="margin-left:auto;background:none;border:none;cursor:pointer;color:#dc2626;font-size:1.1em;" title="Unlink">✕</button></div>';
                document.getElementById('lgms-us-linked-' + field).style.display = 'block';
                document.getElementById('lgms-us-search-wrap-' + field).style.display = 'none';
                document.getElementById('lgms-us-results-' + field).style.display = 'none';
            };
            window.lgmsUserSearchClear = function(field) {
                document.getElementById('lgms-us-val-' + field).value = '';
                document.getElementById('lgms-us-linked-' + field).style.display = 'none';
                document.getElementById('lgms-us-search-wrap-' + field).style.display = 'block';
                document.getElementById('lgms-us-q-' + field).value = '';
                document.getElementById('lgms-us-results-' + field).style.display = 'none';
            };
        })();
        </script>
        <?php
    }

    private static function renderAffiliatesTab(): void
    {
        $notice  = isset( $_GET['lgms_aff_ok'] )  ? rawurldecode( (string) $_GET['lgms_aff_ok'] )  : '';
        $err     = isset( $_GET['lgms_aff_err'] ) ? rawurldecode( (string) $_GET['lgms_aff_err'] ) : '';
        $editId  = (int) ( $_GET['lgms_edit_aff'] ?? 0 );

        if ( $notice !== '' ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
        <?php endif;
        if ( $err !== '' ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div>
        <?php endif;

        $rows = [];
        try {
            $rows = Db::pdo()->query(
                'SELECT a.id, a.slug, a.label, a.created_at,
                        a.commission_pct, a.commission_pct_annual, a.retention_bonus_pct,
                        COUNT(DISTINCT cl.id)  AS clicks,
                        COUNT(DISTINCT cv.id)  AS conversions,
                        COUNT(DISTINCT CASE WHEN cv.retention_bonus_eligible_at IS NOT NULL THEN cv.id END) AS retention_eligible
                 FROM affiliates a
                 LEFT JOIN affiliate_clicks      cl ON cl.affiliate_id = a.id
                 LEFT JOIN affiliate_conversions cv ON cv.affiliate_id = a.id
                 GROUP BY a.id
                 ORDER BY a.created_at DESC'
            )->fetchAll( \PDO::FETCH_ASSOC );
        } catch ( \Throwable $_ ) {}

        $joinBase = home_url( '/lgjoin/' );
        ?>

        <h2 style="margin-top:0;">Affiliate links</h2>
        <p class="description">
            Conversions are tracked when a checkout session started on an affiliate link completes payment.
            Commission rates are informational — use the retention poller script to generate payout reports.
        </p>

        <?php if ( $rows !== [] ) : ?>
        <table class="widefat striped" style="margin-bottom:2em;">
            <thead>
                <tr>
                    <th>Slug / Label</th>
                    <th style="text-align:center;">Clicks</th>
                    <th style="text-align:center;">Conv.</th>
                    <th style="text-align:center;">Rate</th>
                    <th style="text-align:center;">Monthly&nbsp;%</th>
                    <th style="text-align:center;">Annual&nbsp;%</th>
                    <th style="text-align:center;">Retention&nbsp;bonus&nbsp;%</th>
                    <th style="text-align:center;">Retention<br>eligible</th>
                    <th style="text-align:center;">Refund<br>debits</th>
                    <th>Link</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $row ) :
                $link             = add_query_arg( 'ref', esc_attr( (string) $row['slug'] ), $joinBase );
                $clicks           = (int) $row['clicks'];
                $conversions      = (int) $row['conversions'];
                $retEligible      = (int) $row['retention_eligible'];
                $rate             = $clicks > 0 ? round( $conversions / $clicks * 100 ) . '%' : '—';
                $debitsCents      = (int) $row['total_debits_cents'];
                $editUrl          = add_query_arg( [
                    'page'          => self::OPT_PAGE,
                    'tab'           => 'affiliates',
                    'lgms_edit_aff' => $row['id'],
                ], admin_url( 'options-general.php' ) );
            ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( (string) $row['label'] ); ?></strong><br>
                        <code style="font-size:11px;"><?php echo esc_html( (string) $row['slug'] ); ?></code>
                    </td>
                    <td style="text-align:center;"><?php echo $clicks; ?></td>
                    <td style="text-align:center;font-weight:700;"><?php echo $conversions; ?></td>
                    <td style="text-align:center;color:<?php echo $clicks > 0 ? '#15803d' : '#aaa'; ?>;"><?php echo $rate; ?></td>
                    <td style="text-align:center;"><?php echo (float) $row['commission_pct'] > 0 ? esc_html( $row['commission_pct'] ) . '%' : '—'; ?></td>
                    <td style="text-align:center;"><?php echo (float) $row['commission_pct_annual'] > 0 ? esc_html( $row['commission_pct_annual'] ) . '%' : '—'; ?></td>
                    <td style="text-align:center;"><?php echo (float) $row['retention_bonus_pct'] > 0 ? esc_html( $row['retention_bonus_pct'] ) . '%' : '—'; ?></td>
                    <td style="text-align:center;<?php echo $retEligible > 0 ? 'font-weight:700;color:#b45309;' : 'color:#aaa;'; ?>">
                        <?php echo $retEligible > 0 ? $retEligible : '—'; ?>
                    </td>
                    <td style="text-align:center;<?php echo $debitsCents > 0 ? 'font-weight:700;color:#dc2626;' : 'color:#aaa;'; ?>">
                        <?php echo $debitsCents > 0 ? '$' . number_format( $debitsCents / 100, 2 ) : '—'; ?>
                    </td>
                    <td style="min-width:240px;">
                        <input type="text" value="<?php echo esc_attr( $link ); ?>"
                               readonly onclick="this.select()"
                               style="width:100%;font-size:11px;font-family:monospace;padding:3px 5px;border:1px solid #ddd;border-radius:3px;">
                    </td>
                    <td><a href="<?php echo esc_url( $editUrl ); ?>">Edit rates</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p><em>No affiliates yet. Create your first one below.</em></p>
        <?php endif; ?>

        <?php
        // ── Edit commission rates ────────────────────────────────────────────
        $editRow = null;
        if ( $editId > 0 ) {
            foreach ( $rows as $r ) {
                if ( (int) $r['id'] === $editId ) { $editRow = $r; break; }
            }
        }
        if ( $editRow !== null ) : ?>
        <h3>Edit commission rates — <?php echo esc_html( (string) $editRow['label'] ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:480px;">
            <?php wp_nonce_field( 'lgms_update_affiliate_commission' ); ?>
            <input type="hidden" name="action"       value="lgms_update_affiliate_commission">
            <input type="hidden" name="affiliate_id" value="<?php echo (int) $editRow['id']; ?>">
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="lgms-aff-pct">Monthly commission %</label></th>
                    <td>
                        <input type="number" id="lgms-aff-pct" name="commission_pct" step="0.01" min="0" max="100"
                               value="<?php echo esc_attr( (string) $editRow['commission_pct'] ); ?>" class="small-text">
                        <p class="description">Paid on monthly subscription conversions.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lgms-aff-pct-ann">Annual commission %</label></th>
                    <td>
                        <input type="number" id="lgms-aff-pct-ann" name="commission_pct_annual" step="0.01" min="0" max="100"
                               value="<?php echo esc_attr( (string) $editRow['commission_pct_annual'] ); ?>" class="small-text">
                        <p class="description">Paid on annual / one-time conversions. Set higher to incentivise yearly.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>WP User</label></th>
                    <td>
                        <?php
                        $linkedUser  = $editRow['wp_user_id'] ? get_user_by( 'id', (int) $editRow['wp_user_id'] ) : null;
                        $searchNonce = wp_create_nonce( 'lgms_user_search' );
                        ?>
                        <?php self::renderUserSearchField( 'wp_user', $linkedUser, $searchNonce, (int) $editRow['id'] ); ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="lgms-aff-bonus">1-year retention bonus %</label></th>
                    <td>
                        <input type="number" id="lgms-aff-bonus" name="retention_bonus_pct" step="0.01" min="0" max="100"
                               value="<?php echo esc_attr( (string) $editRow['retention_bonus_pct'] ); ?>" class="small-text">
                        <p class="description">% of the member's actual total Stripe invoices in their first year. Paid if still subscribed at the 1-year mark. Run <code>bin/poll-retention.php</code> to generate payout report.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save rates' ); ?>
        </form>
        <?php endif; ?>

        <h3>Create a new affiliate</h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:480px;">
            <?php wp_nonce_field( 'lgms_create_affiliate' ); ?>
            <input type="hidden" name="action" value="lgms_create_affiliate">
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="lgms-aff-slug">Slug</label></th>
                    <td>
                        <input type="text" id="lgms-aff-slug" name="slug" class="regular-text"
                               placeholder="dan" pattern="[a-zA-Z0-9\-]+" required>
                        <p class="description">Letters, digits, hyphens only. Link will be <code>/lgjoin/?ref=<em>slug</em></code></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lgms-aff-label">Label</label></th>
                    <td>
                        <input type="text" id="lgms-aff-label" name="label" class="regular-text"
                               placeholder="Dan">
                        <p class="description">Human-readable name. Defaults to the slug if blank.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>WP User</label></th>
                    <td>
                        <?php self::renderUserSearchField( 'wp_user', null, wp_create_nonce( 'lgms_user_search' ), 0 ); ?>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Create affiliate' ); ?>
        </form>
        <?php
    }
}
