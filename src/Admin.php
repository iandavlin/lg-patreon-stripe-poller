<?php

declare(strict_types=1);

namespace LGMS;

/**
 * Admin settings page — DB connection + Stripe/Patreon credentials.
 *
 * Phase 1: bare-bones DB connection settings only. Cron status read-only.
 * Phase 2 will add: Stripe API key, Patreon OAuth credentials, last-tick
 *                   timestamp, manual "poll now" button.
 */
final class Admin
{
    private const OPT_GROUP = 'lgms_settings';
    private const OPT_PAGE  = 'lg-member-sync';

    public static function boot(): void
    {
        add_action( 'admin_menu', [ self::class, 'menu' ] );
        add_action( 'admin_init', [ self::class, 'registerSettings' ] );
        add_action( 'admin_post_lgms_rerun_pages', [ self::class, 'handleRerunPages' ] );
    }

    /**
     * Handler for the "Re-create membership pages" admin button.
     * Re-runs Pages::ensureAll() outside of activation so admins can sync
     * pages after editing the PAGES registry without having to
     * deactivate-and-reactivate the whole plugin.
     */
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
            [
                'page'        => self::OPT_PAGE,
                'lgms_pages'  => rawurlencode( $msg ),
            ],
            admin_url( 'options-general.php' )
        ) );
        exit;
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
            'lgms_db_host'           => '127.0.0.1',
            'lgms_db_port'           => '3306',
            'lgms_db_name'           => 'lg_membership',
            'lgms_db_user'           => 'lg_membership',
            'lgms_db_pass'           => '',
            'lgms_stripe_secret_key' => '',
            'lgms_shared_secret'     => '',
            'lgms_refund_email'      => '',
            'lgms_refund_window_days' => '30',
            'lgms_plan_switch_cooldown_hours' => '24',
        ];
        foreach ( $fields as $key => $_default ) {
            register_setting( self::OPT_GROUP, $key, [
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        }
    }

    public static function render(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Connectivity probe.
        $probe = '<em>not tested</em>';
        try {
            $pdo  = Db::pdo();
            $row  = $pdo->query( 'SELECT VERSION() AS v' )->fetch();
            $probe = sprintf( '✓ connected (MySQL %s)', esc_html( (string) ( $row['v'] ?? '?' ) ) );
        } catch ( \Throwable $e ) {
            $probe = '✗ ' . esc_html( $e->getMessage() );
        }

        $nextRun = wp_next_scheduled( Plugin::CRON_HOOK );
        $nextRunDisplay = $nextRun ? gmdate( 'c', $nextRun ) . ' UTC' : '<em>not scheduled</em>';

        $pagesNotice = isset( $_GET['lgms_pages'] ) ? rawurldecode( (string) $_GET['lgms_pages'] ) : '';

        ?>
        <div class="wrap">
            <h1>LG Member Sync</h1>

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
                    <tr><th><label>Refund window (days)</label></th><td><input type="number" name="lgms_refund_window_days" value="<?php echo esc_attr( get_option( 'lgms_refund_window_days', '30' ) ); ?>" class="small-text" min="1" max="365"> <span class="description">Number of days after a charge that a customer is eligible for an automated refund. Items outside the window are shown to the customer as "outside the refund window" but they can still submit a request.</span></td></tr>
                    <tr><th><label>Plan-switch cooldown (hours)</label></th><td><input type="number" name="lgms_plan_switch_cooldown_hours" value="<?php echo esc_attr( get_option( 'lgms_plan_switch_cooldown_hours', '24' ) ); ?>" class="small-text" min="0" max="720"> <span class="description">Minimum hours between customer-initiated plan changes. Prevents abuse / accidental rapid switching. Set to 0 to disable.</span></td></tr>
                </table>

                <h2>Slim ↔ plugin shared secret</h2>
                <p class="description">Used to authenticate Slim's calls to <code>/wp-json/lg-member-sync/v1/sync-customer</code>. Set the same value on Slim's <code>LGMS_SHARED_SECRET</code> in <code>.env</code>.</p>
                <table class="form-table">
                    <tr><th><label>Shared secret</label></th><td><input type="password" name="lgms_shared_secret" value="<?php echo esc_attr( get_option( 'lgms_shared_secret', '' ) ); ?>" class="regular-text" autocomplete="off"></td></tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
