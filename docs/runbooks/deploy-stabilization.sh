#!/usr/bin/env bash
# ============================================================================
# Stabilization release of 8 October 2026: code, built assets and ONE setting
# (SESSION_SECURE_COOKIE=true). No schema change. Runs ON the VPS as root, one
# environment per run:
#
#   deploy-stabilization.sh <preflight|release|resume|return> <staging|production> \
#       <from-sha (deployed now)> <target-sha> <bundle> <assets.tar.gz> <assets-sha256>
#
# preflight  every gate that can be checked without touching what serves,
#            including a database dump read end to end. Changes nothing.
# release    the same gates, then the window. On production it also needs
#            STABILIZATION_RELEASE_APPROVED=<target-sha>.
# resume     finishes a release that a gate stopped inside its window (site in
#            maintenance). Needs STABILIZATION_PREV=<that run's directory>: its
#            pre-release copies and dump are used; every step is repeated only
#            if it has not already taken effect.
# return     goes back to an EARLIER commit of this batch (target is an
#            ancestor of from) with that commit's assets: the same window and
#            the same checks. No bundle is needed (pass -); the assets are the
#            build.before.tar.gz of the run that replaced them. Possible
#            because this batch changes no schema.
#
# What it touches beyond its own environment, stated plainly:
#   * php8.2-fpm is ONE pool serving production and staging. Its reload at the
#     end of a window restarts the other environment's PHP workers too and
#     empties their code cache. Requests in flight finish; the next ones are
#     slower for a moment. The script checks that the other environment
#     still answers and that its checkout, .env, config cache and assets are
#     byte for byte what they were. It cannot make the reload not happen.
#   * The scheduler's cron is NOT held. The window is started just after a
#     minute boundary and is over before the next one. A release that a gate
#     STOPS stays in maintenance with cron running: scheduled commands are
#     skipped while the site is down and do not catch up. Resume or return
#     before the next daily job (00:00 to 03:00 and 06:00 India time).
#
# deploy-forward.sh with three additions, each lifted from the takeover
# release script: built assets from a tarball checked by SHA-256, one named
# line of .env, and the worker started after the site is up and required
# steady. It refuses a target that changes a migration or a dependency.
#
# Fail-closed: after `down`, a failed gate leaves maintenance on and says
# where the pre-release copies are. Recovery is to move forward: the copies
# of .env, the config cache and public/build are in the run's directory.
# ============================================================================
set -u -o pipefail

MODE=${1:?usage: mode env from-sha target-sha bundle assets assets-sha256}
ENVN=${2:?env}; FROM=${3:?from-sha}; TARGET=${4:?target-sha}; BUNDLE=${5:?bundle}; ASSETS=${6:?assets tarball}; ASSETS_SHA=${7:?assets sha256}
BRANCH=fix/stabilization-20261008; REF=refs/remotes/stabilization/$BRANCH
SETTING=SESSION_SECURE_COOKIE
STAGING_DIR=/var/www/jewelflow-staging
case "$MODE" in preflight|release|resume|return) ;; *) echo "unknown mode: $MODE"; exit 64 ;; esac
case "$ENVN" in
  staging)    DIR=$STAGING_DIR;       OWNER=root; DB=jewelflow_staging; APPURL=https://staging.jewelflows.com; HOSTS=(staging.jewelflows.com); WORKER=jewelflow-staging-ops-alerts;    OTHER=/var/www/jewelflow; OTHER_HOST=jewelflows.com ;;
  production) DIR=/var/www/jewelflow; OWNER=dev;  DB=jewelflow;         APPURL=https://jewelflows.com;         HOSTS=(jewelflows.com www.jewelflows.com dhiran.jewelflows.com); WORKER=jewelflow-production-ops-alerts; OTHER=$STAGING_DIR; OTHER_HOST=staging.jewelflows.com ;;
  *) echo "unknown environment: $ENVN"; exit 64 ;;
