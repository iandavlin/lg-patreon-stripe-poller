# Pickup — lg-patreon-stripe-poller

*Last worked: 2026-05-04*

> **See companion repo** [`lg-stripe-billing`](https://github.com/iandavlin/lg-stripe-billing) (Slim API). Slim's PICKUP has the cross-cutting picture; this doc is the WP plugin half.

## ⚠️ Open bugs / immediate triage

1. **`/lggift-buy/` returning Bad Gateway.** Reported by user 2026-05-04 right after the latest `/lgjoin/` polish (password confirm + eye toggle + existing-account modal + grid layout — see `git log` last 5 commits on `src/Wp/Shortcodes.php`). The same shortcodes file was touched, so a stray heredoc / PHP fatal in the gift-buy render path is the most likely culprit.
   - Triage: enable `WP_DEBUG_DISPLAY` (`/var/www/dev/wp-config.php`), curl `/lggift-buy/`, capture the inline trace, fix, flip back.
   - Confirmed-good earlier this session before the polish bundle: revert to compare if needed (`git log --oneline -- src/Wp/Shortcodes.php`).

## Architecture (unchanged)

Every role write goes through `Arbiter::sync($wpUserId)`. Sources of truth (`lg_role_sources` rows): `stripe`, `patreon`, `manual_*`. The arbiter merges, picks the highest tier across `looth1..4`, writes `wp_capabilities`, and **never touches** non-tier roles (administrator, bbp_keymaster, etc.). looth4 users are protected — the arbiter early-returns and won't modify them.

Bridge points (where the arbiter gets called from):

1. Cron tick — `Tick::run` → `Sync::all()` → `Sync::customer()` per dirty customer
2. Slim webhook → `WpSync::trigger($customerId)` → POST to `/wp-json/lg-member-sync/v1/sync-customer` → `UserProvisioner::findOrProvision` → `RoleSourceWriter::report` → `Arbiter::sync`
3. OAuth onboarding paths — all use `lgpo_apply_role_via_arbiter()` helper
4. **NEW:** gift-auth REST endpoint — see below

## What changed this session (2026-05-03 → 2026-05-04)

### Tier 2 Phase D — gift management UI shipped

Five new WP REST endpoints under `/wp-json/lg-member-sync/v1/`:
- `POST /send-gift-recipient` — shared-secret auth; called by Slim's `GiftActionController` when a buyer hits Send / Resend / Reassign on the dashboard. Single per-recipient email + `email_sent_at` stamp.
- `POST /me/gift-{send,resend,reassign,void}` — WP-nonce auth (browser-only); permission via `authLoggedInUser`. Each one calls `proxyToSlim()` which curls back to Slim's `/v1/gift-*` endpoints. **Important:** `proxyToSlim` uses raw curl with `CURLOPT_RESOLVE => host:443:127.0.0.1` to bypass Cloudflare on internal calls. `wp_remote_post()` does NOT work for these — Cloudflare returns a 403 HTML challenge instead of letting the request reach origin nginx.
- `POST /gift-auth` — public; no nonce. Login or sign-up endpoint that creates `customer`-role users with their own typed password and sets the auth cookie. Detailed below.

`[lg_my_gifts]` shortcode is the buyer-facing dashboard at `/my-gifts/`. Renders Unsent / Sent / Redeemed / Voided buckets, with inline action buttons that fire the `/me/gift-*` endpoints. **Wrong-account "Oops" gate:** if the URL carries `?for=<email>` (added to the buyer's receipt-email link by `GiftMailer::sendDashboardSummary`) and the session email doesn't match, the dashboard refuses to render and shows "you're signed in as the wrong account" with a sign-out button.

### Gift purchase form (`[lg_gift]` at `/lggift-buy/`)

- Two radio cards (was a 3-card accordion mid-session): **"Log in to manage & send personalized gift emails"** (default, with **Recommended** pill) and **"Get codes via email"** (anonymous, with subtle amber inline warning).
- Inline auth panel (email + password + weekly opt-in) attached to the managed radio. On submit calls `/gift-auth` BEFORE Stripe — cookie is set before checkout, so post-payment the welcome modal lands the user logged in.
- Consent modal for new accounts: when `/gift-auth` returns `needs_consent: true`, pop modal with privacy disclosure and weekly-email opt-in. Confirm → re-submit with `confirmed_consent: true`.
- Anonymous-checkout warning modal with required acknowledgment checkbox.
- Stripe embedded checkout is hosted in a centered modal (max 720px) portaled to `<body>` so BuddyBoss containing-blocks can't trap `position: fixed`. Fullscreen redirect overlay shown immediately on click (700ms minimum display) with a watchdog that drops the spinner after 12s.
- One-shot `checkoutInProgress` flag prevents button-spam double-charges (verified — user spammed the button, only 1 Stripe session fired).

### Gift redemption form (`[lg_redeem_gift]` at `/lggift/`)

- Email is **stapled** to the gift code: server-side override in `RedeemController::redeem()` plus form field `readonly` whenever the code's `recipient_email` is set. Defense in depth — DevTools tampering can't change the destination.
- Three render variants:
  1. **Sign-in variant** (existing email + visitor not logged in) — green "this email already has an account" banner, no Name field, sign-in copy + Forgot link, "Sign in & redeem" button. After auth success, page reloads to `/lggift/?code=XXX` so the visitor lands logged-in and confirms redemption from there.
  2. **Wrong-user hard fail** (logged-in session != recipient) — red banner "You're not <recipient>. This gift isn't for you." + sign-out button. Form is **not rendered** (early return).
  3. **Create-account variant** (new email) — full form with Name + 8-char password.
- Tier conflict picker (Stacked / Prorated options) only renders for authenticated users. Anonymous users hitting `requires_choice` get the inline-login gate (above).
- `gift-auth` runs **before** `/v1/redeem` — wrong password = inline error, redemption never fires. New users created with their typed password, account auto-claim happens via `lg_auto_provisioned` user meta + `redemption_code` proof in `redemptionCodeProves()` (10-min window OR un-redeemed-yet codes for that email).
- Welcome modal on success: "Welcome to the Looth Group!" with Take-me-to-feed CTA → `/activity/`.

### Subscription form (`[lg_join]` at `/lgjoin/`) — auth-before-Stripe

- Profile name copy strengthened (was "Used for your account / community profile" → now "what other members will see in forums, comments, and the activity feed — not optional").
- Required Password + Confirm Password fields with live mismatch indicator + 👁 reveal toggles on both. Hidden when already logged in.
- Submit calls `/gift-auth` first → cookie set before Stripe iframe loads → user lands on `/welcome/` already authenticated.
- **Existing-account modal:** when gift-auth says wrong password for an existing email, show a dedicated modal with primary CTA → `wp-login.php?redirect_to=/manage-subscription/` (don't bounce them to a fresh checkout — they already have an account, send them to manage it).
- Active-gift confirmation modal: server returns `needs_gift_confirmation` if email has an active gift entitlement. Modal shows "you have N days of looth2 from a gift" + checkbox "I understand — charge me today and stack on top". Re-submit with `acknowledged_active_gift: true`.
- Stripe embedded checkout in a centered modal (same 720px lightbox pattern as the gift form).

### Customer role lockdown (gift-only buyers)

- New WP role `customer` (already in WordPress; we just lean on it).
- `Plugin::denyGlobalAccessForCustomers` filter on `bbp_allow_global_access` prevents bbPress from auto-adding `bbp_participant` on every page load for users whose only role is `customer`.
- `Plugin::stripForumCapsForCustomers` filter on `user_has_cap` forces `participate / publish_replies / publish_topics / edit_* / delete_* / moderate / throttle / spectate / *_topic_tags / mark_as_spam` to `false` for customer-only users. Read caps left intact.
- `Plugin::maskCustomerBpUserId` masks `bp_loggedin_user_id` and `bp_displayed_user_id` to 0 for customer-only — they appear logged-out to every BB component (no avatar menu, no member-directory entry).
- `Plugin::excludeCustomersFromBpQueries` appends customer-only user IDs to every `bp_pre_user_query` exclude list.
- `RestController::eraseBuddypressFootprint($userId)` runs on every fresh customer creation — wipes `bp_activity / bp_friends / bp_groups_members / bp_messages_recipients / bp_notifications / bp_xprofile_data / bp_user_blogs / bp_invitations` rows and `last_activity / bp_latest_update / total_friend_count / total_group_count` user meta.
- `customer` is sticky — Arbiter only manages `looth1..4`, so a customer who later subscribes ends up with `customer + looth2`. Both caps test true.

### BuddyBoss public-content allowlist auto-sync

- `Pages::PAGES` registry has `public => true|false` flag per shortcode page.
- `Pages::ensureBuddyBossAllowlist()` appends slugs of `public => true` pages to the `bp-enable-private-network-public-content` option (idempotent, only adds what's missing).
- `Plugin::maybeRefreshBbAllowlist()` runs the above on `init` priority 20, gated by a 6-hour transient. Self-heals after page renames or fresh installs.
- `lg_my_gifts` and `lg_manage_subscription` were flipped from `public=>false` to `public=>true` — the shortcodes themselves render "please sign in" for anon, so BB doesn't need to double-gate them.

### Slim-side changes (lg-stripe-billing repo)

- `RedeemController` server-side enforces `recipient_email` override — the email param is replaced with the gift code's recipient_email if set, regardless of what the form posted.
- `CheckoutController` injects `EntitlementRepository` and gates new subscriptions behind `acknowledged_active_gift` when an active gift exists.
- `GiftRedemptionService` strategy labels prefixed with `Stacked:` / `Prorated:` for clarity on the conflict picker.

## Settings / cutover gotchas

- **Patreon CSV plugin (`lg-patreon-sync`) is decommissioned** as of cutover. Already inactive on dev (`*.deprecated-2026-04-25`). Looth roles (looth1-4) are now persisted via User Role Editor — make sure URE registration sticks on prod.
- Role display names = role slugs (renamed via wp-cli; if URE re-syncs labels, push the rename via URE's option key).
- `WP_MEMORY_LIMIT` and `WP_MAX_MEMORY_LIMIT` set to **512M** in `wp-config.php`. The php-fpm pool config (`/etc/php/8.3/fpm/pool.d/looth-dev.conf`) has `php_admin_value[memory_limit] = 256M` — that's the actual ceiling and **needs sudo to bump**:
  ```
  sudo sed -i 's/^php_admin_value\[memory_limit\] = 256M/php_admin_value[memory_limit] = 512M/' /etc/php/8.3/fpm/pool.d/looth-dev.conf
  sudo systemctl reload php8.3-fpm
  ```
  Until that lands, Search & Filter Pro's bitmap indexer can OOM `/archive/`.
- **Dev mu-plugin `dev-admin-only-login.php` is disabled** (`.disabled` extension). Do NOT re-enable on prod.
- **Debug log rotation** is set up via user-cron at 03:15 daily (`~/logrotate-debug.conf`, state at `~/logrotate-debug.state`). Caps `wp-content/debug.log` at 10 MB live + 5 gzipped rotations.

## What's planned but not done

1. **3-month gift duration POC** — server-side already reads `grants_duration_days` from price metadata, so it's mostly a Stripe-admin task (create new one-time prices with `metadata.grants_duration_days=90`) plus a duration toggle on each tier card. **NOT YET STARTED** — user wants to run by team first.
2. Subscription-flow active-gift confirmation server-side query is in place; UI tested but not yet validated end-to-end with real fixture data.
3. Post-Stripe processing modal on the gift checkout — built and reverted in same session because Stripe's `onComplete` timing was awkward and the redirect-to-feed felt sufficient. May re-attempt later.

## Test fixtures + cleanup

Quick "nuke ianhates + clear ian.davlin gifts" recipe (we ran it ~10 times this session):

```bash
ssh -i "ssh keys/ccdev_key" ccdev@54.157.13.77 'wp eval "
require_once \"/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/vendor/autoload.php\";
\$pdo = \LGMS\Db::pdo();
foreach ([\"ianhatesguitars@gmail.com\",\"ian.davlin@gmail.com\"] as \$email) {
    \$st = \$pdo->prepare(\"SELECT id FROM customers WHERE email = ?\");
    \$st->execute([\$email]);
    \$cid = \$st->fetchColumn();
    if (!\$cid) continue;
    \$pdo->prepare(\"DELETE FROM gift_codes WHERE purchased_by = ? OR redeemed_by = ?\")->execute([\$cid, \$cid]);
}" --path=/var/www/dev'
```

Full ianhates teardown (WP user + customer + all FK-linked rows) is documented in earlier session transcript.

## Quick commands

```bash
# Trigger the cron tick manually
cd /var/www/dev && wp cron event run lgms_poll_tick

# Sync one customer via REST
SECRET=$(sudo grep '^LGMS_SHARED_SECRET=' /home/ccdev/lg-stripe-billing/.env | cut -d= -f2)
curl -s -X POST -H "Content-Type: application/json" -H "X-LGMS-Token: $SECRET" \
  -d '{"customer_id":3}' \
  --resolve dev.loothgroup.com:443:127.0.0.1 -k \
  https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer

# Force BB-allowlist refresh
wp transient delete lgms_bb_allowlist_synced --path=/var/www/dev

# Inspect role sources for a user
wp eval 'print_r(\LGMS\RoleSourceWriter::readAllForUser(1838));' --path=/var/www/dev
```

## Server access

```bash
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77
```
