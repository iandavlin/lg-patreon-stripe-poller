# lg-patreon-stripe-poller — Claude instructions

At the start of every session, read `PICKUP.md` before doing anything else.
It contains current state, outstanding TODOs in priority order, server access,
endpoint table, DB snapshot, gotchas, and quick test commands. Do not ask what
to work on — the next item is always labeled **NEXT** in the TODO list.

## Stack
- WordPress plugin, PHP 8.3, two databases: WP's (`$wpdb`) and own `lg_membership` (`LGMS\Db::pdo()`)
- Hourly cron `lgms_poll_tick` → `Tick::run` is the heart (Stripe poll, expiry sweep, reconcile-pending, sync sweep)
- Companion Slim app: `lg-stripe-billing` at `/home/ccdev/lg-stripe-billing/` on the same dev box

## Dev deploy
```bash
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77
cd /var/www/dev/wp-content/plugins/lg-patreon-stripe-poller && git pull
# Schema migrations: wp eval 'LGMS\Schema::apply();' --path=/var/www/dev
# Or deactivate + reactivate the plugin (Schema::apply runs on activation)
```

## Conventions
- No comments unless the WHY is non-obvious
- New tables → add `CREATE TABLE IF NOT EXISTS` to `Schema.php` (idempotent on activation)
- All role writes go through `Arbiter::sync` — never write `wp_capabilities` directly
- Server-to-server HTTP: raw curl with `CURLOPT_RESOLVE => host:port:127.0.0.1` (CF challenges PHP-curl). `wp_remote_post` does NOT work for these.
- Keep `PICKUP.md` updated at end of each session