esac
HOSTN=${HOSTS[0]}
COUNTED=(shops users customers invoices invoice_items invoice_payments cash_transactions karigar_invoices stock_purchases shop_billing_settings loyalty_transactions report_exports platform_admins quick_bills quick_bill_payments product_recognitions)
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
WORK=/root/stabilization/$ENVN-$MODE-$STAMP
mkdir -p "$WORK" && chmod 700 /root/stabilization "$WORK"
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
config_hashes() { cfg 'ksort($c); foreach ($c as $k => $v) { echo $k." ".hash("sha256", serialize($v))."\n"; }'; }
# The other environment as this script can see it: its commit, its .env, its config cache, its assets.
other_state() { printf '%s %s %s %s' "$(git -c safe.directory='*' -C "$OTHER" rev-parse HEAD)" "$(sha256sum < "$OTHER/.env" | cut -c1-16)" "$(sha256sum < "$OTHER/bootstrap/cache/config.php" | cut -c1-16)" "$(sha256sum < "$OTHER/public/build/manifest.json" | cut -c1-16)"; }
untracked() { G ls-files --others --exclude-standard -z | ( cd "$DIR" && xargs -0 -r sha256sum -- ) | sort -k2 | sha256sum | cut -d' ' -f1; }
code() { local h=$1; shift; curl -sk -o /dev/null -w '%{http_code}' -m 20 --resolve "$h:443:127.0.0.1" "$@"; }
counts() { local t; for t in "${COUNTED[@]}"; do printf '%s=%s ' "$t" "$(PSQL "select count(*) from $t")"; done; }
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
# The scheduler does not catch up on a daily job it missed: 23:40-03:10 and 05:40-06:10 India time are refused.
window_clear() { local m; m=$(TZ=Asia/Kolkata date +'%H %M' | awk '{ print $1 * 60 + $2 }'); ! { [ "$m" -ge 1420 ] || [ "$m" -le 190 ] || { [ "$m" -ge 340 ] && [ "$m" -le 370 ]; }; }; }
worker_steady() {
  local a b
  systemctl start "$WORKER" || return 1
  sleep 2; a=$(systemctl show -p MainPID --value "$WORKER")
  sleep 10; b=$(systemctl show -p MainPID --value "$WORKER")
  [ "$(systemctl is-active "$WORKER")" = active ] && [ -n "$a" ] && [ "$a" != 0 ] && [ "$a" = "$b" ]
}
# Rules the deployed stylesheets hold that the tarball's do not. A build from an incomplete tree loses
# rules silently: part of the stylesheet is generated from compiled views that only a real checkout has.
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
    *) echo "The site is LEFT IN MAINTENANCE and $WORKER is stopped. Pre-release .env, config cache and assets: ${PRE:-$WORK}."
       echo "After the cause is fixed: STABILIZATION_PREV=${PRE:-$WORK} $0 resume $ENVN $FROM $TARGET <bundle> <assets> <sha256>"
       echo "Cron is still running: scheduled commands are skipped while the site is down and do not catch up. India time now $(TZ=Asia/Kolkata date +%H:%M)." ;;
  esac
  exit 2
}
echo "########## stabilization $MODE: $ENVN $FROM -> $TARGET at $STAMP (UTC); script $(sha256sum "$0" | cut -d' ' -f1) ##########"

# ── gates: nothing that serves is touched ───────────────────────────────────
[ "$(id -u)" = 0 ] || fail "run as root"
cd "$DIR" || fail "no directory $DIR"
if [ "$MODE" != preflight ] && [ "$ENVN" = production ]; then
  [ "${STABILIZATION_RELEASE_APPROVED:-}" = "$TARGET" ] || fail "a production release needs STABILIZATION_RELEASE_APPROVED=$TARGET"
  window_clear || fail "inside a window that would cover a daily scheduled job (India time $(TZ=Asia/Kolkata date +%H:%M))"
