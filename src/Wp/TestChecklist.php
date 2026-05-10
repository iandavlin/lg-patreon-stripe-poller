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
 * Shortcode renders nothing for non-admins — page is in the nav under
 * 'admins' visibility, so non-admins won't see the link in the first
 * place, but this is defense-in-depth in case the page is reached
 * directly.
 */
final class TestChecklist
{
    public static function register(): void
    {
        add_shortcode( 'lg_test_checklist', [ self::class, 'render' ] );
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
                'auth-throttle-email' => [ 'desc' => 'Submit /gift-auth six times against the same email with wrong passwords.',                                                    'expect' => '6th attempt gets HTTP 429 with "Too many failed attempts for this account" and rate_limited:true. Even the correct password is blocked until 15 min elapse.' ],
                'auth-throttle-ip' => [ 'desc' => 'Submit /gift-auth twenty-one times rapidly from the same IP (different emails).',                                                'expect' => '21st request gets HTTP 429 with "Too many attempts. Please wait an hour" and rate_limited:true.' ],
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
                'mg-admin-bar'    => [ 'desc' => 'Visit /membership-guide/ as a WP admin.',                                                                                       'expect' => 'Fixed admin preview bar visible top-right with Visitor / Member toggle and the WELCOME EMAIL test form.', 'url' => '/membership-guide/' ],
                'mg-toggle'       => [ 'desc' => 'In the admin preview bar, click Visitor and Member buttons.',                                                                  'expect' => 'Body class flips between lgms-mg-anon / lgms-mg-member. Loothalong gating updates client-side.' ],
                'mg-test-email'   => [ 'desc' => 'In the admin preview bar, enter an email and click Send test.',                                                                'expect' => 'Status line shows "Test email sent to ...". Email arrives with [TEST] subject prefix and matches the live page visually.' ],
                'mg-avatar-override' => [ 'desc' => 'Edit an elder via the front-end edit modal, paste a URL into "Avatar Override URL", save, reload.',                          'expect' => 'Elder card now shows the override URL\'s image. NOT the BuddyBoss avatar.' ],
                'mg-shows'        => [ 'desc' => 'Confirm Recurring Shows section renders.',                                                                                     'expect' => 'Configured shows appear with thumbnails. Empty config = no slider rendered.' ],
                'mg-events'       => [ 'desc' => 'Confirm Live Events shortcode renders next 4 events.',                                                                         'expect' => 'Cards with date pill + title + thumbnail. Fallback "Recent shows" appears if no upcoming events exist.' ],
                'mg-elders'       => [ 'desc' => 'Confirm Council of Elders slider renders.',                                                                                    'expect' => 'Avatars + names + IG link per card. View bio links to /elder-NAME/ pages.' ],
                'mg-loothalong'   => [ 'desc' => 'Confirm Loothalong section gating.',                                                                                           'expect' => 'Anon: "See the plans →". Member with URL configured: "Join the room →". Member without URL: "URL not yet configured".' ],
            ],
        ],

        'admin' => [
            'title' => 'Admin tools (wp-admin)',
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
                'rl-looth4-protect'   => [ 'desc' => 'Confirm a looth4 user does not get downgraded by the Arbiter on tick.',                                                    'expect' => 'looth4 role + caps remain after Tick::run. lg_role_sources rows for that user are still respected.' ],
                'rl-bb-allowlist'     => [ 'desc' => 'Anon visit each public Pages registry page (/lgjoin/, /lggift-buy/, /lggift/, /membership-guide/, /request-refund/).',     'expect' => 'All render without redirecting to wp-login.php?bp-auth=1. (BuddyBoss public-content allowlist auto-populated.)' ],
            ],
        ],

        'cron' => [
            'title' => 'Cron / polling / webhooks',
            'items' => [
                'cr-tick-manual'    => [ 'desc' => 'Trigger Tick::run via /run-now (or wp cron event run lgms_poll_tick).',                                                       'expect' => 'tick.log shows: tick start → stripe poll → expiry sweep → reconcile-pending → sync sweep ok=N errors=0.' ],
                'cr-tick-lock'      => [ 'desc' => 'Fire two concurrent /run-now calls.',                                                                                       'expect' => 'tick.log shows one "tick start" and one "tick SKIPPED: another tick is already running".' ],
                'cr-dup-detect'     => [ 'desc' => 'Inspect lg_processed_events table after a couple ticks (SELECT COUNT(*), SUM(dup_count > 0)).',                              'expect' => 'Row per processed event_id. dup_count > 0 only if Stripe redelivered or a tick crashed mid-batch.' ],
                'cr-webhook-sig'    => [ 'desc' => 'POST a malformed payload (or wrong signature) to Slim\'s /v1/webhook.',                                                      'expect' => 'HTTP 400 returned. Stripe SDK signature verification rejects the request before any handler runs.' ],
                'cr-reconcile'      => [ 'desc' => 'Trigger /v1/reconcile-pending on Slim with a valid X-LGMS-Token.',                                                           'expect' => '{"ok":true,"stats":{"examined":N,"recovered":M,...}}. Without the token: 401.' ],
            ],
        ],

        'security' => [
            'title' => 'Security smoke tests (post-audit)',
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

    public static function render(): string
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return '<p style="text-align:center;padding:2em;color:#888;"><em>This page is admin-only.</em></p>';
        }

        ob_start();
        ?>
        <div class="lgtc">
            <header class="lgtc-head">
                <h1>QA Test Checklist</h1>
                <p class="lgtc-lede">Walk through every flow before prod cutover. Checkboxes save in your browser's local storage &mdash; close and come back later, your progress is preserved. Each tester tracks their own progress; nothing syncs across browsers.</p>
                <div class="lgtc-controls">
                    <span class="lgtc-progress" id="lgtc-progress">0 of 0 checked</span>
                    <label class="lgtc-toggle"><input type="checkbox" id="lgtc-hide-checked"> Hide checked</label>
                    <button type="button" id="lgtc-reset" class="lgtc-btn lgtc-btn-danger">Reset all</button>
                </div>
            </header>

            <?php foreach ( self::SECTIONS as $sectionId => $section ) : ?>
                <section class="lgtc-section" data-section="<?php echo esc_attr( $sectionId ); ?>">
                    <h2><?php echo esc_html( (string) $section['title'] ); ?>
                        <span class="lgtc-section-progress" data-section-progress="<?php echo esc_attr( $sectionId ); ?>"></span>
                    </h2>
                    <ol class="lgtc-items">
                        <?php foreach ( (array) ( $section['items'] ?? [] ) as $itemId => $item ) :
                            $fullId = $sectionId . ':' . $itemId;
                            $url    = (string) ( $item['url'] ?? '' );
                        ?>
                        <li class="lgtc-item" data-item-id="<?php echo esc_attr( $fullId ); ?>">
                            <label class="lgtc-check">
                                <input type="checkbox" data-id="<?php echo esc_attr( $fullId ); ?>">
                                <span class="lgtc-check-box" aria-hidden="true"></span>
                            </label>
                            <div class="lgtc-body">
                                <p class="lgtc-desc"><?php echo esc_html( (string) ( $item['desc'] ?? '' ) ); ?></p>
                                <p class="lgtc-expect"><strong>Expect:</strong> <?php echo esc_html( (string) ( $item['expect'] ?? '' ) ); ?></p>
                                <?php if ( $url !== '' ) : ?>
                                    <p class="lgtc-link"><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?> &rarr;</a></p>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endforeach; ?>
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
            .lgtc-items { list-style: none; padding: 0; margin: 0; }
            .lgtc-item { display: flex; gap: 14px; padding: 12px 0; border-bottom: 1px dashed var(--sand); align-items: flex-start; }
            .lgtc-item:last-child { border-bottom: 0; }
            .lgtc-check { position: relative; flex: 0 0 auto; cursor: pointer; padding-top: 2px; }
            .lgtc-check input { position: absolute; opacity: 0; pointer-events: none; }
            .lgtc-check-box { display: inline-block; width: 20px; height: 20px; border: 2px solid var(--amber); border-radius: 4px; background: #fff; transition: background 0.1s, border-color 0.1s; }
            .lgtc-check input:checked + .lgtc-check-box { background: var(--green); border-color: var(--green); position: relative; }
            .lgtc-check input:checked + .lgtc-check-box::after { content: '\2713'; position: absolute; left: 2px; top: -3px; color: #fff; font-size: 16px; font-weight: 700; line-height: 1; }
            .lgtc-body { flex: 1 1 auto; min-width: 0; }
            .lgtc-desc { margin: 0 0 4px; font-size: 14px; color: var(--dark); line-height: 1.5; font-weight: 600; }
            .lgtc-expect { margin: 0 0 4px; font-size: 13px; color: var(--ink); line-height: 1.5; }
            .lgtc-expect strong { color: var(--amber-d); text-transform: uppercase; letter-spacing: 0.06em; font-size: 11px; }
            .lgtc-link { margin: 4px 0 0; font-size: 12px; }
            .lgtc-link a { color: var(--green); text-decoration: none; font-weight: 700; }
            .lgtc-link a:hover { text-decoration: underline; }
            .lgtc-item.is-checked { opacity: 0.55; }
            .lgtc-item.is-checked .lgtc-desc { text-decoration: line-through; }
            .lgtc-hide-mode .lgtc-item.is-checked { display: none; }
            .lgtc-hide-mode .lgtc-section.is-empty { display: none; }
            @media (max-width: 600px) {
                .lgtc-head { padding: 22px 18px; border-radius: 6px 6px 0 0; }
                .lgtc-section { padding: 14px 16px 16px; }
                .lgtc-controls { gap: 10px; }
            }
        </style>

        <script>
        (function(){
            var STORAGE_KEY = 'lgtc_state_v1';
            var root        = document.querySelector('.lgtc');
            if (!root) return;

            var hideToggle  = root.querySelector('#lgtc-hide-checked');
            var resetBtn    = root.querySelector('#lgtc-reset');
            var progressEl  = root.querySelector('#lgtc-progress');
            var checkboxes  = root.querySelectorAll('input[type="checkbox"][data-id]');

            function loadState() {
                try {
                    var raw = localStorage.getItem(STORAGE_KEY);
                    return raw ? (JSON.parse(raw) || {}) : {};
                } catch (e) { return {}; }
            }
            function saveState(state) {
                try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
            }

            function applyItemChecked(input, checked) {
                var li = input.closest('.lgtc-item');
                if (li) li.classList.toggle('is-checked', !!checked);
            }

            function updateProgress() {
                var total = checkboxes.length;
                var done  = 0;
                checkboxes.forEach(function(cb){ if (cb.checked) done++; });
                progressEl.textContent = done + ' of ' + total + ' checked';

                root.querySelectorAll('.lgtc-section').forEach(function(sec){
                    var sId = sec.getAttribute('data-section');
                    var items = sec.querySelectorAll('input[type="checkbox"][data-id]');
                    var sDone = 0;
                    items.forEach(function(cb){ if (cb.checked) sDone++; });
                    var label = sec.querySelector('[data-section-progress="' + sId + '"]');
                    if (label) label.textContent = sDone + ' / ' + items.length;
                    if (root.classList.contains('lgtc-hide-mode')) {
                        sec.classList.toggle('is-empty', sDone === items.length);
                    } else {
                        sec.classList.remove('is-empty');
                    }
                });
            }

            // Initial state from localStorage
            var state = loadState();
            checkboxes.forEach(function(cb){
                var id = cb.getAttribute('data-id');
                if (state[id]) {
                    cb.checked = true;
                    applyItemChecked(cb, true);
                }
                cb.addEventListener('change', function(){
                    var s = loadState();
                    if (cb.checked) s[id] = true;
                    else delete s[id];
                    saveState(s);
                    applyItemChecked(cb, cb.checked);
                    updateProgress();
                });
            });

            hideToggle.addEventListener('change', function(){
                root.classList.toggle('lgtc-hide-mode', hideToggle.checked);
                updateProgress();
            });

            resetBtn.addEventListener('click', function(){
                if (!confirm('Clear all your check marks? This only affects your browser.')) return;
                localStorage.removeItem(STORAGE_KEY);
                checkboxes.forEach(function(cb){
                    cb.checked = false;
                    applyItemChecked(cb, false);
                });
                updateProgress();
            });

            updateProgress();
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }
}
