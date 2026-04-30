<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\Db;
use LGMS\Repos\CustomerRepo;
use PDO;
use WP_User;

/**
 * Adds a "Membership" section to WP user profile pages (admin-only).
 * Renders the customer's Stripe info + active subscriptions + gift
 * entitlements, with action buttons for cancel/refund/block. Buttons
 * call the admin REST endpoints with a wp_rest nonce.
 */
final class UserProfile
{
    public static function boot(): void
    {
        add_action( 'show_user_profile', [ self::class, 'render' ] );
        add_action( 'edit_user_profile', [ self::class, 'render' ] );
    }

    public static function render( WP_User $user ): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $customer = CustomerRepo::findByEmail( (string) $user->user_email );
        $stripeKey = (string) get_option( 'lgms_stripe_secret_key', '' );
        $modeSeg   = ( strpos( $stripeKey, 'sk_test_' ) === 0 ) ? '/test' : '';
        $stripeBase = 'https://dashboard.stripe.com' . $modeSeg;

        $endpoints = [
            'cancel' => esc_url_raw( rest_url( 'lg-member-sync/v1/admin/cancel-subscription' ) ),
            'block'  => esc_url_raw( rest_url( 'lg-member-sync/v1/admin/block-customer' ) ),
        ];
        $nonce = wp_create_nonce( 'wp_rest' );

        ?>
        <h2 id="lgms-membership">Membership</h2>
        <?php if ( $customer === null ) : ?>
            <p style="color:#666;"><em>No membership record found for <?php echo esc_html( $user->user_email ); ?>.</em></p>
            <?php return; endif; ?>

        <?php
        $subs       = self::activeSubsForCustomer( (int) $customer['id'] );
        $gifts      = self::activeGiftsForCustomer( (int) $customer['id'] );
        $blocked    = ! empty( $customer['blocked_at'] );
        $blockReason = (string) ( $customer['block_reason'] ?? '' );
        ?>

