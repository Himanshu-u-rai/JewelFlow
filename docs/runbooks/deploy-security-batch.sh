#!/usr/bin/env bash
# ============================================================================
# Security batch — phased deploy of ONE environment. Runs ON the VPS as root.
#
#   deploy-security-batch.sh <staging|production> preflight <target-sha>
#   deploy-security-batch.sh <staging|production> deploy    <target-sha>
#
# Implements docs/runbooks/signature-migration-release-order.md inside one
# maintenance window: D0 → Phase 1 (eight files, one per command) → D1 →
# Phase 2 (code, caches, FPM reload, worker restart, :switch) → D2 →
# Phase 2b → D2b → Phase 3 → D3 → up → smoke and row counts.
#
# preflight: read-only gates plus a fresh pg_dump, verified by a full read.
#            Fetches the target commit; changes nothing that serves.
# deploy:    preflight, then the phases.
#
# Fail-closed. A failed gate before the checkout changes nothing. After it,
# the site stays in maintenance and the script stops: recovery is a decision
# (runbook § Recovery — move the code forward; the baseline may serve again
# only before `up`, with Phase 3 down). It never downgrades a schema, never
# deletes a file or a row, never disables a trigger, and never touches the
# other environment's tree or database. Secrets are never printed.
# ============================================================================
set -u -o pipefail

ENVN=${1:?usage: env action target-sha}
ACTION=${2:?usage: env action target-sha}
TARGET=${3:?usage: env action target-sha}
BASE=018b3d810e37d534f498033ab582ee41f3197c27
BRANCH_REF=refs/remotes/origin/security/multi-tenant-audit
STAGING_DIR=/var/www/jewelflow-staging
case "$ENVN" in
  staging)    DIR=$STAGING_DIR;       OWNER=root; DB=jewelflow_staging; APPURL=https://staging.jewelflows.com; HOSTN=staging.jewelflows.com; WORKER=jewelflow-staging-ops-alerts;    OTHER=/var/www/jewelflow ;;
  production) DIR=/var/www/jewelflow; OWNER=dev;  DB=jewelflow;         APPURL=https://jewelflows.com;         HOSTN=jewelflows.com;         WORKER=jewelflow-production-ops-alerts; OTHER=$STAGING_DIR ;;
  *) echo "unknown environment: $ENVN"; exit 64 ;;
esac
EXPAND=(
  2026_09_15_120000_add_invoice_file_disk_to_karigar_invoices
  2026_09_15_140000_add_invoice_image_disk_to_stock_purchases
  2026_09_16_120000_add_digital_signature_disk_to_billing_settings
  2026_09_20_120000_create_signature_relocations_table
  2026_09_21_120000_create_invoice_payment_claims_table
  2026_09_23_120000_add_response_headers_to_idempotency_keys
  2026_09_24_120100_add_notification_outcome_to_report_exports
  2026_09_24_130000_add_expires_lot_id_to_loyalty_transactions
)
NOTIF=2026_09_24_120000_create_notifications_table
CONTRACT=2026_09_20_130000_add_disk_column_check_constraints
TEN="'$(IFS=,; echo "${EXPAND[*]}" | sed "s/,/','/g")','$NOTIF','$CONTRACT'"
CONSTRAINTS="'shop_billing_settings_digital_signature_disk_check','karigar_invoices_attachment_disk_check','stock_purchases_invoice_image_disk_check'"
NEW_LAYOUT='^reporting-exports/[0-9]+/[0-9]+/[^/]+$'
COUNTED=(shops users customers invoices invoice_items invoice_payments cash_transactions karigar_invoices stock_purchases shop_billing_settings loyalty_transactions report_exports idempotency_keys platform_admins)

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
WORK=/root/security-batch/$ENVN-$ACTION-$STAMP
mkdir -p "$WORK" && chmod 700 /root/security-batch "$WORK"   # evidence and dumps: root only
LOG=$WORK/run.log
exec > >(tee -a "$LOG") 2>&1
# Everything the deploy writes into the served tree must stay readable by
# www-data: the checkout, composer and caches run under the normal umask
# (sudo carries it to the owner). A 077 umask here once left changed files
# 0600 and stopped a staging deploy at its checkout gate.
umask 022

