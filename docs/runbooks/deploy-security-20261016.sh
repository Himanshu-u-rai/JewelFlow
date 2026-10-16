#!/usr/bin/env bash
# ============================================================================
# Security release of 16 October 2026: GIF guard in ImageOptimizer (libgd
# CVE-2026-9672 is unpatched on this server) and dependency security updates
# (composer.lock, package-lock.json). No migration, no .env or configuration
# change. Derived from deploy-web-release-20261016.sh; differences:
#   * no migration may change; composer.json/package.json/vite.config.js may
#     not change; composer.lock and package-lock.json may;
#   * Composer packages are fetched into the owner's cache BEFORE the window
#     (in a throwaway copy), so the window's install does not wait on the network;
#   * smoke adds the GIF guard (temporary disk, removed afterwards) and composer audit.
#
#   deploy-security-20261016.sh <preflight|release|resume> <staging|production> \
#       <from-sha> <target-sha> <bundle> <assets.tar.gz> <assets-sha256>
# Production needs SECURITY_RELEASE_APPROVED=<target-sha>; resume needs
# SECURITY_RELEASE_PREV=<stopped run's directory>.
# ============================================================================
set -u -o pipefail

MODE=${1:?usage: mode env from-sha target-sha bundle assets assets-sha256}
ENVN=${2:?env}; FROM=${3:?from-sha}; TARGET=${4:?target-sha}; BUNDLE=${5:?bundle}; ASSETS=${6:?assets tarball}; ASSETS_SHA=${7:?assets sha256}
BRANCH=fix/security-20261016; REF=refs/remotes/securityrelease/$BRANCH
PROD_BASE=c68f0f932035370e72cf126da88d724aa3815665
MIGRATION=2026_10_11_000001_create_customer_profile_photos_table
OTP_MIGRATIONS=(2026_10_09_000001_add_login_mobile_verification 2026_10_10_000001_add_platform_admin_login_code_challenges)
STAGING_DIR=/var/www/jewelflow-staging
case "$MODE" in preflight|release|resume) ;; *) echo "unknown mode: $MODE"; exit 64 ;; esac
case "$ENVN" in
  staging)    DIR=$STAGING_DIR;       OWNER=root; DB=jewelflow_staging; APPURL=https://staging.jewelflows.com; HOSTS=(staging.jewelflows.com); WORKER=jewelflow-staging-ops-alerts;    FPM=jewelflow-staging-php82-fpm; OTHER=/var/www/jewelflow; OTHER_HOST=jewelflows.com ;;
  production) DIR=/var/www/jewelflow; OWNER=dev;  DB=jewelflow;         APPURL=https://jewelflows.com;         HOSTS=(jewelflows.com www.jewelflows.com dhiran.jewelflows.com); WORKER=jewelflow-production-ops-alerts; FPM=php8.2-fpm; OTHER=$STAGING_DIR; OTHER_HOST=staging.jewelflows.com ;;
  *) echo "unknown environment: $ENVN"; exit 64 ;;
esac
HOSTN=${HOSTS[0]}
COUNTED=(shops users customers invoices invoice_items invoice_payments cash_transactions karigar_invoices stock_purchases shop_billing_settings loyalty_transactions report_exports platform_admins quick_bills quick_bill_payments product_recognitions platform_settings)
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
WORK=/root/security-release-20261016/$ENVN-$MODE-$STAMP
mkdir -p "$WORK" && chmod 700 /root/security-release-20261016 "$WORK"
exec > >(tee -a "$WORK/run.log") 2>&1
TEE=$!; trap 'exec >&- 2>&-; wait "$TEE"' EXIT
umask 022