fi
if [ "$MODE" != resume ]; then
  [ "$(cfgid)" = "$ENVN|$APPURL|$DB" ] || fail "effective (cached) config is '$(cfgid)'"
  [ "$(G rev-parse HEAD)" = "$FROM" ] || fail "HEAD is $(G rev-parse HEAD), expected the deployed $FROM"
  [ -z "$(G status --porcelain --untracked-files=no)" ] || fail "tracked tree is dirty"
  [ ! -f storage/framework/down ] || fail "the site is already in maintenance (a stopped release is finished with: resume)"
  for _ in $(seq 1 30); do [ "$(artisan_running)" = 0 ] && break; sleep 2; done   # a scheduled command may be finishing
  [ "$(artisan_running)" = 0 ] || fail "an artisan command is running in $DIR"
  OTHER_STATE_BEFORE=$(other_state)
  UNTRACKED_BEFORE=$(untracked)
fi
if [ "$MODE" = return ]; then
  G cat-file -e "$TARGET^{commit}" 2>/dev/null || fail "the commit to return to is not in this repository"
  G merge-base --is-ancestor "$TARGET" "$FROM" && [ "$TARGET" != "$FROM" ] || fail "return goes to an earlier commit: $TARGET is not an ancestor of the deployed $FROM"
else
  [ -f "$BUNDLE" ] && git bundle verify "$BUNDLE" >/dev/null 2>&1 || fail "the bundle is missing or does not verify"
  [ "$(git bundle list-heads "$BUNDLE" "refs/heads/$BRANCH" | cut -d' ' -f1)" = "$TARGET" ] || fail "the bundle's $BRANCH is not $TARGET"
  BTMP=$(OWNERDO mktemp -d) && install -o "$OWNER" -g "$OWNER" -m 600 "$BUNDLE" "$BTMP/candidate.bundle" || fail "could not hand the bundle to $OWNER"
  G fetch --quiet "$BTMP/candidate.bundle" "+refs/heads/$BRANCH:$REF"; FETCHED=$?; rm -rf "$BTMP"
  [ "$FETCHED" = 0 ] && [ "$(G rev-parse "$REF")" = "$TARGET" ] || fail "fetch from the bundle failed"
  G merge-base --is-ancestor "$FROM" "$TARGET" || fail "target does not descend from the deployed commit"
fi
[ -z "$(G diff --name-only "$FROM" "$TARGET" -- database/migrations composer.lock composer.json package.json package-lock.json vite.config.js)" ] \
  || fail "the target changes a migration or a dependency: not this script"
