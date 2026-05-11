<?php

declare(strict_types=1);

namespace LGMS\Wp;

/**
 * [lg_test_checklist] — interactive QA checklist for partners testing
 * the membership stack before prod cutover.
 *
 * Coverage spans every customer-facing flow plus admin/security smoke
 * tests. Checkbox state persists per-tester via localStorage (key
 * `lgtc_state_v1`); no DB writes, no cross-tester sync — each browser
 * tracks its own progress.
 *
 * Item IDs are stable: don't rename one once shipped, or testers will
 * lose their existing checks. Add-only changes are safe.
 *
 * Page is publicly seeded (so testers can hit the URL without a WP
 * login) and gated by a WordPress post password set on the page row.
 * The nav link is admin-only via 'admins' visibility so admins can
 * always find the page; non-admin testers reach it via the shared URL +
 * password.
 */
final class TestChecklist
{
    public static function register(): void
    {
        add_shortcode( 'lg_test_checklist', [ self::class, 'render' ] );
        add_action( 'wp_ajax_lgms_test_wipe_email', [ self::class, 'handleAjaxWipeEmail' ] );

        // Feedback submission: must be reachable by non-logged-in testers
        // who passed the page password gate, hence the nopriv hook too.
        add_action( 'wp_ajax_lgms_test_feedback_submit',        [ self::class, 'handleAjaxFeedbackSubmit' ] );
        add_action( 'wp_ajax_nopriv_lgms_test_feedback_submit', [ self::class, 'handleAjaxFeedbackSubmit' ] );

        // Triage endpoints — admin-only.
        add_action( 'wp_ajax_lgms_test_feedback_status',        [ self::class, 'handleAjaxFeedbackStatus' ] );
    }

    /**
     * Severities (with display labels and colours) and statuses. Keep the
     * string keys stable — they're stored verbatim in the DB.
     *
     * Active set is pass / fail / question — that's what the report modal
     * lets a tester pick. Legacy 'bug' / 'note' values are retained here so
     * any rows from before this UI restructure still render correctly in
     * the admin inbox.
     */
    private const SEVERITY = [
        'pass'     => [ 'label' => 'Passed',   'color' => '#87986A' ],
        'fail'     => [ 'label' => 'Failed',   'color' => '#b04a3c' ],
        'question' => [ 'label' => 'Question', 'color' => '#C68A1E' ],
        // Legacy (pre-restructure) — render only, never offered in the UI.
        'bug'      => [ 'label' => 'Bug',      'color' => '#b04a3c' ],
        'note'     => [ 'label' => 'Note',     'color' => '#87986A' ],
    ];
    private const STATUSES = [ 'open', 'fixed', 'wontfix' ];

