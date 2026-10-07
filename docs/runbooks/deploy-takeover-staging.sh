#!/usr/bin/env bash
# ============================================================================
# Retail STAGING release of the takeover candidate (navigation batches,
# Categories, landing page, product preferences). Runs ON the VPS as root:
#
#   deploy-takeover-staging.sh <preflight|release> <from-sha (deployed now)> \
#       <target-sha> <git bundle> <assets tarball> <assets tarball sha256>
#   deploy-takeover-staging.sh resume <from-sha> <target-sha> \
#       <evidence dir of the stopped release> <expected manifest sha256>
#
# `resume` finishes a release that a gate stopped AFTER the migration was
# applied (staging in maintenance on the target commit, the four tables
# empty). It moves forward only: it re-verifies the migrated database against
# the stopped run's own backup, then runs the same remaining gates. (First
# used on 2026-10-07: the schema gate compared hashes of pg_dump output, which
# carries a random \restrict token on every run, so it could never match.)
#
# What differs from deploy-forward.sh, and why that script cannot do this:
#   - one additive migration (four promotion-metadata tables) and new built
#     assets, both of which deploy-forward.sh refuses;
#   - the candidate arrives as a git bundle (nothing is pushed anywhere) and
#     the assets as the tarball that was built and verified from the same
#     commit, pinned by its SHA-256;
#   - STAGING-ONLY process handling: php8.2-fpm serves production too, so it
#     is NOT reloaded (opcache validates timestamps, revalidate 2 s: the script
#     waits and then PROVES the new code is served before it leaves
#     maintenance). Only the staging worker is stopped and started, and only
#     the staging reconcile cron is held and put back, byte for byte.
#   - Dhiran: staging has no Dhiran host and must never be paired with the
#     production one. DHIRAN_REGISTER_URL is set explicitly EMPTY (disabled).
#
# Fail-closed. `preflight` changes nothing. In `release`, a gate that fails
# before the checkout puts staging back as it was; a gate that fails after it
# leaves staging IN MAINTENANCE with the worker stopped and the cron held,
# and prints where the evidence and the held files are. Recovery rule:
# docs/runbooks/product-promotion-recognition.md, "Recovery".
# ============================================================================
set -uo pipefail

MODE=${1:?usage: preflight|release from-sha target-sha bundle assets.tar.gz assets-sha256 | resume from-sha target-sha stopped-run-dir manifest-sha256}
FROM=${2:?from-sha}
TARGET=${3:?target-sha}
case "$MODE" in
  preflight|release) BUNDLE=${4:?bundle}; ASSETS=${5:?assets tarball}; ASSETS_SHA=${6:?assets sha256} ;;
  resume) PREV=${4:?evidence dir of the stopped release}; MANIFEST_EXPECT=${5:?expected manifest sha256} ;;
  *) echo "unknown mode: $MODE"; exit 64 ;;
esac

DIR=/var/www/jewelflow-staging
DB=jewelflow_staging
APPURL=https://staging.jewelflows.com
HOSTN=staging.jewelflows.com
WORKER=jewelflow-staging-ops-alerts
CRON=/etc/cron.d/jewelflow-staging-reconcile
CRONLOCK=$DIR/storage/framework/reconcile-payments.lock
OTHER=/var/www/jewelflow
OTHER_HOST=jewelflows.com
FLOOR=250950fa7739085fb1240f1c321711df336bc31c
BRANCH=integration/jewelflows-takeover
REF=refs/takeover/candidate
MIGRATION=2026_10_05_000001_create_product_promotion_tables
NEW_TABLES=(product_promotion_preferences product_promotion_exposures product_recognition_requests product_recognitions)
COUNTED=(shops users customers invoices invoice_items invoice_payments cash_transactions karigar_invoices stock_purchases shop_billing_settings loyalty_transactions report_exports idempotency_keys platform_admins categories sub_categories)

[ "$(id -u)" = 0 ] || { echo "run as root"; exit 64; }
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
WORK=/root/takeover-staging/$MODE-$STAMP
mkdir -p "$WORK" && chmod 700 /root/takeover-staging "$WORK"
exec > >(tee -a "$WORK/run.log") 2>&1
TEE=$!; trap 'exec >&- 2>&-; wait "$TEE"' EXIT
umask 022

