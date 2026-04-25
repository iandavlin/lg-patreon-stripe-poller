# Pickup — lg-patreon-stripe-poller

*Last worked: 2026-04-25 (long session, dev fully operational)*

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

## Next steps (mostly tied to lg-stripe-billing's PICKUP)

1. Add `[lg_join]` shortcode to render the Stripe Checkout flow (member-facing)
2. Add `[lg_manage_subscription]` shortcode that calls Slim's `/v1/portal`
3. Optionally clean up `Sync::all()` to only iterate "dirty" customers from each tick

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
- `entitlements` (shared)
- `lg_role_sources` (this plugin only — per-source role opinions)
- `lg_event_cursor` (this plugin only — Stripe poller cursor)

## Server access

```bash
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77
```
