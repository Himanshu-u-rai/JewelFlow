# Server update policy (set 2026-10-16)

One VPS runs production and staging. They share every system package, including
PHP; only their PHP-FPM services are separate (`php8.2-fpm` production,
`jewelflow-staging-php82-fpm` staging).

## Ubuntu security updates: automatic

`unattended-upgrades` installs the Ubuntu origins (`jammy`, `jammy-security`, ESM)
daily, unchanged. Package scripts may restart the services they update. Libraries
updated this way stay old in already-running processes until those restart:
`needrestart -b` lists them; restart them in a planned window (PostgreSQL means a
short outage for both environments).

## ondrej/php PPA (PHP 8.1/8.2/8.4, libgd3): manual, by design, reported daily

The PPA is deliberately not in `Unattended-Upgrade::Origins-Pattern`
(`/etc/apt/apt.conf.d/52jewelflows-update-policy` says so on the server). A
php8.2 upgrade hard-restarts production and staging PHP from its package scripts
at an unattended time, on a runtime nobody tested, and unattended-upgrades cannot
limit one origin to chosen packages.

Instead, `jewelflows-pending-updates.timer` (daily 04:30 UTC, after apt refreshed
its lists) runs `/usr/local/sbin/jewelflows-pending-updates`, which only reports:
journal tag `jewelflows-updates` (warning when something is pending) and
`/var/lib/jewelflows/pending-ppa-updates.txt`. It never installs.

Check: `journalctl -t jewelflows-updates -n 5` or `cat /var/lib/jewelflows/pending-ppa-updates.txt`.

## Applying a PHP update (the tested procedure)

1. Run the application suite locally on the new PHP version.
2. Backups first: both databases (pg_dump, read back), `/etc/php/8.2`, and a copy of
   every file of the installed php8.2 packages (old versions disappear from the PPA,
   so this copy is the only way back).
3. Simulate: exactly the installed php8.2 packages, nothing new, nothing removed.
4. Install with `/usr/sbin/policy-rc.d` returning 101 (no restarts by package
   scripts), `NEEDRESTART_MODE=l`, `--force-confold`.
5. Reload each PHP-FPM master gracefully (`systemctl reload`: in-flight requests
   finish, the master re-executes the new binary); restart the two ops-alerts
   workers; restart nginx after `nginx -t` if `needrestart` lists it.
6. Smoke both environments (health, login, admin login, image encoder) and check
   the application logs for new errors.

Avoid 23:40-03:10 and 05:40-06:10 IST (daily scheduled jobs).

## libgd and GIF

CVE-2026-9672 (malformed GIF) is not fixed in any libgd3 package available to this
server (2.3.3-13 from the PPA; Ubuntu lists jammy as "needs evaluation"). The
application never hands GIF bytes to GD (`ImageOptimizer::isGif`, and the customer
photo encoder refuses GIF before decoding). Remove that guard only once the
installed libgd3 carries the fix.
