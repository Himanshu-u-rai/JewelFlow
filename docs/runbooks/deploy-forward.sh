#!/usr/bin/env bash
# ============================================================================
# Code-only forward release on top of the deployed security release. Runs ON
# the VPS as root, one environment per run:
#
#   deploy-forward.sh <staging|production> <from-sha (deployed now)> <target-sha>
#
# For fixes that change no schema: refuses if any migration, dependency or
# built-asset source differs between the deployed commit and the target —
# those go through deploy-security-batch.sh. The runbook's recovery is to move
# forward (signature-migration-release-order.md § Recovery); this is that path.
#
# Same gates as the phased script where they apply: the environment identity
# from the cached config; HEAD = from-sha and a clean tracked tree; the target
# on the security branch; no production code referencing dev-only code; a
# pg_dump read end to end; the owner able to write every changed path; the
# config cache built by a user that can read .env; the classmap; every
# changed file readable by www-data; the FPM reload and the environment's
# worker restart; smoke checks, including no new error in any laravel*.log;
# equal row counts; the other environment untouched. Fail-closed: after
# `down`, a failed gate leaves maintenance on (after `up`, it says so).
# ============================================================================
set -u -o pipefail

ENVN=${1:?usage: env from-sha target-sha}
FROM=${2:?usage: env from-sha target-sha}
TARGET=${3:?usage: env from-sha target-sha}
BRANCH_REF=refs/remotes/origin/security/multi-tenant-audit
STAGING_DIR=/var/www/jewelflow-staging
case "$ENVN" in
  staging)    DIR=$STAGING_DIR;       OWNER=root; DB=jewelflow_staging; APPURL=https://staging.jewelflows.com; HOSTN=staging.jewelflows.com; WORKER=jewelflow-staging-ops-alerts;    OTHER=/var/www/jewelflow ;;
  production) DIR=/var/www/jewelflow; OWNER=dev;  DB=jewelflow;         APPURL=https://jewelflows.com;         HOSTN=jewelflows.com;         WORKER=jewelflow-production-ops-alerts; OTHER=$STAGING_DIR ;;
  *) echo "unknown environment: $ENVN"; exit 64 ;;
esac
COUNTED=(shops users customers invoices invoice_items invoice_payments cash_transactions karigar_invoices stock_purchases shop_billing_settings loyalty_transactions report_exports idempotency_keys platform_admins)
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
WORK=/root/security-batch/$ENVN-forward-$STAMP
mkdir -p "$WORK" && chmod 700 /root/security-batch "$WORK"
exec > >(tee -a "$WORK/run.log") 2>&1
umask 022

PHASE=preflight
ART() { ( cd "$DIR" && sudo -u www-data php artisan "$@" ); }
CFG() { if sudo -u www-data test -r "$DIR/.env"; then ART "$@"; else ( cd "$DIR" && umask 022 && php artisan "$@" ); fi; }
cache_ok() { sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; exit($c["app"]["key"] && $c["database"]["connections"]["pgsql"]["password"] !== null ? 0 : 1);'; }
PSQL() { sudo -u postgres psql -X -A -t -q -v ON_ERROR_STOP=1 -d "$DB" -c "$1"; }
OWNERDO() { if [ "$OWNER" = root ]; then "$@"; else sudo -u "$OWNER" env HOME="$(getent passwd "$OWNER" | cut -d: -f6)" "$@"; fi; }
ok() { echo "ok    $*"; }
fail() {
  echo "!!!!! GATE FAILED [$PHASE]: $*"
  case "$PHASE" in
    preflight) echo "Nothing that serves was changed." ;;
    smoke) echo "The site is UP on the target (maintenance had ended); nothing was rolled back. Inspect now. State: $WORK." ;;
    *) echo "The site is LEFT IN MAINTENANCE. State: $WORK." ;;
  esac
  exit 2
}
# Both environments log through the `daily` channel (laravel-YYYY-MM-DD.log,
# a new file at midnight); laravel.log is written only when the config is
# broken. So mark EVERY laravel*.log at its size and count error lines written
# after the mark, including files created during the window. (The first
# version read laravel.log alone, which the application was not writing.)
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
counts() { for t in "${COUNTED[@]}"; do printf '%s=%s ' "$t" "$(PSQL "select count(*) from $t")"; done; }
echo "########## forward release: $ENVN $FROM -> $TARGET at $STAMP (UTC) ##########"