PHASE=preflight
ART() { ( cd "$DIR" && sudo -u www-data php artisan "$@" ); }
PSQL() { sudo -u postgres psql -X -A -t -q -v ON_ERROR_STOP=1 -d "$DB" -c "$1"; }
OWNERDO() { if [ "$OWNER" = root ]; then "$@"; else sudo -u "$OWNER" env HOME="$(getent passwd "$OWNER" | cut -d: -f6)" "$@"; fi; }
ok() { echo "ok    $*"; }
note() { echo "note  $*"; }
fail() {
  echo "!!!!! GATE FAILED [$PHASE]: $*"
  if [ "$PHASE" = preflight ]; then echo "Nothing that serves was changed."; else
    echo "The site is LEFT IN MAINTENANCE. State: $WORK. Recovery per runbook § Recovery."; fi
  exit 2
}
now_app() { ART tinker --execute='echo now()->toDateTimeString();' 2>/dev/null | tail -1; }
counts() { for t in "${COUNTED[@]}"; do printf '%s=%s ' "$t" "$(PSQL "select count(*) from $t")"; done; }

echo "########## security batch: $ENVN $ACTION target=$TARGET at $STAMP (UTC) ##########"

# ── PREFLIGHT (read-only gates, the fetch, the backup) ──────────────────────
cd "$DIR" || fail "no directory $DIR"
CFG=$(sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; echo $c["app"]["env"]."|".$c["app"]["url"]."|".$c["database"]["connections"]["pgsql"]["database"];' 2>/dev/null)
[ "$CFG" = "$ENVN|$APPURL|$DB" ] || fail "effective (cached) config is '$CFG', expected '$ENVN|$APPURL|$DB'"
ok "environment identity from the cached config: $CFG"
HEAD_NOW=$(git -C "$DIR" rev-parse HEAD)
[ "$HEAD_NOW" = "$BASE" ] || fail "HEAD is $HEAD_NOW, expected the baseline $BASE"
[ -z "$(git -C "$DIR" status --porcelain --untracked-files=no)" ] || fail "tracked tree is dirty"
ok "serving tree at the baseline, tracked tree clean"
OTHER_HEAD_BEFORE=$(git -C "$OTHER" rev-parse HEAD)
note "other environment $OTHER at $OTHER_HEAD_BEFORE (must be unchanged by this run)"

# The target commit: staging fetches from GitHub as root (its tree is
# root-owned); production — owned by dev, who holds no GitHub key — fetches a
# bundle made from the staging repository, so no root-owned object lands in it.
if [ "$ENVN" = staging ]; then
  git -C "$DIR" fetch --quiet origin "+refs/heads/security/multi-tenant-audit:$BRANCH_REF" || fail "fetch from origin failed"
else
  git -C "$STAGING_DIR" cat-file -e "$TARGET^{commit}" 2>/dev/null || fail "target absent from the staging repository (deploy staging first)"
  BUNDLE=/var/tmp/security-batch-$TARGET.bundle
  git -C "$STAGING_DIR" bundle create "$BUNDLE" "$BASE..$BRANCH_REF" >/dev/null 2>&1 || fail "bundle creation failed"
  chmod 644 "$BUNDLE"
  OWNERDO git -C "$DIR" bundle verify "$BUNDLE" >/dev/null 2>&1 || fail "bundle does not verify against $DIR"
  OWNERDO git -C "$DIR" fetch --quiet "$BUNDLE" "+$BRANCH_REF:$BRANCH_REF" || fail "fetch from the bundle failed"
fi
git -C "$DIR" cat-file -e "$TARGET^{commit}" 2>/dev/null || fail "target $TARGET not present"
git -C "$DIR" merge-base --is-ancestor "$BASE" "$TARGET" || fail "target does not descend from the baseline"
git -C "$DIR" merge-base --is-ancestor "$TARGET" "$BRANCH_REF" || fail "target is not on the security branch"
ok "target $TARGET present, descends from the baseline, on the security branch"
[ -z "$(git -C "$DIR" diff --name-only "$BASE" "$TARGET" -- composer.lock composer.json package.json package-lock.json vite.config.js resources/js resources/css)" ] \
  || fail "dependency or asset sources changed: this script does not rebuild them"
ok "no dependency or asset change between the baseline and the target"

# D0
ART migrate:status --pending 2>&1 | grep -qi "no pending migrations" || fail "D0: a baseline migration is pending"
[ "$(PSQL "select count(*) from migrations where migration in ($TEN)")" = 0 ] || fail "D0: some of the ten are already recorded"
[ "$(PSQL "select coalesce(to_regclass('signature_relocations')::text,'') || coalesce(to_regclass('invoice_payment_claims')::text,'')")" = "" ] || fail "D0: a Phase 1 table already exists"
[ "$(PSQL "select count(*) from information_schema.columns where table_schema = current_schema() and (table_name, column_name) in (('karigar_invoices','invoice_file_disk'),('stock_purchases','invoice_image_disk'),('shop_billing_settings','digital_signature_disk'),('idempotency_keys','response_headers'),('report_exports','notified_at'),('report_exports','notification_error'),('loyalty_transactions','expires_lot_id'))")" = 0 ] \
  || fail "D0: a Phase 1 column was added by hand"
note "D0: notifications table now: $(PSQL "select coalesce(to_regclass('notifications')::text,'absent')") (current configuration only)"
ok "D0 clean"

# The backup: custom-format dump, checksummed, then read end to end.
sudo -u postgres pg_dump -Fc -d "$DB" > "$WORK/$DB.dump" || fail "pg_dump failed"
chmod 600 "$WORK/$DB.dump"
sha256sum "$WORK/$DB.dump" > "$WORK/$DB.dump.sha256"
TOC=$(pg_restore -l "$WORK/$DB.dump" 2>/dev/null | grep -c "TABLE DATA") || true
[ "${TOC:-0}" -gt 50 ] || fail "backup TOC lists only ${TOC:-0} table-data entries"
pg_restore -f /dev/null "$WORK/$DB.dump" 2>/dev/null || fail "backup does not read end to end"
COUNTS_BEFORE=$(counts)
echo "$COUNTS_BEFORE" > "$WORK/counts.before"
ok "backup $WORK/$DB.dump ($(du -h "$WORK/$DB.dump" | cut -f1), $TOC table-data entries, read end to end); sha256 $(cut -c1-16 "$WORK/$DB.dump.sha256")…"
note "row counts: $COUNTS_BEFORE"

# The checkout must not half-apply: the owner must be able to write every
# changed path (mixed ownership was recorded in this tree before).
UNWRITABLE=0
while IFS= read -r f; do
  p="$DIR/$f"; while [ ! -e "$p" ]; do p=$(dirname "$p"); done
  OWNERDO test -w "$p" || { echo "not writable by $OWNER: $p"; UNWRITABLE=$((UNWRITABLE + 1)); }
done < <(git -C "$DIR" diff --name-only "$BASE" "$TARGET")
[ "$UNWRITABLE" = 0 ] || fail "$UNWRITABLE changed path(s) not writable by $OWNER — the checkout could half-apply"
ok "every path the checkout changes is writable by $OWNER"

if [ "$ACTION" = preflight ]; then echo "PREFLIGHT PASSED — nothing that serves was changed."; exit 0; fi
[ "$ACTION" = deploy ] || fail "unknown action $ACTION"

# ── MAINTENANCE, CHECKOUT ────────────────────────────────────────────────────
PHASE=down
LOGF=$DIR/storage/logs/laravel.log
LOG_MARK=$(stat -c %s "$LOGF" 2>/dev/null || echo 0)
ART down --retry=60 || fail "artisan down failed"
ok "maintenance on at $(date -u +%H:%M:%SZ)"

PHASE=checkout
OWNERDO git -C "$DIR" checkout --quiet --detach "$TARGET" || fail "checkout failed"
[ "$(git -C "$DIR" rev-parse HEAD)" = "$TARGET" ] || fail "HEAD is not the target after checkout"
[ -z "$(git -C "$DIR" status --porcelain --untracked-files=no)" ] || fail "tracked tree dirty after checkout"
ART config:clear >/dev/null || fail "config:clear failed"
OWNERDO env COMPOSER_ALLOW_SUPERUSER=1 composer -d "$DIR" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts 2>&1 | tail -3
[ "${PIPESTATUS[0]}" = 0 ] || fail "composer install failed"
ART package:discover --ansi >/dev/null || fail "package:discover failed"
for cls in 'App\\Services\\SignatureStore' 'App\\Console\\Commands\\Reporting\\AuditExportFiles' 'App\\Services\\SignatureRelocationLedger'; do
  grep -q "$cls" "$DIR/vendor/composer/autoload_classmap.php" || fail "class $cls missing from the optimized classmap"
done
while IFS= read -r f; do
  [ -e "$DIR/$f" ] || continue
  sudo -u www-data test -r "$DIR/$f" || fail "www-data cannot read $f"
done < <(git -C "$DIR" diff --name-only "$BASE" "$TARGET")
ok "checked out $TARGET; classmap regenerated; every changed file readable by www-data"

# ── PHASE 1 — EXPAND, one file per command ──────────────────────────────────
PHASE=phase1
for m in "${EXPAND[@]}"; do
  P=$(ART migrate --pretend --force --path="database/migrations/$m.php" 2>&1)
  echo "$P" > "$WORK/pretend-$m.sql"
  echo "$P" | grep -q "$m" || fail "pretend for $m did not name it"
  echo "$P" | grep -qiE 'drop (table|column)|truncate|delete from' && fail "destructive SQL in the pretend of $m"
  ART migrate --force --path="database/migrations/$m.php" >/dev/null 2>&1 || fail "migration $m failed"
  [ "$(PSQL "select count(*) from migrations where migration = '$m'")" = 1 ] || fail "$m not recorded"
  ok "Phase 1: $m"
done

# ── D1 ───────────────────────────────────────────────────────────────────────
PHASE=d1
[ "$(PSQL "select count(*) from migrations where migration in ($TEN)")" = 8 ] || fail "D1: expected exactly the eight"
[ "$(PSQL "select count(*) from migrations where migration in ('$NOTIF','$CONTRACT')")" = 0 ] || fail "D1: notifications or contract recorded"
[ "$(PSQL "select count(*) from pg_constraint where conname in ($CONSTRAINTS)")" = 0 ] || fail "D1: a contract constraint exists"
CONN=$(cd "$DIR" && sudo -u www-data php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $c = config("database.connections.pgsql"); echo ($c["host"] ?? "?").":".($c["port"] ?? "?")."|pooler=".(env("DB_USE_POOLER") ? "yes" : "no")."|url=".(filled($c["url"] ?? null) ? "set" : "unset")."|persistent=".(($c["options"][PDO::ATTR_PERSISTENT] ?? false) ? "yes" : "no");')
[ "$CONN" = "127.0.0.1:5432|pooler=no|url=unset|persistent=no" ] || fail "D1: effective connection is '$CONN'"
ss -ltnp 'sport = :5432' 2>/dev/null | grep -q postgres || fail "D1: 127.0.0.1:5432 is not PostgreSQL itself"
ok "D1 clean: eight applied, contract and notifications absent, direct PostgreSQL connection ($CONN)"

# ── PHASE 2 — APPLICATION ────────────────────────────────────────────────────
PHASE=phase2
ART optimize:clear >/dev/null || fail "optimize:clear failed"
ART config:cache >/dev/null && ART route:cache >/dev/null && ART view:cache >/dev/null || fail "caching failed"
[ -L "$DIR/public/storage" ] || fail "public/storage link missing"
[ -z "$(ART tinker --execute='echo config("loyalty.expiry_active_from");' 2>/dev/null | tail -1)" ] || fail "loyalty expiry activation is set"
RELOAD_EPOCH=$(date +%s)
systemctl reload php8.2-fpm || fail "php8.2-fpm reload failed"
systemctl restart "$WORKER" || fail "restart of $WORKER failed"
sleep 3
SWITCH=$(now_app)
SWITCH_EPOCH=$(date +%s)
FLAT_AT_SWITCH=$(PSQL "select count(*) from report_exports where file_path is not null and file_path !~ '$NEW_LAYOUT'")
echo "$SWITCH|$SWITCH_EPOCH|$FLAT_AT_SWITCH" > "$WORK/switch"
ok "Phase 2 complete. :switch = $SWITCH (application clock and timezone); flat-layout exports at the switch: $FLAT_AT_SWITCH"

d2_checks() {
  local label=$1
  [ "$(git -C "$DIR" rev-parse HEAD)" = "$TARGET" ] || fail "$label: HEAD moved"
  local old=0
  while read -r pid; do
    s=$(date -d "$(ps -o lstart= -p "$pid")" +%s 2>/dev/null) || continue
    [ "$s" -ge "$RELOAD_EPOCH" ] || old=$((old + 1))
  done < <(pgrep -P "$(cat /run/php/php8.2-fpm.pid)"; pgrep -f "$DIR/artisan queue:work")
  [ "$old" = 0 ] || fail "$label: $old php8.2-fpm pool or queue worker process(es) predate the switch"
  local wpid; wpid=$(systemctl show -p MainPID --value "$WORKER")
  [ "$wpid" -gt 0 ] && [ "$(date -d "$(ps -o lstart= -p "$wpid")" +%s)" -ge "$RELOAD_EPOCH" ] || fail "$label: $WORKER not restarted after the switch"
  [ "$(PSQL "select count(*) from report_exports where finished_at > '$SWITCH' and file_path is not null and file_path !~ '$NEW_LAYOUT'")" = 0 ] \
    || fail "$label: a flat-layout export finished after :switch — a baseline writer ran"
  [ "$(PSQL "select count(*) from report_exports where file_path is not null and file_path !~ '$NEW_LAYOUT'")" = "$FLAT_AT_SWITCH" ] \
    || fail "$label: the flat-layout count changed since :switch"
  local qroot; qroot=$(ART tinker --execute="echo Storage::disk(config('reporting.queue_disk','local'))->path('reporting-exports');" 2>/dev/null | tail -1)
  if [ -d "$qroot" ]; then
    [ -z "$(TZ=Asia/Kolkata find "$qroot" -maxdepth 1 -type f -newermt "$SWITCH" 2>/dev/null)" ] || fail "$label: a flat export file was written after :switch"
  fi
  for spec in "karigar_invoices invoice_file_path invoice_file_disk" "stock_purchases invoice_image invoice_image_disk" "shop_billing_settings digital_signature_path digital_signature_disk"; do
    set -- $spec
    [ "$(PSQL "select count(*) from $1 where updated_at > '$SWITCH' and (($2 is not null and $3 is null) or ($2 is null and $3 is not null))")" = 0 ] \
      || fail "$label: a baseline-shaped $1 row was written after :switch"
  done
  ok "$label: no baseline process, no flat export, no baseline-shaped row since :switch"
}

PHASE=d2
[ "$(PSQL "select count(*) from migrations where migration in ($TEN)")" = 8 ] || fail "D2: migrations changed"
d2_checks D2

# ── PHASE 2b — NOTIFICATIONS ─────────────────────────────────────────────────
PHASE=phase2b
P=$(ART migrate --pretend --force --path="database/migrations/$NOTIF.php" 2>&1); echo "$P" > "$WORK/pretend-$NOTIF.sql"
echo "$P" | grep -qiE 'drop (table|column)|truncate|delete from' && fail "destructive SQL in the pretend of $NOTIF"
ART migrate --force --path="database/migrations/$NOTIF.php" >/dev/null 2>&1 || fail "migration $NOTIF failed"
[ -n "$(PSQL "select coalesce(to_regclass('notifications')::text,'')")" ] || fail "notifications table absent after Phase 2b"
ok "Phase 2b: $NOTIF"

PHASE=d2b
[ "$(PSQL "select count(*) from migrations where migration in ($TEN)")" = 9 ] || fail "D2b: expected nine"
[ "$(PSQL "select count(*) from pg_constraint where conname in ($CONSTRAINTS)")" = 0 ] || fail "D2b: a contract constraint exists"
ART reporting:notify-export > "$WORK/notify-export.list" 2>&1 || true
note "D2b: reporting:notify-export (list only, nothing sent): $(tail -1 "$WORK/notify-export.list")"
d2_checks D2b

# ── PHASE 3 — CONTRACT ───────────────────────────────────────────────────────
PHASE=phase3
P=$(ART migrate --pretend --force --path="database/migrations/$CONTRACT.php" 2>&1); echo "$P" > "$WORK/pretend-$CONTRACT.sql"
echo "$P" | grep -qiE 'drop (table|column)|truncate|delete from' && fail "destructive SQL in the pretend of $CONTRACT"
ART migrate --force --path="database/migrations/$CONTRACT.php" > "$WORK/contract.out" 2>&1 || fail "migration $CONTRACT failed (see $WORK/contract.out)"
ok "Phase 3: $CONTRACT"

PHASE=d3
[ "$(PSQL "select count(*) from migrations where migration in ($TEN)")" = 10 ] || fail "D3: expected all ten"
[ "$(PSQL "select count(*) from pg_constraint where conname in ($CONSTRAINTS) and convalidated")" = 3 ] || fail "D3: the three constraints are not all validated"
ART migrate:status --pending 2>&1 | grep -qi "no pending migrations" || fail "D3: a migration is pending"
ok "D3 clean: ten applied, three constraints validated, nothing pending"

# ── UP, SMOKE, ROW COUNTS ────────────────────────────────────────────────────
PHASE=up
ART up || fail "artisan up failed"
ok "maintenance off at $(date -u +%H:%M:%SZ)"
PHASE=smoke
code() { curl -sk -o /dev/null -w '%{http_code}' -m 20 --resolve "$HOSTN:443:127.0.0.1" "https://$HOSTN$1"; }
loc() { curl -sk -o /dev/null -w '%{redirect_url}' -m 20 --resolve "$HOSTN:443:127.0.0.1" "https://$HOSTN$1"; }
[ "$(code /health)" = 200 ] || fail "smoke: /health is $(code /health)"
[ "$(code /admin/login)" = 200 ] || fail "smoke: /admin/login is $(code /admin/login)"
for u in /super-admin/shops /super-admin/users /admin/shops; do
  [ "$(code $u)" = 302 ] && loc "$u" | grep -q '/admin/login' || fail "smoke: unauthenticated $u did not redirect to /admin/login"
done
if [ "$(PSQL "select count(*) from platform_admins where role = 'super_admin'")" -gt 0 ]; then
  [ "$(code /admin/register)" = 302 ] || fail "smoke: /admin/register answered $(code /admin/register) on a configured instance (S3-21: it redirects)"
else
  note "no super admin exists here: /admin/register serves the bootstrap form ($(code /admin/register))"
fi
ok "smoke: /health 200, /admin/login 200, legacy and admin views redirect to login, /admin/register redirects"
NEWERR=$(tail -c +"$((LOG_MARK + 1))" "$LOGF" 2>/dev/null | grep -cE '\.(ERROR|CRITICAL|EMERGENCY|ALERT):' || true)
[ "${NEWERR:-0}" = 0 ] || fail "smoke: $NEWERR new error line(s) in laravel.log (see the log after byte $LOG_MARK)"
COUNTS_AFTER=$(counts)
echo "$COUNTS_AFTER" > "$WORK/counts.after"
[ "$COUNTS_AFTER" = "$COUNTS_BEFORE" ] || fail "row counts changed across the window: before [$COUNTS_BEFORE] after [$COUNTS_AFTER]"
ok "no row added or lost across the window; no new error in laravel.log"
[ "$(git -C "$OTHER" rev-parse HEAD)" = "$OTHER_HEAD_BEFORE" ] || fail "the other environment's HEAD changed"
ok "other environment untouched ($OTHER at $OTHER_HEAD_BEFORE)"
echo "DEPLOY PASSED: $ENVN at $TARGET; :switch $SWITCH; evidence in $WORK"