[ "$MODE" = resume ] || grep -qi "no pending migrations" <<< "$(ART migrate:status --pending 2>&1)" || fail "a migration is pending"
DEVREF=$(G grep -nE '(^|[^A-Za-z_])(Tests|Faker|PHPUnit)\\|Mockery|fake\(\)' "$TARGET" -- app bootstrap config routes database/migrations | grep -v 'class_exists(' || true)
[ -z "$DEVREF" ] || fail "the target's production code references dev-only code: $DEVREF"
ok "target $TARGET: $([ "$MODE" = return ] && echo "an ancestor of" || echo "descends from") $FROM, no migration, no dependency change, no dev-only reference; $(G diff --name-only "$FROM" "$TARGET" -- app bootstrap config routes resources public | wc -l) runtime path(s) change"
[ -f "$ASSETS" ] && [ "$(sha256sum "$ASSETS" | cut -d' ' -f1)" = "$ASSETS_SHA" ] || fail "the assets tarball is missing or its SHA-256 is not $ASSETS_SHA"
[ -z "$(tar -tzf "$ASSETS" | grep -vE '^build/([A-Za-z0-9._/-]*)$' | head -1)" ] && ! tar -tzf "$ASSETS" | grep -q '\.\.' || fail "the assets tarball holds a path outside build/"
tar -tzf "$ASSETS" | grep -qx 'build/manifest.json' || fail "the assets tarball has no build/manifest.json"
MISSING=$(comm -23 <(tar -xzOf "$ASSETS" build/manifest.json | grep -oE '"(file|src)": *"[^"]+"' | grep '"file"' | sed -E 's/.*"file": *"([^"]+)".*/build\/\1/' | sort -u) <(tar -tzf "$ASSETS" | sort -u) | head -3)
[ -z "$MISSING" ] || fail "the manifest names files the tarball does not hold: $MISSING"
NEW_MANIFEST=$(tar -xzOf "$ASSETS" build/manifest.json | sha256sum | cut -d' ' -f1)
CSS_LOST=$(css_lost)
[ "$CSS_LOST" = 0 ] || [ "$MODE" = return ] || [ "${STABILIZATION_CSS_RULES_REMOVED:-}" = accepted ] || fail "the new stylesheets lack $CSS_LOST rule(s) that the deployed ones have (build in a real checkout; or set STABILIZATION_CSS_RULES_REMOVED=accepted if the removal is intended)"
ok "assets tarball $ASSETS_SHA: $(tar -tzf "$ASSETS" | grep -vc '/$') files, manifest $NEW_MANIFEST; deployed CSS rules absent from the new build: $CSS_LOST$([ "$CSS_LOST" = 0 ] || { [ "$MODE" = return ] && echo ' (a return to an earlier build)' || echo ' (declared intended)'; })"
if [ "$MODE" = resume ]; then
  PRE=${STABILIZATION_PREV:?resume needs STABILIZATION_PREV=<directory of the stopped run>}
  [ -f "$DIR/storage/framework/down" ] || fail "resume is for a release stopped in maintenance; this site is up"
  H=$(G rev-parse HEAD); [ "$H" = "$FROM" ] || [ "$H" = "$TARGET" ] || fail "HEAD is $H, neither the deployed $FROM nor the target"
  [ -z "$(G status --porcelain --untracked-files=no)" ] || fail "tracked tree is dirty"
  for f in env.before config.cache.before build.before.tar.gz config.before.hashes log.mark "$DB.dump"; do [ -s "$PRE/$f" ] || fail "$PRE has no $f"; done
  pg_restore -f /dev/null "$PRE/$DB.dump" 2>/dev/null || fail "the stopped run's backup does not read end to end"
  if [ -f "$PRE/state" ]; then . "$PRE/state"; else
    ENV_STAT=$(stat -c '%U:%G %a' "$DIR/.env"); CACHE_STAT=$(stat -c '%U:%G %a' "$DIR/bootstrap/cache/config.php")
    BUILD_STAT=$(stat -c '%U:%G' "$PRE/build.replaced" 2>/dev/null || stat -c '%U:%G' "$DIR/public/build")
    UNTRACKED_BEFORE=$(untracked); OTHER_STATE_BEFORE=$(other_state)
  fi
  CUR=$(grep -E "^$SETTING=" "$PRE/env.before" | cut -d= -f2)
  ok "resuming the run in $PRE: HEAD $H, site in maintenance, its backup reads end to end; assets were $BUILD_STAT, .env $ENV_STAT"
