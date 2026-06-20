# Backup & Restore Runbook

Spatie Laravel-Backup. Backs up the PostgreSQL database + selected app files into a
single zip per run, stored on the configured destination disk(s).

> **Status (Phase 5):** Local daily backup is live and healthy. **Off-server
> (S3) backup is wired but NOT active** — it needs credentials (see
> [Off-server configuration](#off-server-configuration)). Until then, a server
> loss is a data-loss risk; provision off-site storage before serious customer use.

---

## Where backups are stored

- **Disk:** `local` → `storage/app/<APP_NAME>/` (configured in `config/backup.php` →
  `backup.destination.disks` and `backup.name`).
- **Format:** one encrypted (AES-256) zip per run when `BACKUP_ARCHIVE_PASSWORD` is set;
  otherwise an unencrypted zip.
- **Retention** (`config/backup.php` → `cleanup.default_strategy`, applies to every
  destination disk including off-server):
  - keep ALL backups for **7 days**
  - daily for 16 days, weekly for 8 weeks, monthly for 4 months, yearly for 2 years
  - prune oldest once total exceeds 5000 MB

---

## Daily backup command

```bash
# Run a backup now (DB + files), as the app user:
runuser -u www-data -- /usr/bin/php /var/www/jewelflow/artisan backup:run

# DB only (faster; skips file collection):
runuser -u www-data -- /usr/bin/php /var/www/jewelflow/artisan backup:run --only-db
```

The scheduler runs `backup:run` **daily** (`routes/console.php`).
**Gap to close:** `backup:clean` (retention prune) and `backup:monitor` (health
alert) are NOT scheduled yet — add when alerting destination is decided:
```php
Schedule::command('backup:clean')->daily()->at('01:30');
Schedule::command('backup:monitor')->daily()->at('02:00');
```

---

## Scheduler status check

```bash
# Confirm the scheduler is registered in cron (Laravel scheduler entry):
crontab -l -u www-data | grep schedule:run

# List scheduled tasks the app knows about:
runuser -u www-data -- /usr/bin/php /var/www/jewelflow/artisan schedule:list | grep -i backup
```

---

## Backup verification command

```bash
# Spatie's built-in health check (reachable disk, recent backup, size sane):
runuser -u www-data -- /usr/bin/php /var/www/jewelflow/artisan backup:list

# Inspect the newest local archive without restoring (read-only):
ls -lht storage/app/*/ | head
unzip -l "$(ls -t storage/app/*/*.zip | head -1)" | head
```

A healthy result shows a recent backup, on every configured disk, of plausible size.

---

## Restore test steps

> Restore into a SCRATCH database first — never restore over production to "test".

```bash
# 1. Copy the chosen archive out and unzip (encrypted: -P "$BACKUP_ARCHIVE_PASSWORD"):
cp storage/app/<APP_NAME>/<timestamp>.zip /tmp/restore/
cd /tmp/restore && unzip <timestamp>.zip      # yields db-dumps/<db>.sql + files

# 2. Create a scratch DB and load the dump:
sudo -u postgres createdb jewelflow_restore_test
sudo -u postgres psql jewelflow_restore_test -f db-dumps/postgresql-jewelflow.sql

# 3. Smoke-check the restored data, then drop the scratch DB:
sudo -u postgres psql jewelflow_restore_test -c "SELECT count(*) FROM users; SELECT count(*) FROM dhiran_loans;"
sudo -u postgres dropdb jewelflow_restore_test
```

For a real disaster recovery, restore the dump into a fresh `jewelflow` database on a
new host, then deploy the app code at the matching commit and run `php artisan migrate`
for anything newer than the dump.

---

## Off-server configuration

The S3-compatible disk (`s3` in `config/filesystems.php`) is already defined. Off-server
backup turns on via two env flags + standard AWS creds — **no code change needed**:

```dotenv
# .env (DO NOT commit real values)
BACKUP_OFFSITE_ENABLED=true
BACKUP_OFFSITE_DISK=s3          # or any S3-compatible disk name

AWS_ACCESS_KEY_ID=__set_me__
AWS_SECRET_ACCESS_KEY=__set_me__
AWS_DEFAULT_REGION=__set_me__
AWS_BUCKET=__set_me__
# S3-compatible (Backblaze B2 / Wasabi / MinIO / DO Spaces):
AWS_ENDPOINT=__set_me__
AWS_USE_PATH_STYLE_ENDPOINT=true

# Strongly recommended for off-site archives:
BACKUP_ARCHIVE_PASSWORD=__set_a_long_random_secret__
```

When `BACKUP_OFFSITE_ENABLED=true`, `config/backup.php` adds the off-server disk to the
destination list alongside `local`; each `backup:run` writes to both. Verify with
`artisan backup:list` (it shows one row per disk). The retention strategy above prunes the
off-server copies the same way (weekly/monthly/yearly retained).

**Until these creds exist, backups are local-only — that is the current state.**

---

## Pre-deployment backup rule

**Always take a fresh backup immediately before any production migration or risky
deploy.** Preferred (matches the realm-migration runbook) — a direct timestamped dump
the restore path can use 1:1:

```bash
sudo -u postgres pg_dump -Fc jewelflow \
  -f /var/backups/jewelflow/jewelflow_predeploy_$(date +%Y%m%d_%H%M%S).dump
```

Then deploy / migrate. Keep the pre-deploy dump until the release is confirmed stable.
