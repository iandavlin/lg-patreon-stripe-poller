# Pickup — lg-patreon-stripe-poller

*Updated: 2026-05-09*

> Companion repo: [`lg-stripe-billing`](https://github.com/iandavlin/lg-stripe-billing) (Slim API). Cross-cutting picture is in its PICKUP.

## State

WordPress plugin at `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/` on `dev.loothgroup.com` (php-fpm pool `php8.3-fpm-looth-dev.sock`). Two databases: WordPress (`wp_*`, accessed via `$wpdb`) and `lg_membership` (own PDO via `LGMS\Db::pdo()`). The plugin's hourly cron (`lgms_poll_tick` → `Tick::run`) is the heart: pulls Stripe events, sweeps expired entitlements, calls back to Slim's `/v1/reconcile-pending`, and runs the customer sync.

**Server WIP not yet committed (canonical version):** `Plugin.php`, `Pages.php`, `Shortcodes.php`, `class-lgpo-sync-engine.php`, `Schema.php` (membership-guide table), plus untracked `src/Membership.php`, `src/Wp/MembershipGuide.php`, `src/Wp/UpcomingEvents.php`, `templates/page/*.php`, `assets/video/`, `seed-elders.php`, `snippets/admin-view-as-toggle.php`. The session-16 revert (`2d679fc`) restored `origin/main` to *before* a stale local laptop version of these. Don't pull origin over the server's working tree.

## TODO

- **NEXT:** Triage `/lggift-buy/` Bad Gateway (reported 2026-05-04 after `[lg_join]` polish; suspect heredoc / PHP fatal in `Shortcodes.php` gift-buy render path). Enable `WP_DEBUG_DISPLAY` in `wp-config.php`, curl `/lggift-buy/`, capture inline trace, fix.
- Commit the server's membership-guide WIP (see "State" above) when ready.
- Verify `session 15` carryover items in companion `lg-stripe-billing` PICKUP — `charge.refunded` webhook event registration, affiliate `dan` linkage, etc.

## Architecture

**Arbiter:** every role write goes through `Arbiter::sync($wpUserId)`. Sources of truth (`lg_role_sources` rows): `stripe`, `patreon`, `manual_*`. Arbiter merges, picks the highest tier across `looth1..4`, writes `wp_capabilities`, **never touches** non-tier roles (administrator, bbp_keymaster, customer). looth4 users are protected — early return.

**Bridge points (where `Arbiter::sync` gets called):**
1. Cron tick — `Tick::run` → `Sync::all()` → `Sync::customer()` per dirty customer.
2. Slim webhook → `WpSync::trigger` → `/sync-customer` → `UserProvisioner::findOrProvision` → `RoleSourceWriter::report` → `Arbiter::sync`.
3. OAuth onboarding paths — all use `lgpo_apply_role_via_arbiter()` helper.
4. Gift-auth REST endpoint — see endpoints table.

**Roles:**
- `looth1..4` — paid tiers, managed by Arbiter.
- `customer` — gift-only buyers; sticky. `Plugin::denyGlobalAccessForCustomers` + cap-strip filters keep them out of forums and directories. `RestController::eraseBuddypressFootprint` wipes BB rows on creation.
- `bbp_*` / `administrator` — never touched by the arbiter.

## REST endpoints (prefix `/wp-json/lg-member-sync/v1/`)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/run-now` | X-LGMS-Token | Run `Tick::run` on demand (acquires GET_LOCK) |
| POST | `/sync-customer` | X-LGMS-Token | `Sync::customer($id)` — fast on-checkout provisioning |
| POST | `/send-gift-codes` | X-LGMS-Token | Gift code email + buyer's "My Gifts" stamp |
| POST | `/send-gift-recipient` | X-LGMS-Token | Per-recipient send/resend/reassign email |
| POST | `/refund-request` | **public** (rate-limited 5/hr/IP) | Customer-facing refund-request form → admin email |
| POST | `/admin/cancel-subscription` | manage_options + nonce | Cancel + optional refund |
| POST | `/admin/block-customer` | manage_options + nonce | Block by email |
| POST | `/admin/refund-gift-purchase` | manage_options + nonce | Refund a gift purchase |
| POST | `/me/cancel-subscription` | logged-in + nonce | Customer self-cancel |
| POST | `/me/switch-plan` | logged-in + nonce | Customer self-switch |
| POST | `/me/{create-setup-intent,set-default-payment-method,delete-payment-method}` | logged-in + nonce | Payment-method management |
| GET | `/me/{payment-methods,invoices}` | logged-in + nonce | Customer self-service reads |
| POST | `/me/gift-{send,resend,reassign,void}` | logged-in + nonce | Buyer dashboard actions (proxies to Slim) |
| POST | `/gift-auth` | **public** | Login or sign-up for gift redemption / lgjoin |
| POST | `/affiliate-withdraw` | logged-in + nonce | Affiliate payout request → admin email |

## DB tables (in `lg_membership`)

| Table | Created by | What |
|---|---|---|
| `lg_role_sources` | `Schema.php` | (wp_user_id, source, tier) — input rows the Arbiter merges |
| `lg_patreon_members` | `Schema.php` | Patreon membership snapshot per WP user |
| `lg_event_cursor` | `Schema.php` | Stripe poller's cursor + last-poll status |
| `lg_processed_events` | `Schema.php` | (event_id PK, first_seen_at, last_seen_at, dup_count) — populated by `EventHandler::handle` for duplicate detection |

Plus all the cross-cutting tables in `lg_membership` owned by Slim (`customers`, `subscriptions`, `entitlements`, `gift_codes`, `pending_sessions`, `affiliates`, etc.).

## Settings / cutover gotchas

- **Patreon CSV plugin (`lg-patreon-sync`) is decommissioned** — inactive on dev (`*.deprecated-2026-04-25`). Looth roles persisted via User Role Editor — make sure URE registration sticks on prod.
- **Role display names = role slugs** (renamed via wp-cli; if URE re-syncs labels, push rename via URE's option key).
- **PHP-FPM memory limit** at 256M caps the effective limit even though `WP_MEMORY_LIMIT` is 512M:
  ```bash
  sudo sed -i 's/^php_admin_value\[memory_limit\] = 256M/php_admin_value[memory_limit] = 512M/' /etc/php/8.3/fpm/pool.d/looth-dev.conf
  sudo systemctl reload php8.3-fpm
  ```
  Without this, Search & Filter Pro's bitmap indexer can OOM `/archive/`.
- **Dev mu-plugin `dev-admin-only-login.php` is disabled** (`.disabled` extension). Do NOT re-enable on prod.
- **Debug log rotation** — user-cron at 03:15 daily (`~/logrotate-debug.conf`, state `~/logrotate-debug.state`). Caps `wp-content/debug.log` at 10 MB live + 5 gzipped rotations.
- **BuddyBoss public-content allowlist auto-syncs** — `Pages::PAGES` registry has `public => true|false`. `ensureBuddyBossAllowlist()` runs on `init` priority 20, gated by 6h transient. Self-heals after page renames.

## Key gotchas

- **`Tick::run` holds a GET_LOCK** (`lgms_tick_lock`, non-blocking). A second tick (e.g. cron + manual `/run-now`) returns immediately and logs `tick SKIPPED`. PDO is non-persistent so MySQL auto-releases on connection end — no stale-lock risk.
- **`tick.log` ownership matters** — written by `looth-dev` (php-fpm pool user). If you `echo > tick.log` as `ccdev`, future `@file_put_contents` from PHP fails silently. Delete the file instead and let php-fpm recreate it with the right owner.
- **CF resolve pin (`CURLOPT_RESOLVE => host:port:127.0.0.1`)** for all internal server-to-server HTTP. `wp_remote_post` does NOT honor this — use raw curl. Pattern is in `Tick::run` Pass 1.7 (`reconcile-pending`) and the gift-action proxy in `RestController::proxyToSlim`.
- **`/refund-request` is the only public unauthenticated POST** — rate-limited 5/hr/IP via transient keyed off `HTTP_CF_CONNECTING_IP` (Cloudflare-aware, falls back to `REMOTE_ADDR`).
- **`lg_membership` DB user is separate from the WP DB user** — wp-cli queries the WP DB by default, **cannot** SELECT/INSERT against `lg_membership` tables directly. Use `wp eval 'LGMS\Db::pdo()->...'` to access them.
- **Gift email is stapled to the code** — `RedeemController::redeem()` server-side override + `readonly` form field. DevTools tampering can't change the destination.

## Quick test commands

```bash
# Server SSH
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77

# Trigger the cron tick manually
cd /var/www/dev && wp cron event run lgms_poll_tick

# Manually run Tick::run via REST (exercises GET_LOCK)
SECRET=$(wp --path=/var/www/dev option get lgms_shared_secret)
curl -sS -X POST 'https://dev.loothgroup.com/wp-json/lg-member-sync/v1/run-now' \
  --resolve dev.loothgroup.com:443:127.0.0.1 -k \
  -H "X-LGMS-Token: $SECRET" -H 'Content-Type: application/json' -d '{}'

# Sync one customer
curl -sS -X POST -H "X-LGMS-Token: $SECRET" -H 'Content-Type: application/json' \
  -d '{"customer_id":3}' \
  --resolve dev.loothgroup.com:443:127.0.0.1 -k \
  https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer

# Inspect role sources for a user
wp eval 'print_r(\LGMS\RoleSourceWriter::readAllForUser(1838));' --path=/var/www/dev

# Force BB-allowlist refresh
wp transient delete lgms_bb_allowlist_synced --path=/var/www/dev

# Smoke-test the rate limit on /refund-request (6th+ should be silently throttled)
for i in 1 2 3 4 5 6; do
  curl -sS -o /dev/null -w "$i: %{http_code} %{time_total}s\n" -X POST \
    'https://dev.loothgroup.com/wp-json/lg-member-sync/v1/refund-request' \
    -H 'Content-Type: application/json' \
    -d '{"name":"smoke","email":"smoke@example.com","reasons":["test"],"items":[]}'
done

# Check duplicate-event table
wp --path=/var/www/dev eval '
  $pdo = LGMS\Db::pdo();
  echo "total: " . $pdo->query("SELECT COUNT(*) FROM lg_processed_events")->fetchColumn() . "\n";
  echo "dupes: " . $pdo->query("SELECT COUNT(*) FROM lg_processed_events WHERE dup_count > 0")->fetchColumn() . "\n";
'

# Tail tick.log
tail -f /var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/tick.log
```

## Test fixture cleanup

```bash
# Nuke ianhates + clear ian.davlin gift codes (run between gift flow tests)
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77 'wp eval "
require_once \"/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/vendor/autoload.php\";
\$pdo = \\LGMS\\Db::pdo();
foreach ([\"ianhatesguitars@gmail.com\",\"ian.davlin@gmail.com\"] as \$email) {
    \$st = \$pdo->prepare(\"SELECT id FROM customers WHERE email = ?\");
    \$st->execute([\$email]);
    \$cid = \$st->fetchColumn();
    if (!\$cid) continue;
    \$pdo->prepare(\"DELETE FROM gift_codes WHERE purchased_by = ? OR redeemed_by = ?\")->execute([\$cid, \$cid]);
}" --path=/var/www/dev'
```

Full ianhates teardown (WP user + customer + FK-linked rows) is in earlier session transcripts — search for "ianhates teardown".

## Recent history

- **2026-05-09 (security audit)** — `/refund-request` rate limit, `Tick` GET_LOCK, `lg_processed_events` table + dup detection, `refund_window_days` clamp 1–90. Reverted commit `a545d39` (laptop's stale membership-guide snapshot) — server's working tree is canonical for that work.
- **2026-05-04** — gift-management UI shipped: `[lg_my_gifts]` dashboard, `/me/gift-*` endpoints, `/gift-auth` for login-before-Stripe, gift-buy form (`[lg_gift]`), gift-redemption (`[lg_redeem_gift]`), customer-role lockdown. See `git log --grep gift`.
- Earlier — see `git log` and the companion repo's PICKUP for cross-cutting context.
