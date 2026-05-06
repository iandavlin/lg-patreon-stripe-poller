<?php

declare(strict_types=1);

namespace LGMS;

use Throwable;

/**
 * Admin → "Member tools" page. Lookup a member by email, see their full
 * footprint across WP + lg_membership + Stripe, then act:
 *
 *  - Set role        (looth1 / looth2 / looth3 / looth4 / customer)
 *                     looth4 is the protected/poll-bypass tier — Arbiter and
 *                     PatreonSync both early-return on it, so granting it
 *                     pins the user above any automated re-sync.
 *  - Ban / Unban     toggle customers.blocked_at; CheckoutController already
 *                     refuses new subs for blocked customers.
 *  - Nuke            cancel any active Stripe subs, then DB cleanup in
 *                     FK-safe order, then wp_delete_user. Type-the-email
 *                     re-confirm guard.
 *
 * Lives on its own admin page (not the settings page) so the destructive
 * tools don't sit next to DB credentials.
 */
final class MemberTools
{
    private const PAGE_SLUG = 'lg-member-tools';
    private const NONCE     = 'lgms_member_tools';

    public static function boot(): void
    {
        add_action( 'admin_menu', [ self::class, 'menu' ] );
        add_action( 'admin_post_lgms_member_action', [ self::class, 'handleAction' ] );
    }

    public static function menu(): void
    {
        add_management_page(
            'Looth member tools',
            'Looth member tools',
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render' ],
        );
    }