else
PRE=$WORK
ENV_STAT=$(stat -c '%U:%G %a' "$DIR/.env"); CACHE_STAT=$(stat -c '%U:%G %a' "$DIR/bootstrap/cache/config.php"); BUILD_STAT=$(stat -c '%U:%G' "$DIR/public/build")
N_SET=$(grep -cE "^$SETTING=" "$DIR/.env"); CUR=$(grep -E "^$SETTING=" "$DIR/.env" | cut -d= -f2)
[ "$N_SET" = 1 ] && { [ "$CUR" = false ] || [ "$CUR" = true ]; } || fail ".env does not hold exactly one $SETTING line set to false or true"
[ "$(cfg 'echo var_export($c["session"]["secure"], true);')" = "$CUR" ] || fail "the cached session.secure is not what .env says"
for h in "${HOSTS[@]}"; do [ "$(code "$h" "https://$h/health")" = 200 ] || fail "$h /health is not 200 before the release"; done
ok "$SETTING is $CUR now; .env $ENV_STAT; hosts healthy"
sudo -u postgres pg_dump -Fc -d "$DB" > "$WORK/$DB.dump" && chmod 600 "$WORK/$DB.dump" || fail "pg_dump failed"
pg_restore -f /dev/null "$WORK/$DB.dump" 2>/dev/null || fail "backup does not read end to end"
ok "backup $WORK/$DB.dump ($(sha256sum "$WORK/$DB.dump" | cut -c1-16)…) read end to end"
UNWRITABLE=0
while IFS= read -r f; do p="$DIR/$f"; while [ ! -e "$p" ]; do p=$(dirname "$p"); done
  OWNERDO test -w "$p" || { echo "not writable by $OWNER: $p"; UNWRITABLE=$((UNWRITABLE + 1)); }
done < <(G diff --name-only "$FROM" "$TARGET")
[ "$UNWRITABLE" = 0 ] || fail "$UNWRITABLE changed path(s) not writable by $OWNER"
install -m 600 "$DIR/.env" "$WORK/env.before" && install -m 600 "$DIR/bootstrap/cache/config.php" "$WORK/config.cache.before" && tar -C "$DIR/public" -czf "$WORK/build.before.tar.gz" build || fail "could not copy the pre-release .env, config cache and assets"
chmod 600 "$WORK/build.before.tar.gz"; config_hashes > "$WORK/config.before.hashes"
ok "pre-release copies in $WORK (root only)"
declare -p ENV_STAT CACHE_STAT BUILD_STAT UNTRACKED_BEFORE OTHER_STATE_BEFORE > "$WORK/state"
if [ "$MODE" = preflight ]; then echo "STABILIZATION PREFLIGHT PASSED: $ENVN $FROM -> $TARGET; nothing was changed; evidence in $WORK"; exit 0; fi

fi

# ── the window ──────────────────────────────────────────────────────────────
PHASE=down
if [ "$MODE" = resume ]; then
  LOG_MARK=$(cat "$PRE/log.mark"); DOWN_AT=$(date -u +%s)
  systemctl stop "$WORKER" 2>/dev/null || true
  COUNTS_BEFORE=$(cat "$PRE/counts.before" 2>/dev/null || counts)
else
  LOG_MARK=$(log_mark); echo "$LOG_MARK" > "$WORK/log.mark"
  # The scheduler's cron fires on the minute and is not held: start just after one, so the window ends before the next.
  while s=$(date +%S); [ "$((10#$s))" -lt 3 ] || [ "$((10#$s))" -gt 15 ]; do sleep 1; done
  ART down --retry=30 >/dev/null || fail "artisan down failed"
  DOWN_AT=$(date -u +%s); ok "maintenance on at $(date -u +%H:%M:%SZ)"
  systemctl stop "$WORKER" || fail "could not stop $WORKER"
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
# The directory this script has just put there, and only that: owned as the assets it replaces were.
chown -R "$BUILD_STAT" "$DIR/public/build" || fail "could not give the new assets the owner of the old ($BUILD_STAT)"
[ "$(stat -c '%U:%G' "$DIR/public/build")" = "$BUILD_STAT" ] && [ "$(sha256sum "$DIR/public/build/manifest.json" | cut -d' ' -f1)" = "$NEW_MANIFEST" ] || fail "public/build is not owned as before or its manifest is not the released one"
PHASE=config
# Written through the existing file (same inode, owner and mode), from the pre-release copy: one line differs.
sed "s/^$SETTING=false\$/$SETTING=true/" "$PRE/env.before" > "$WORK/env.after" && chmod 600 "$WORK/env.after" && cat "$WORK/env.after" > "$DIR/.env" || fail "could not write $SETTING"
[ "$(stat -c '%U:%G %a' "$DIR/.env")" = "$ENV_STAT" ] || fail ".env ownership or mode changed (was $ENV_STAT)"
CHANGED=$(diff "$PRE/env.before" "$DIR/.env" | grep -E '^[<>]' | sed -E 's/=.*//' | tr '\n' ' ')
[ "$CUR" = true ] && [ -z "$CHANGED" ] || [ "$CHANGED" = "< $SETTING > $SETTING " ] || fail ".env differs from its pre-release copy by more than the one setting: $CHANGED"
CFG config:cache >/dev/null && ART route:cache >/dev/null && ART view:clear >/dev/null && ART view:cache >/dev/null || fail "caching failed"
cache_ok || fail "the config cache has no application key or database password"
[ "$(cfgid)" = "$ENVN|$APPURL|$DB" ] || fail "effective config after the release is '$(cfgid)'"
[ "$(cfg 'echo var_export($c["session"]["secure"], true);')" = true ] || fail "session.secure is not true in the cached config"
SECTIONS=$(diff <(config_hashes) "$PRE/config.before.hashes" | awk '/^[<>]/ { print $2 }' | sort -u | tr '\n' ' ')
[ "$SECTIONS" = "session " ] || [ -z "$SECTIONS" ] || fail "configuration changed outside the session section: $SECTIONS"
[ "$(stat -c '%U:%G %a' "$DIR/bootstrap/cache/config.php")" = "$CACHE_STAT" ] || fail "the config cache's ownership or mode changed (was $CACHE_STAT)"
while IFS= read -r f; do [ -e "$DIR/$f" ] || continue; sudo -u www-data test -r "$DIR/$f" || fail "www-data cannot read $f"; done < <(G diff --name-only "$FROM" "$TARGET")
[ -z "$(find "$DIR/storage" "$DIR/bootstrap/cache" -user root ! -path "$DIR/bootstrap/cache/config.php" 2>/dev/null | head -1)" ] || [ "$OWNER" = root ] || fail "a root-owned file appeared under storage or bootstrap/cache"
systemctl reload php8.2-fpm || fail "php8.2-fpm reload failed"
ok "checked out $TARGET; assets in place; $SETTING=true and nothing else changed in .env or outside the session section; caches rebuilt; php8.2-fpm reloaded"
[ "$(counts)" = "$COUNTS_BEFORE" ] || fail "row counts changed inside the window"
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
ART route:list --path=api/mobile/quick-bills --method=POST -v 2>/dev/null | grep -q 'mobile.idempotency:optional\|EnsureIdempotency:optional' || fail "smoke: the quick-bill create route does not carry the idempotency middleware"
! ART schedule:list 2>/dev/null | grep -q 'archive-audit-logs' || fail "smoke: the retired archive command is still scheduled"
NEWERR=$(new_errors)
[ "$NEWERR" = 0 ] || fail "smoke: $NEWERR new error line(s) in storage/logs/laravel*.log since the mark ($WORK/log.mark)"
[ "$(other_state)" = "${OTHER_STATE_BEFORE:-}" ] || fail "the other environment's commit, .env, config cache or assets changed"
[ "$(code "$OTHER_HOST" "https://$OTHER_HOST/health")" = 200 ] || fail "the other environment ($OTHER_HOST) does not answer 200 after the shared PHP reload"
ok "smoke passed on ${HOSTS[*]}: cookies Secure, mobile API 401, released manifest served, route and schedule as released, worker steady, no row added or lost, no new error"
ok "other environment ($OTHER_HOST): commit, .env, config cache and assets unchanged, and it answers 200. Its PHP workers were reloaded with this one's: the pool is shared"
echo "STABILIZATION RELEASE PASSED: $ENVN at $TARGET (from $FROM); manifest $NEW_MANIFEST; evidence in $WORK"
