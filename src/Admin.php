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

        ?>
        <div class="wrap">
            <h1>LG Member Sync</h1>

            <h2>DB connection</h2>
            <p><strong>Probe:</strong> <?php echo $probe; ?></p>
            <p><strong>Cron next run:</strong> <?php echo $nextRunDisplay; ?></p>

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