cd "$DIR" || fail "no directory $DIR"
CFGID=$(sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; echo $c["app"]["env"]."|".$c["app"]["url"]."|".$c["database"]["connections"]["pgsql"]["database"];' 2>/dev/null)
[ "$CFGID" = "$ENVN|$APPURL|$DB" ] || fail "effective (cached) config is '$CFGID'"
[ "$(git rev-parse HEAD)" = "$FROM" ] || fail "HEAD is $(git rev-parse HEAD), expected the deployed $FROM"
[ -z "$(git status --porcelain --untracked-files=no)" ] || fail "tracked tree is dirty"
[ ! -f storage/framework/down ] || fail "the site is already in maintenance"
OTHER_HEAD_BEFORE=$(git -C "$OTHER" rev-parse HEAD)
if [ "$ENVN" = staging ]; then
  git fetch --quiet origin "+refs/heads/security/multi-tenant-audit:$BRANCH_REF" || fail "fetch failed"
else
  BUNDLE=/var/tmp/security-batch-forward-$TARGET.bundle
  git -C "$STAGING_DIR" cat-file -e "$TARGET^{commit}" 2>/dev/null || fail "target absent from the staging repository (release staging first)"
  git -C "$STAGING_DIR" bundle create "$BUNDLE" "$FROM..$BRANCH_REF" >/dev/null 2>&1 || fail "bundle creation failed"
  chmod 644 "$BUNDLE"
  OWNERDO git -C "$DIR" fetch --quiet "$BUNDLE" "+$BRANCH_REF:$BRANCH_REF" || fail "fetch from the bundle failed"
fi
git merge-base --is-ancestor "$FROM" "$TARGET" || fail "target does not descend from the deployed commit"
git merge-base --is-ancestor "$TARGET" "$BRANCH_REF" || fail "target is not on the security branch"
[ -z "$(git diff --name-only "$FROM" "$TARGET" -- database/migrations composer.lock composer.json package.json package-lock.json vite.config.js resources/js resources/css)" ] \
  || fail "the target changes migrations, dependencies or built assets — use deploy-security-batch.sh"
ART migrate:status --pending 2>&1 | grep -qi "no pending migrations" || fail "a migration is pending"
DEVREF=$(git grep -nE '(^|[^A-Za-z_])(Tests|Faker|PHPUnit)\\|Mockery|fake\(\)' "$TARGET" -- app bootstrap config routes database/migrations | grep -v 'class_exists(' || true)
[ -z "$DEVREF" ] || fail "the target's production code references dev-only code: $DEVREF"
ok "target $TARGET: code only, on the branch, no dev-only references; changes: $(git diff --name-only "$FROM" "$TARGET" | tr '\n' ' ')"
sudo -u postgres pg_dump -Fc -d "$DB" > "$WORK/$DB.dump" && chmod 600 "$WORK/$DB.dump" || fail "pg_dump failed"
pg_restore -f /dev/null "$WORK/$DB.dump" 2>/dev/null || fail "backup does not read end to end"
COUNTS_BEFORE=$(counts)
ok "backup $WORK/$DB.dump read end to end; counts: $COUNTS_BEFORE"
UNWRITABLE=0
while IFS= read -r f; do p="$DIR/$f"; while [ ! -e "$p" ]; do p=$(dirname "$p"); done
  OWNERDO test -w "$p" || { echo "not writable by $OWNER: $p"; UNWRITABLE=$((UNWRITABLE + 1)); }
done < <(git diff --name-only "$FROM" "$TARGET")
[ "$UNWRITABLE" = 0 ] || fail "$UNWRITABLE changed path(s) not writable by $OWNER"

PHASE=down
LOG_MARK=$(log_mark)
echo "$LOG_MARK" > "$WORK/log.mark"
ART down --retry=30 >/dev/null || fail "artisan down failed"
ok "maintenance on at $(date -u +%H:%M:%SZ)"
PHASE=checkout
OWNERDO git checkout --quiet --detach "$TARGET" || fail "checkout failed"
[ "$(git rev-parse HEAD)" = "$TARGET" ] && [ -z "$(git status --porcelain --untracked-files=no)" ] || fail "checkout did not land cleanly"
OWNERDO env COMPOSER_ALLOW_SUPERUSER=1 composer -d "$DIR" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --quiet || fail "composer install failed"
ART package:discover >/dev/null || fail "package:discover failed"
CFG config:cache >/dev/null && ART route:cache >/dev/null && ART view:clear >/dev/null && ART view:cache >/dev/null || fail "caching failed"
cache_ok || fail "the config cache has no application key or database password"
while IFS= read -r f; do [ -e "$DIR/$f" ] || continue; sudo -u www-data test -r "$DIR/$f" || fail "www-data cannot read $f"; done < <(git diff --name-only "$FROM" "$TARGET")
[ -z "$(find "$DIR/storage" "$DIR/bootstrap/cache" -user root ! -path "$DIR/bootstrap/cache/config.php" 2>/dev/null | head -1)" ] || fail "a root-owned file appeared under storage or bootstrap/cache"
systemctl reload php8.2-fpm && systemctl restart "$WORKER" || fail "reload or worker restart failed"
ok "checked out $TARGET; caches rebuilt; php8.2-fpm reloaded; $WORKER restarted"
PHASE=up
ART up >/dev/null || fail "artisan up failed"
ok "maintenance off at $(date -u +%H:%M:%SZ)"
PHASE=smoke
code() { curl -sk -o /dev/null -w '%{http_code}' -m 20 --resolve "$HOSTN:443:127.0.0.1" "https://$HOSTN$1"; }
[ "$(code /health)" = 200 ] && [ "$(code /admin/login)" = 200 ] || fail "smoke: /health or /admin/login not 200"
for u in /super-admin/shops /admin/shops; do [ "$(code $u)" = 302 ] || fail "smoke: $u did not redirect"; done
NEWERR=$(new_errors)
[ "$NEWERR" = 0 ] || fail "smoke: $NEWERR new error line(s) in storage/logs/laravel*.log since the mark ($WORK/log.mark)"
[ "$(counts)" = "$COUNTS_BEFORE" ] || fail "row counts changed across the window"
[ "$(git -C "$OTHER" rev-parse HEAD)" = "$OTHER_HEAD_BEFORE" ] || fail "the other environment's HEAD changed"
ok "smoke passed; no row added or lost; no new error; other environment untouched"
echo "FORWARD RELEASE PASSED: $ENVN at $TARGET (from $FROM); evidence in $WORK"
