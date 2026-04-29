# Pickup — lg-patreon-stripe-poller

*Last worked: 2026-04-29*

> **See the companion repo** [`lg-stripe-billing`](https://github.com/iandavlin/lg-stripe-billing) for the Slim user-facing API. That repo's `PICKUP.md` has the bigger picture; this doc is the WP plugin half.

## What this plugin owns

| Responsibility | Location |
|---|---|
| Patreon OAuth onboarding (UI + creator flow) | `lg-patreon-onboard.php` (legacy LGPO_*) |
| Patreon API polling | `includes/class-lgpo-sync-engine.php` (legacy LGPO_*) |
| Stripe Events API polling | `src/Stripe/` (LGMS\\*) |
| Customer / Subscription / Entitlement repos | `src/Repos/` |
| WP user provisioning + `wp_user_bridge` | `src/Wp/UserProvisioner.php` |
| `lg_role_sources` writer | `src/RoleSourceWriter.php` |
| **Arbiter** (sole writer of `wp_capabilities`) | `src/Arbiter.php` |
| Cron entrypoint | `src/Tick.php` |
| REST endpoints `/run-now` and `/sync-customer` | `src/Wp/RestController.php` |
| Sync orchestrator | `src/Sync.php` |
| **Shortcodes** (not yet built) | `src/Wp/Shortcodes.php` (planned) |

## Architecture — every role write goes through one path

```
                          Arbiter::sync($wp_user_id)
                          ↑    ↑    ↑
              ┌───────────┘    │    └───────────┐
              │                │                │
   STRIPE side                 │       PATREON side
   ──────────                  │       ──────────
   Tick::run pass 1:           │       Tick::run pass 1:
     poll Stripe events,       │         poll Patreon API,
     update lg_membership,     │         (cron-driven, hourly)
     pass 2 calls              │
     Sync::all()               │       OAuth flow:
                               │         link / reconnect / new user
   Slim's /v1/return ──────────┘         (lgpo_apply_role_via_arbiter)
     POSTs /sync-customer
```

Every code path that previously called `$user->set_role()` now calls `RoleSourceWriter::report()` + `Arbiter::sync()`. The arbiter merges all sources, picks the highest tier, writes `wp_capabilities` while preserving `administrator`, `bbp_participant`, etc.

## Verified on dev

- ✅ Cron tick fires hourly, processes Stripe events, syncs customers
- ✅ `/sync-customer` REST endpoint works (Slim posts to it on every checkout)
- ✅ Multi-source arbitration: Stripe=looth2 + Patreon=looth3 → wp_capabilities = looth3
- ✅ Source lapse: Patreon goes null, Stripe still active → falls back to Stripe's tier
- ✅ Default cascade: subscription canceled → entitlement revoked → `lg_role_sources(stripe, NULL)` → wp_capabilities downgrades to looth1
- ✅ Idempotency on entitlement grant (no duplicate rows)

## Subscription status policy (decided 2026-04-28)

The cron and any sync path must treat statuses as follows:

| Stripe status | Access |
|---|---|
| `active` | Full access to tier |
| `trialing` | Full access to trialing tier (same as active) |
| `past_due` | **Keep access** — Stripe retries for several days, don't punish mid-retry |
| `canceled` | Revoke immediately |
| `refunded` | Revoke immediately — all cases, subscription and one-time |

**TODO:** Verify the cron currently handles `trialing` and `past_due` correctly. Likely a one-line fix in the status check.

## Bridge points (so you know where to look)

If the arbiter isn't running, check these places:

1. **Cron-driven Patreon role change** — `LGPO_Sync_Engine::apply_change()` line ~550
2. **OAuth onboarding (3 spots)** — all use the helper `lgpo_apply_role_via_arbiter()` defined at top of `lg-patreon-onboard.php`
3. **Stripe events** — `Tick::run` pass 2 calls `Sync::all()` which iterates customers
4. **Synchronous Slim provisioning** — `Sync::customer($customerId)` via REST `/sync-customer`

## Settings (WP admin: Settings → LG Member Sync)

- DB connection: host, port, name, user, password (the `lg_membership` MySQL user)
- Stripe secret key (used by the Stripe poller)
- Shared secret (auth for Slim's `/sync-customer` calls; matches `LGMS_SHARED_SECRET` in Slim's `.env`)

Plus the existing Patreon settings (Settings → Patreon OAuth) — client credentials, campaign ID, tier map, auto-sync toggle.

## Next steps, in priority order

### 1. ~~Verify subscription status policy in cron~~ — DONE

`EventHandler::onSubscriptionUpdated` now mirrors Slim's `SubscriptionWebhookHandler` policy: grants on `active`/`trialing`, revokes on `canceled`/`incomplete_expired`, leaves `past_due` alone. The bug was masked in practice because direct webhooks usually win, but the poller is now resilient to webhook delivery failures.

### 2. Shortcodes — `src/Wp/Shortcodes.php`

Register in `Plugin::boot()` with `add_action('init', [Wp\Shortcodes::class, 'register'])`.

Four shortcodes to build:

**`[lg_join]`** (~150 lines)
- Fetches products from Slim's `GET /v1/products` (dynamic — no hardcoded price IDs)
- Renders tier picker
- On selection: POSTs to `/billing/v1/checkout`, gets `clientSecret`, mounts Stripe embedded checkout via Stripe.js
- Pre-fills email if user is logged into WP (`wp_get_current_user()`)
- Reference: legacy `lg-stripe-membership.deprecated-2026-04-25/class-checkout.php` on server

**`[lg_manage_subscription]`** (~30 lines)
- Visible to logged-in `looth2`+ users only
- Button POSTs to `/billing/v1/portal` with user's email
- Redirects to Stripe portal URL

**`[lg_redeem_gift]`** (~60 lines)
- Logged-in users enter a gift code
- POSTs to Slim's `POST /v1/redeem` (not yet built in Slim)
- On success: triggers WP sync, shows confirmation

**`[lg_membership_status]`** (~40 lines)
- Shows current tier, renewal date, upgrade prompt
- Reads from `lg_role_sources` or `entitlements` for the current WP user
- Good for account/profile pages

### 3. Expiry sweep in cron tick

Add to `Tick::run` (before the Sync pass):
```sql
UPDATE entitlements SET active = 0
WHERE expires_at IS NOT NULL AND expires_at < NOW() AND active = 1
```
Then fire `Sync::customer()` for each affected customer. **Must ship before one-time yearly goes on sale.**

### 4. Optimization (nice-to-have)

`Sync::all()` currently iterates every customer on every cron tick. Track "dirty" customers in pass 1 and only sync those in pass 2. Linear → constant for unchanged users.

## Quick commands

```bash
# Trigger the cron tick manually
cd /var/www/dev && wp cron event run lgms_poll_tick

# Look at the log
tail -50 /var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/tick.log

# Sync one customer via REST
SECRET=$(sudo grep '^LGMS_SHARED_SECRET=' /home/ccdev/lg-stripe-billing/.env | cut -d= -f2)
curl -s -X POST -H "Content-Type: application/json" -H "X-LGMS-Token: $SECRET" \
  -d '{"customer_id":3}' \
  https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer

# Inspect role sources for a user
mysql -e "SELECT * FROM lg_role_sources WHERE wp_user_id = 1817;"
```

## Tables in `lg_membership` (this plugin's DB writes)

- `customers` (shared with Slim — Slim writes on /v1/return path)
- `wp_user_bridge` (this plugin only — written by UserProvisioner)
- `subscriptions` (shared)
- `entitlements` (shared — expiry sweep runs here)
- `lg_role_sources` (this plugin only — per-source role opinions)
- `lg_event_cursor` (this plugin only — Stripe poller cursor)

## Server access

```bash
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77
```