        <table class="form-table" role="presentation">
            <tr>
                <th>Customer ID</th>
                <td><?php echo (int) $customer['id']; ?></td>
            </tr>
            <tr>
                <th>Stripe customer</th>
                <td>
                    <?php if ( ! empty( $customer['stripe_customer_id'] ) ) : ?>
                        <a href="<?php echo esc_url( $stripeBase . '/customers/' . rawurlencode( (string) $customer['stripe_customer_id'] ) ); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html( (string) $customer['stripe_customer_id'] ); ?>
                        </a>
                    <?php else : ?>
                        <em>(none)</em>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Status</th>
                <td>
                    <?php if ( $blocked ) : ?>
                        <strong style="color:#b00;">Blocked</strong>
                        <?php if ( $blockReason !== '' ) : ?>
                            <em>— <?php echo esc_html( $blockReason ); ?></em>
                        <?php endif; ?>
                        <em>(since <?php echo esc_html( (string) $customer['blocked_at'] ); ?>)</em>
                    <?php else : ?>
                        <span style="color:#080;">Active</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <h3>Active subscriptions</h3>
        <?php if ( $subs === [] ) : ?>
            <p style="color:#666;"><em>None.</em></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:900px;">
                <thead><tr><th>Stripe ID</th><th>Status</th><th>Period ends</th><th style="width:240px;">Actions</th></tr></thead>
                <tbody>
                <?php foreach ( $subs as $s ) :
                    $subId = (string) $s['stripe_subscription_id'];
                    $subUrl = $stripeBase . '/subscriptions/' . rawurlencode( $subId );
                ?>
                    <tr data-lgms-sub-row="<?php echo esc_attr( $subId ); ?>">
                        <td><a href="<?php echo esc_url( $subUrl ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $subId ); ?></a></td>
                        <td><?php echo esc_html( (string) $s['status'] ); ?></td>
                        <td><?php echo esc_html( (string) ( $s['current_period_end'] ?? 'n/a' ) ); ?></td>
                        <td>
                            <button type="button" class="button" data-lgms-action="cancel" data-lgms-sub="<?php echo esc_attr( $subId ); ?>">Cancel</button>
                            <button type="button" class="button button-primary" data-lgms-action="cancel-refund" data-lgms-sub="<?php echo esc_attr( $subId ); ?>">Cancel &amp; Refund</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Active gift entitlements</h3>
        <?php if ( $gifts === [] ) : ?>
            <p style="color:#666;"><em>None.</em></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:900px;">
                <thead><tr><th>Tier</th><th>Source</th><th>Started</th><th>Expires</th></tr></thead>
                <tbody>
                <?php foreach ( $gifts as $g ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $g['ref'] ); ?></td>
                        <td>gift_code #<?php echo (int) $g['source_id']; ?></td>
                        <td><?php echo esc_html( (string) ( $g['starts_at'] ?? 'n/a' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $g['expires_at'] ?? 'n/a' ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Recent admin actions</h3>
        <?php $log = self::adminActionLog( (int) $customer['id'] ); ?>
        <?php if ( $log === [] ) : ?>
            <p style="color:#666;"><em>None.</em></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:1100px;">
                <thead><tr><th>When</th><th>Action</th><th>Subject</th><th>Reason</th><th>Result</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ( $log as $row ) :
                    $actor = (int) ( $row['actor_wp_user'] ?? 0 );
                    $actorName = $actor > 0 ? ( get_userdata( $actor )->user_login ?? "user#{$actor}" ) : '(system)';
                    $subject = '';
                    if ( ! empty( $row['sub_id'] ) ) {
                        $subUrl  = $stripeBase . '/subscriptions/' . rawurlencode( (string) $row['sub_id'] );
                        $subject = '<a href="' . esc_url( $subUrl ) . '" target="_blank" rel="noopener">' . esc_html( (string) $row['sub_id'] ) . '</a>';
                    }
                    if ( ! empty( $row['refund_id'] ) ) {
                        $subject .= '<br><span style="color:#666;font-size:0.9em;">refund ' . esc_html( (string) $row['refund_id'] );
                        if ( ! empty( $row['refund_amount'] ) ) {
                            $subject .= ' (' . number_format( ( (int) $row['refund_amount'] ) / 100, 2 ) . ')';
                        }
                        $subject .= '</span>';
                    }
                    $statusHtml = ( (int) ( $row['success'] ?? 0 ) === 1 )
                        ? '<span style="color:#080;">✓ ok</span>'
                        : '<span style="color:#b00;">✗ failed</span><br><em style="font-size:0.85em;">' . esc_html( (string) ( $row['error_message'] ?? '' ) ) . '</em>';
                ?>
                    <tr>
                        <td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
                        <td><?php echo esc_html( (string) $row['action'] ); ?></td>
                        <td><?php echo $subject !== '' ? $subject : '<em>—</em>'; ?></td>
                        <td><?php echo $row['reason'] !== null && $row['reason'] !== '' ? esc_html( (string) $row['reason'] ) : '<em>—</em>'; ?></td>
                        <td><?php echo $statusHtml; ?></td>
                        <td><?php echo esc_html( (string) $actorName ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Block status</h3>
        <?php if ( $blocked ) : ?>
            <p>
                <button type="button" class="button" data-lgms-action="unblock">Unblock customer</button>
                <span style="margin-left:10px;color:#666;">Existing reason: <em><?php echo $blockReason !== '' ? esc_html( $blockReason ) : '(none on file)'; ?></em></span>
            </p>
        <?php else : ?>
            <p>Add an optional reason (saved internally for audit) and confirm to block this customer from future subscriptions and gift redemptions. Existing entitlements are not touched.</p>
            <p>
                <textarea data-lgms-block-reason rows="3" cols="60" style="width:100%;max-width:600px;display:block;margin-bottom:8px;" placeholder="e.g. Refunded for chargeback risk; previously gamed gift codes; etc."></textarea>
                <button type="button" class="button" data-lgms-action="block">Block from future subscriptions</button>
            </p>
        <?php endif; ?>

        <div data-lgms-result style="margin-top:1em;"></div>

        <script>
        (function(){
            const ENDPOINTS  = <?php echo wp_json_encode( $endpoints ); ?>;
            const NONCE      = <?php echo wp_json_encode( $nonce ); ?>;
            const CUST_ID    = <?php echo (int) $customer['id']; ?>;
            const resultEl   = document.querySelector('[data-lgms-result]');

            function showResult(html, isError){
                resultEl.innerHTML = '<div class="notice notice-' + (isError ? 'error' : 'success') + ' inline" style="padding:10px;"><p>' + html + '</p></div>';
            }

            async function postJson(url, payload){
                const res = await fetch(url, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body:    JSON.stringify(payload),
                });
                return { status: res.status, body: await res.json() };
            }

            document.querySelectorAll('button[data-lgms-action]').forEach(function(btn){
                btn.addEventListener('click', async function(){
                    const action = btn.dataset.lgmsAction;

                    if (action === 'cancel' || action === 'cancel-refund') {
                        const subId  = btn.dataset.lgmsSub;
                        const refund = action === 'cancel-refund';
                        const verb   = refund ? 'cancel AND refund the latest charge for' : 'cancel';
                        if (!confirm('Are you sure you want to ' + verb + ' subscription ' + subId + '? This is immediate and cannot be undone.')) return;
                        const reason = refund ? (prompt('Optional reason (will be saved to Stripe metadata):', '') || '') : '';
                        btn.disabled = true;
                        const orig   = btn.textContent;
                        btn.textContent = 'Working...';
                        try {
                            const { status, body } = await postJson(ENDPOINTS.cancel, {
                                sub_id:    subId,
                                refund:    refund,
                                reason:    reason,
                                immediate: true,
                            });
                            if (status === 200 && body.ok) {
                                showResult('Subscription ' + subId + ': ' + (body.actions || []).join('; '), false);
                                const row = document.querySelector('[data-lgms-sub-row="' + subId + '"]');
                                if (row) row.style.opacity = '0.5';
                                btn.textContent = 'Done';
                            } else {
                                showResult('Failed: ' + (body.error || 'unknown') + (body.partial ? ' (partial: ' + body.partial.join('; ') + ')' : '') + ' &mdash; an alert email has been sent if Stripe failed.', true);
                                btn.disabled = false;
                                btn.textContent = orig;
                            }
                        } catch (err) {
                            showResult('Network error: ' + err.message, true);
                            btn.disabled = false;
                            btn.textContent = orig;
                        }
                    }

                    if (action === 'block' || action === 'unblock') {
                        const blocking = action === 'block';
                        const ta       = document.querySelector('[data-lgms-block-reason]');
                        const reason   = blocking ? (ta ? (ta.value || '').trim() : '') : '';
                        if (!confirm((blocking ? 'Block' : 'Unblock') + ' this customer from future subscriptions and gift redemptions?')) return;
                        btn.disabled = true;
                        try {
                            const { status, body } = await postJson(ENDPOINTS.block, {
                                customer_id: CUST_ID,
                                blocked:     blocking,
                                reason:      reason,
                            });
                            if (status === 200 && body.ok) {
                                showResult('Customer ' + (body.blocked ? 'blocked' : 'unblocked') + '. Reload the page to refresh the status.', false);
                                btn.textContent = 'Done — reload page';
                            } else {
                                showResult('Failed: ' + (body.error || 'unknown'), true);
                                btn.disabled = false;
                            }
                        } catch (err) {
                            showResult('Network error: ' + err.message, true);
                            btn.disabled = false;
                        }
                    }
                });
            });
        })();
        </script>
        <?php
    }

    private static function activeSubsForCustomer( int $customerId ): array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT stripe_subscription_id, status, current_period_end FROM subscriptions
             WHERE customer_id = ? AND status IN ('active','trialing','past_due')
             ORDER BY id DESC"
        );
        $stmt->execute( [ $customerId ] );
        return $stmt->fetchAll( PDO::FETCH_ASSOC );
    }

    private static function activeGiftsForCustomer( int $customerId ): array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT ref, source_type, source_id, starts_at, expires_at FROM entitlements
             WHERE customer_id = ?
               AND source_type = 'gift_code'
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY id DESC"
        );
        $stmt->execute( [ $customerId ] );
        return $stmt->fetchAll( PDO::FETCH_ASSOC );
    }

    private static function adminActionLog( int $customerId, int $limit = 20 ): array
    {
        try {
            $stmt = Db::pdo()->prepare(
                'SELECT created_at, action, sub_id, refund_id, refund_amount, reason, success, error_message, actor_wp_user
                 FROM admin_action_log WHERE customer_id = ? ORDER BY id DESC LIMIT ?'
            );
            $stmt->bindValue( 1, $customerId, PDO::PARAM_INT );
            $stmt->bindValue( 2, $limit, PDO::PARAM_INT );
            $stmt->execute();
            return $stmt->fetchAll( PDO::FETCH_ASSOC );
        } catch ( \Throwable $_ ) {
            return [];
        }
    }
}