    public static function render(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $email   = isset( $_GET['email'] ) ? sanitize_email( (string) wp_unslash( $_GET['email'] ) ) : '';
        $notice  = isset( $_GET['notice'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['notice'] ) ) : '';
        $err     = isset( $_GET['err'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['err'] ) ) : '';
        $profile = $email !== '' ? self::buildProfile( $email ) : null;

        ?>
        <div class="wrap">
            <h1>Looth member tools</h1>
            <p class="description">Lookup any account by email and act on it: change tier, ban/unban, or fully nuke. Independent of WP's user delete (which leaves Stripe + lg_membership rows behind).</p>

            <?php if ( $notice !== '' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>
            <?php if ( $err !== '' ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div>
            <?php endif; ?>

            <form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" style="margin:1.5em 0 2em;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                <label for="lgms-email-lookup"><strong>Member email</strong></label><br>
                <input type="email" id="lgms-email-lookup" name="email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" placeholder="member@example.com" required>
                <button type="submit" class="button button-primary">Look up</button>
            </form>

            <?php if ( $profile !== null ) : ?>
                <?php self::renderProfile( $profile ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Gather state about a member from WP + lg_membership.
     *
     * @return array{
     *   email:string,
     *   wp_user: \WP_User|null,
     *   customer: ?array,
     *   subscriptions: array,
     *   entitlements: array,
     *   gifts_purchased: array,
     *   gifts_received: array,
     *   role_sources: array
     * }
     */
    private static function buildProfile( string $email ): array
    {
        $wpUser  = get_user_by( 'email', $email ) ?: null;
        $customer = null;
        $subs    = [];
        $ents    = [];
        $bought  = [];
        $received = [];
        $roleSrc = [];

        try {
            $pdo = Db::pdo();

            $stmt = $pdo->prepare( 'SELECT * FROM customers WHERE email = ? LIMIT 1' );
            $stmt->execute( [ $email ] );
            $customer = $stmt->fetch( \PDO::FETCH_ASSOC ) ?: null;

            if ( $customer !== null ) {
                $cid = (int) $customer['id'];

                $stmt = $pdo->prepare(
                    'SELECT id, stripe_subscription_id, stripe_price_id, status, current_period_end, cancel_at_period_end
                     FROM subscriptions WHERE customer_id = ? ORDER BY id DESC'
                );
                $stmt->execute( [ $cid ] );
                $subs = $stmt->fetchAll( \PDO::FETCH_ASSOC );

                $stmt = $pdo->prepare(
                    'SELECT id, kind, ref, source_type, source_id, starts_at, expires_at, revoked_at
                     FROM entitlements WHERE customer_id = ? ORDER BY id DESC'
                );
                $stmt->execute( [ $cid ] );
                $ents = $stmt->fetchAll( \PDO::FETCH_ASSOC );

                $stmt = $pdo->prepare(
                    'SELECT id, code, tier, duration_days, recipient_email, redeemed_at, voided_at, created_at
                     FROM gift_codes WHERE purchased_by = ? ORDER BY id DESC'
                );
                $stmt->execute( [ $cid ] );
                $bought = $stmt->fetchAll( \PDO::FETCH_ASSOC );
            }

            $stmt = $pdo->prepare(
                'SELECT id, code, tier, duration_days, redeemed_at, voided_at FROM gift_codes WHERE recipient_email = ? ORDER BY id DESC'
            );
            $stmt->execute( [ $email ] );
            $received = $stmt->fetchAll( \PDO::FETCH_ASSOC );

            if ( $wpUser !== null ) {
                $stmt = $pdo->prepare( 'SELECT source, tier, updated_at FROM lg_role_sources WHERE wp_user_id = ?' );
                $stmt->execute( [ (int) $wpUser->ID ] );
                $roleSrc = $stmt->fetchAll( \PDO::FETCH_ASSOC );
            }
        } catch ( Throwable $e ) {
            // Swallow — show what we have.
        }

        return [
            'email'           => $email,
            'wp_user'         => $wpUser,
            'customer'        => $customer,
            'subscriptions'   => $subs,
            'entitlements'    => $ents,
            'gifts_purchased' => $bought,
            'gifts_received'  => $received,
            'role_sources'    => $roleSrc,
        ];
    }

    private static function renderProfile( array $p ): void
    {
        $email   = $p['email'];
        $wpUser  = $p['wp_user'];
        $cust    = $p['customer'];
        $blocked = $cust !== null && ! empty( $cust['blocked_at'] );
        $activeSubs = array_values( array_filter( $p['subscriptions'], static fn( $s ) => in_array( (string) $s['status'], [ 'active', 'trialing', 'past_due' ], true ) ) );
        $activeEnts = array_values( array_filter( $p['entitlements'], static fn( $e ) => empty( $e['revoked_at'] ) ) );

        ?>
        <h2>Profile · <?php echo esc_html( $email ); ?></h2>

        <table class="widefat striped" style="max-width:920px;">
            <tr><th>WP user</th><td>
                <?php if ( $wpUser ) : ?>
                    #<?php echo (int) $wpUser->ID; ?> · <code><?php echo esc_html( $wpUser->user_login ); ?></code> ·
                    roles: <strong><?php echo esc_html( implode( ', ', (array) $wpUser->roles ) ); ?></strong> ·
                    registered <?php echo esc_html( $wpUser->user_registered ); ?>
                <?php else : ?>
                    <em>none</em>
                <?php endif; ?>
            </td></tr>
            <tr><th>lg_membership customer</th><td>
                <?php if ( $cust ) : ?>
                    #<?php echo (int) $cust['id']; ?> ·
                    Stripe <code><?php echo esc_html( (string) ( $cust['stripe_customer_id'] ?? '' ) ); ?></code> ·
                    <?php echo $blocked ? '<span style="color:#b91c1c;font-weight:600;">BANNED</span> at ' . esc_html( (string) $cust['blocked_at'] ) . ' · reason: ' . esc_html( (string) ( $cust['block_reason'] ?? '' ) ) : '<span style="color:#15803d;">active</span>'; ?>
                <?php else : ?>
                    <em>none</em>
                <?php endif; ?>
            </td></tr>
            <tr><th>Active subscriptions</th><td>
                <?php if ( $activeSubs === [] ) : ?>
                    <em>none</em>
                <?php else : ?>
                    <?php foreach ( $activeSubs as $s ) : ?>
                        <code><?php echo esc_html( (string) $s['stripe_subscription_id'] ); ?></code>
                        · <strong><?php echo esc_html( (string) $s['status'] ); ?></strong>
                        · ends <?php echo esc_html( (string) $s['current_period_end'] ); ?>
                        <br>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td></tr>
            <tr><th>Active entitlements</th><td>
                <?php if ( $activeEnts === [] ) : ?>
                    <em>none</em>
                <?php else : ?>
                    <?php foreach ( $activeEnts as $e ) : ?>
                        <strong><?php echo esc_html( (string) $e['ref'] ); ?></strong>
                        · <?php echo esc_html( (string) $e['source_type'] ); ?>#<?php echo (int) $e['source_id']; ?>
                        · expires <?php echo esc_html( (string) ( $e['expires_at'] ?? '∞' ) ); ?>
                        <br>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td></tr>
            <tr><th>Gifts purchased</th><td><?php echo (int) count( $p['gifts_purchased'] ); ?> total</td></tr>
            <tr><th>Gifts received (by email)</th><td><?php echo (int) count( $p['gifts_received'] ); ?> total</td></tr>
            <tr><th>Role sources</th><td>
                <?php if ( $p['role_sources'] === [] ) : ?>
                    <em>none</em>
                <?php else : ?>
                    <?php foreach ( $p['role_sources'] as $r ) : ?>
                        <code><?php echo esc_html( (string) $r['source'] ); ?></code> → <?php echo esc_html( (string) ( $r['tier'] ?? '—' ) ); ?>
                        (updated <?php echo esc_html( (string) $r['updated_at'] ); ?>)<br>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td></tr>
        </table>

        <h2 style="margin-top:2em;">Actions</h2>

        <h3>Set tier (Arbiter override)</h3>
        <p class="description">Setting the tier writes a <code>manual_admin</code> source row that the Arbiter respects. <strong>looth4</strong> is the protected role — Patreon sync and Arbiter both skip looth4 users so the manual grant won't get overwritten.</p>
        <?php if ( $wpUser === null ) : ?>
            <p><em>No WP user — set tier requires a WP account.</em></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:2em;">
                <?php wp_nonce_field( self::NONCE ); ?>
                <input type="hidden" name="action" value="lgms_member_action">
                <input type="hidden" name="op"     value="set_tier">
                <input type="hidden" name="email"  value="<?php echo esc_attr( $email ); ?>">
                <select name="tier">
                    <?php foreach ( [ 'looth1','looth2','looth3','looth4','customer' ] as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( in_array( $t, (array) $wpUser->roles, true ) ); ?>><?php echo esc_html( $t ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">Apply tier</button>
            </form>
        <?php endif; ?>

        <h3>Ban / Unban</h3>
        <p class="description">Sets <code>customers.blocked_at</code>. CheckoutController refuses new subs for blocked customers. Existing subs keep billing — cancel separately or use Nuke.</p>
        <?php if ( $cust === null ) : ?>
            <p><em>No lg_membership customer — nothing to ban.</em></p>
        <?php elseif ( $blocked ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:2em;">
                <?php wp_nonce_field( self::NONCE ); ?>
                <input type="hidden" name="action" value="lgms_member_action">
                <input type="hidden" name="op"     value="unban">
                <input type="hidden" name="email"  value="<?php echo esc_attr( $email ); ?>">
                <button type="submit" class="button">Unban customer</button>
            </form>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:2em;">
                <?php wp_nonce_field( self::NONCE ); ?>
                <input type="hidden" name="action" value="lgms_member_action">
                <input type="hidden" name="op"     value="ban">
                <input type="hidden" name="email"  value="<?php echo esc_attr( $email ); ?>">
                <input type="text" name="reason" placeholder="reason (shown in audit)" class="regular-text">
                <button type="submit" class="button">Ban customer</button>
            </form>
        <?php endif; ?>

        <h3 style="color:#b91c1c;">Nuke member</h3>
        <p class="description"><strong>Destructive and not reversible.</strong> Cancels any active Stripe subscriptions, deletes <code>lg_membership</code> customer + subs + entitlements + role_sources + bridge + gifts purchased + gift_recipients_pending, then deletes the WP user.</p>
        <p class="description">Re-type the email to confirm.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('NUKE <?php echo esc_js( $email ); ?>? This cannot be undone.');">
            <?php wp_nonce_field( self::NONCE ); ?>
            <input type="hidden" name="action" value="lgms_member_action">
            <input type="hidden" name="op"     value="nuke">
            <input type="hidden" name="email"  value="<?php echo esc_attr( $email ); ?>">
            <input type="email" name="email_confirm" placeholder="re-type email to confirm" class="regular-text" required>
            <button type="submit" class="button button-link-delete" style="color:#b91c1c;">Nuke</button>
        </form>
        <?php
    }

    public static function handleAction(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( self::NONCE );

        $email = sanitize_email( (string) ( $_POST['email'] ?? '' ) );
        $op    = sanitize_text_field( (string) ( $_POST['op'] ?? '' ) );

        if ( $email === '' || $op === '' ) {
            self::redirect( $email, '', 'Missing email or op.' );
        }

        $notice = '';
        $err    = '';
        try {
            switch ( $op ) {
                case 'set_tier':
                    $tier = sanitize_text_field( (string) ( $_POST['tier'] ?? '' ) );
                    $notice = self::doSetTier( $email, $tier );
                    break;
                case 'ban':
                    $reason = sanitize_text_field( (string) ( $_POST['reason'] ?? '' ) );
                    $notice = self::doBan( $email, $reason );
                    break;
                case 'unban':
                    $notice = self::doUnban( $email );
                    break;
                case 'nuke':
                    $confirm = sanitize_email( (string) ( $_POST['email_confirm'] ?? '' ) );
                    if ( strtolower( $confirm ) !== strtolower( $email ) ) {
                        throw new \RuntimeException( 'Confirm email did not match.' );
                    }
                    $notice = self::doNuke( $email );
                    break;
                default:
                    $err = "Unknown op: {$op}";
            }
        } catch ( Throwable $e ) {
            $err = $e->getMessage();
        }

        self::redirect( $email, $notice, $err );
    }

    private static function doSetTier( string $email, string $tier ): string
    {
        $allowed = [ 'looth1', 'looth2', 'looth3', 'looth4', 'customer' ];
        if ( ! in_array( $tier, $allowed, true ) ) {
            throw new \RuntimeException( 'Invalid tier.' );
        }
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            throw new \RuntimeException( 'No WP user for that email.' );
        }
        // Strip any other looth tier so they don't carry stale grants.
        foreach ( [ 'looth1', 'looth2', 'looth3', 'looth4', 'customer' ] as $r ) {
            if ( $r !== $tier && in_array( $r, (array) $user->roles, true ) ) {
                $user->remove_role( $r );
            }
        }
        $user->add_role( $tier );

        // Record as a manual_admin source so Arbiter respects it.
        try {
            $pdo = Db::pdo();
            $pdo->prepare(
                'INSERT INTO lg_role_sources (wp_user_id, source, tier) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE tier = VALUES(tier), updated_at = CURRENT_TIMESTAMP'
            )->execute( [ (int) $user->ID, 'manual_admin', $tier ] );
        } catch ( Throwable $_ ) {}

        self::audit( $email, 'set_tier', "tier={$tier}" );
        return "Set {$email} to {$tier}.";
    }

    private static function doBan( string $email, string $reason ): string
    {
        $cust = self::loadCustomer( $email );
        if ( $cust === null ) {
            throw new \RuntimeException( 'No lg_membership customer for that email.' );
        }
        $pdo = Db::pdo();
        $pdo->prepare( 'UPDATE customers SET blocked_at = NOW(), block_reason = ? WHERE id = ?' )
            ->execute( [ $reason !== '' ? $reason : 'banned by admin', (int) $cust['id'] ] );
        self::audit( $email, 'ban', "reason={$reason}" );
        return "Banned {$email}.";
    }

    private static function doUnban( string $email ): string
    {
        $cust = self::loadCustomer( $email );
        if ( $cust === null ) {
            throw new \RuntimeException( 'No lg_membership customer for that email.' );
        }
        $pdo = Db::pdo();
        $pdo->prepare( 'UPDATE customers SET blocked_at = NULL, block_reason = NULL WHERE id = ?' )
            ->execute( [ (int) $cust['id'] ] );
        self::audit( $email, 'unban', '' );
        return "Unbanned {$email}.";
    }

    /**
     * Full obliteration. Cancel Stripe subs first (so we don't keep charging
     * after deletion), then DB cleanup in FK-safe order, then WP user.
     */
    private static function doNuke( string $email ): string
    {
        $wpUser = get_user_by( 'email', $email );
        $cust   = self::loadCustomer( $email );

        // 1. Cancel any active Stripe subs first — failure here aborts the
        //    nuke before we touch local state, so we don't end up with a
        //    deleted local record but a still-billing Stripe sub.
        $cancelled = [];
        if ( $cust !== null ) {
            $pdo = Db::pdo();
            $stmt = $pdo->prepare(
                "SELECT stripe_subscription_id FROM subscriptions
                 WHERE customer_id = ? AND status IN ('active','trialing','past_due')"
            );
            $stmt->execute( [ (int) $cust['id'] ] );
            $stripeIds = array_column( $stmt->fetchAll( \PDO::FETCH_ASSOC ), 'stripe_subscription_id' );

            if ( $stripeIds !== [] ) {
                $client = new \LGMS\Stripe\Client();
                foreach ( $stripeIds as $sid ) {
                    if ( $sid === '' || $sid === null ) continue;
                    try {
                        $client->cancelSubscription( (string) $sid );
                        $cancelled[] = (string) $sid;
                    } catch ( Throwable $e ) {
                        // 404s and "already canceled" are fine — keep going.
                        if ( stripos( $e->getMessage(), 'No such subscription' ) === false ) {
                            throw new \RuntimeException( "Stripe cancel failed for {$sid}: " . $e->getMessage() );
                        }
                    }
                }
            }
        }

        // 2. Local lg_membership cleanup (order matters for FKs).
        if ( $cust !== null ) {
            $pdo = Db::pdo();
            $cid = (int) $cust['id'];

            $pdo->prepare( 'DELETE FROM gift_recipients_pending WHERE stripe_checkout_session_id IN (SELECT stripe_session_id FROM gift_codes WHERE purchased_by = ?)' )->execute( [ $cid ] );
            $pdo->prepare( 'UPDATE gift_codes SET recipient_email = NULL, recipient_name = NULL, gift_message = NULL, email_sent_at = NULL WHERE recipient_email = ?' )->execute( [ $email ] );
            $pdo->prepare( 'DELETE FROM gift_codes WHERE purchased_by = ?' )->execute( [ $cid ] );
            $pdo->prepare( 'DELETE FROM admin_action_log WHERE customer_id = ?' )->execute( [ $cid ] );
            $pdo->prepare( 'DELETE FROM entitlements WHERE customer_id = ?' )->execute( [ $cid ] );
            $pdo->prepare( 'DELETE FROM subscriptions WHERE customer_id = ?' )->execute( [ $cid ] );
            $pdo->prepare( 'DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_id = ?)' )->execute( [ $cid ] );
            $pdo->prepare( 'DELETE FROM orders WHERE customer_id = ?' )->execute( [ $cid ] );
            $pdo->prepare( 'DELETE FROM wp_user_bridge WHERE customer_id = ?' )->execute( [ $cid ] );
            $pdo->prepare( 'DELETE FROM customers WHERE id = ?' )->execute( [ $cid ] );
        }

        // 3. WP user. Only nuke if not an administrator (safety guard).
        $deletedWp = false;
        if ( $wpUser ) {
            if ( in_array( 'administrator', (array) $wpUser->roles, true ) ) {
                throw new \RuntimeException( 'Refusing to nuke an administrator account. Demote first.' );
            }
            try {
                $pdo = Db::pdo();
                $pdo->prepare( 'DELETE FROM lg_role_sources WHERE wp_user_id = ?' )->execute( [ (int) $wpUser->ID ] );
            } catch ( Throwable $_ ) {}
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $deletedWp = wp_delete_user( (int) $wpUser->ID );
        }

        self::audit( $email, 'nuke', 'cancelled=' . implode( ',', $cancelled ) . ';wp_deleted=' . ( $deletedWp ? '1' : '0' ) );
        $cancelMsg = $cancelled !== [] ? ' Cancelled Stripe subs: ' . implode( ', ', $cancelled ) . '.' : '';
        return "Nuked {$email}.{$cancelMsg}";
    }

    private static function loadCustomer( string $email ): ?array
    {
        try {
            $stmt = Db::pdo()->prepare( 'SELECT * FROM customers WHERE email = ? LIMIT 1' );
            $stmt->execute( [ $email ] );
            $row = $stmt->fetch( \PDO::FETCH_ASSOC );
            return $row ?: null;
        } catch ( Throwable $_ ) {
            return null;
        }
    }

    private static function audit( string $email, string $action, string $details ): void
    {
        try {
            $stmt = Db::pdo()->prepare( 'SELECT id FROM customers WHERE email = ? LIMIT 1' );
            $stmt->execute( [ $email ] );
            $row = $stmt->fetch( \PDO::FETCH_ASSOC );
            if ( ! $row ) return; // Customer already nuked / never existed — admin_action_log requires customer_id.
            $admin = wp_get_current_user();
            Db::pdo()->prepare(
                'INSERT INTO admin_action_log (customer_id, actor_wp_user, action, reason)
                 VALUES (?, ?, ?, ?)'
            )->execute( [
                (int) $row['id'],
                $admin && $admin->ID ? (int) $admin->ID : null,
                $action,
                $details,
            ] );
        } catch ( Throwable $_ ) {}
    }

    private static function redirect( string $email, string $notice, string $err ): void
    {
        $args = [ 'page' => self::PAGE_SLUG ];
        if ( $email !== '' ) $args['email'] = $email;
        if ( $notice !== '' ) $args['notice'] = $notice;
        if ( $err !== '' )    $args['err']    = $err;
        wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
        exit;
    }
}