PHASE=preflight
ART() { ( cd "$DIR" && sudo -u www-data php artisan "$@" ); }
CFG() { if sudo -u www-data test -r "$DIR/.env"; then ART "$@"; else echo "www-data cannot read $DIR/.env"; return 1; fi; }
G() { if [ "$OWNER" = root ]; then git -C "$DIR" "$@"; else sudo -u "$OWNER" env HOME="$(getent passwd "$OWNER" | cut -d: -f6)" git -C "$DIR" "$@"; fi; }
OWNERDO() { if [ "$OWNER" = root ]; then "$@"; else sudo -u "$OWNER" env HOME="$(getent passwd "$OWNER" | cut -d: -f6)" "$@"; fi; }
PSQL() { sudo -u postgres psql -X -A -t -q -v ON_ERROR_STOP=1 -d "$DB" -c "$1"; }
cfg() { ( cd "$DIR" && sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; '"$1" ); }
cache_ok() { cfg 'exit($c["app"]["key"] && $c["database"]["connections"]["pgsql"]["password"] !== null ? 0 : 1);'; }
cfgid() { cfg 'echo $c["app"]["env"]."|".$c["app"]["url"]."|".$c["database"]["connections"]["pgsql"]["database"];' 2>/dev/null; }
# Section hashes of a cached configuration FILE, read by root (a pre-release copy is root-only).
# On staging the sideways move drops the paused OTP work's configuration, so exactly those keys are
# left out there: mobile_verification, services.fast2sms, services.whatsapp. Every other section,
# and every other services key, must come out the same.
config_hashes() { OTP_KEYS=$([ "$ENVN" = staging ] && echo 1 || echo 0) php -r '$c = require $argv[1]; if (getenv("OTP_KEYS") === "1") { unset($c["mobile_verification"], $c["services"]["fast2sms"], $c["services"]["whatsapp"]); } ksort($c); foreach ($c as $k => $v) { echo $k." ".hash("sha256", serialize($v))."\n"; }' "$1"; }
other_state() { printf '%s %s %s %s' "$(git -c safe.directory='*' -C "$OTHER" rev-parse HEAD)" "$(sha256sum < "$OTHER/.env" | cut -c1-16)" "$(sha256sum < "$OTHER/bootstrap/cache/config.php" | cut -c1-16)" "$(sha256sum < "$OTHER/public/build/manifest.json" | cut -c1-16)"; }
untracked() { G ls-files --others --exclude-standard -z | ( cd "$DIR" && xargs -0 -r sha256sum -- ) | sort -k2 | sha256sum | cut -d' ' -f1; }
code() { local h=$1; shift; curl -sk -o /dev/null -w '%{http_code}' -m 20 --resolve "$h:443:127.0.0.1" "$@"; }
counts() { local t; for t in "${COUNTED[@]}"; do printf '%s=%s ' "$t" "$(PSQL "select count(*) from $t")"; done; }
settings() { PSQL "select coalesce(string_agg(key||'='||value, ' ' order by key), '') from platform_settings where key in ('maintenance_mode','free_trials_offered')"; }
recorded() { PSQL "select count(*) from migrations where migration = '$1'"; }
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
artisan_running() {
  local p n=0
  for p in $(pgrep -u www-data -f artisan || true); do
    if grep -qa "queue:work" "/proc/$p/cmdline" 2>/dev/null; then continue; fi
    if grep -qa "$DIR/artisan" "/proc/$p/cmdline" 2>/dev/null || [ "$(readlink "/proc/$p/cwd" 2>/dev/null)" = "$DIR" ]; then n=$((n + 1)); fi
  done
  echo "$n"
}
window_clear() { local m; m=$(TZ=Asia/Kolkata date +'%H %M' | awk '{ print $1 * 60 + $2 }'); ! { [ "$m" -ge 1420 ] || [ "$m" -le 190 ] || { [ "$m" -ge 340 ] && [ "$m" -le 370 ]; }; }; }
worker_steady() {
  local a b
  systemctl start "$WORKER" || return 1
  sleep 2; a=$(systemctl show -p MainPID --value "$WORKER")
  sleep 10; b=$(systemctl show -p MainPID --value "$WORKER")
  [ "$(systemctl is-active "$WORKER")" = active ] && [ -n "$a" ] && [ "$a" != 0 ] && [ "$a" = "$b" ]
}
css_lost() {
  local d n=0 f b new
  d=$(mktemp -d) && tar -xzf "$ASSETS" -C "$d" || { echo unreadable; return; }
  for f in "$DIR"/public/build/assets/*.css; do
    b=$(basename "$f" | sed -E 's/-[A-Za-z0-9_-]{8}\.css$//'); new=$(ls "$d"/build/assets/"$b"-????????.css 2>/dev/null | head -1)
    if [ -z "$new" ]; then n=$((n + 1)); continue; fi
    n=$((n + $(comm -23 <(tr '}' '\n' < "$f" | sort -u) <(tr '}' '\n' < "$new" | sort -u) | grep -c .)))
  done
  rm -rf "$d"; echo "$n"
}
ok() { echo "ok    $*"; }
fail() {
  echo "!!!!! GATE FAILED [$PHASE]: $*"
  case "$PHASE" in
    preflight) echo "Nothing that serves was changed." ;;
    smoke) echo "The site is UP on the target (maintenance had ended); nothing was rolled back. Inspect now. State: $WORK." ;;
    *) echo "The site is LEFT IN MAINTENANCE and $WORKER is stopped. Pre-release copies and dump: ${PRE:-$WORK}."
       echo "After the cause is fixed: SECURITY_RELEASE_PREV=${PRE:-$WORK} $0 resume $ENVN $FROM $TARGET <bundle> <assets> <sha256>"
       echo "Cron is still running: scheduled commands are skipped while the site is down. India time now $(TZ=Asia/Kolkata date +%H:%M)." ;;
  esac
  exit 2
}
echo "########## security release $MODE: $ENVN $FROM -> $TARGET at $STAMP (UTC); script $(sha256sum "$0" | cut -d' ' -f1) ##########"

# ── gates: nothing that serves is touched ───────────────────────────────────
[ "$(id -u)" = 0 ] || fail "run as root"
cd "$DIR" || fail "no directory $DIR"
if [ "$MODE" != preflight ]; then
  window_clear || fail "inside a window that would cover a daily scheduled job (India time $(TZ=Asia/Kolkata date +%H:%M))"
  [ "$ENVN" != production ] || [ "${SECURITY_RELEASE_APPROVED:-}" = "$TARGET" ] || fail "a production release needs SECURITY_RELEASE_APPROVED=$TARGET"
fi
if [ "$MODE" != resume ]; then
  [ "$(cfgid)" = "$ENVN|$APPURL|$DB" ] || fail "effective (cached) config is '$(cfgid)'"
  [ "$(G rev-parse HEAD)" = "$FROM" ] || fail "HEAD is $(G rev-parse HEAD), expected the deployed $FROM"
  [ -z "$(G status --porcelain --untracked-files=no)" ] || fail "tracked tree is dirty"
  [ ! -f storage/framework/down ] || fail "the site is already in maintenance (a stopped release is finished with: resume)"
  for _ in $(seq 1 30); do [ "$(artisan_running)" = 0 ] && break; sleep 2; done
  [ "$(artisan_running)" = 0 ] || fail "an artisan command is running in $DIR"
  OTHER_STATE_BEFORE=$(other_state)
  UNTRACKED_BEFORE=$(untracked)
fi
[ -f "$BUNDLE" ] && git bundle verify "$BUNDLE" >/dev/null 2>&1 || fail "the bundle is missing or does not verify"
[ "$(git bundle list-heads "$BUNDLE" "refs/heads/$BRANCH" | cut -d' ' -f1)" = "$TARGET" ] || fail "the bundle's $BRANCH is not $TARGET"
BTMP=$(OWNERDO mktemp -d) && install -o "$OWNER" -g "$OWNER" -m 600 "$BUNDLE" "$BTMP/candidate.bundle" || fail "could not hand the bundle to $OWNER"
G fetch --quiet "$BTMP/candidate.bundle" "+refs/heads/$BRANCH:$REF"; FETCHED=$?; rm -rf "$BTMP"
[ "$FETCHED" = 0 ] && [ "$(G rev-parse "$REF")" = "$TARGET" ] || fail "fetch from the bundle failed"
G merge-base --is-ancestor "$PROD_BASE" "$TARGET" || fail "target does not descend from production's $PROD_BASE"
MIG_DIFF=$(G diff --name-status "$FROM" "$TARGET" -- database/migrations | tr '\t' ' ' | sort | tr '\n' ';')
G merge-base --is-ancestor "$FROM" "$TARGET" || fail "target does not descend from the deployed commit"
[ -z "$MIG_DIFF" ] || fail "the target changes a migration: $MIG_DIFF"
[ "$(recorded "$MIGRATION")" = 1 ] || fail "$MIGRATION is not recorded (the photo release is a prerequisite)"
[ -z "$(G diff --name-only "$FROM" "$TARGET" -- composer.json package.json vite.config.js)" ] || fail "the target changes composer.json, package.json or vite.config.js: not this script"
DEP_DIFF=$(G diff --name-only "$FROM" "$TARGET" -- composer.lock package-lock.json | tr '\n' ' ')
DEVREF=$(G grep -nE '(^|[^A-Za-z_])(Tests|Faker|PHPUnit)\\|Mockery|fake\(\)' "$TARGET" -- app bootstrap config routes database/migrations | grep -v 'class_exists(' || true)
[ -z "$DEVREF" ] || fail "the target's production code references dev-only code: $DEVREF"
if [ "$MODE" != resume ]; then
  grep -qi "no pending migrations" <<< "$(ART migrate:status --pending 2>&1)" || fail "a migration is pending before the release"
fi
sudo -u www-data test -w "$DIR/storage/app/private" || fail "www-data cannot write $DIR/storage/app/private (photos are stored there)"
ok "target $TARGET: descends from $PROD_BASE; migrations: ${MIG_DIFF:-none}; lock files changed: ${DEP_DIFF:-none}; no dev-only reference; $(G diff --name-only "$FROM" "$TARGET" -- app bootstrap config routes resources public | wc -l) runtime path(s) change"
[ -f "$ASSETS" ] && [ "$(sha256sum "$ASSETS" | cut -d' ' -f1)" = "$ASSETS_SHA" ] || fail "the assets tarball is missing or its SHA-256 is not $ASSETS_SHA"
[ -z "$(tar -tzf "$ASSETS" | grep -vE '^build/([A-Za-z0-9._/-]*)$' | head -1)" ] && ! tar -tzf "$ASSETS" | grep -q '\.\.' || fail "the assets tarball holds a path outside build/"
tar -tzf "$ASSETS" | grep -qx 'build/manifest.json' || fail "the assets tarball has no build/manifest.json"
MISSING=$(comm -23 <(tar -xzOf "$ASSETS" build/manifest.json | grep -oE '"file": *"[^"]+"' | sed -E 's/.*"file": *"([^"]+)".*/build\/\1/' | sort -u) <(tar -tzf "$ASSETS" | sort -u) | head -3)
[ -z "$MISSING" ] || fail "the manifest names files the tarball does not hold: $MISSING"
NEW_MANIFEST=$(tar -xzOf "$ASSETS" build/manifest.json | sha256sum | cut -d' ' -f1)
if [ "$MODE" != resume ]; then
  CSS_LOST=$(css_lost)
  [ "$CSS_LOST" = 0 ] || [ "${SECURITY_CSS_RULES_REMOVED:-}" = "$CSS_LOST" ] || fail "the new stylesheets lack $CSS_LOST rule(s) the deployed ones have; account for each, then set SECURITY_CSS_RULES_REMOVED=$CSS_LOST"
  ok "assets tarball $ASSETS_SHA: $(tar -tzf "$ASSETS" | grep -vc '/$') files, manifest $NEW_MANIFEST; deployed CSS rules absent from the new build: $CSS_LOST"
fi
if [ "$MODE" = resume ]; then
  PRE=${SECURITY_RELEASE_PREV:?resume needs SECURITY_RELEASE_PREV=<directory of the stopped run>}
  [ -f "$DIR/storage/framework/down" ] || fail "resume is for a release stopped in maintenance; this site is up"
  H=$(G rev-parse HEAD); [ "$H" = "$FROM" ] || [ "$H" = "$TARGET" ] || fail "HEAD is $H, neither the deployed $FROM nor the target"
  [ -z "$(G status --porcelain --untracked-files=no)" ] || fail "tracked tree is dirty"
  for f in env.before config.cache.before build.before.tar.gz config.before.hashes log.mark state counts.before settings.before "$DB.dump"; do [ -s "$PRE/$f" ] || fail "$PRE has no $f"; done
  pg_restore -f /dev/null "$PRE/$DB.dump" 2>/dev/null || fail "the stopped run's backup does not read end to end"
  . "$PRE/state"
  ok "resuming the run in $PRE: HEAD $H, site in maintenance, its backup reads end to end"
else
  PRE=$WORK
  ENV_STAT=$(stat -c '%U:%G %a' "$DIR/.env"); CACHE_STAT=$(stat -c '%U:%G %a' "$DIR/bootstrap/cache/config.php"); BUILD_STAT=$(stat -c '%U:%G' "$DIR/public/build")
  for h in "${HOSTS[@]}"; do [ "$(code "$h" "https://$h/health")" = 200 ] || fail "$h /health is not 200 before the release"; done
  SETTINGS_BEFORE=$(settings); echo "$SETTINGS_BEFORE" > "$WORK/settings.before"
  ok "hosts healthy; platform settings now: ${SETTINGS_BEFORE:-<no rows>}"
  sudo -u postgres pg_dump -Fc -d "$DB" > "$WORK/$DB.dump" && chmod 600 "$WORK/$DB.dump" || fail "pg_dump failed"
  pg_restore -f /dev/null "$WORK/$DB.dump" 2>/dev/null || fail "backup does not read end to end"
  ok "backup $WORK/$DB.dump ($(sha256sum "$WORK/$DB.dump" | cut -c1-16)…) read end to end"
  UNWRITABLE=0
  while IFS= read -r f; do p="$DIR/$f"; while [ ! -e "$p" ]; do p=$(dirname "$p"); done
    OWNERDO test -w "$p" || { echo "not writable by $OWNER: $p"; UNWRITABLE=$((UNWRITABLE + 1)); }
  done < <(G diff --name-only "$FROM" "$TARGET")
  [ "$UNWRITABLE" = 0 ] || fail "$UNWRITABLE changed path(s) not writable by $OWNER"
  install -m 600 "$DIR/.env" "$WORK/env.before" && install -m 600 "$DIR/bootstrap/cache/config.php" "$WORK/config.cache.before" && tar -C "$DIR/public" -czf "$WORK/build.before.tar.gz" build || fail "could not copy the pre-release .env, config cache and assets"
  chmod 600 "$WORK/build.before.tar.gz"; config_hashes "$WORK/config.cache.before" > "$WORK/config.before.hashes"
  declare -p ENV_STAT CACHE_STAT BUILD_STAT UNTRACKED_BEFORE OTHER_STATE_BEFORE SETTINGS_BEFORE > "$WORK/state"
  ok "pre-release copies in $WORK (root only)"
  PF=$(OWNERDO mktemp -d) && G show "$TARGET:composer.json" | OWNERDO tee "$PF/composer.json" >/dev/null && G show "$TARGET:composer.lock" | OWNERDO tee "$PF/composer.lock" >/dev/null \
    && OWNERDO env COMPOSER_ALLOW_SUPERUSER=1 composer -d "$PF" install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --quiet; PFRC=$?; rm -rf "$PF"
  [ "$PFRC" = 0 ] || fail "could not fetch the target's Composer packages into $OWNER's cache"
  ok "target's Composer packages fetched into $OWNER's cache (throwaway copy removed)"
  if [ "$MODE" = preflight ]; then echo "SECURITY RELEASE PREFLIGHT PASSED: $ENVN $FROM -> $TARGET; nothing was changed; evidence in $WORK"; exit 0; fi
fi

# ── the window ──────────────────────────────────────────────────────────────
PHASE=down
if [ "$MODE" = resume ]; then
  LOG_MARK=$(cat "$PRE/log.mark"); DOWN_AT=$(date -u +%s)
  systemctl stop "$WORKER" 2>/dev/null || true
  COUNTS_BEFORE=$(cat "$PRE/counts.before")
else
  LOG_MARK=$(log_mark); echo "$LOG_MARK" > "$WORK/log.mark"
  PHASE=preflight
  for _ in $(seq 1 240); do s=$(date +%S); { [ "$((10#$s))" -ge 3 ] && [ "$((10#$s))" -le 15 ] && [ "$(artisan_running)" = 0 ]; } && break; sleep 1; done
  s=$(date +%S); { [ "$((10#$s))" -ge 3 ] && [ "$((10#$s))" -le 20 ] && [ "$(artisan_running)" = 0 ]; } || fail "no quiet moment after a minute boundary in four minutes: a scheduled command keeps running"
  PHASE=down
  ART down --retry=30 >/dev/null || fail "artisan down failed"
  DOWN_AT=$(date -u +%s); ok "maintenance on at $(date -u +%H:%M:%SZ)"
  systemctl stop "$WORKER" || fail "could not stop $WORKER"
  for _ in $(seq 1 60); do [ "$(artisan_running)" = 0 ] && break; sleep 2; done
  [ "$(artisan_running)" = 0 ] || fail "an artisan command that began before maintenance is still running after two minutes"
  COUNTS_BEFORE=$(counts); echo "$COUNTS_BEFORE" > "$WORK/counts.before"; ok "counts in maintenance: $COUNTS_BEFORE"
fi
PHASE=checkout
G checkout --quiet --detach "$TARGET" || fail "checkout failed"
[ "$(G rev-parse HEAD)" = "$TARGET" ] && [ -z "$(G status --porcelain --untracked-files=no)" ] || fail "checkout did not land cleanly"
[ "$(untracked)" = "$UNTRACKED_BEFORE" ] || fail "an untracked file was added, removed or changed by the checkout"
OWNERDO env COMPOSER_ALLOW_SUPERUSER=1 composer -d "$DIR" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --quiet || fail "composer install failed"
ART package:discover >/dev/null || fail "package:discover failed"
PHASE=assets
if [ "$(sha256sum "$DIR/public/build/manifest.json" 2>/dev/null | cut -d' ' -f1)" != "$NEW_MANIFEST" ]; then
  OWNERDO rm -rf "$DIR/public/build.incoming" && OWNERDO mkdir "$DIR/public/build.incoming" && OWNERDO tar -xzmf - -C "$DIR/public/build.incoming" < "$ASSETS" || fail "could not unpack the assets"
  mv "$DIR/public/build" "$WORK/build.replaced" && OWNERDO mv "$DIR/public/build.incoming/build" "$DIR/public/build" && OWNERDO rmdir "$DIR/public/build.incoming" || fail "could not put the assets in place"
fi
chown -R "$BUILD_STAT" "$DIR/public/build" || fail "could not give the new assets the owner of the old ($BUILD_STAT)"
[ "$(stat -c '%U:%G' "$DIR/public/build")" = "$BUILD_STAT" ] && [ "$(sha256sum "$DIR/public/build/manifest.json" | cut -d' ' -f1)" = "$NEW_MANIFEST" ] || fail "public/build is not owned as before or its manifest is not the released one"
PHASE=config
cmp -s "$PRE/env.before" "$DIR/.env" && [ "$(stat -c '%U:%G %a' "$DIR/.env")" = "$ENV_STAT" ] || fail ".env changed (content, owner or mode)"
CFG config:cache >/dev/null && ART route:cache >/dev/null && ART view:clear >/dev/null && ART view:cache >/dev/null || fail "caching failed"
# artisan writes with www-data's umask; the cache keeps the mode it had before (it holds secrets).
chmod "${CACHE_STAT##* }" "$DIR/bootstrap/cache/config.php" || fail "could not restore the config cache's mode"
cache_ok || fail "the config cache has no application key or database password"
[ "$(cfgid)" = "$ENVN|$APPURL|$DB" ] || fail "effective config after the release is '$(cfgid)'"
SECTIONS=$(diff <(config_hashes "$DIR/bootstrap/cache/config.php") <(config_hashes "$PRE/config.cache.before") | awk '/^[<>]/ { print $2 }' | sort -u | tr '\n' ' ')
[ -z "$SECTIONS" ] || fail "configuration changed: $SECTIONS"
[ "$(stat -c '%U:%G %a' "$DIR/bootstrap/cache/config.php")" = "$CACHE_STAT" ] || fail "the config cache's ownership or mode changed (was $CACHE_STAT)"
PHASE=migrate
if [ "$ENVN" = production ] && [ "$(recorded "$MIGRATION")" = 0 ]; then
  PENDING=$(ART migrate:status --pending 2>&1 | grep -oE '[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+' | sort -u | tr '\n' ' ')
  [ "$PENDING" = "$MIGRATION " ] || fail "pending migrations are not exactly $MIGRATION: $PENDING"
  ART migrate --force --no-interaction >/dev/null || fail "migrate failed"
fi
[ "$(recorded "$MIGRATION")" = 1 ] && [ "$(PSQL "select to_regclass('public.customer_profile_photos') is not null")" = t ] || fail "the photo table or its migration row is missing after the migration step"
grep -qi "no pending migrations" <<< "$(ART migrate:status --pending 2>&1)" || fail "a migration is still pending"
while IFS= read -r f; do [ -e "$DIR/$f" ] || continue; sudo -u www-data test -r "$DIR/$f" || fail "www-data cannot read $f"; done < <(G diff --name-only "$FROM" "$TARGET")
[ -z "$(find "$DIR/storage" "$DIR/bootstrap/cache" -user root ! -path "$DIR/bootstrap/cache/config.php" 2>/dev/null | head -1)" ] || [ "$OWNER" = root ] || fail "a root-owned file appeared under storage or bootstrap/cache"
systemctl reload "$FPM" || fail "$FPM reload failed"
ok "checked out $TARGET; assets in place; .env and configuration unchanged; photo migration recorded and its table present; caches rebuilt; $FPM reloaded"
[ "$(counts)" = "$COUNTS_BEFORE" ] || fail "row counts changed inside the window"
[ "$(settings)" = "$SETTINGS_BEFORE" ] || fail "platform settings changed inside the window: $(settings)"
PHASE=up
ART up >/dev/null || fail "artisan up failed"
ok "maintenance off at $(date -u +%H:%M:%SZ)$([ "$MODE" = resume ] || echo " after $(( $(date -u +%s) - DOWN_AT )) s")"
PHASE=smoke
worker_steady || fail "$WORKER is not steady after the site came up"
for h in "${HOSTS[@]}"; do
  [ "$(code "$h" "https://$h/health")" = 200 ] && [ "$(code "$h" "https://$h/login")" = 200 ] || fail "smoke: $h /health or /login not 200"
  HDRS=$(curl -sk -D- -o /dev/null -m 20 --resolve "$h:443:127.0.0.1" "https://$h/login" | grep -i '^set-cookie:')
  [ "$(grep -c . <<< "$HDRS")" = 2 ] && [ "$(grep -ci '; *secure' <<< "$HDRS")" = 2 ] && [ "$(grep -ci '; *httponly' <<< "$HDRS")" = 1 ] || fail "smoke: $h does not send two cookies, both Secure, the session one HttpOnly"
done
[ "$(code "$HOSTN" "https://$HOSTN/admin/login")" = 200 ] || fail "smoke: /admin/login not 200"
[ "$(code "$HOSTN" -H 'Accept: application/json' "https://$HOSTN/api/mobile/v1/sessions/me")" = 401 ] || fail "smoke: the mobile API does not answer 401 to a request without a token"
[ "$(curl -sk -m 20 --resolve "$HOSTN:443:127.0.0.1" "https://$HOSTN/build/manifest.json" | sha256sum | cut -d' ' -f1)" = "$NEW_MANIFEST" ] || fail "smoke: the served asset manifest is not the released one"
ART route:list --name=customers.photo.show 2>/dev/null | grep -q 'customers/{customer}/photo' || fail "smoke: the customer photo route is not registered"
( cd "$DIR" && sudo -u www-data env HOME=/tmp php artisan tinker --execute 'echo in_array(App\Http\Middleware\CheckMaintenanceMode::class, app("router")->getMiddlewareGroups()["api"] ?? [], true) ? "api-maintenance-yes" : "api-maintenance-no";' </dev/null 2>/dev/null ) | grep -q 'api-maintenance-yes' || fail "smoke: the mobile API group does not carry the maintenance check"
GIF=$( cd "$DIR" && sudo -u www-data env HOME=/tmp php artisan tinker --execute '
  Illuminate\Support\Facades\Storage::fake("smokecheck"); $i = imagecreatetruecolor(20, 20); ob_start(); imagegif($i); $g = ob_get_clean(); ob_start(); imagejpeg($i); $j = ob_get_clean();
  $o = app(App\Services\ImageOptimizer::class);
  $a = $o->optimizeAndStoreBinary($g, "x", "smokecheck", "gif"); $b = $o->optimizeAndStoreBinary($j, "x", "smokecheck", "jpg");
  $r = (str_ends_with($a, ".gif") && Illuminate\Support\Facades\Storage::disk("smokecheck")->get($a) === $g ? "gif-stored-as-sent" : "gif-DECODED")." ".(str_ends_with($b, ".webp") ? "jpeg-converted" : "jpeg-NOT-converted");
  Illuminate\Support\Facades\File::deleteDirectory(storage_path("framework/testing/disks/smokecheck")); echo $r;' </dev/null 2>/dev/null | tail -1 )
[ "$GIF" = "gif-stored-as-sent jpeg-converted" ] || fail "smoke: GIF guard check returned '$GIF'"
AUDIT=$(OWNERDO env COMPOSER_ALLOW_SUPERUSER=1 composer -d "$DIR" audit --no-dev --locked --format=plain 2>&1 | grep -vE "Deprecation|E_STRICT" | tail -1)
NEWERR=$(new_errors)
[ "$NEWERR" = 0 ] || fail "smoke: $NEWERR new error line(s) in storage/logs/laravel*.log since the mark ($WORK/log.mark)"
[ "$(other_state)" = "${OTHER_STATE_BEFORE:-}" ] || fail "the other environment's commit, .env, config cache or assets changed"
[ "$(code "$OTHER_HOST" "https://$OTHER_HOST/health")" = 200 ] || fail "the other environment ($OTHER_HOST) does not answer 200"
ok "GIF guard: $GIF (temporary disk under storage/framework/testing, removed); composer audit --no-dev: $AUDIT"
ok "smoke passed on ${HOSTS[*]}: cookies Secure, mobile API 401 and maintenance-checked, photo route registered, released manifest served, worker steady, rows and platform settings unchanged, no new error"
ok "other environment ($OTHER_HOST): commit, .env, config cache and assets unchanged, and it answers 200"
echo "SECURITY RELEASE PASSED: $ENVN at $TARGET (from $FROM); manifest $NEW_MANIFEST; settings ${SETTINGS_BEFORE:-<no rows>}; evidence in $WORK"