PHASE=preflight
QUIESCED=0
ART() { ( cd "$DIR" && sudo -u www-data php artisan "$@" ); }
CFG() { if sudo -u www-data test -r "$DIR/.env"; then ART "$@"; else echo "www-data cannot read $DIR/.env: refusing to run artisan as root" >&2; return 1; fi; }
cache_ok() { ( cd "$DIR" && sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; exit($c["app"]["key"] && $c["database"]["connections"]["pgsql"]["password"] !== null ? 0 : 1);' ); }
cfgid() { ( cd "$DIR" && sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; echo $c["app"]["env"]."|".$c["app"]["url"]."|".$c["database"]["connections"]["pgsql"]["database"];' 2>/dev/null ); }
PSQL() { sudo -u postgres psql -X -A -t -q -v ON_ERROR_STOP=1 -d "$DB" -c "$1"; }
ok() { echo "ok    $*"; }
code() { curl -sk -o /dev/null -w '%{http_code}' -m 20 --resolve "$HOSTN:443:127.0.0.1" "$@"; }
counts() { local t; for t in "${COUNTED[@]}"; do printf '%s=%s ' "$t" "$(PSQL "select count(*) from $t")"; done; }
new_rows() { local t n=0; for t in "${NEW_TABLES[@]}"; do n=$((n + $(PSQL "select count(*) from $t"))); done; echo "$n"; }
# The existing schema as text: comments and pg_dump's per-run \restrict token dropped (the
# token is random, so two dumps of one schema never hash alike), and the release's own
# relations left out by pattern (the tables and their id sequences).
schema_norm() { grep -vE '^(--|\\(un)?restrict |$)'; }
schema_sql() { sudo -u postgres pg_dump -s -d "$DB" -T 'product_promotion_*' -T 'product_recognition*' | schema_norm; }
untracked() { git -C "$DIR" status --porcelain | grep '^??' | sort; }
# Production must be exactly as it was: its commit, its maintenance state, its
# config cache, and the two shared services (never reloaded by this script).
other_state() {
  printf '%s|%s|%s|%s|%s' "$(git -C "$OTHER" rev-parse HEAD)" "$([ -e "$OTHER/storage/framework/down" ] && echo down || echo up)" \
    "$(stat -c %Y "$OTHER/bootstrap/cache/config.php")" "$(systemctl show -p ActiveEnterTimestamp --value php8.2-fpm)" "$(systemctl show -p ActiveEnterTimestamp --value nginx)"
}
log_mark() { local f; for f in "$DIR"/storage/logs/laravel*.log; do [ -e "$f" ] && echo "$f $(stat -c %s "$f")"; done; true; }
new_errors() {
  local f start n=0
  for f in "$DIR"/storage/logs/laravel*.log; do
    [ -e "$f" ] || continue
    start=$(printf '%s\n' "$LOG_MARK" | awk -v f="$f" '$1 == f { print $2 }')
    n=$((n + $(tail -c +"$((${start:-0} + 1))" "$f" | grep -cE '\.(ERROR|CRITICAL|EMERGENCY|ALERT):')))
  done
  echo "$n"
}
HELD=$WORK/cron.held
unquiesce() {
  [ -e "$HELD" ] && { mv "$HELD" "$CRON"; echo "      cron put back: $CRON"; }
  systemctl start "$WORKER" && echo "      $WORKER started"
  ART up >/dev/null 2>&1 && echo "      maintenance off"
}
fail() {
  echo "!!!!! GATE FAILED [$PHASE]: $*"
  case "$PHASE" in
    preflight) echo "Nothing was changed." ;;
    quiesce|backup)
      echo "No code, schema or configuration was changed. Putting staging back as it was:"
      [ "$QUIESCED" = 1 ] && unquiesce ;;
    smoke) echo "Staging is UP on $TARGET (maintenance had ended); nothing was rolled back. Inspect now. Evidence: $WORK." ;;
    *) echo "Staging is LEFT IN MAINTENANCE, $WORKER stopped, the reconcile cron held at ${HELD:-$WORK/cron.held}."
       echo "Evidence and the pre-release copies (.env, config cache, assets, database dump): $WORK."
       echo "Recovery: docs/runbooks/product-promotion-recognition.md, 'Recovery' (count the four tables first)." ;;
  esac
  exit 2
}