    /**
     * AJAX: a tester submits per-item feedback. Available to anonymous
     * password-holders (nopriv) and logged-in users alike. Nonce gates spam.
     */
    public static function handleAjaxFeedbackSubmit(): void
    {
        check_ajax_referer( 'lgms_test_feedback', 'nonce' );

        $itemId    = sanitize_text_field( (string) ( $_POST['item_id']     ?? '' ) );
        $name      = sanitize_text_field( (string) ( $_POST['tester_name'] ?? '' ) );
        $sevRaw    = sanitize_text_field( (string) ( $_POST['severity']    ?? '' ) );
        $body      = trim( (string) ( $_POST['body'] ?? '' ) );
        $body      = sanitize_textarea_field( $body );
        // Only the three active severities accepted from the UI. Legacy
        // values exist for render-back-compat but can't be re-introduced.
        $allowSev  = [ 'pass', 'fail', 'question' ];
        $severity  = in_array( $sevRaw, $allowSev, true ) ? $sevRaw : '';
        $ua        = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

        if ( $itemId === '' || ! self::isKnownItemId( $itemId ) ) {
            wp_send_json_error( [ 'message' => 'Unknown item id.' ], 400 );
        }
        if ( $severity === '' ) {
            wp_send_json_error( [ 'message' => 'Pick a result: pass, fail, or question.' ], 400 );
        }
        if ( $name === '' ) {
            $name = 'anonymous';
        }
        // Comment is required for fail / question; optional for pass so
        // testers can rip through happy-path items fast.
        if ( $severity !== 'pass' && $body === '' ) {
            wp_send_json_error( [ 'message' => 'Tell us what you saw.' ], 400 );
        }
        // Hard cap on body length so a runaway tester can't bloat the table.
        $body = mb_substr( $body, 0, 4000 );
        $ua   = mb_substr( $ua,   0, 255 );

        try {
            $stmt = \LGMS\Db::pdo()->prepare(
                'INSERT INTO lg_test_feedback (item_id, tester_name, severity, status, body, user_agent)
                 VALUES (?, ?, ?, "open", ?, ?)'
            );
            $stmt->execute( [ $itemId, $name, $severity, $body, $ua ] );
            wp_send_json_success( [
                'id'      => (int) \LGMS\Db::pdo()->lastInsertId(),
                'message' => 'Logged. Thanks!',
            ] );
        } catch ( \Throwable $e ) {
            error_log( 'lgms_test_feedback_submit DB error: ' . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'Could not save just now. Try again.' ], 500 );
        }
    }

    /**
     * AJAX: admin updates a feedback row's status (open/fixed/wontfix) or
     * deletes it entirely (status="delete"). Admin-only.
     */
    public static function handleAjaxFeedbackStatus(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
        }
        check_ajax_referer( 'lgms_test_feedback', 'nonce' );

        $id     = (int) ( $_POST['id'] ?? 0 );
        $status = sanitize_text_field( (string) ( $_POST['status'] ?? '' ) );
        if ( $id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Bad id.' ], 400 );
        }

        try {
            if ( $status === 'delete' ) {
                $stmt = \LGMS\Db::pdo()->prepare( 'DELETE FROM lg_test_feedback WHERE id = ?' );
                $stmt->execute( [ $id ] );
                wp_send_json_success( [ 'deleted' => $stmt->rowCount() ] );
            }
            if ( ! in_array( $status, self::STATUSES, true ) ) {
                wp_send_json_error( [ 'message' => 'Bad status.' ], 400 );
            }
            $stmt = \LGMS\Db::pdo()->prepare( 'UPDATE lg_test_feedback SET status = ? WHERE id = ?' );
            $stmt->execute( [ $status, $id ] );
            wp_send_json_success( [ 'updated' => $stmt->rowCount(), 'status' => $status ] );
        } catch ( \Throwable $e ) {
            error_log( 'lgms_test_feedback_status DB error: ' . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'DB error.' ], 500 );
        }
    }

    /**
     * Validate that an item_id from a submitted feedback row maps to a real
     * checklist item. Prevents random strings from getting saved.
     */
    private static function isKnownItemId( string $fullId ): bool
    {
        if ( strpos( $fullId, ':' ) === false ) return false;
        [ $section, $item ] = explode( ':', $fullId, 2 );
        return isset( self::SECTIONS[ $section ]['items'][ $item ] );
    }

    /**
     * Returns label info for an item id ('section:item') — used by the admin
     * viewer to render readable headings instead of opaque strings.
     */
    private static function itemLabel( string $fullId ): array
    {
        if ( strpos( $fullId, ':' ) === false ) {
            return [ 'section' => '?', 'desc' => $fullId ];
        }
        [ $sec, $item ] = explode( ':', $fullId, 2 );
        return [
            'section' => (string) ( self::SECTIONS[ $sec ]['title']                ?? $sec ),
            'desc'    => (string) ( self::SECTIONS[ $sec ]['items'][ $item ]['desc'] ?? $item ),
        ];
    }

    /**
     * Server-side fetch of all feedback rows for the admin viewer.
     *
     * @return list<array<string,mixed>>
     */
    private static function fetchFeedback(): array
    {
        try {
            $stmt = \LGMS\Db::pdo()->query(
                'SELECT id, item_id, tester_name, severity, status, body, created_at
                 FROM lg_test_feedback
                 ORDER BY (status = "open") DESC, created_at DESC'
            );
            $rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
            return is_array( $rows ) ? $rows : [];
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    /**
     * AJAX endpoint backing the "Wipe a tester email" panel. Two modes:
     *   - mode=preview  → returns counts of what would be deleted, no writes
     *   - mode=perform  → actually deletes everything across lg_membership
     *                     + WP user + BP tables. Requires self_confirm:1
     *                     in the body when wiping the calling admin's own
     *                     email (otherwise refuses).
     *
     * Auth: manage_options + nonce. Refuses to wipe user_id=1 (the original
     * site installer) and refuses to wipe the last administrator on the
     * site (to avoid locking everyone out).
     */
    public static function handleAjaxWipeEmail(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
        }
        check_ajax_referer( 'lgms_test_wipe', 'nonce' );

        $mode  = sanitize_text_field( (string) ( $_POST['mode'] ?? 'preview' ) );
        $email = sanitize_email( (string) ( $_POST['email'] ?? '' ) );
        $self  = ! empty( $_POST['self_confirm'] );

        if ( $email === '' || ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'A valid email is required.' ], 400 );
        }

        $wpUser = get_user_by( 'email', $email );
        $wpId   = $wpUser ? (int) $wpUser->ID : 0;

        // Safety: protect the original installer.
        if ( $wpId === 1 ) {
            wp_send_json_error( [ 'message' => 'Refusing to wipe user_id=1 (site installer).' ], 400 );
        }

        // Safety: refuse to wipe the last admin.
        if ( $wpUser && in_array( 'administrator', (array) $wpUser->roles, true ) ) {
            $admins = (array) get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 5 ] );
            if ( count( $admins ) <= 1 ) {
                wp_send_json_error( [ 'message' => 'Refusing to wipe the last administrator on the site.' ], 400 );
            }
        }

        // Self-wipe gate: target email matches the calling admin's email.
        $callingEmail = (string) ( wp_get_current_user()->user_email ?? '' );
        $isSelf       = $callingEmail !== '' && strcasecmp( $callingEmail, $email ) === 0;

        $custId    = 0;
        $custEmail = '';
        try {
            $stmt = \LGMS\Db::pdo()->prepare( 'SELECT id, email FROM customers WHERE email = ? LIMIT 1' );
            $stmt->execute( [ $email ] );
            $row = $stmt->fetch( \PDO::FETCH_ASSOC );
            if ( $row ) {
                $custId    = (int) $row['id'];
                $custEmail = (string) $row['email'];
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'DB error looking up customer: ' . $e->getMessage() ], 500 );
        }

        $counts = self::wipeCounts( $custId, $wpId, $email );

        if ( $mode === 'preview' ) {
            wp_send_json_success( [
                'preview'   => true,
                'wp_user'   => $wpUser ? [ 'id' => $wpId, 'login' => $wpUser->user_login, 'roles' => array_values( (array) $wpUser->roles ) ] : null,
                'customer'  => $custId > 0 ? [ 'id' => $custId, 'email' => $custEmail ] : null,
                'is_self'   => $isSelf,
                'counts'    => $counts,
                'total'     => array_sum( $counts ),
            ] );
        }

        if ( $mode !== 'perform' ) {
            wp_send_json_error( [ 'message' => 'Invalid mode.' ], 400 );
        }

        // Self-wipe needs an explicit second-step opt-in.
        if ( $isSelf && ! $self ) {
            wp_send_json_error( [
                'message'        => 'Self-wipe blocked: pass self_confirm=1 to confirm.',
                'requires_self'  => true,
            ], 409 );
        }

        $deleted = self::wipePerform( $custId, $wpId, $email );
        wp_send_json_success( [
            'preview'  => false,
            'wp_user'  => $wpId > 0 ? [ 'id' => $wpId ] : null,
            'customer' => $custId > 0 ? [ 'id' => $custId ] : null,
            'is_self'  => $isSelf,
            'deleted'  => $deleted,
            'total'    => array_sum( $deleted ),
        ] );
    }

    /**
     * Returns how many rows each tracked table holds for the given keys.
     * Tables that don't exist on this install are silently skipped.
     *
     * @return array<string,int>
     */
    private static function wipeCounts( int $custId, int $wpId, string $email ): array
    {
        $counts = [];
        $pdo    = \LGMS\Db::pdo();
        $sets   = self::wipeQueries( $custId, $wpId, $email );
        foreach ( $sets as $label => $q ) {
            try {
                $stmt = $pdo->prepare( $q['count'] );
                $stmt->execute( $q['params'] );
                $counts[ $label ] = (int) $stmt->fetchColumn();
            } catch ( \Throwable $e ) {
                // Table may not exist on this install (e.g. audit_log only
                // populated by Slim path) — count as 0 and move on.
                $counts[ $label ] = 0;
            }
        }
        // BB tables and WP user are in WP DB, not lg_membership PDO. Approximate:
        if ( $wpId > 0 ) {
            $counts['wp_user'] = 1;
            global $wpdb;
            $bpTables = [ 'bp_activity', 'bp_friends', 'bp_groups_members', 'bp_messages_recipients', 'bp_notifications', 'bp_xprofile_data', 'bp_user_blogs' ];
            $bpTotal  = 0;
            foreach ( $bpTables as $t ) {
                $full = $wpdb->prefix . $t;
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
                    continue;
                }
                $col = $t === 'bp_friends' ? 'initiator_user_id' : 'user_id';
                $bpTotal += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$full} WHERE {$col} = %d", $wpId ) );
            }
            $counts['bp_*_rows'] = $bpTotal;
        }
        return $counts;
    }

    /**
     * Actually performs the deletes. Same table set as wipeCounts(); each
     * DELETE is independent so a failure on one table doesn't block the
     * others.
     *
     * @return array<string,int>  rows deleted per table
     */
    private static function wipePerform( int $custId, int $wpId, string $email ): array
    {
        $deleted = [];
        $pdo     = \LGMS\Db::pdo();
        $sets    = self::wipeQueries( $custId, $wpId, $email );
        foreach ( $sets as $label => $q ) {
            try {
                $stmt = $pdo->prepare( $q['delete'] );
                $stmt->execute( $q['params'] );
                $deleted[ $label ] = $stmt->rowCount();
            } catch ( \Throwable $e ) {
                $deleted[ $label ] = 0; // Table absent or already empty.
            }
        }
        if ( $wpId > 0 ) {
            \LGMS\Wp\RestController::eraseBuddypressFootprint( $wpId );
            $deleted['bp_*_rows'] = 1; // Aggregate signal — exact per-table counts not surfaced.
            require_once ABSPATH . 'wp-admin/includes/user.php';
            if ( wp_delete_user( $wpId ) ) {
                $deleted['wp_user'] = 1;
            }
        }
        return $deleted;
    }

    /**
     * One source of truth for the lg_membership table set we touch on wipe.
     * Returns label => { count: SQL, delete: SQL, params: array }. Counts and
     * deletes use the same WHERE clause so previews match what perform will do.
     *
     * @return array<string,array{count:string,delete:string,params:list<mixed>}>
     */
    private static function wipeQueries( int $custId, int $wpId, string $email ): array
    {
        $sets = [];
        if ( $custId > 0 ) {
            $sets['admin_action_log'] = [
                'count'  => 'SELECT COUNT(*) FROM admin_action_log WHERE customer_id = ?',
                'delete' => 'DELETE FROM admin_action_log WHERE customer_id = ?',
                'params' => [ $custId ],
            ];
            $sets['audit_log'] = [
                'count'  => 'SELECT COUNT(*) FROM audit_log WHERE customer_id = ?',
                'delete' => 'DELETE FROM audit_log WHERE customer_id = ?',
                'params' => [ $custId ],
            ];
            $sets['entitlements'] = [
                'count'  => 'SELECT COUNT(*) FROM entitlements WHERE customer_id = ?',
                'delete' => 'DELETE FROM entitlements WHERE customer_id = ?',
                'params' => [ $custId ],
            ];
            $sets['subscriptions'] = [
                'count'  => 'SELECT COUNT(*) FROM subscriptions WHERE customer_id = ?',
                'delete' => 'DELETE FROM subscriptions WHERE customer_id = ?',
                'params' => [ $custId ],
            ];
            $sets['gift_codes'] = [
                'count'  => 'SELECT COUNT(*) FROM gift_codes WHERE purchased_by = ? OR redeemed_by = ?',
                'delete' => 'DELETE FROM gift_codes WHERE purchased_by = ? OR redeemed_by = ?',
                'params' => [ $custId, $custId ],
            ];
            $sets['wp_user_bridge'] = [
                'count'  => 'SELECT COUNT(*) FROM wp_user_bridge WHERE customer_id = ?',
                'delete' => 'DELETE FROM wp_user_bridge WHERE customer_id = ?',
                'params' => [ $custId ],
            ];
            $sets['customers'] = [
                'count'  => 'SELECT COUNT(*) FROM customers WHERE id = ?',
                'delete' => 'DELETE FROM customers WHERE id = ?',
                'params' => [ $custId ],
            ];
        }
        if ( $wpId > 0 ) {
            $sets['lg_role_sources'] = [
                'count'  => 'SELECT COUNT(*) FROM lg_role_sources WHERE wp_user_id = ?',
                'delete' => 'DELETE FROM lg_role_sources WHERE wp_user_id = ?',
                'params' => [ $wpId ],
            ];
            $sets['lg_patreon_members'] = [
                'count'  => 'SELECT COUNT(*) FROM lg_patreon_members WHERE wp_user_id = ?',
                'delete' => 'DELETE FROM lg_patreon_members WHERE wp_user_id = ?',
                'params' => [ $wpId ],
            ];
        }
        $sets['pending_sessions_by_email'] = [
            'count'  => 'SELECT COUNT(*) FROM pending_sessions WHERE buyer_email = ?',
            'delete' => 'DELETE FROM pending_sessions WHERE buyer_email = ?',
            'params' => [ $email ],
        ];
        return $sets;
    }

    /**
     * Stable section IDs are used in localStorage keys. Don't rename them.
     */
    private const SECTIONS = [
        'auth' => [
            'title' => 'Auth & sign-up',
            'items' => [
                'auth-new'        => [ 'desc' => 'Sign up at /lgjoin/ with a brand-new email and a password (≥ 8 chars).',                                                         'expect' => 'Account is created, redirect to /activity/, the welcome modal appears, looth1 role assigned (then promoted by Arbiter on paid entitlement).', 'url' => '/lgjoin/' ],
                'auth-welcome-email' => [ 'desc' => 'Within 60 seconds of a successful paid signup, check your inbox.',                                                            'expect' => 'Welcome email arrives, lands in inbox (not spam), images load, [TEST] prefix is NOT present (real send, not dry-run).' ],
                'auth-existing-right' => [ 'desc' => 'Sign up at /lgjoin/ with an existing account email and the correct password.',                                                'expect' => 'Auth succeeds before Stripe, checkout proceeds, lands on /activity/ already logged in.', 'url' => '/lgjoin/' ],
                'auth-existing-wrong' => [ 'desc' => 'Sign up at /lgjoin/ with an existing account email and a wrong password.',                                                    'expect' => 'Existing-account modal appears (NOT a fresh-checkout flow). "Forgot?" link goes to wp-login.php?action=lostpassword.', 'url' => '/lgjoin/' ],
                'auth-throttle-email' => [ 'desc' => 'Submit /wp-json/lg-member-sync/v1/auth six times against the same email with wrong passwords.',                              'expect' => '6th attempt gets HTTP 429 with "Too many failed attempts for this account" and rate_limited:true. Even the correct password is blocked until 15 min elapse.' ],
                'auth-throttle-ip' => [ 'desc' => 'Submit /wp-json/lg-member-sync/v1/auth twenty-one times rapidly from the same IP (different emails).',                          'expect' => '21st request gets HTTP 429 with "Too many attempts. Please wait an hour" and rate_limited:true.' ],
            ],
        ],

        'subscribe' => [
            'title' => 'Subscribe → checkout → return',
            'items' => [
                'sub-lite-monthly' => [ 'desc' => 'Choose Looth LITE monthly on /lgjoin/ and complete checkout.',                                                                'expect' => 'After return, looth2 role is assigned, /activity/ accessible, Welcome modal fires once.', 'url' => '/lgjoin/' ],
                'sub-pro-annual'   => [ 'desc' => 'Choose Looth PRO annual on /lgjoin/ and complete checkout.',                                                                  'expect' => 'After return, looth3 role assigned, /activity/ accessible, sub status active in /manage-subscription/.', 'url' => '/lgjoin/' ],
                'sub-promo'        => [ 'desc' => 'Apply promo code PATREON5 in checkout.',                                                                                     'expect' => '5% discount visible on the Stripe modal before payment.' ],
                'sub-spam'         => [ 'desc' => 'Click the Pay button rapidly multiple times.',                                                                               'expect' => 'Only one Stripe Checkout session is created (verify in Stripe Dashboard or by watching network calls).' ],
                'sub-no-leave'     => [ 'desc' => 'Open the Stripe checkout modal, then close it without paying.',                                                              'expect' => 'No "Leave site?" browser prompt is shown.' ],
                'sub-regional-block' => [ 'desc' => 'Use a card from a country not eligible for regional pricing while on a regional product.',                                  'expect' => 'Returned to the regional-fail page with a clear "not eligible" message.' ],
            ],
        ],

        'manage' => [
            'title' => 'Manage subscription (/manage-subscription/)',
            'items' => [
                'mgr-render'            => [ 'desc' => 'Visit /manage-subscription/ as a paid member.',                                                                          'expect' => 'Plan name, next charge date, default payment method last-4, and invoice list all render.', 'url' => '/manage-subscription/' ],
                'mgr-cancel-period-end' => [ 'desc' => 'Cancel the subscription with timing = "at period end".',                                                                  'expect' => 'Confirmation message shows. Email arrives. Role + access remain through current_period_end.' ],
                'mgr-cancel-immediate'  => [ 'desc' => 'Cancel the subscription with timing = "immediate".',                                                                     'expect' => 'Access revoked right away. Role downgraded. Email confirms.' ],
                'mgr-switch-up-now'     => [ 'desc' => 'Switch plan up (LITE → PRO) with timing = "now".',                                                                       'expect' => 'Proration shown by Stripe. Role updates within seconds (sync trigger).' ],
                'mgr-switch-period-end' => [ 'desc' => 'Switch plan with timing = "period end".',                                                                                'expect' => 'Subscription update is scheduled. Confirmation message references the renewal date.' ],
                'mgr-switch-pastdue'    => [ 'desc' => 'Try to switch plans on a subscription whose status is past_due.',                                                        'expect' => 'HTTP 409 returned with "Your subscription has a payment issue right now" message. No Stripe write fires.' ],
                'mgr-cooldown'          => [ 'desc' => 'After a successful switch, attempt another switch within 24 hours.',                                                     'expect' => 'Friendly cooldown error returned. Underlying option lgms_plan_switch_cooldown_hours can be tuned.' ],
                'mgr-add-pm'            => [ 'desc' => 'Add a new payment method via the form on /manage-subscription/.',                                                        'expect' => 'New card appears in the payment-methods list. SetupIntent succeeds.' ],
                'mgr-set-default'       => [ 'desc' => 'Set the new card as the default payment method.',                                                                        'expect' => 'is_default flag flips on the new card; Stripe customer\'s invoice_settings.default_payment_method updated.' ],
                'mgr-delete-pm'         => [ 'desc' => 'Delete a non-default payment method.',                                                                                   'expect' => 'PM disappears from list; Stripe detachPaymentMethod fired.' ],
                'mgr-delete-only'       => [ 'desc' => 'Try to delete the only remaining payment method while you have an active sub.',                                          'expect' => 'Blocked with "You cannot remove your only payment method" 400. PM stays attached.' ],
                'mgr-invoices'          => [ 'desc' => 'View invoices on /manage-subscription/.',                                                                                'expect' => 'Up to 24 recent invoices render with PDF download + hosted-invoice URL links.' ],
                'mgr-idor'              => [ 'desc' => 'Logged in as user A, POST to /me/cancel-subscription with user B\'s sub_id (open DevTools).',                            'expect' => 'HTTP 403 "Subscription not found or not yours". User B\'s sub is unaffected.' ],
            ],
        ],

        'gift-buy' => [
            'title' => 'Gift purchase (/lggift-buy/)',
            'items' => [
                'gb-anon'      => [ 'desc' => 'Buy gift codes anonymously (don\'t sign in first).',                                                                              'expect' => 'Acknowledgment modal forces explicit consent. After payment, codes are emailed to the buyer; success modal stays in-place.', 'url' => '/lggift-buy/' ],
                'gb-managed'   => [ 'desc' => 'Buy gift codes via the "managed" path (creates / signs into a buyer account).',                                                   'expect' => 'After payment, /my-gifts/ dashboard renders with Unsent codes. Cookie set before Stripe iframe loaded.', 'url' => '/lggift-buy/' ],
                'gb-qty-min'   => [ 'desc' => 'Open DevTools and try to submit /v1/checkout with quantity=1.',                                                                   'expect' => 'Server rejects (Slim CheckoutController enforces quantity >= 2 for gift mode).' ],
                'gb-bulk-10'   => [ 'desc' => 'Buy 10 gift codes.',                                                                                                              'expect' => '10% bulk discount applied (per BULK_DISCOUNT_TIERS env). Total = 10 × tier_price × 0.9.' ],
                'gb-bulk-20'   => [ 'desc' => 'Buy 20 gift codes.',                                                                                                              'expect' => '20% bulk discount applied.' ],
                'gb-bulk-50'   => [ 'desc' => 'Buy 50 gift codes.',                                                                                                              'expect' => '30% bulk discount applied.' ],
                'gb-spam'      => [ 'desc' => 'Click Pay rapidly on the gift form.',                                                                                             'expect' => 'Only one Stripe session created. checkoutInProgress flag works.' ],
            ],
        ],

        'my-gifts' => [
            'title' => 'Gift dashboard (/my-gifts/)',
            'items' => [
                'mg-buckets'  => [ 'desc' => 'Visit /my-gifts/ as a buyer.',                                                                                                     'expect' => 'Unsent / Sent / Redeemed / Voided buckets render with the right code counts.', 'url' => '/my-gifts/' ],
                'mg-send'     => [ 'desc' => 'Send an Unsent code: enter recipient email + name + (optional) message.',                                                          'expect' => 'Email arrives at recipient. Code moves to Sent bucket. email_sent_at stamped.' ],
                'mg-resend'   => [ 'desc' => 'Resend an already-sent code.',                                                                                                     'expect' => 'Recipient email fires again. Stays in Sent bucket.' ],
                'mg-reassign' => [ 'desc' => 'Reassign a Sent (un-redeemed) code to a different recipient.',                                                                     'expect' => 'recipient_email + name updated. Old recipient can no longer redeem (server-side stapled email check).' ],
                'mg-void'     => [ 'desc' => 'Void an Unsent code.',                                                                                                             'expect' => 'Buyer is partially refunded for that code. Code moves to Voided bucket.' ],
                'mg-oops'     => [ 'desc' => 'Visit /my-gifts/?for=someone-else@example.com while logged in as a different email.',                                              'expect' => '"You\'re signed in as the wrong account" gate renders. Sign-out CTA visible. No buyer data leaks.' ],
                'mg-idor'     => [ 'desc' => 'Logged in as user A, POST to /me/gift-void with user B\'s code_id (DevTools).',                                                    'expect' => 'HTTP 403 "Code not found or not yours". User B\'s code unchanged.' ],
            ],
        ],

        'redeem' => [
            'title' => 'Gift redemption (/lggift/)',
            'items' => [
                'rd-new'        => [ 'desc' => 'Redeem a code as a new (no-account) recipient.',                                                                                  'expect' => 'Create-account variant renders with Name + 8-char password fields. After redemption, lands on /activity/ logged in.', 'url' => '/lggift/' ],
                'rd-signin'     => [ 'desc' => 'Redeem a code where the recipient email is already a WP user but you\'re logged out.',                                            'expect' => 'Sign-in variant: green banner, no Name field, "Sign in & redeem" button. After auth, page reloads and redemption confirms.' ],
                'rd-wrong-user' => [ 'desc' => 'Log in as a different account, then visit /lggift/?code=XXX where XXX\'s recipient is someone else.',                              'expect' => 'Wrong-user red banner, sign-out button. Redemption form NOT rendered.' ],
                'rd-stapled'    => [ 'desc' => 'In DevTools, modify the email field of a code with recipient_email set, then submit.',                                            'expect' => 'Server overrides with the stapled email. Entitlement granted to recipient_email regardless of what was POSTed.' ],
                'rd-conflict'   => [ 'desc' => 'Logged in as a paid member, try to redeem a gift.',                                                                                'expect' => 'Tier-conflict picker renders (Stacked vs Prorated). Selection persists through /v1/redeem.' ],
                'rd-active-gift'=> [ 'desc' => 'On /lgjoin/ as someone with an active gift entitlement, attempt to subscribe.',                                                    'expect' => 'Active-gift confirmation modal appears with "you have N days left from your gift" + "I understand" checkbox.' ],
            ],
        ],

        'guide' => [
            'title' => 'Membership Guide (/membership-guide/)',
            'items' => [
                'mg-anon'         => [ 'desc' => 'Visit /membership-guide/ logged out.',                                                                                          'expect' => 'Visitor-state hero, anon preview cards visible in Archive, gated CTAs (Loothalong shows "See the plans →"). No Start Here section.', 'url' => '/membership-guide/' ],
                'mg-member'       => [ 'desc' => 'Visit /membership-guide/ as a paid member.',                                                                                    'expect' => 'Start Here section visible (if starter content exists). Loothalong shows the Zoom link if configured. All sections render.', 'url' => '/membership-guide/' ],
                'mg-admin-bar'    => [ 'desc' => 'Visit /membership-guide/ as a WP admin.',                                                                                       'expect' => 'Fixed admin preview bar visible top-right with Visitor / Member toggle and the WELCOME EMAIL test form.', 'url' => '/membership-guide/', 'audience' => 'admins' ],
                'mg-toggle'       => [ 'desc' => 'In the admin preview bar, click Visitor and Member buttons.',                                                                  'expect' => 'Body class flips between lgms-mg-anon / lgms-mg-member. Loothalong gating updates client-side.', 'audience' => 'admins' ],
                'mg-test-email'   => [ 'desc' => 'In the admin preview bar, enter an email and click Send test.',                                                                'expect' => 'Status line shows "Test email sent to ...". Email arrives with [TEST] subject prefix and matches the live page visually.', 'audience' => 'admins' ],
                'mg-avatar-override' => [ 'desc' => 'Edit an elder via the front-end edit modal, paste a URL into "Avatar Override URL", save, reload.',                          'expect' => 'Elder card now shows the override URL\'s image. NOT the BuddyBoss avatar.', 'audience' => 'admins' ],
                'mg-shows'        => [ 'desc' => 'Confirm Recurring Shows section renders.',                                                                                     'expect' => 'Configured shows appear with thumbnails. Empty config = no slider rendered.' ],
                'mg-events'       => [ 'desc' => 'Confirm Live Events shortcode renders next 4 events.',                                                                         'expect' => 'Cards with date pill + title + thumbnail. Fallback "Recent shows" appears if no upcoming events exist.' ],
                'mg-elders'       => [ 'desc' => 'Confirm Council of Elders slider renders.',                                                                                    'expect' => 'Avatars + names + IG link per card. View bio links to /elder-NAME/ pages.' ],
                'mg-loothalong'   => [ 'desc' => 'Confirm Loothalong section gating.',                                                                                           'expect' => 'Anon: "See the plans →". Member with URL configured: "Join the room →". Member without URL: "URL not yet configured".' ],
            ],
        ],

        'admin' => [
            'title'    => 'Admin tools (wp-admin)',
            'audience' => 'admins',
            'items' => [
                'ad-user-edit'   => [ 'desc' => 'In /wp-admin/users.php, edit a customer with an active subscription.',                                                          'expect' => 'Membership section at the bottom shows the subscription. Cancel & Refund + Block buttons present.' ],
                'ad-cancel'      => [ 'desc' => 'Use the admin "Cancel & Refund" button on the user-edit page.',                                                                 'expect' => 'Subscription canceled in Stripe. Refund processed. Audit log row written. Customer email confirms.' ],
                'ad-block'       => [ 'desc' => 'Use the admin "Block" button on the user-edit page.',                                                                           'expect' => 'is_blocked flag set on customer record. Future redemptions / signups for that email are rejected.' ],
                'ad-pages-sync'  => [ 'desc' => 'In Settings → LG Member Sync, click "Re-create / sync membership pages".',                                                      'expect' => 'Missing pages get created with their shortcodes. BuddyBoss public-content allowlist updates.' ],
                'ad-mosaic'      => [ 'desc' => 'In Settings → LG Member Sync (welcome mosaic), pick attachments and save.',                                                     'expect' => 'lgms_welcome_mosaic_ids option saved. Welcome email mosaic now shows those images.' ],
                'ad-loothalong'  => [ 'desc' => 'In Settings → LG Member Sync, set the Loothalong Zoom URL and save.',                                                           'expect' => 'lgms_guide_loothalong_url option saved. /membership-guide/ for members shows the live link.' ],
                'ad-affiliate'   => [ 'desc' => 'Affiliate dashboard (admin) lists all affiliates with click + conversion + commission columns.',                                'expect' => 'Counts match Stripe + DB. Editing commission_pct updates the row.' ],
                'ad-audit-log'   => [ 'desc' => 'On a customer\'s user-edit page, scroll to the audit log section.',                                                              'expect' => 'Recent actions appear (cancel, refund, block, self-cancel, self-switch, self-set-default-pm, self-remove-pm, self-gift-*).' ],
            ],
        ],

        'refund' => [
            'title' => 'Refund request (/request-refund/)',
            'items' => [
                'rf-form'      => [ 'desc' => 'Submit the form with valid name + email + at least one reason + (optional) item selection.',                                       'expect' => 'Admin email arrives within ~30s with subscription details, eligibility window, and a "Open in WP admin" link.', 'url' => '/request-refund/' ],
                'rf-throttle'  => [ 'desc' => 'Submit the form 6 times in a row from the same IP.',                                                                              'expect' => '6th submission returns {"ok":true} with no email sent (silent throttle). Latency drops by ~200ms on throttled calls.' ],
                'rf-honeypot'  => [ 'desc' => 'In DevTools, fill the hidden "website" field and submit.',                                                                        'expect' => 'Form returns {"ok":true} but no admin email arrives.' ],
                'rf-window'    => [ 'desc' => 'Submit a refund for a subscription that\'s within the refund window vs outside it.',                                              'expect' => 'Admin email shows green "within X-day window" or red "outside window" tag per item.' ],
            ],
        ],

        'email' => [
            'title' => 'Email deliverability',
            'items' => [
                'em-welcome-gmail'   => [ 'desc' => 'Welcome email lands in Gmail (web + mobile).',                                                                              'expect' => 'Inbox tab (not Promotions / Spam). Images load when "Show images" is clicked. Renders without horizontal scroll on mobile.' ],
                'em-welcome-outlook' => [ 'desc' => 'Welcome email lands in Outlook (desktop / web).',                                                                          'expect' => 'Inbox folder. Tables render correctly. Buttons clickable.' ],
                'em-welcome-apple'   => [ 'desc' => 'Welcome email lands in Apple Mail (macOS / iOS).',                                                                          'expect' => 'Renders correctly. Dark mode acceptable.' ],
                'em-refund-admin'    => [ 'desc' => 'Refund-request admin email arrives at the configured admin inbox.',                                                        'expect' => 'Reply-To header points to the customer\'s email so admins can reply directly.' ],
                'em-gift-recipient'  => [ 'desc' => 'Gift recipient email arrives at the configured recipient.',                                                                'expect' => 'Code visible. Redeem CTA button works. Branding consistent with welcome email.' ],
                'em-gift-buyer'      => [ 'desc' => 'Gift-buyer dashboard summary email lands.',                                                                                 'expect' => '"View my gifts" CTA links to /my-gifts/?for=buyer-email. Codes are listed.' ],
                'em-payment-failed'  => [ 'desc' => '(Synthetic) Trigger an invoice.payment_failed event for a test customer.',                                                  'expect' => 'Customer receives the "Action needed" email with personalized greeting and update-payment-method link.' ],
            ],
        ],

        'roles' => [
            'title' => 'Roles & BuddyBoss lockdown',
            'items' => [
                'rl-customer-hidden' => [ 'desc' => 'Log in as a customer-only user (gift-only buyer, no paid sub).',                                                            'expect' => 'No avatar in BB site chrome. Not in /members/ directory. Cannot post or reply in forums.' ],
                'rl-sticky'           => [ 'desc' => 'A customer-only user later subscribes to a paid tier.',                                                                    'expect' => 'User has both customer + looth tier roles. Forum + directory access enabled. customer cap remains.' ],
                'rl-looth4-protect'   => [ 'desc' => 'Confirm a looth4 user does not get downgraded by the Arbiter on tick.',                                                    'expect' => 'looth4 role + caps remain after Tick::run. lg_role_sources rows for that user are still respected.', 'audience' => 'admins' ],
                'rl-bb-allowlist'     => [ 'desc' => 'Anon visit each public Pages registry page (/lgjoin/, /lggift-buy/, /lggift/, /membership-guide/, /request-refund/).',     'expect' => 'All render without redirecting to wp-login.php?bp-auth=1. (BuddyBoss public-content allowlist auto-populated.)' ],
            ],
        ],

        'cron' => [
            'title'    => 'Cron / polling / webhooks',
            'audience' => 'admins',
            'items' => [
                'cr-tick-manual'    => [ 'desc' => 'Trigger Tick::run via /run-now (or wp cron event run lgms_poll_tick).',                                                       'expect' => 'tick.log shows: tick start → stripe poll → expiry sweep → reconcile-pending → sync sweep ok=N errors=0.' ],
                'cr-tick-lock'      => [ 'desc' => 'Fire two concurrent /run-now calls.',                                                                                       'expect' => 'tick.log shows one "tick start" and one "tick SKIPPED: another tick is already running".' ],
                'cr-dup-detect'     => [ 'desc' => 'Inspect lg_processed_events table after a couple ticks (SELECT COUNT(*), SUM(dup_count > 0)).',                              'expect' => 'Row per processed event_id. dup_count > 0 only if Stripe redelivered or a tick crashed mid-batch.' ],
                'cr-webhook-sig'    => [ 'desc' => 'POST a malformed payload (or wrong signature) to Slim\'s /v1/webhook.',                                                      'expect' => 'HTTP 400 returned. Stripe SDK signature verification rejects the request before any handler runs.' ],
                'cr-reconcile'      => [ 'desc' => 'Trigger /v1/reconcile-pending on Slim with a valid X-LGMS-Token.',                                                           'expect' => '{"ok":true,"stats":{"examined":N,"recovered":M,...}}. Without the token: 401.' ],
            ],
        ],

        'security' => [
            'title'    => 'Security smoke tests (post-audit)',
            'audience' => 'admins',
            'items' => [
                'sec-prod-error'      => [ 'desc' => '(On prod only) Hit a deliberately-broken Slim URL.',                                                                       'expect' => 'Generic 500 with no stack trace, file paths, or SQL. Confirms APP_DEBUG is off.' ],
                'sec-debug-display'   => [ 'desc' => '(On prod only) Trigger a PHP notice/warning anywhere on the site.',                                                       'expect' => 'No errors render to the browser. WP_DEBUG_DISPLAY is false.' ],
                'sec-pm-idor'         => [ 'desc' => 'Logged in as A, POST to /me/set-default-payment-method with B\'s pm_id.',                                                  'expect' => 'HTTP 404 "Payment method not found on your account". B\'s default unchanged.' ],
                'sec-affiliate-auth'  => [ 'desc' => 'curl Slim /v1/affiliates without X-LGMS-Token.',                                                                            'expect' => '401 Unauthorized. With the right token: 200 + JSON.' ],
                'sec-rest-no-secret'  => [ 'desc' => 'curl WP /wp-json/lg-member-sync/v1/run-now without X-LGMS-Token.',                                                         'expect' => '403 / no run. With the secret: 200 ok.' ],
                'sec-cf-ip'           => [ 'desc' => 'Confirm rate limits identify users by HTTP_CF_CONNECTING_IP, not REMOTE_ADDR.',                                            'expect' => 'Behind Cloudflare, throttle counters key off the real client IP, not the CF edge.' ],
            ],
        ],
    ];

    /**
     * Wrap any /path/ or http(s)://… reference inside the supplied text in
     * an <a> tag so testers can click straight through. Relative paths get
     * routed through home_url(); absolute URLs are linked as-is.
     */
    private static function linkifyText( string $text ): string
    {
        $escaped = esc_html( $text );
        // URLs grab everything up to whitespace (greedy). Relative paths are
        // tighter — letter-led, path-safe chars only. Trailing sentence
        // punctuation is trimmed from the link target back into the
        // surrounding text so "Visit /lgjoin/." links just /lgjoin/ and the
        // period reads naturally.
        return (string) preg_replace_callback(
            '#(?<![A-Za-z0-9/])(https?://\S+|/[a-zA-Z][\w./?=&@-]*[A-Za-z0-9/])#',
            static function ( array $m ): string {
                $token   = (string) $m[1];
                $trimmed = rtrim( $token, '.,;:!?)' );
                $tail    = substr( $token, strlen( $trimmed ) );
                $href    = strpos( $trimmed, 'http' ) === 0 ? $trimmed : home_url( $trimmed );
                return '<a href="' . esc_url( $href )
                     . '" target="_blank" rel="noopener" style="color:#87986A;font-weight:600;text-decoration:underline;">'
                     . esc_html( $trimmed ) . '</a>'
                     . esc_html( $tail );
            },
            $escaped
        );
    }

    public static function render(): string
    {
        // Admins auto-bypass the WP post-password gate, so they never see the
        // password they need to share with testers. Surface it here as an
        // admin-only callout so it can be copied without leaving the page.
        $pagePassword = '';
        if ( current_user_can( 'manage_options' ) ) {
            $postId = (int) get_the_ID();
            if ( $postId > 0 ) {
                $pagePassword = (string) get_post_field( 'post_password', $postId );
            }
        }

        ob_start();
        ?>
        <div class="lgtc">
            <header class="lgtc-head">
                <h1>QA Test Checklist</h1>
                <p class="lgtc-lede">Walk through every flow before prod cutover. Checkboxes save in your browser's local storage &mdash; close and come back later, your progress is preserved. Each tester tracks their own progress; nothing syncs across browsers.</p>
                <div class="lgtc-controls">
                    <span class="lgtc-progress" id="lgtc-progress">0 of 0 checked</span>
                    <label class="lgtc-toggle"><input type="checkbox" id="lgtc-hide-checked"> Hide passed</label>
                    <button type="button" id="lgtc-reset" class="lgtc-btn lgtc-btn-danger">Reset all</button>
                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                        <span class="lgtc-view-toggle" role="group" aria-label="View as">
                            View as
                            <button type="button" id="lgtc-view-admin"  class="lgtc-vt-btn is-active">Admin</button>
                            <button type="button" id="lgtc-view-tester" class="lgtc-vt-btn">Tester</button>
                        </span>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ( $pagePassword !== '' && current_user_can( 'manage_options' ) ) : ?>
                <section class="lgtc-pwd">
                    <span class="lgtc-pwd-label">Page password (share with testers)</span>
                    <input type="text" id="lgtc-pwd-value" value="<?php echo esc_attr( $pagePassword ); ?>" readonly>
                    <button type="button" id="lgtc-pwd-copy" class="lgtc-btn">Copy</button>
                    <span class="lgtc-pwd-hint">Admins bypass this automatically; testers need it to view the page.</span>
                </section>
            <?php elseif ( current_user_can( 'manage_options' ) ) : ?>
                <section class="lgtc-pwd lgtc-pwd-unset">
                    <span class="lgtc-pwd-label">No page password set</span>
                    <span class="lgtc-pwd-hint">Anyone with the URL can view. Set one in wp-admin → Pages → Test Checklist → "Password protected".</span>
                </section>
            <?php endif; ?>

            <?php if ( current_user_can( 'manage_options' ) ) :
                $allFb  = self::fetchFeedback();
                $openFb = array_values( array_filter( $allFb, fn( $r ) => ( $r['status'] ?? '' ) === 'open' ) );
            ?>
                <section class="lgtc-inbox">
                    <header class="lgtc-inbox-head">
                        <h2>Feedback inbox <span class="lgtc-inbox-count"><?php echo count( $openFb ); ?> open · <?php echo count( $allFb ); ?> total</span></h2>
                        <div class="lgtc-inbox-actions">
                            <span class="lgtc-inbox-filter" role="group" aria-label="Filter by severity">
                                <button type="button" class="lgtc-fbtn is-active" data-fb-filter="all">All</button>
                                <button type="button" class="lgtc-fbtn" data-fb-filter="fail">Fails</button>
                                <button type="button" class="lgtc-fbtn" data-fb-filter="question">Qs</button>
                                <button type="button" class="lgtc-fbtn" data-fb-filter="pass">Passes</button>
                            </span>
                            <label class="lgtc-toggle"><input type="checkbox" id="lgtc-inbox-show-closed"> Show closed</label>
                            <button type="button" id="lgtc-inbox-copy" class="lgtc-btn">Copy as Markdown</button>
                        </div>
                    </header>
                    <?php if ( ! $allFb ) : ?>
                        <p class="lgtc-inbox-empty">No feedback yet. Testers' submissions land here.</p>
                    <?php else : ?>
                        <ul class="lgtc-inbox-list" id="lgtc-inbox-list">
                            <?php foreach ( $allFb as $fb ) :
                                $sev    = (string) ( $fb['severity'] ?? 'note' );
                                $status = (string) ( $fb['status']   ?? 'open' );
                                $sevDef = self::SEVERITY[ $sev ] ?? self::SEVERITY['note'];
                                $label  = self::itemLabel( (string) $fb['item_id'] );
                                $isOpen = $status === 'open';
                            ?>
                            <li class="lgtc-inbox-item lgtc-inbox-status-<?php echo esc_attr( $status ); ?>" data-fb-id="<?php echo (int) $fb['id']; ?>" data-fb-status="<?php echo esc_attr( $status ); ?>" data-fb-severity="<?php echo esc_attr( $sev ); ?>">
                                <div class="lgtc-inbox-meta">
                                    <span class="lgtc-inbox-sev" style="background:<?php echo esc_attr( $sevDef['color'] ); ?>;"><?php echo esc_html( $sevDef['label'] ); ?></span>
                                    <span class="lgtc-inbox-status"><?php echo esc_html( strtoupper( $status ) ); ?></span>
                                    <strong class="lgtc-inbox-who"><?php echo esc_html( (string) $fb['tester_name'] ); ?></strong>
                                    <span class="lgtc-inbox-when"><?php echo esc_html( (string) $fb['created_at'] ); ?></span>
                                </div>
                                <div class="lgtc-inbox-where">
                                    <span class="lgtc-inbox-section"><?php echo esc_html( $label['section'] ); ?></span>
                                    <span class="lgtc-inbox-desc"><?php echo esc_html( $label['desc'] ); ?></span>
                                </div>
                                <p class="lgtc-inbox-body"><?php echo nl2br( esc_html( (string) $fb['body'] ) ); ?></p>
                                <div class="lgtc-inbox-controls">
                                    <button type="button" class="lgtc-btn"        data-fb-set="open">Open</button>
                                    <button type="button" class="lgtc-btn"        data-fb-set="fixed">Fixed</button>
                                    <button type="button" class="lgtc-btn"        data-fb-set="wontfix">Won't fix</button>
                                    <button type="button" class="lgtc-btn lgtc-btn-danger" data-fb-set="delete">Delete</button>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- TIPS FOR TESTERS -->
            <section class="lgtc-tips">
                <h2>Tips for testers</h2>
                <ul>
                    <li><strong>Don't burn 20 inboxes.</strong> Use Gmail <code>+</code>-aliases: <code>you+l1@gmail.com</code>, <code>you+gift1@gmail.com</code>, <code>you+sub2@gmail.com</code> &mdash; everything before the <code>+</code> is your real account; everything after is ignored by Gmail but treated as a distinct address by WordPress, Stripe, and us. All emails arrive in <code>you@gmail.com</code>'s inbox.</li>
                    <li>Same trick works in <strong>Outlook / Hotmail / iCloud / Fastmail / ProtonMail</strong>. Doesn't work in AOL.</li>
                    <li>For tests that need <em>different physical inboxes</em> (e.g. "send a gift to someone else"), Gmail+aliases still work &mdash; the recipient inbox is the same Gmail account, you'll just see "to: you+gift-recipient@gmail.com" in the To: header.</li>
                    <li>Need to re-run the same flow on the same email? Use the wipe tool below to delete that email's WP user, customer record, subscriptions, gift codes, BuddyBoss footprint, and audit-log rows. Then sign up again from scratch.</li>
                </ul>
            </section>

            <!-- WIPE TESTER EMAIL -->
            <section class="lgtc-wipe">
                <h2>Wipe a tester email</h2>
                <p class="lgtc-wipe-lede">Removes the tester's WP user + every linked record across the membership stack. Use this between test runs so you don't need a fresh <code>+alias</code> every time. <strong>Destructive &mdash; can't be undone.</strong></p>
                <div class="lgtc-wipe-row">
                    <input type="email" id="lgtc-wipe-email" placeholder="tester+l1@gmail.com" autocomplete="off">
                    <button type="button" id="lgtc-wipe-preview" class="lgtc-btn">Preview</button>
                    <button type="button" id="lgtc-wipe-perform" class="lgtc-btn lgtc-btn-danger" disabled>Wipe it</button>
                </div>
                <div id="lgtc-wipe-status" class="lgtc-wipe-status" aria-live="polite"></div>
            </section>

            <?php foreach ( self::SECTIONS as $sectionId => $section ) :
                $sectionAdminOnly = ( ( $section['audience'] ?? 'all' ) === 'admins' );
                $sectionClasses   = 'lgtc-section' . ( $sectionAdminOnly ? ' lgtc-admin-only' : '' );
            ?>
                <section class="<?php echo esc_attr( $sectionClasses ); ?>" data-section="<?php echo esc_attr( $sectionId ); ?>">
                    <h2><?php echo esc_html( (string) $section['title'] ); ?>
                        <?php if ( $sectionAdminOnly ) : ?><span class="lgtc-admin-badge">admin only</span><?php endif; ?>
                        <span class="lgtc-section-progress" data-section-progress="<?php echo esc_attr( $sectionId ); ?>"></span>
                    </h2>
                    <ol class="lgtc-items">
                        <?php foreach ( (array) ( $section['items'] ?? [] ) as $itemId => $item ) :
                            $fullId        = $sectionId . ':' . $itemId;
                            $url           = (string) ( $item['url'] ?? '' );
                            $itemAdminOnly = ( ( $item['audience'] ?? 'all' ) === 'admins' );
                            $itemClasses   = 'lgtc-item' . ( $itemAdminOnly ? ' lgtc-admin-only' : '' );
                        ?>
                        <li class="<?php echo esc_attr( $itemClasses ); ?>" data-item-id="<?php echo esc_attr( $fullId ); ?>">
                            <div class="lgtc-body">
                                <p class="lgtc-desc"><?php echo self::linkifyText( (string) ( $item['desc'] ?? '' ) ); ?></p>
                                <p class="lgtc-expect"><strong>Expect:</strong> <?php echo self::linkifyText( (string) ( $item['expect'] ?? '' ) ); ?></p>
                                <?php if ( $url !== '' ) : ?>
                                    <p class="lgtc-link"><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?> &rarr;</a></p>
                                <?php endif; ?>
                                <div class="lgtc-report">
                                    <span class="lgtc-report-state" data-state-for="<?php echo esc_attr( $fullId ); ?>"></span>
                                    <button type="button" class="lgtc-btn lgtc-btn-report" data-report-trigger="<?php echo esc_attr( $fullId ); ?>">Report result</button>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endforeach; ?>

            <!-- Shared report modal. Populated by JS when a Report-result button is clicked. -->
            <div class="lgtc-modal" id="lgtc-report-modal" role="dialog" aria-modal="true" aria-labelledby="lgtc-modal-title" hidden>
                <div class="lgtc-modal__backdrop" data-modal-close></div>
                <div class="lgtc-modal__card">
                    <button type="button" class="lgtc-modal__x" data-modal-close aria-label="Close">&times;</button>
                    <h3 id="lgtc-modal-title" class="lgtc-modal__title">Report result</h3>
                    <p class="lgtc-modal__where" id="lgtc-modal-where"></p>
                    <p class="lgtc-modal__desc"  id="lgtc-modal-desc"></p>
                    <form class="lgtc-modal__form" id="lgtc-modal-form">
                        <div class="lgtc-modal__sev">
                            <button type="button" class="lgtc-sev-btn lgtc-sev-pass"     data-sev="pass">✓ Passed</button>
                            <button type="button" class="lgtc-sev-btn lgtc-sev-fail"     data-sev="fail">✗ Failed</button>
                            <button type="button" class="lgtc-sev-btn lgtc-sev-question" data-sev="question">? Question</button>
                        </div>
                        <input type="text" id="lgtc-modal-name" placeholder="Your name (saved in this browser)" maxlength="120">
                        <label class="lgtc-modal__bodylabel" for="lgtc-modal-body">
                            <span id="lgtc-modal-bodylabel">Optional notes</span>
                        </label>
                        <textarea id="lgtc-modal-body" rows="4" maxlength="4000" placeholder="Anything you want recorded with this result. Required for Failed and Question."></textarea>
                        <div class="lgtc-modal__actions">
                            <button type="submit" class="lgtc-btn" id="lgtc-modal-submit" disabled>Submit</button>
                            <button type="button" class="lgtc-btn lgtc-btn-cancel" data-modal-close>Cancel</button>
                            <span class="lgtc-fb-status" id="lgtc-modal-status"></span>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <style>
            .lgtc { --cream:#FAF6EE; --sand:#EAE5DC; --bg:#e8e2d8; --dark:#2B2318; --ink:#5C4E3A; --amber:#ECB351; --amber-d:#C68A1E; --green:#87986A; --green-l:#D4E0B8; --red:#b04a3c; }
            .lgtc { max-width: 920px; margin: 0 auto; padding: 24px 16px 80px; font-family: Arial, Helvetica, sans-serif; color: var(--ink); }
            .lgtc-head { background: var(--dark); color: var(--cream); padding: 28px 32px; border-radius: 8px 8px 0 0; }
            .lgtc-head h1 { margin: 0 0 6px; font-family: Georgia, serif; font-size: 28px; color: var(--amber); font-weight: 700; }
            .lgtc-lede { margin: 0 0 18px; font-size: 14px; line-height: 1.6; color: #d8cfc0; }
            .lgtc-controls { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; font-size: 13px; }
            .lgtc-progress { background: rgba(236,179,81,0.18); border: 1px solid var(--amber); padding: 4px 12px; border-radius: 14px; color: var(--amber); font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; font-size: 11px; }
            .lgtc-toggle { color: #d8cfc0; cursor: pointer; user-select: none; }
            .lgtc-toggle input { margin-right: 6px; }
            .lgtc-btn { background: transparent; border: 1px solid #87986A; color: var(--cream); padding: 5px 14px; border-radius: 4px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; cursor: pointer; font-weight: 700; }
            .lgtc-btn:hover { background: #87986A; }
            .lgtc-btn-danger { border-color: var(--red); color: #f1c8c1; }
            .lgtc-btn-danger:hover { background: var(--red); color: #fff; }
            .lgtc-section { background: var(--cream); border: 1px solid var(--sand); border-top: 0; padding: 18px 28px 22px; }
            .lgtc-section:last-of-type { border-radius: 0 0 8px 8px; }
            .lgtc-section h2 { font-family: Georgia, serif; font-size: 19px; color: var(--dark); margin: 0 0 12px; padding-bottom: 8px; border-bottom: 1px solid var(--sand); display: flex; align-items: baseline; gap: 12px; }
            .lgtc-section-progress { font-family: Arial, sans-serif; font-size: 11px; color: var(--amber-d); font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
            .lgtc-items { list-style: none; padding: 0; margin: 0; counter-reset: lgtc-item; }
            .lgtc-item { display: flex; gap: 14px; padding: 12px 0; border-bottom: 1px dashed var(--sand); align-items: flex-start; counter-increment: lgtc-item; position: relative; }
            .lgtc-item:last-child { border-bottom: 0; }
            .lgtc-item::before { content: counter(lgtc-item) "."; flex: 0 0 auto; min-width: 22px; font-weight: 700; color: var(--amber-d); font-size: 13px; padding-top: 4px; text-align: right; }
            .lgtc-body { flex: 1 1 auto; min-width: 0; }
            .lgtc-desc { margin: 0 0 4px; font-size: 14px; color: var(--dark); line-height: 1.5; font-weight: 600; }
            .lgtc-expect { margin: 0 0 4px; font-size: 13px; color: var(--ink); line-height: 1.5; }
            .lgtc-expect strong { color: var(--amber-d); text-transform: uppercase; letter-spacing: 0.06em; font-size: 11px; }
            .lgtc-link { margin: 4px 0 0; font-size: 12px; }
            .lgtc-link a { color: var(--green); text-decoration: none; font-weight: 700; }
            .lgtc-link a:hover { text-decoration: underline; }
            /* Per-item report controls + state pill */
            .lgtc-report { margin-top: 8px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
            .lgtc-btn-report { background: var(--dark); color: var(--cream); border: 1px solid var(--dark); padding: 4px 14px; font-size: 11px; }
            .lgtc-btn-report:hover { background: var(--ink); }
            .lgtc-report-state { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; padding: 3px 10px; border-radius: 10px; color: #fff; }
            .lgtc-report-state[data-state=""] { display: none; }
            .lgtc-report-state[data-state="pass"]     { background: #87986A; }
            .lgtc-report-state[data-state="fail"]     { background: #b04a3c; }
            .lgtc-report-state[data-state="question"] { background: #C68A1E; }
            .lgtc-item.is-pass     { opacity: 0.55; }
            .lgtc-item.is-pass .lgtc-desc { text-decoration: line-through; }
            .lgtc-hide-mode .lgtc-item.is-pass { display: none; }
            .lgtc-hide-mode .lgtc-section.is-empty { display: none; }
            /* Report modal */
            .lgtc-modal { position: fixed; inset: 0; z-index: 2147483600; display: flex; align-items: center; justify-content: center; padding: 1em; }
            .lgtc-modal[hidden] { display: none !important; }
            .lgtc-modal__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.55); }
            .lgtc-modal__card { position: relative; background: var(--cream); color: var(--dark); border: 2px solid var(--amber); border-radius: 12px; padding: 24px 26px; max-width: 520px; width: 100%; box-shadow: 0 24px 60px rgba(0,0,0,0.45); }
            .lgtc-modal__x { position: absolute; top: 8px; right: 8px; width: 30px; height: 30px; padding: 0; background: transparent; border: 0; font-size: 22px; line-height: 1; color: var(--ink); cursor: pointer; }
            .lgtc-modal__x:hover { color: var(--dark); }
            .lgtc-modal__title { margin: 0 0 4px; font-family: Georgia, serif; font-size: 20px; color: var(--dark); }
            .lgtc-modal__where { margin: 0 0 2px; font-size: 11px; font-weight: 700; color: var(--amber-d); text-transform: uppercase; letter-spacing: 0.07em; }
            .lgtc-modal__desc  { margin: 0 0 16px; font-size: 13px; color: var(--ink); line-height: 1.5; }
            .lgtc-modal__form  { display: flex; flex-direction: column; gap: 12px; }
            .lgtc-modal__sev   { display: flex; gap: 8px; flex-wrap: wrap; }
            .lgtc-sev-btn      { flex: 1 1 130px; padding: 12px 10px; border: 2px solid; background: #fff; font-size: 14px; font-weight: 700; cursor: pointer; border-radius: 6px; transition: background 0.1s, color 0.1s; }
            .lgtc-sev-pass     { color: #87986A; border-color: #87986A; }
            .lgtc-sev-pass.is-active     { background: #87986A; color: #fff; }
            .lgtc-sev-fail     { color: #b04a3c; border-color: #b04a3c; }
            .lgtc-sev-fail.is-active     { background: #b04a3c; color: #fff; }
            .lgtc-sev-question { color: #C68A1E; border-color: #C68A1E; }
            .lgtc-sev-question.is-active { background: #C68A1E; color: #fff; }
            .lgtc-modal__form input[type="text"], .lgtc-modal__form textarea { padding: 8px 10px; border: 1px solid var(--sand); border-radius: 4px; font-size: 13px; font-family: inherit; }
            .lgtc-modal__bodylabel { font-size: 11px; font-weight: 700; color: var(--ink); text-transform: uppercase; letter-spacing: 0.06em; }
            .lgtc-modal__form textarea { resize: vertical; }
            .lgtc-modal__actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
            .lgtc-modal__actions .lgtc-btn { background: var(--green); color: #fff; border-color: var(--green); padding: 8px 18px; font-size: 13px; }
            .lgtc-modal__actions .lgtc-btn:hover { background: #6e8252; }
            .lgtc-modal__actions .lgtc-btn:disabled { opacity: 0.4; cursor: not-allowed; }
            .lgtc-modal__actions .lgtc-btn-cancel { background: transparent; color: var(--ink); border-color: var(--sand); }
            .lgtc-modal__actions .lgtc-btn-cancel:hover { background: var(--sand); color: var(--dark); }
            .lgtc-pwd { background: var(--dark); color: var(--cream); padding: 12px 22px; border-top: 1px solid #3a2f24; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
            .lgtc-pwd-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--amber); font-weight: 700; flex: 0 0 auto; }
            .lgtc-pwd input { background: #1a140d; color: var(--amber); border: 1px solid #5a4732; padding: 5px 10px; border-radius: 4px; font-family: Consolas, Menlo, monospace; font-size: 13px; flex: 0 0 auto; min-width: 200px; }
            .lgtc-pwd .lgtc-btn { background: transparent; border: 1px solid var(--amber); color: var(--amber); padding: 5px 14px; }
            .lgtc-pwd .lgtc-btn:hover { background: var(--amber); color: var(--dark); }
            .lgtc-pwd-hint { font-size: 11px; color: #888; flex: 1 1 auto; min-width: 200px; }
            .lgtc-pwd-unset { background: #5a3a1f; }
            .lgtc-pwd-unset .lgtc-pwd-label { color: #f1c8c1; }
            .lgtc-tips { background: var(--green-l); border: 1px solid var(--green); padding: 16px 22px; margin: 0 0 0; border-top: 0; }
            .lgtc-tips h2 { margin: 0 0 8px; font-family: Georgia, serif; font-size: 17px; color: var(--dark); }
            .lgtc-tips ul { margin: 0; padding-left: 20px; }
            .lgtc-tips li { font-size: 13px; line-height: 1.55; color: var(--dark); margin-bottom: 6px; }
            .lgtc-tips code { background: rgba(43,35,24,0.08); padding: 1px 6px; border-radius: 3px; font-size: 12px; color: var(--amber-d); font-weight: 600; }
            .lgtc-wipe { background: #fff7ec; border: 1px solid var(--amber); border-top: 0; padding: 16px 22px 18px; }
            .lgtc-wipe h2 { margin: 0 0 6px; font-family: Georgia, serif; font-size: 17px; color: var(--amber-d); }
            .lgtc-wipe-lede { margin: 0 0 12px; font-size: 13px; color: var(--ink); line-height: 1.55; }
            .lgtc-wipe code { background: rgba(43,35,24,0.08); padding: 1px 6px; border-radius: 3px; font-size: 12px; color: var(--amber-d); font-weight: 600; }
            .lgtc-wipe-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
            .lgtc-wipe-row input[type="email"] { flex: 1 1 280px; min-width: 200px; padding: 7px 10px; border: 1px solid var(--sand); border-radius: 4px; font-size: 13px; font-family: inherit; }
            .lgtc-wipe-row .lgtc-btn { background: var(--dark); color: var(--cream); border: 1px solid var(--dark); padding: 7px 14px; }
            .lgtc-wipe-row .lgtc-btn:hover { background: var(--ink); }
            .lgtc-wipe-row .lgtc-btn-danger { background: var(--red); color: #fff; border-color: var(--red); }
            .lgtc-wipe-row .lgtc-btn-danger:hover { background: #8d3a30; }
            .lgtc-wipe-row .lgtc-btn:disabled { opacity: 0.5; cursor: not-allowed; }
            .lgtc-wipe-status { margin-top: 10px; font-size: 13px; line-height: 1.55; min-height: 16px; }
            .lgtc-wipe-status.ok  { color: #4f6d3a; }
            .lgtc-wipe-status.err { color: var(--red); }
            .lgtc-wipe-status table { border-collapse: collapse; margin-top: 8px; font-size: 12px; }
            .lgtc-wipe-status td { padding: 2px 12px 2px 0; }
            .lgtc-wipe-status td:last-child { text-align: right; color: var(--amber-d); font-weight: 700; }
            .lgtc-wipe-self { background: var(--red); color: #fff; padding: 8px 12px; border-radius: 4px; margin: 8px 0 0; font-weight: 700; }
            /* Submit-status pill (shared by modal + wipe panel) */
            .lgtc-fb-status { font-size: 12px; }
            .lgtc-fb-status.ok  { color: #4f6d3a; }
            .lgtc-fb-status.err { color: var(--red); }

            /* Admin feedback inbox */
            .lgtc-inbox { background: #fff; border: 1px solid var(--sand); border-top: 0; padding: 14px 22px 18px; }
            .lgtc-inbox-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
            .lgtc-inbox-head h2 { margin: 0; font-family: Georgia, serif; font-size: 17px; color: var(--dark); }
            .lgtc-inbox-count { font-family: Arial, sans-serif; font-size: 11px; color: var(--amber-d); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; margin-left: 8px; }
            .lgtc-inbox-actions { display: flex; gap: 12px; align-items: center; font-size: 12px; }
            .lgtc-inbox-actions .lgtc-toggle { color: var(--ink); }
            .lgtc-inbox-actions .lgtc-btn { background: var(--dark); color: var(--cream); border-color: var(--dark); }
            .lgtc-inbox-actions .lgtc-btn:hover { background: var(--ink); }
            .lgtc-inbox-empty { font-size: 13px; color: #888; font-style: italic; margin: 0; }
            .lgtc-inbox-list { list-style: none; padding: 0; margin: 0; }
            .lgtc-inbox-item { padding: 10px 12px; margin-bottom: 8px; background: #fdfbf6; border: 1px solid var(--sand); border-left: 4px solid var(--green); border-radius: 3px; }
            .lgtc-inbox-status-fixed   { opacity: 0.55; border-left-color: var(--green); }
            .lgtc-inbox-status-wontfix { opacity: 0.55; border-left-color: #888; }
            .lgtc-inbox-meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 11px; }
            .lgtc-inbox-sev { display: inline-block; padding: 1px 8px; border-radius: 10px; color: #fff; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; font-size: 10px; }
            .lgtc-inbox-status { font-weight: 700; letter-spacing: 0.06em; color: var(--ink); }
            .lgtc-inbox-who { color: var(--dark); }
            .lgtc-inbox-when { color: #888; }
            .lgtc-inbox-where { font-size: 12px; color: var(--amber-d); margin: 6px 0 4px; }
            .lgtc-inbox-section { font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-right: 8px; }
            .lgtc-inbox-desc { color: var(--dark); font-weight: 600; }
            .lgtc-inbox-body { margin: 0 0 8px; font-size: 13px; line-height: 1.55; color: var(--dark); white-space: pre-wrap; }
            .lgtc-inbox-controls { display: flex; gap: 6px; flex-wrap: wrap; }
            .lgtc-inbox-controls .lgtc-btn { padding: 3px 10px; font-size: 10px; background: transparent; color: var(--ink); border-color: var(--sand); }
            .lgtc-inbox-controls .lgtc-btn:hover { background: var(--sand); color: var(--dark); }
            .lgtc-inbox-controls .lgtc-btn-danger { color: var(--red); border-color: var(--red); }
            .lgtc-inbox-controls .lgtc-btn-danger:hover { background: var(--red); color: #fff; }
            .lgtc-inbox.lgtc-inbox-hide-closed .lgtc-inbox-item:not(.lgtc-inbox-status-open) { display: none; }
            /* Severity filter buttons + hiding */
            .lgtc-inbox-filter { display: inline-flex; gap: 2px; align-items: center; }
            .lgtc-fbtn { background: transparent; border: 1px solid var(--sand); color: var(--ink); padding: 4px 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; }
            .lgtc-fbtn:first-child { border-radius: 4px 0 0 4px; }
            .lgtc-fbtn:last-child  { border-radius: 0 4px 4px 0; }
            .lgtc-fbtn:not(:first-child) { border-left: 0; }
            .lgtc-fbtn:hover { background: var(--sand); color: var(--dark); }
            .lgtc-fbtn.is-active { background: var(--dark); color: var(--cream); border-color: var(--dark); }
            .lgtc-inbox[data-fb-filter="fail"]     .lgtc-inbox-item:not([data-fb-severity="fail"]):not([data-fb-severity="bug"])     { display: none; }
            .lgtc-inbox[data-fb-filter="question"] .lgtc-inbox-item:not([data-fb-severity="question"]) { display: none; }
            .lgtc-inbox[data-fb-filter="pass"]     .lgtc-inbox-item:not([data-fb-severity="pass"]):not([data-fb-severity="note"])    { display: none; }

            /* View toggle (admin-only): Admin vs Tester */
            .lgtc-view-toggle { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; color: #d8cfc0; text-transform: uppercase; letter-spacing: 0.06em; }
            .lgtc-vt-btn { background: transparent; border: 1px solid #87986A; color: var(--cream); padding: 4px 12px; border-radius: 3px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; }
            .lgtc-vt-btn:hover { background: rgba(135,152,106,0.2); }
            .lgtc-vt-btn.is-active { background: var(--amber); color: var(--dark); border-color: var(--amber); }
            .lgtc-admin-badge { display: inline-block; margin-left: 8px; padding: 1px 8px; background: var(--dark); color: var(--amber); font-family: Arial, sans-serif; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; border-radius: 3px; vertical-align: middle; }
            /* Tester view: hide admin-only sections, items, and the inbox.
               The password callout stays visible to admins regardless of view
               mode — it's an admin working tool, not content testers see. */
            .lgtc.lgtc-view-as-tester .lgtc-admin-only { display: none !important; }
            .lgtc.lgtc-view-as-tester .lgtc-inbox      { display: none !important; }
            .lgtc.lgtc-view-as-tester .lgtc-items { counter-reset: lgtc-item; }
            .lgtc.lgtc-view-as-tester .lgtc-admin-only.lgtc-item { counter-increment: none; }
            @media (max-width: 600px) {
                .lgtc-head { padding: 22px 18px; border-radius: 6px 6px 0 0; }
                .lgtc-section { padding: 14px 16px 16px; }
                .lgtc-controls { gap: 10px; }
                .lgtc-tips, .lgtc-wipe, .lgtc-inbox { padding: 14px 16px; }
            }
        </style>

        <script>
        (function(){
            var STORAGE_KEY  = 'lgtc_state_v2'; // shape: { itemId: "pass" | "fail" | "question" }
            var NAME_KEY     = 'lgtc_tester_name_v1';
            var AJAX_URL     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var WIPE_NONCE   = <?php echo wp_json_encode( wp_create_nonce( 'lgms_test_wipe' ) ); ?>;
            var FB_NONCE     = <?php echo wp_json_encode( wp_create_nonce( 'lgms_test_feedback' ) ); ?>;
            var ADMIN_EMAIL  = <?php echo wp_json_encode( (string) ( wp_get_current_user()->user_email ?? '' ) ); ?>;
            var root         = document.querySelector('.lgtc');
            if (!root) return;

            var hideToggle  = root.querySelector('#lgtc-hide-checked');
            var resetBtn    = root.querySelector('#lgtc-reset');
            var progressEl  = root.querySelector('#lgtc-progress');
            var items       = root.querySelectorAll('.lgtc-item[data-item-id]');

            function loadState() {
                try {
                    var raw = localStorage.getItem(STORAGE_KEY);
                    return raw ? (JSON.parse(raw) || {}) : {};
                } catch (e) { return {}; }
            }
            function saveState(state) {
                try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
            }
            function applyItemState(li, sev) {
                ['is-pass','is-fail','is-question'].forEach(function(c){ li.classList.remove(c); });
                if (sev) li.classList.add('is-' + sev);
                var pill = li.querySelector('.lgtc-report-state');
                if (pill) {
                    pill.setAttribute('data-state', sev || '');
                    pill.textContent = sev === 'pass' ? '✓ Passed'
                                     : sev === 'fail' ? '✗ Failed'
                                     : sev === 'question' ? '? Question'
                                     : '';
                }
            }

            function isVisibleItem(li) {
                // Items inside admin-only sections or admin-only items are
                // hidden in tester view; we exclude them from the progress
                // tally so testers see "X of <items-they-can-actually-do>".
                if (!root.classList.contains('lgtc-view-as-tester')) return true;
                var sec = li.closest('.lgtc-section');
                return !li.classList.contains('lgtc-admin-only')
                    && !(sec && sec.classList.contains('lgtc-admin-only'));
            }
            function updateProgress() {
                var total = 0, pass = 0, fail = 0, question = 0, reported = 0;
                items.forEach(function(li){
                    if (!isVisibleItem(li)) return;
                    total++;
                    if (li.classList.contains('is-pass'))     { pass++;     reported++; }
                    else if (li.classList.contains('is-fail'))     { fail++;     reported++; }
                    else if (li.classList.contains('is-question')) { question++; reported++; }
                });
                progressEl.innerHTML = reported + ' / ' + total + ' reported'
                    + ' <span style="color:#9ec56e;">· ' + pass + ' pass</span>'
                    + ' <span style="color:#e88080;">· ' + fail + ' fail</span>'
                    + ' <span style="color:#ECB351;">· ' + question + ' ?</span>';

                root.querySelectorAll('.lgtc-section').forEach(function(sec){
                    var sId = sec.getAttribute('data-section');
                    var its = sec.querySelectorAll('.lgtc-item[data-item-id]');
                    var sTotal = 0, sDone = 0;
                    its.forEach(function(li){
                        if (!isVisibleItem(li)) return;
                        sTotal++;
                        if (li.classList.contains('is-pass') || li.classList.contains('is-fail') || li.classList.contains('is-question')) sDone++;
                    });
                    var label = sec.querySelector('[data-section-progress="' + sId + '"]');
                    if (label) label.textContent = sDone + ' / ' + sTotal;
                    if (root.classList.contains('lgtc-hide-mode')) {
                        sec.classList.toggle('is-empty', sTotal > 0 && sDone === sTotal);
                    } else {
                        sec.classList.remove('is-empty');
                    }
                });
            }

            // Initial state from localStorage
            var state = loadState();
            items.forEach(function(li){
                var id  = li.getAttribute('data-item-id');
                var sev = state[id];
                if (sev) applyItemState(li, sev);
            });

            hideToggle.addEventListener('change', function(){
                root.classList.toggle('lgtc-hide-mode', hideToggle.checked);
                updateProgress();
            });

            resetBtn.addEventListener('click', function(){
                if (!confirm('Clear your reported results? This only affects your browser. Submitted DB rows are kept for the audit log.')) return;
                localStorage.removeItem(STORAGE_KEY);
                items.forEach(function(li){ applyItemState(li, ''); });
                updateProgress();
            });

            updateProgress();

            // ── Report modal ───────────────────────────────────────────
            var modal       = root.querySelector('#lgtc-report-modal');
            var modalForm   = modal.querySelector('#lgtc-modal-form');
            var modalTitle  = modal.querySelector('#lgtc-modal-title');
            var modalWhere  = modal.querySelector('#lgtc-modal-where');
            var modalDesc   = modal.querySelector('#lgtc-modal-desc');
            var modalName   = modal.querySelector('#lgtc-modal-name');
            var modalBody   = modal.querySelector('#lgtc-modal-body');
            var modalBodyLbl= modal.querySelector('#lgtc-modal-bodylabel');
            var modalSubmit = modal.querySelector('#lgtc-modal-submit');
            var modalStatus = modal.querySelector('#lgtc-modal-status');
            var sevButtons  = modal.querySelectorAll('.lgtc-sev-btn');
            var currentItemId = '';
            var currentSev    = '';

            function openModal(itemId) {
                var li = root.querySelector('.lgtc-item[data-item-id="' + CSS.escape(itemId) + '"]');
                if (!li) return;
                currentItemId  = itemId;
                currentSev     = '';
                modalStatus.className = 'lgtc-fb-status';
                modalStatus.textContent = '';
                modalSubmit.disabled = true;
                sevButtons.forEach(function(b){ b.classList.remove('is-active'); });
                modalBody.value = '';
                // Title + context strings
                var sec = li.closest('.lgtc-section');
                var secTitle = sec ? (sec.querySelector('h2') || {}).textContent || '' : '';
                secTitle = (secTitle || '').replace(/\s*admin only\s*$/i, '').trim();
                var desc = (li.querySelector('.lgtc-desc') || {}).textContent || '';
                modalWhere.textContent = secTitle;
                modalDesc.textContent  = desc;
                // Restore saved name
                try {
                    var savedName = localStorage.getItem(NAME_KEY) || '';
                    if (savedName) modalName.value = savedName;
                } catch (e) {}
                modal.hidden = false;
                setTimeout(function(){ modalName.focus(); }, 0);
            }
            function closeModal() {
                modal.hidden = true;
                currentItemId = '';
                currentSev    = '';
            }
            function setSeverity(sev) {
                currentSev = sev;
                sevButtons.forEach(function(b){
                    b.classList.toggle('is-active', b.getAttribute('data-sev') === sev);
                });
                // Body required for fail/question; optional for pass.
                if (sev === 'pass') {
                    modalBodyLbl.textContent = 'Optional notes';
                    modalBody.placeholder = 'Anything you want recorded with this pass.';
                } else {
                    modalBodyLbl.textContent = 'What happened? (required)';
                    modalBody.placeholder = 'What did you see? What were you expecting? Be specific.';
                }
                modalSubmit.disabled = !sev;
            }

            // Open from any Report-result button
            root.querySelectorAll('[data-report-trigger]').forEach(function(btn){
                btn.addEventListener('click', function(){
                    openModal(btn.getAttribute('data-report-trigger'));
                });
            });

            // Severity pickers
            sevButtons.forEach(function(b){
                b.addEventListener('click', function(){
                    setSeverity(b.getAttribute('data-sev'));
                });
            });

            // Close
            modal.querySelectorAll('[data-modal-close]').forEach(function(el){
                el.addEventListener('click', closeModal);
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && !modal.hidden) closeModal();
            });

            // Submit
            modalForm.addEventListener('submit', function(e){
                e.preventDefault();
                if (!currentSev || !currentItemId) return;
                var name = (modalName.value || '').trim() || 'anonymous';
                var body = (modalBody.value || '').trim();
                if (currentSev !== 'pass' && !body) {
                    modalStatus.className = 'lgtc-fb-status err';
                    modalStatus.textContent = 'Tell us what you saw.';
                    return;
                }
                try { localStorage.setItem(NAME_KEY, name); } catch (e) {}
                modalSubmit.disabled = true;
                modalStatus.className = 'lgtc-fb-status';
                modalStatus.textContent = 'Submitting…';
                var fd = new URLSearchParams();
                fd.append('action', 'lgms_test_feedback_submit');
                fd.append('nonce', FB_NONCE);
                fd.append('item_id', currentItemId);
                fd.append('tester_name', name);
                fd.append('severity', currentSev);
                fd.append('body', body);
                fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function(r){ return r.json().then(function(j){ return { http: r.status, json: j }; }); })
                    .then(function(o){
                        if (o.json && o.json.success) {
                            // Update the cached state + the item visual
                            var s = loadState();
                            s[currentItemId] = currentSev;
                            saveState(s);
                            var li = root.querySelector('.lgtc-item[data-item-id="' + CSS.escape(currentItemId) + '"]');
                            if (li) applyItemState(li, currentSev);
                            updateProgress();
                            modalStatus.className = 'lgtc-fb-status ok';
                            modalStatus.textContent = 'Recorded.';
                            setTimeout(closeModal, 900);
                        } else {
                            modalStatus.className = 'lgtc-fb-status err';
                            modalStatus.textContent = (o.json && o.json.data && o.json.data.message) || ('HTTP ' + o.http);
                            modalSubmit.disabled = false;
                        }
                    })
                    .catch(function(){
                        modalStatus.className = 'lgtc-fb-status err';
                        modalStatus.textContent = 'Network error.';
                        modalSubmit.disabled = false;
                    });
            });

            // ── View toggle: Admin vs Tester (admin-only control) ──────
            var btnViewAdmin  = root.querySelector('#lgtc-view-admin');
            var btnViewTester = root.querySelector('#lgtc-view-tester');
            var VIEW_KEY      = 'lgtc_view_v1';
            function setView(mode) {
                var isTester = (mode === 'tester');
                root.classList.toggle('lgtc-view-as-tester', isTester);
                if (btnViewAdmin)  btnViewAdmin.classList.toggle('is-active',  !isTester);
                if (btnViewTester) btnViewTester.classList.toggle('is-active',  isTester);
                try { localStorage.setItem(VIEW_KEY, mode); } catch (e) {}
                updateProgress(); // counters re-derive from visible items
            }
            if (btnViewAdmin && btnViewTester) {
                var savedView = '';
                try { savedView = localStorage.getItem(VIEW_KEY) || ''; } catch (e) {}
                if (savedView === 'tester') setView('tester');
                btnViewAdmin.addEventListener('click',  function(){ setView('admin');  });
                btnViewTester.addEventListener('click', function(){ setView('tester'); });
            }

            // ── Copy-password button ────────────────────────────────────
            var pwdCopyBtn = root.querySelector('#lgtc-pwd-copy');
            if (pwdCopyBtn) {
                pwdCopyBtn.addEventListener('click', function(){
                    var input = root.querySelector('#lgtc-pwd-value');
                    if (!input) return;
                    input.focus();
                    input.select();
                    var ok = false;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(input.value).then(function(){
                            pwdCopyBtn.textContent = 'Copied ✓';
                            setTimeout(function(){ pwdCopyBtn.textContent = 'Copy'; }, 1500);
                        });
                        return;
                    }
                    try { ok = document.execCommand('copy'); } catch (e) {}
                    pwdCopyBtn.textContent = ok ? 'Copied ✓' : 'Press Ctrl/Cmd+C';
                    setTimeout(function(){ pwdCopyBtn.textContent = 'Copy'; }, 1800);
                });
            }

            // ── Wipe panel ──────────────────────────────────────────────
            var wipeEmailIn   = root.querySelector('#lgtc-wipe-email');
            var wipePreview   = root.querySelector('#lgtc-wipe-preview');
            var wipePerform   = root.querySelector('#lgtc-wipe-perform');
            var wipeStatus    = root.querySelector('#lgtc-wipe-status');
            var lastPreview   = null;

            function setStatus(html, kind) {
                wipeStatus.className = 'lgtc-wipe-status' + (kind ? ' ' + kind : '');
                wipeStatus.innerHTML = html;
            }
            function escHtml(s){ return String(s).replace(/[&<>"']/g, function(c){
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            }); }
            function buildCountsTable(counts) {
                var rows = Object.keys(counts).filter(function(k){ return counts[k] > 0; });
                if (rows.length === 0) return '<p style="margin:6px 0 0;color:#888;">Nothing to delete &mdash; this email has no records on the system.</p>';
                var html = '<table>';
                rows.forEach(function(k){ html += '<tr><td>' + escHtml(k) + '</td><td>' + counts[k] + '</td></tr>'; });
                html += '</table>';
                return html;
            }
            function postWipe(mode, selfConfirm) {
                var email = (wipeEmailIn.value || '').trim();
                if (!email) { setStatus('Enter an email first.', 'err'); return Promise.resolve(null); }
                var body = new URLSearchParams();
                body.append('action', 'lgms_test_wipe_email');
                body.append('nonce', WIPE_NONCE);
                body.append('mode', mode);
                body.append('email', email);
                if (selfConfirm) body.append('self_confirm', '1');
                return fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json().then(function(j){ return { http: r.status, json: j }; }); });
            }

            wipePreview.addEventListener('click', function(){
                wipePerform.disabled = true;
                lastPreview = null;
                setStatus('Looking up &hellip;');
                postWipe('preview').then(function(o){
                    if (!o) return;
                    if (!o.json || !o.json.success) {
                        setStatus(escHtml((o.json && o.json.data && o.json.data.message) || ('HTTP ' + o.http)), 'err');
                        return;
                    }
                    var d = o.json.data;
                    lastPreview = d;
                    var who = '';
                    if (d.wp_user)  who += '<strong>WP user:</strong> #' + d.wp_user.id + ' (' + escHtml(d.wp_user.login) + ', roles: ' + escHtml((d.wp_user.roles || []).join(', ')) + ')<br>';
                    else            who += '<strong>WP user:</strong> none<br>';
                    if (d.customer) who += '<strong>Customer:</strong> #' + d.customer.id + ' (' + escHtml(d.customer.email) + ')';
                    else            who += '<strong>Customer:</strong> none';
                    var selfNote = d.is_self
                        ? '<div class="lgtc-wipe-self">Heads up: this is YOUR own admin account. Wiping it will delete your WP user and log you out immediately. You\'ll need a different admin to log back in.</div>'
                        : '';
                    setStatus(who + buildCountsTable(d.counts || {}) + selfNote + '<p style="margin:8px 0 0;font-size:12px;color:#888;">Total rows: ' + (d.total || 0) + '. Click <strong>Wipe it</strong> to delete.</p>', 'ok');
                    wipePerform.disabled = (d.total === 0);
                }).catch(function(){ setStatus('Network error.', 'err'); });
            });

            wipePerform.addEventListener('click', function(){
                if (!lastPreview) { setStatus('Run Preview first.', 'err'); return; }
                var msg = 'Permanently delete every record for ' + (wipeEmailIn.value || '').trim() + '? This cannot be undone.';
                if (lastPreview.is_self) {
                    msg += '\n\nThis is YOUR admin account. You will be logged out and will need a different admin login to recover.';
                }
                if (!confirm(msg)) return;

                wipePerform.disabled = true;
                setStatus('Wiping &hellip;');
                postWipe('perform', !!lastPreview.is_self).then(function(o){
                    if (!o) return;
                    if (!o.json || !o.json.success) {
                        setStatus(escHtml((o.json && o.json.data && o.json.data.message) || ('HTTP ' + o.http)), 'err');
                        return;
                    }
                    var d = o.json.data;
                    var html = '<strong>Wiped.</strong> Deleted ' + (d.total || 0) + ' rows total.' + buildCountsTable(d.deleted || {});
                    if (d.is_self) {
                        html += '<p style="margin:8px 0 0;color:#b04a3c;font-weight:700;">Your session is now stale. Reload to confirm logout.</p>';
                    }
                    setStatus(html, 'ok');
                    lastPreview = null;
                    wipeEmailIn.value = '';
                }).catch(function(){ setStatus('Network error during wipe.', 'err'); });
            });

            // ── Admin: feedback inbox ──────────────────────────────────
            var inbox      = root.querySelector('.lgtc-inbox');
            if (inbox) {
                var showClosed = inbox.querySelector('#lgtc-inbox-show-closed');
                var copyBtn    = inbox.querySelector('#lgtc-inbox-copy');

                // Default: hide closed items
                inbox.classList.add('lgtc-inbox-hide-closed');
                if (showClosed) {
                    showClosed.addEventListener('change', function(){
                        inbox.classList.toggle('lgtc-inbox-hide-closed', !showClosed.checked);
                    });
                }

                // Severity filter (All / Fails / Qs / Passes). Default "all" — no attribute set.
                inbox.querySelectorAll('[data-fb-filter]').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var f = btn.getAttribute('data-fb-filter');
                        inbox.querySelectorAll('[data-fb-filter]').forEach(function(b){ b.classList.remove('is-active'); });
                        btn.classList.add('is-active');
                        if (f === 'all') inbox.removeAttribute('data-fb-filter');
                        else             inbox.setAttribute('data-fb-filter', f);
                    });
                });

                // Status-change buttons
                inbox.querySelectorAll('[data-fb-set]').forEach(function(b){
                    b.addEventListener('click', function(){
                        var li     = b.closest('.lgtc-inbox-item');
                        var fbId   = parseInt(li.getAttribute('data-fb-id'), 10);
                        var newSt  = b.getAttribute('data-fb-set');
                        if (newSt === 'delete' && !confirm('Delete this feedback row?')) return;
                        var fd = new URLSearchParams();
                        fd.append('action', 'lgms_test_feedback_status');
                        fd.append('nonce', FB_NONCE);
                        fd.append('id', fbId);
                        fd.append('status', newSt);
                        fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function(r){ return r.json(); })
                            .then(function(j){
                                if (!j || !j.success) return;
                                if (newSt === 'delete') {
                                    li.parentNode.removeChild(li);
                                    return;
                                }
                                li.className = 'lgtc-inbox-item lgtc-inbox-status-' + newSt;
                                li.setAttribute('data-fb-status', newSt);
                                var sBadge = li.querySelector('.lgtc-inbox-status');
                                if (sBadge) sBadge.textContent = newSt.toUpperCase();
                            });
                    });
                });

                // Copy as Markdown — exports only currently-visible rows so
                // the filter selection (severity + show-closed) translates 1:1
                // into the exported report.
                if (copyBtn) {
                    copyBtn.addEventListener('click', function(){
                        var items = Array.prototype.filter.call(
                            inbox.querySelectorAll('.lgtc-inbox-item'),
                            function(li){ return li.offsetParent !== null; }
                        );
                        var lines = ['# Test feedback (' + items.length + ' shown)\n'];
                        items.forEach(function(li){
                            var sev    = (li.querySelector('.lgtc-inbox-sev') || {}).textContent || 'note';
                            var status = li.getAttribute('data-fb-status') || 'open';
                            var who    = (li.querySelector('.lgtc-inbox-who') || {}).textContent || 'anonymous';
                            var when   = (li.querySelector('.lgtc-inbox-when') || {}).textContent || '';
                            var sec    = (li.querySelector('.lgtc-inbox-section') || {}).textContent || '';
                            var desc   = (li.querySelector('.lgtc-inbox-desc') || {}).textContent || '';
                            var body   = (li.querySelector('.lgtc-inbox-body') || {}).textContent || '';
                            lines.push('## ' + sec + ' — ' + desc);
                            lines.push('**' + sev.toLowerCase() + '** · **' + status + '** · ' + who + ' · ' + when);
                            lines.push('');
                            body.split(/\n/).forEach(function(l){ lines.push('> ' + l); });
                            lines.push('');
                        });
                        var md = lines.join('\n');
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(md).then(function(){
                                copyBtn.textContent = 'Copied ✓';
                                setTimeout(function(){ copyBtn.textContent = 'Copy as Markdown'; }, 1500);
                            });
                            return;
                        }
                        try {
                            var ta = document.createElement('textarea');
                            ta.value = md;
                            document.body.appendChild(ta);
                            ta.select();
                            document.execCommand('copy');
                            ta.remove();
                            copyBtn.textContent = 'Copied ✓';
                            setTimeout(function(){ copyBtn.textContent = 'Copy as Markdown'; }, 1500);
                        } catch (e) {}
                    });
                }
            }
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }
}