verify_migrated() {
  PHASE=migrate
  grep -qi "no pending migrations" <<< "$(ART migrate:status --pending 2>&1)" || fail "a migration is still pending"
  [ "$(PSQL "select count(*) from migrations")" = "$((MIG_BEFORE + 1))" ] || fail "the migrations table did not grow by one"
  [ "$(PSQL "select count(*) from information_schema.tables where table_schema = 'public'")" = "$((TBL_BEFORE + 4))" ] || fail "the table count did not grow by four"
  [ "$(PSQL "select count(*) from pg_trigger where not tgisinternal")" = "$TRIG_BEFORE" ] || fail "the trigger count changed"
  [ "$(new_rows)" = 0 ] || fail "the new tables are not empty"
  schema_sql > "$WORK/schema.after.sql"
  cmp -s "$WORK/schema.before.sql" "$WORK/schema.after.sql" || fail "the schema of the existing tables changed: diff $WORK/schema.before.sql $WORK/schema.after.sql"
  [ "$(counts)" = "$COUNTS_BEFORE" ] || fail "row counts changed"
  ok "migration $MIGRATION applied: four empty tables; existing schema ($(wc -l < "$WORK/schema.after.sql") lines) identical, $TRIG_BEFORE triggers and row counts unchanged"
}
finish() {
  PHASE=caches
  ART route:cache >/dev/null && ART view:clear >/dev/null && ART view:cache >/dev/null || fail "caching failed"
  ART assets:verify-fresh >/dev/null 2>&1 || fail "assets:verify-fresh says the built assets are older than their sources"
  while IFS= read -r f; do [ -e "$DIR/$f" ] || continue; sudo -u www-data test -r "$DIR/$f" || fail "www-data cannot read $f"; done < <(git diff --name-only "$FROM" "$TARGET")
  [ -z "$(find "$DIR/storage" "$DIR/bootstrap/cache" "$DIR/public/build" -user root ! -path "$DIR/bootstrap/cache/config.php" 2>/dev/null | head -1)" ] || fail "a root-owned file appeared under storage, bootstrap/cache or public/build"
  ok "routes and views cached; assets fresh; every changed file readable by www-data"

  # ── proof, still in maintenance: the shared FPM pool serves the NEW code ─────
  PHASE=freshness
  sleep 6   # opcache: validate_timestamps on, revalidate_freq 2, file_update_protection 2
  JAR=$WORK/bypass.jar
  [ "$(code -c "$JAR" "https://$HOSTN/$SECRET")" = 302 ] || fail "the maintenance bypass did not answer"
  LANDING=$(curl -sk -m 20 -b "$JAR" --resolve "$HOSTN:443:127.0.0.1" "https://$HOSTN/")
  grep -q 'Start with Retail' <<< "$LANDING" || fail "the new landing page is not served (stale code?)"
  ! grep -qiE 'https?://dhiran\.' <<< "$LANDING" || fail "the landing page links to a Dhiran host"
  [ "$(code -b "$JAR" "https://$HOSTN/product-preferences")" = 302 ] || fail "the new route /product-preferences is not served"
  [ "$(curl -sk -m 20 -b "$JAR" --resolve "$HOSTN:443:127.0.0.1" "https://$HOSTN/build/manifest.json" | sha256sum | cut -d' ' -f1)" = "$MANIFEST_SHA" ] || fail "the served asset manifest is not the released one"
  [ "$(code "https://$HOSTN/")" = 503 ] || fail "maintenance does not hold for a visitor without the bypass"
  rm -f "$JAR"
  ok "new code is served without any shared-service reload: landing, new route and released manifest; visitors still get 503"

  # ── staging processes back, then up ──────────────────────────────────────────
  PHASE=processes
  mv "$HELD" "$CRON" || fail "could not put the reconcile cron back"
  [ "$(sha256sum "$CRON" | cut -d' ' -f1)" = "$CRON_SHA" ] && [ "$(stat -c '%U:%G %a' "$CRON")" = "$CRON_STAT" ] || fail "the reconcile cron is not byte-identical with its mode"
  systemctl start "$WORKER" && sleep 2 && [ "$(systemctl is-failed "$WORKER")" != failed ] || fail "$WORKER did not start"
  ok "reconcile cron back ($CRON_SHA, $CRON_STAT); $WORKER started"
  PHASE=up
  ART up >/dev/null || fail "artisan up failed"
  ok "maintenance off at $(date -u +%H:%M:%SZ)"

  PHASE=smoke
  for u in /health / /login /register /admin/login; do [ "$(code "https://$HOSTN$u")" = 200 ] || fail "smoke: $u is not 200"; done
  for u in /dashboard /product-preferences /super-admin/shops /admin/shops; do [ "$(code "https://$HOSTN$u")" = 302 ] || fail "smoke: $u did not redirect a guest"; done
  NEWERR=$(new_errors)
  [ "$NEWERR" = 0 ] || fail "smoke: $NEWERR new error line(s) in storage/logs/laravel*.log since the mark ($WORK/log.mark)"
  [ "$(counts)" = "$COUNTS_BEFORE" ] || fail "row counts changed across the window"
  [ "$(untracked)" = "$UNTRACKED_BEFORE" ] || fail "the untracked files changed"
  [ "$(other_state)" = "$OTHER_BEFORE" ] || fail "production's commit, maintenance state, config cache or a shared service changed: $(other_state)"
  [ "$(curl -sk -o /dev/null -w '%{http_code}' -m 20 --resolve "$OTHER_HOST:443:127.0.0.1" "https://$OTHER_HOST/health")" = "$OTHER_HEALTH_BEFORE" ] || fail "production /health changed"
  ok "smoke passed; no row added or lost; no new error; production and the shared services untouched"
  echo "TAKEOVER STAGING RELEASE PASSED: staging at $TARGET (from $FROM); manifest $MANIFEST_SHA; backup $BACKUP ($DUMP_SHA); evidence in $WORK"
}

# ── resume: finish a release a gate stopped after the migration ──────────────
if [ "$MODE" = resume ]; then
  echo "########## takeover staging resume: $FROM -> $TARGET at $STAMP (UTC), finishing the run in $PREV ##########"
  cd "$DIR" || fail "no directory $DIR"
  grep -qE '^APP_ENV=staging$' "$DIR/.env" || fail "$DIR/.env is not APP_ENV=staging"
  [ -f storage/framework/down ] || fail "staging is not in maintenance: there is nothing to resume"
  [ "$(git rev-parse HEAD)" = "$TARGET" ] && [ -z "$(git status --porcelain --untracked-files=no)" ] || fail "staging is not cleanly on $TARGET"
  [ "$(cat "$PREV/code.before" 2>/dev/null)" = "$FROM" ] || fail "$PREV is not the evidence of a release from $FROM"
  HELD=$PREV/cron.held; BACKUP=$PREV/$DB.dump
  [ -f "$HELD" ] && [ ! -e "$CRON" ] || fail "the reconcile cron is not held at $HELD"
  [ "$(systemctl is-active "$WORKER")" != active ] || fail "$WORKER is running"
  [ -f "$BACKUP" ] && pg_restore -f /dev/null "$BACKUP" 2>/dev/null || fail "the stopped run's backup does not read"
  [ "$(cfgid)" = "staging|$APPURL|$DB" ] || fail "effective config is '$(cfgid)'"
  [ "$(code "https://$HOSTN/")" = 503 ] || fail "staging does not answer 503"
  # What was true before the release, from the stopped run's own backup and files.
  LOG_MARK=$(cat "$PREV/log.mark")
  SECRET=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["secret"] ?? "";' "$DIR/storage/framework/down")
  [ -n "$SECRET" ] || fail "the maintenance file holds no bypass secret"
  CRON_SHA=$(sha256sum "$HELD" | cut -d' ' -f1); CRON_STAT=$(stat -c '%U:%G %a' "$HELD")
  DUMP_SHA=$(sha256sum "$BACKUP" | cut -d' ' -f1)
  RESTORED=$(cat "$PREV/restore-rehearsal.txt")
  COUNTS_BEFORE=$(for t in "${COUNTED[@]}"; do printf '%s=%s ' "$t" "$(awk -v t="$t" '$1 == "count" && $2 == t { print $3 }' <<< "$RESTORED")"; done)
  MIG_BEFORE=$(awk '$1 == "count" && $2 == "migrations" { print $3 }' <<< "$RESTORED"); TRIG_BEFORE=$(awk '$1 == "triggers" { print $2 }' <<< "$RESTORED")
  TBL_BEFORE=$(pg_restore -l "$BACKUP" | grep -cE '^[0-9]+; [0-9]+ [0-9]+ TABLE public ')
  pg_restore -s -f - "$BACKUP" | schema_norm > "$WORK/schema.before.sql"
  [ -s "$WORK/schema.before.sql" ] || fail "could not read the schema from the backup"
  MANIFEST_SHA=$(sha256sum "$DIR/public/build/manifest.json" | cut -d' ' -f1)
  [ "$MANIFEST_SHA" = "$MANIFEST_EXPECT" ] || fail "the manifest on disk is $MANIFEST_SHA, not the released $MANIFEST_EXPECT"
  ENV_STAT=$(stat -c '%U:%G %a' "$DIR/.env")
  [ "$ENV_STAT" = "$(stat -c '%U:%G %a' "$PREV/env.before")" ] || [ "$ENV_STAT" = "www-data:www-data 600" ] || fail ".env ownership or mode is $ENV_STAT"
  UNTRACKED_BEFORE=$(untracked)
  OTHER_BEFORE=$(other_state)
  OTHER_HEALTH_BEFORE=$(curl -sk -o /dev/null -w '%{http_code}' -m 20 --resolve "$OTHER_HOST:443:127.0.0.1" "https://$OTHER_HOST/health")
  ok "resuming on $TARGET in maintenance; before-state from $PREV: $MIG_BEFORE migrations, $TRIG_BEFORE triggers, $TBL_BEFORE tables; backup $DUMP_SHA"
  ok "production now: ${OTHER_BEFORE%%|*} (health $OTHER_HEALTH_BEFORE)"
  verify_migrated
  finish
  exit 0
fi

echo "########## takeover staging $MODE: $FROM -> $TARGET at $STAMP (UTC) ##########"
cd "$DIR" || fail "no directory $DIR"

# ── preflight: identity, candidate, delta, assets (nothing is changed) ───────
grep -qE '^APP_ENV=staging$' "$DIR/.env" || fail "$DIR/.env is not APP_ENV=staging"
[ "$(cfgid)" = "staging|$APPURL|$DB" ] || fail "effective (cached) config is '$(cfgid)'"
[ "$(git rev-parse HEAD)" = "$FROM" ] || fail "HEAD is $(git rev-parse HEAD), expected the deployed $FROM"
[ -z "$(git status --porcelain --untracked-files=no)" ] || fail "tracked tree is dirty"
[ ! -f storage/framework/down ] || fail "staging is already in maintenance"
sudo -u www-data test -r "$DIR/.env" || fail "www-data cannot read .env"
UNTRACKED_BEFORE=$(untracked)
ENV_STAT=$(stat -c '%U:%G %a' "$DIR/.env")
OTHER_BEFORE=$(other_state)
OTHER_HEALTH_BEFORE=$(curl -sk -o /dev/null -w '%{http_code}' -m 20 --resolve "$OTHER_HOST:443:127.0.0.1" "https://$OTHER_HOST/health")
ok "staging is $FROM, clean, up; production recorded: ${OTHER_BEFORE%%|*} (health $OTHER_HEALTH_BEFORE)"

[ -f "$BUNDLE" ] && git bundle verify "$BUNDLE" >/dev/null 2>&1 || fail "the bundle is missing or does not verify against this repository"
git fetch --quiet "$BUNDLE" "+refs/heads/$BRANCH:$REF" || fail "fetch from the bundle failed"
[ "$(git rev-parse "$REF")" = "$TARGET" ] || fail "the bundle's $BRANCH is $(git rev-parse "$REF"), not $TARGET"
git merge-base --is-ancestor "$FROM" "$TARGET" || fail "target does not descend from the deployed commit"
git merge-base --is-ancestor "$FLOOR" "$TARGET" || fail "target predates the security release floor"
[ "$(git diff --name-status "$FROM" "$TARGET" -- database/migrations)" = $'A\tdatabase/migrations/'"$MIGRATION.php" ] || fail "the migration delta is not exactly $MIGRATION"
[ -z "$(git diff --name-only "$FROM" "$TARGET" -- composer.json composer.lock package.json package-lock.json)" ] || fail "the target changes dependencies"
DEVREF=$(git grep -nE '(^|[^A-Za-z_])(Tests|Faker|PHPUnit)\\|Mockery|fake\(\)' "$TARGET" -- app bootstrap config routes database/migrations | grep -v 'class_exists(' || true)
[ -z "$DEVREF" ] || fail "the target's production code references dev-only code: $DEVREF"
grep -qi "no pending migrations" <<< "$(ART migrate:status --pending 2>&1)" || fail "a migration is already pending on the deployed code"
for t in "${NEW_TABLES[@]}"; do [ "$(PSQL "select count(*) from information_schema.tables where table_schema = 'public' and table_name = '$t'")" = 0 ] || fail "table $t already exists"; done
[ -f "$ASSETS" ] && [ "$(sha256sum "$ASSETS" | cut -d' ' -f1)" = "$ASSETS_SHA" ] || fail "the assets tarball is missing or its SHA-256 is not $ASSETS_SHA"
[ -z "$(tar -tzf "$ASSETS" | grep -vE '^build/([A-Za-z0-9._/-]*)$' | head -1)" ] && ! tar -tzf "$ASSETS" | grep -q '\.\.' || fail "the assets tarball holds a path outside build/"
tar -tzf "$ASSETS" | grep -qx 'build/manifest.json' || fail "the assets tarball has no build/manifest.json"
[ "$(df --output=avail -m /var/www | tail -1)" -gt 1024 ] || fail "less than 1 GB free"
MIG_BEFORE=$(PSQL "select count(*) from migrations"); TRIG_BEFORE=$(PSQL "select count(*) from pg_trigger where not tgisinternal")
TBL_BEFORE=$(PSQL "select count(*) from information_schema.tables where table_schema = 'public'")
COUNTS_BEFORE=$(counts)
schema_sql > "$WORK/schema.before.sql"
[ -s "$WORK/schema.before.sql" ] && schema_sql | cmp -s - "$WORK/schema.before.sql" || fail "two reads of the schema differ: the schema gate would be meaningless"
SCHEMA_BEFORE=$(sha256sum < "$WORK/schema.before.sql" | cut -d' ' -f1)
ok "candidate $TARGET: on the bundle's branch, above the floor, one additive migration, no dependency change, no dev-only reference"
ok "changes: $(git diff --shortstat "$FROM" "$TARGET")"
ok "database $DB: $MIG_BEFORE migrations, $TRIG_BEFORE triggers, $TBL_BEFORE tables; schema hash $SCHEMA_BEFORE"
ok "assets tarball $ASSETS_SHA: $(tar -tzf "$ASSETS" | grep -vc '/$') files"
if [ "$MODE" = preflight ]; then echo "PREFLIGHT PASSED: nothing was changed. Evidence: $WORK"; exit 0; fi

# ── quiesce: staging only ────────────────────────────────────────────────────
PHASE=quiesce
LOG_MARK=$(log_mark); echo "$LOG_MARK" > "$WORK/log.mark"
SECRET=$(cat /proc/sys/kernel/random/uuid)
QUIESCED=1
ART down --retry=30 --secret="$SECRET" >/dev/null || fail "artisan down failed"
systemctl stop "$WORKER" || fail "could not stop $WORKER"
[ "$(systemctl is-active "$WORKER")" != active ] || fail "$WORKER is still active"
CRON_SHA=$(sha256sum "$CRON" | cut -d' ' -f1); CRON_STAT=$(stat -c '%U:%G %a' "$CRON")
mv "$CRON" "$HELD" || fail "could not hold $CRON"
for i in $(seq 1 60); do sudo -u www-data flock -n "$CRONLOCK" true 2>/dev/null && break; sleep 3; done
sudo -u www-data flock -n "$CRONLOCK" true 2>/dev/null || fail "a reconcile run still holds its lock after 3 minutes"
sleep 5   # requests already inside PHP finish
[ "$(code "https://$HOSTN/")" = 503 ] || fail "staging does not answer 503 in maintenance"
ok "maintenance on at $(date -u +%H:%M:%SZ); $WORKER stopped; reconcile cron held ($CRON_SHA, $CRON_STAT) and idle"

# ── backup: fresh, protected, read end to end, restored in isolation ────────
PHASE=backup
sudo -u postgres pg_dump -Fc -d "$DB" > "$WORK/$DB.dump" && chmod 600 "$WORK/$DB.dump" || fail "pg_dump failed"
pg_restore -f /dev/null "$WORK/$DB.dump" 2>/dev/null || fail "the backup does not read end to end"
DUMP_SHA=$(sha256sum "$WORK/$DB.dump" | cut -d' ' -f1); DUMP_DATA=$(pg_restore -l "$WORK/$DB.dump" | grep -c 'TABLE DATA')
SANDBOX_SH=$(cat <<'SH'
set -eu
fail() { echo "sandbox: $*" >&2; exit 97; }
[ "$(id -u)" != 0 ] || fail "running as root"
for p in $MUST_NOT_SEE; do if [ -r "$p" ] || ls "$p" >/dev/null 2>&1; then fail "can read $p"; fi; done
if (exec 3<>"/dev/tcp/127.0.0.1/5432") 2>/dev/null; then fail "can reach the live PostgreSQL port"; fi
[ "$(awk 'NR > 2 { sub(/:.*/, ""); gsub(/[[:space:]]/, ""); printf "%s ", $0 }' /proc/net/dev)" = "lo " ] || fail "has a network interface besides lo"
B=$(ls -d /usr/lib/postgresql/*/bin | sort -V | tail -1)
W=$(mktemp -d /mnt/pg.XXXXXX)
"$B/initdb" -D "$W/data" -U postgres -A trust -E UTF8 --locale=C.UTF-8 </dev/null >/dev/null
"$B/pg_ctl" -D "$W/data" -s -w -l "$W/log" -o "-c listen_addresses='' -c unix_socket_directories=$W -c fsync=off -c synchronous_commit=off -c full_page_writes=off -c shared_buffers=64MB" start </dev/null >/dev/null
P() { "$B/psql" -X -q -A -t -v ON_ERROR_STOP=1 -h "$W" -U postgres "$@"; }
P -d postgres -c "create database restore" </dev/null
cat > "$W/in.dump"                       # the dump: this unit's stdin
"$B/pg_restore" --no-owner --no-acl --exit-on-error -h "$W" -U postgres -d restore "$W/in.dump" </dev/null >/dev/null
for t in $COUNTED migrations; do printf 'count %s %s\n' "$t" "$(P -d restore -c "select count(*) from public.\"$t\"" </dev/null)"; done
printf 'triggers %s\n' "$(P -d restore -c 'select count(*) from pg_trigger where not tgisinternal' </dev/null)"
"$B/pg_ctl" -D "$W/data" -s -m immediate stop </dev/null >/dev/null || true
SH
)
RESTORED=$(systemd-run -p DynamicUser=yes --quiet --wait --pipe --collect \
    -p PrivateNetwork=yes -p PrivateIPC=yes -p ProtectProc=invisible -p ProtectSystem=strict -p ProtectHome=yes \
    -p NoNewPrivileges=yes -p CapabilityBoundingSet= -p "InaccessiblePaths=-/var/www -/var/lib/postgresql -/etc/postgresql -/run/postgresql -/var/backups -/var/log -/etc/nginx -/etc/letsencrypt -/etc/ssh -/root" \
    -p "TemporaryFileSystem=/mnt:mode=1777,size=2G" -p MemoryMax=3G -p TasksMax=512 -p RuntimeMaxSec=900 -p LimitFSIZE=2G \
    -E "COUNTED=${COUNTED[*]}" -E "MUST_NOT_SEE=$DIR/.env $OTHER/.env /var/lib/postgresql /run/postgresql" \
    -- /bin/bash -c "$SANDBOX_SH" < "$WORK/$DB.dump") || fail "the backup did not restore in the isolated instance"
echo "$RESTORED" > "$WORK/restore-rehearsal.txt"
for t in "${COUNTED[@]}"; do
  [ "$(awk -v t="$t" '$1 == "count" && $2 == t { print $3 }' <<< "$RESTORED")" = "$(grep -oE "(^| )$t=[0-9]+" <<< "$COUNTS_BEFORE" | cut -d= -f2)" ] || fail "restored $t does not match the live count"
done
[ "$(awk '$1 == "count" && $2 == "migrations" { print $3 }' <<< "$RESTORED")" = "$MIG_BEFORE" ] || fail "restored migrations do not match"
[ "$(awk '$1 == "triggers" { print $2 }' <<< "$RESTORED")" = "$TRIG_BEFORE" ] || fail "restored triggers do not match"
cp -p "$DIR/.env" "$WORK/env.before" && cp -p "$DIR/bootstrap/cache/config.php" "$WORK/config.cache.before" && chmod 600 "$WORK/env.before" "$WORK/config.cache.before" || fail "could not copy the configuration"
tar -C "$DIR/public" -czf "$WORK/build.before.tar.gz" build || fail "could not copy the deployed assets"
echo "$FROM" > "$WORK/code.before"
ok "backup $WORK/$DB.dump: sha256 $DUMP_SHA, $DUMP_DATA table-data entries, read end to end"
ok "restore rehearsal in an isolated instance (own PostgreSQL, throwaway user, no network): ${#COUNTED[@]} tables, $MIG_BEFORE migrations and $TRIG_BEFORE triggers match staging"
BACKUP=$WORK/$DB.dump
ok "pre-release copies: code $FROM, .env, config cache, public/build"

# ── release: code, assets, configuration, the one migration ─────────────────
PHASE=checkout
git checkout --quiet --detach "$TARGET" || fail "checkout failed"
[ "$(git rev-parse HEAD)" = "$TARGET" ] && [ -z "$(git status --porcelain --untracked-files=no)" ] || fail "checkout did not land cleanly"
[ "$(untracked)" = "$UNTRACKED_BEFORE" ] || fail "the untracked files changed"
env COMPOSER_ALLOW_SUPERUSER=1 composer -d "$DIR" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --quiet || fail "composer install failed"
ART package:discover >/dev/null || fail "package:discover failed"
PHASE=assets
rm -rf "$DIR/public/build.incoming" && mkdir "$DIR/public/build.incoming" && tar -xzmf "$ASSETS" -C "$DIR/public/build.incoming" || fail "could not unpack the assets"
mv "$DIR/public/build" "$WORK/build.replaced" && mv "$DIR/public/build.incoming/build" "$DIR/public/build" && rmdir "$DIR/public/build.incoming" || fail "could not put the assets in place"
chown -R www-data:www-data "$DIR/public/build" || fail "chown of the assets failed"
MANIFEST_SHA=$(sha256sum "$DIR/public/build/manifest.json" | cut -d' ' -f1)
PHASE=config
if ! grep -q '^DHIRAN_REGISTER_URL=' "$DIR/.env"; then
  printf '\n# Staging has no Dhiran host and must never point at the production one: explicitly disabled.\nDHIRAN_REGISTER_URL=\n' >> "$DIR/.env" || fail "could not write DHIRAN_REGISTER_URL"
fi
[ "$(stat -c '%U:%G %a' "$DIR/.env")" = "$ENV_STAT" ] || fail ".env ownership or mode changed (was $ENV_STAT)"
CFG config:cache >/dev/null || fail "config:cache failed"
cache_ok || fail "the config cache has no application key or database password"
[ "$(cfgid)" = "staging|$APPURL|$DB" ] || fail "effective config after the release is '$(cfgid)'"
PROMO=$( cd "$DIR" && sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; $p = $c["platform"]["cross_promotion"]; echo var_export($p["dhiran_register_url"], true)."|".var_export($p["erp_register_url"], true);' )
[ "$PROMO" = "''|NULL" ] || fail "other-product destinations are not disabled: dhiran|erp = $PROMO"
ok "checked out $TARGET; assets in place (manifest $MANIFEST_SHA); Dhiran destination explicitly empty, no Retail override"
PHASE=migrate
P=$(ART migrate --pretend --force --path="database/migrations/$MIGRATION.php" 2>&1); echo "$P" > "$WORK/pretend-$MIGRATION.sql"
[ "$(grep -ci 'create table' <<< "$P")" = 4 ] || fail "the migration does not create exactly four tables (see $WORK/pretend-$MIGRATION.sql)"
! grep -qiE 'drop |truncate |delete from|update ' <<< "$P" || fail "the migration holds a destructive statement"
[ -z "$(grep -oiE 'alter table "[a-z_]+"' <<< "$P" | grep -viE "\"($(IFS='|'; echo "${NEW_TABLES[*]}"))\"" | head -1)" ] || fail "the migration alters an existing table"
ART migrate --force --path="database/migrations/$MIGRATION.php" > "$WORK/migrate.out" 2>&1 || fail "migration failed (see $WORK/migrate.out)"
verify_migrated
finish
