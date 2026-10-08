#!/usr/bin/env bash
# ============================================================================
# PRODUCTION release of the takeover candidate (navigation batches, Categories,
# landing page, product preferences). Runs ON the VPS as root, from a file:
#
#   deploy-takeover-production.sh preflight <from-sha (deployed now)> <target-sha> \
#       <git bundle> <assets tarball> <assets tarball sha256>
#   TAKEOVER_RELEASE_APPROVED=<target-sha> [TAKEOVER_PRODUCTION_CHECK=approved] \
#   deploy-takeover-production.sh release   <same five arguments>
#   deploy-takeover-production.sh resume    <from-sha> <target-sha> <evidence dir of the stopped release>
#   deploy-takeover-production.sh baseline  <from-sha> <target-sha> <evidence dir of the stopped release>
#   deploy-takeover-production.sh tree-check <from-sha> <target-sha> <evidence dir of the stopped release>
#   deploy-takeover-production.sh restore-check <dump file>
#
# Retail (jewelflows.com, www.jewelflows.com), Dhiran (dhiran.jewelflows.com)
# and the mobile API are ONE application, one database and one php-fpm pool
# (shared with staging). A release is therefore one maintenance window for all
# of them. Procedure and recovery: docs/handoffs/jewelflows-takeover-staging-2026-10-07.md
# and docs/runbooks/product-promotion-recognition.md, "Recovery".
#
# preflight      changes nothing in the application, its database, repository,
#                configuration or services. It reads; it unpacks the bundle into
#                a throwaway repository of its own; it takes a dump, restores it
#                in an isolated instance, compares it and DELETES it. It leaves
#                only its evidence directory under /root/takeover-production.
# release        refuses without TAKEOVER_RELEASE_APPROVED=<target-sha>. The
#                synthetic two-product check (tests/Production/…) runs inside
#                the window only with TAKEOVER_PRODUCTION_CHECK=approved;
#                otherwise it is printed as NOT RUN.
# resume         finishes, forward only, a release a gate stopped at or after
#                the migration (site in maintenance on the target commit).
# baseline       returns a stopped release to the commit, assets and configuration it
#                started from. It REFUSES unless production is still in the maintenance
#                window of that release and the four promotion tables are absent or
#                empty: the earlier release must never run over recorded preferences
#                or consent. Empty tables are left in place, never dropped; a later
#                `release` recognises them and does not run the migration again.
# tree-check     changes nothing. For a tree that `resume` and `baseline` refuse as dirty (a
#                checkout cut short): says of every differing path whether it is, byte for
#                byte, the other commit's version, and writes out the commands that would put
#                exactly those paths back. It runs none of them. One path that is neither
#                version and it says STOP and writes no command.
# restore-check  restores a dump in the isolated instance and fingerprints it.
#                It touches nothing live. Use it before trusting any backup.
#
# What is deliberately the same as the staging release that ran on 7 October:
# the gates, their order, no reload of php8.2-fpm or nginx (opcache validates
# timestamps; the script proves the new code is served before it leaves
# maintenance), fail-closed behaviour. What differs: the tree belongs to `dev`
# (git, composer and assets run as dev); the scheduler cron is held and running
# scheduled commands are waited for; both product addresses are set explicitly;
# three hosts are proven and smoke-tested; the backup comparison covers EVERY
# table (row count and content hash), not a sample; the schema gate removes
# only pg_dump's random \restrict token and the six relations named below.
#
# Fail-closed. A gate that fails before the checkout puts production back as it
# was. A gate that fails after it leaves production IN MAINTENANCE with the
# worker stopped and the cron held, and prints where the evidence is.
# ============================================================================
set -uo pipefail
export LC_ALL=C

MODE=${1:?usage: preflight|release from-sha target-sha bundle assets.tar.gz assets-sha256 | resume|baseline|tree-check from-sha target-sha stopped-run-dir | restore-check dump}
case "$MODE" in
  preflight|release) FROM=${2:?from-sha}; TARGET=${3:?target-sha}; BUNDLE=${4:?bundle}; ASSETS=${5:?assets tarball}; ASSETS_SHA=${6:?assets sha256} ;;
  resume|baseline|tree-check) FROM=${2:?from-sha}; TARGET=${3:?target-sha}; PREV=${4:?evidence dir of the stopped release} ;;
  restore-check) CHECK_DUMP=${2:?dump file} ;;
  *) echo "unknown mode: $MODE"; exit 64 ;;
esac

DIR=/var/www/jewelflow
OWNER=dev
DB=jewelflow
APPURL=https://jewelflows.com
RETAIL=jewelflows.com
WWW=www.jewelflows.com
DHIRAN=dhiran.jewelflows.com
DHIRAN_REGISTER_URL=https://dhiran.jewelflows.com/register
ERP_REGISTER_URL=https://jewelflows.com/register
WORKER=jewelflow-production-ops-alerts
CRON=/etc/cron.d/jewelflow-scheduler
NIGHTLY=$DIR/storage/app/private/JewelFlows
NGINX_SITE=/etc/nginx/sites-enabled/jewelflow
DENIED=(/storage/kyc/ /storage/signatures/ /storage/karigar-invoices/ /storage/purchases/ /storage/repairs/)
OTHER=/var/www/jewelflow-staging
OTHER_HOST=staging.jewelflows.com
FLOOR=250950fa7739085fb1240f1c321711df336bc31c
REVIEWED=5cbad199a719ffdc86a4a87ac2e60dc7d2ea01f0
RUNTIME=(app bootstrap config database resources routes public artisan composer.json composer.lock package.json package-lock.json vite.config.js)
BRANCH=integration/jewelflows-takeover
REF=refs/takeover/candidate
MIGRATION=2026_10_05_000001_create_product_promotion_tables
NEW_TABLES=(product_promotion_preferences product_promotion_exposures product_recognition_requests product_recognitions)
# Everything the migration may add, by name. Any other new relation fails the release.
NEW_RELATIONS='rel S product_promotion_exposures_id_seq
rel S product_promotion_preferences_id_seq
rel i product_promo_exposure_once
rel i product_promo_preference_identity
rel i product_promotion_exposures_pkey
rel i product_promotion_preferences_pkey
rel i product_recognition_pair
rel i product_recognition_requests_code_hash_unique
rel i product_recognition_requests_expires_at_index
rel i product_recognition_requests_pkey
rel i product_recognitions_pkey
rel r product_promotion_exposures
rel r product_promotion_preferences
rel r product_recognition_requests
rel r product_recognitions'
# pg_dump leaves a table's indexes and constraints out with the table; its id sequence is named separately.
EXCLUDE=(-T product_promotion_preferences -T product_promotion_exposures -T product_recognition_requests -T product_recognitions
         -T product_promotion_preferences_id_seq -T product_promotion_exposures_id_seq)
ENV_BLOCK=$'\n# Product addresses, explicit: production also answers on www, where a host-derived Dhiran address would be wrong.\nDHIRAN_REGISTER_URL='"$DHIRAN_REGISTER_URL"$'\nERP_REGISTER_URL='"$ERP_REGISTER_URL"$'\n'

[ "$(id -u)" = 0 ] || { echo "run as root"; exit 64; }
SELF=$(readlink -f "$0"); [ -f "$SELF" ] && [ "$(basename "$SELF")" != bash ] || { echo "run this script from a file, so that its revision is on record"; exit 64; }
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
EVID=/root/takeover-production
WORK=$EVID/$MODE-$STAMP
mkdir -p "$WORK" && chmod 700 "$EVID" "$WORK"
exec > >(tee -a "$WORK/run.log") 2>&1
TEE=$!; trap 'exec >&- 2>&-; wait "$TEE"' EXIT
umask 022
cp "$SELF" "$WORK/script.sh"
echo "script $(sha256sum "$SELF" | cut -d' ' -f1) ($SELF), copied to $WORK/script.sh"

PHASE=preflight
QUIESCED=0
HELD=$WORK/cron.held
OWNERDO() { sudo -u "$OWNER" env HOME="$(getent passwd "$OWNER" | cut -d: -f6)" "$@"; }
G() { OWNERDO git -C "$DIR" --no-optional-locks "$@"; }              # the production repository, as its owner
C() { git --git-dir="$CAND" "$@"; }                                    # the candidate, in a throwaway repository
ART() { ( cd "$DIR" && sudo -u www-data php artisan "$@" ); }
CFG() { if sudo -u www-data test -r "$DIR/.env"; then ART "$@"; else echo "www-data cannot read $DIR/.env: refusing to run artisan as root" >&2; return 1; fi; }
cache_ok() { ( cd "$DIR" && sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; exit($c["app"]["key"] && $c["database"]["connections"]["pgsql"]["password"] !== null ? 0 : 1);' ); }
cfgid() { ( cd "$DIR" && sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; echo $c["app"]["env"]."|".$c["app"]["url"]."|".$c["database"]["connections"]["pgsql"]["database"];' 2>/dev/null ); }
promo() { ( cd "$DIR" && sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; $p = $c["platform"]["cross_promotion"]; echo var_export($p["dhiran_register_url"] ?? null, true)."|".var_export($p["erp_register_url"] ?? null, true);' ); }
# One line per top-level configuration key: a hash of its value, the two product addresses left
# out. Hashes only, so no secret is copied; a changed key shows up by name.
config_hashes() { ( cd "$DIR" && sudo -u www-data php -r '
  $c = require "bootstrap/cache/config.php"; unset($c["platform"]["cross_promotion"]["dhiran_register_url"], $c["platform"]["cross_promotion"]["erp_register_url"]);
  ksort($c); foreach ($c as $k => $v) { echo $k." ".hash("sha256", serialize($v))."\n"; }' ); }
root_owned() { find "$DIR" -xdev -user root -not -path "$DIR/.git/*" 2>/dev/null | sort; }
PSQL() { sudo -u postgres psql -X -A -t -q -v ON_ERROR_STOP=1 -d "$DB" -c "$1"; }
ok() { echo "ok    $*"; }
code() { local h=$1; shift; curl -sk -o /dev/null -w '%{http_code}' -m 20 --resolve "$h:443:127.0.0.1" "$@"; }
body() { local h=$1; shift; curl -sk -m 20 --resolve "$h:443:127.0.0.1" "$@"; }
new_rows() { local t n=0; for t in "${NEW_TABLES[@]}"; do n=$((n + $(PSQL "select count(*) from $t"))); done; echo "$n"; }
# Every untracked file (not ignored), as "<sha256 of its content>  <path>": the actual files, not
# git's summary of them. (Git folds an untracked directory into one line and unfolds it as soon as
# the target tracks any file in it; production's docs/superpowers/ is such a directory. A gate on
# that listing failed in rehearsal on a tree in which no untracked file had been touched.)
untracked() { G ls-files --others --exclude-standard -z | ( cd "$DIR" && xargs -0 -r sha256sum -- ) | sort -k2; }
untracked_paths() { sed -E 's/^\\?[0-9a-f]{64}  //' <<< "$1"; }
untracked_differ() { diff <(echo "$UNTRACKED_BEFORE") <(untracked) | sed -nE 's/^[<>] \\?[0-9a-f]{64}  //p' | sort -u | tr '\n' ' '; }

# Every table of the public schema (row count and a hash of its content), every relation
# and the trigger count. Session settings are pinned so that the text of a value is the
# same in the live database and in a restored one.
FP_OPTS='-c timezone=UTC -c datestyle=ISO,YMD -c intervalstyle=postgres -c extra_float_digits=1 -c bytea_output=hex'
FP_SQL=$(cat <<'SQL'
select line from (
  select 'table '||c.relname||' '||(xpath('/row/n/text()', x))[1]::text||' '||(xpath('/row/h/text()', x))[1]::text as line
  from pg_class c join pg_namespace n on n.oid = c.relnamespace,
  lateral query_to_xml(format('select count(*) as n, md5(coalesce(string_agg(md5(t::text), '''' order by md5(t::text) collate "C"), '''')) as h from %I.%I t', n.nspname, c.relname), false, true, '') x
  where n.nspname = 'public' and c.relkind = 'r'
  union all select 'rel '||c.relkind||' '||c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind in ('r','S','i','v','m','p')
  union all select 'triggers '||count(*) from pg_trigger where not tgisinternal
) s order by line collate "C";
SQL
)
fingerprint() { sudo -u postgres env "PGOPTIONS=$FP_OPTS" psql -X -q -A -t -v ON_ERROR_STOP=1 -d "$DB" -c "$FP_SQL"; }
fp_tables() { grep '^table ' "$1"; }
fp_summary() { echo "$(grep -c '^table ' "$1") tables, $(awk '$1 == "table" && $3 > 0' "$1" | wc -l) holding rows, $(grep -c '^rel ' "$1") relations, $(awk '$1 == "triggers" { print $2 }' "$1") triggers"; }
fp_get() { awk -v t="$2" '$1 == "table" && $2 == t { print $3 }' "$1"; }
# `sessions` is the one table this script's own page requests write (the proof through the bypass):
# it is left out of the whole-table comparisons after quiesce and checked row by row instead. Two
# kinds of change are understood: a GUEST row added since the window opened, and a row removed by
# the session garbage collector because it had expired. Anything else is listed: a row that existed
# and changed, a new row belonging to a user, a new row older than the window, a live row gone.
SESSION_SQL="select md5(id)||' '||md5(s::text)||' '||last_activity||' '||(user_id is null) from sessions s order by 1"
sessions_now() { sudo -u postgres env "PGOPTIONS=$FP_OPTS" psql -X -q -A -t -v ON_ERROR_STOP=1 -d "$DB" -c "$SESSION_SQL"; }
sessions_unexplained() {
  sessions_now | awk -v start="$WINDOW_START" -v life="$SESSION_LIFETIME" -v now="$(date +%s)" -v snapshot="$1" '
    BEGIN { while ((getline line < snapshot) > 0) { split(line, f, " "); before[f[1]] = f[2]; act[f[1]] = f[3] } }
    { seen[$1] = 1
      if ($1 in before) { if (before[$1] != $2) c++ }
      else if ($4 != "t") u++
      else if ($3 < start - 5) o++ }
    END { for (k in before) if (!(k in seen) && act[k] >= now - life) g++
          if (c + u + o + g) printf "%d existing row(s) changed, %d new row(s) belonging to a user, %d new row(s) older than the window, %d unexpired row(s) gone", c, u, o, g }'
}
fp_differ() { diff <(fp_tables "$1") <(fp_tables "$2") | awk '/^[<>] table/ { print $3 }' | sort -u | tr '\n' ' '; }
# The schema as text. Only pg_dump's per-run \restrict token is removed (it is random, so two
# dumps of one schema never compare equal with it). Comments and blank lines stay: they can be
# part of a function body.
norm() { sed -E '/^\\(un)?restrict [A-Za-z0-9]+$/d'; }
schema_live() { sudo -u postgres pg_dump -s -d "$DB" "$@" | norm; }

# Restore a dump in an instance that can reach nothing: its own PostgreSQL on a tmpfs, a
# throwaway user, no network, the live data directory, sockets, /var/www and /root hidden.
# No application code runs in it, so nothing can be queued, mailed or notified from it.
isolated_restore() {
  local sandbox
  sandbox=$(cat <<'SH'
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
"$B/psql" -X -q -h "$W" -U postgres -d postgres -c "create database restore" </dev/null
cat > "$W/in.dump"                       # the dump: this unit's stdin
"$B/pg_restore" --no-owner --no-acl --exit-on-error -h "$W" -U postgres -d restore "$W/in.dump" </dev/null >/dev/null
"$B/psql" -X -q -A -t -v ON_ERROR_STOP=1 -h "$W" -U postgres -d restore -c "$FP_SQL" </dev/null
"$B/pg_ctl" -D "$W/data" -s -m immediate stop </dev/null >/dev/null || true
SH
)
  systemd-run -p DynamicUser=yes --quiet --wait --pipe --collect \
    -p PrivateNetwork=yes -p PrivateIPC=yes -p ProtectProc=invisible -p ProtectSystem=strict -p ProtectHome=yes \
    -p NoNewPrivileges=yes -p CapabilityBoundingSet= -p "InaccessiblePaths=-/var/www -/var/lib/postgresql -/etc/postgresql -/run/postgresql -/var/backups -/var/log -/etc/nginx -/etc/letsencrypt -/etc/ssh -/root" \
    -p "TemporaryFileSystem=/mnt:mode=1777,size=2G" -p MemoryMax=3G -p TasksMax=512 -p RuntimeMaxSec=900 -p LimitFSIZE=2G \
    -E "FP_SQL=$FP_SQL" -E "PGOPTIONS=$FP_OPTS" -E "MUST_NOT_SEE=$DIR/.env $OTHER/.env /var/lib/postgresql /run/postgresql" \
    -- /bin/bash -c "$sandbox" < "$1" > "$2"
}

if [ "$MODE" = restore-check ]; then
  [ -f "$CHECK_DUMP" ] && pg_restore -f /dev/null "$CHECK_DUMP" 2>/dev/null || { echo "the dump is missing or does not read end to end"; exit 2; }
  isolated_restore "$CHECK_DUMP" "$WORK/restored.fp" || { echo "the dump did not restore in the isolated instance"; exit 2; }
  echo "RESTORE CHECK PASSED: $CHECK_DUMP (sha256 $(sha256sum "$CHECK_DUMP" | cut -d' ' -f1)) restores: $(fp_summary "$WORK/restored.fp"), $(fp_get "$WORK/restored.fp" migrations) migrations. Fingerprint: $WORK/restored.fp"
  exit 0
fi

# Staging and the two shared services must be exactly as they were (nothing here reloads them).
shared_state() {
  printf '%s|%s|%s|%s|%s|%s' "$(git -C "$OTHER" rev-parse HEAD)" "$([ -e "$OTHER/storage/framework/down" ] && echo down || echo up)" "$(stat -c %Y "$OTHER/bootstrap/cache/config.php")" \
    "$(systemctl show -p ActiveEnterTimestamp --value php8.2-fpm)" "$(systemctl show -p ActiveEnterTimestamp --value nginx)" "$(sha256sum "$NGINX_SITE" | cut -d' ' -f1)"
}
# The web server's refusals of private storage, on both products: 403 for every prefix.
protections() { local h p; for h in "$RETAIL" "$DHIRAN"; do for p in "${DENIED[@]}"; do printf '%s ' "$(code "$h" "https://$h${p}takeover-probe.txt")"; done; done; }
# What a visitor without a session is answered, on the three hosts, and the mobile API without a
# token (asked as JSON, as the app asks: a plain request is redirected to the log-in instead).
answers() {
  local h
  for h in "$RETAIL" "$WWW" "$DHIRAN"; do printf '%s ' "$(code "$h" "https://$h/health")" "$(code "$h" "https://$h/login")" "$(code "$h" "https://$h/register")"; done
  printf '%s ' "$(code "$DHIRAN" "https://$DHIRAN/")" "$(code "$RETAIL" "https://$RETAIL/admin/login")" "$(code "$RETAIL" "https://$RETAIL/dashboard")" "$(code "$RETAIL" -H 'Accept: application/json' "https://$RETAIL/api/mobile/v1/sessions/me")"
}
ANSWERS_EXPECTED='200 200 200 200 200 200 200 200 200 302 200 302 401 '
PROTECTIONS_EXPECTED='403 403 403 403 403 403 403 403 403 403 '
# Scheduled or queued artisan processes of THIS application (not staging's, not php-fpm).
artisan_running() {
  local p n=0
  for p in $(pgrep -u www-data -f artisan || true); do
    if grep -qa "$DIR/artisan" "/proc/$p/cmdline" 2>/dev/null || [ "$(readlink "/proc/$p/cwd" 2>/dev/null)" = "$DIR" ]; then n=$((n + 1)); fi
  done
  echo "$n"
}
# The scheduler is held for the window and does not catch up afterwards, so the window must
# not cover a daily job (00:00 to 03:00 and 06:00 India time). 23:40-03:10 and 05:40-06:10 are refused.
window_clear() { local m; m=$(TZ=Asia/Kolkata date +'%H %M' | awk '{ print $1 * 60 + $2 }'); ! { [ "$m" -ge 1420 ] || [ "$m" -le 190 ] || { [ "$m" -ge 340 ] && [ "$m" -le 370 ]; }; }; }
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
save() { declare -p "$@" >> "$WORK/state"; }
# The worker is started only once the site is up, and must then stay up. A worker with --max-time
# that finds the application in maintenance leaves after its first pause, with status 0 (Laravel's
# Worker::pauseWorker asks stopIfNecessary without a start time, so the time limit counts from the
# machine's boot). Started before `up`, it exits; systemd restarts it five seconds later; a look at
# "did not fail" two seconds after the start sees nothing wrong. Seen in the staging release of
# 7 October (started 15:51:03, gone 15:51:06, restarted 15:51:11) and in the tooling rehearsal.
# Healthy here means: active, and the same main process ten seconds apart.
worker_steady() {
  local a b
  systemctl start "$WORKER" || return 1
  sleep 2; a=$(systemctl show -p MainPID --value "$WORKER")
  sleep 10; b=$(systemctl show -p MainPID --value "$WORKER")
  [ "$(systemctl is-active "$WORKER")" = active ] && [ -n "$a" ] && [ "$a" != 0 ] && [ "$a" = "$b" ]
}
unquiesce() {
  [ -e "$HELD" ] && { mv "$HELD" "$CRON"; echo "      cron put back: $CRON"; }
  ART up >/dev/null 2>&1 && echo "      maintenance off"
  worker_steady && echo "      $WORKER started and steady" || echo "      $WORKER IS NOT RUNNING STEADILY: look at it now"
}
fail() {
  echo "!!!!! GATE FAILED [$PHASE]: $*"
  case "$PHASE" in
    preflight) echo "Nothing was changed." ;;
    quiesce|backup)
      echo "No code, schema or configuration was changed. Putting production back as it was:"
      [ "$QUIESCED" = 1 ] && unquiesce ;;
    smoke) echo "Production is UP on $TARGET (maintenance had ended); nothing was rolled back. Inspect now. Evidence: $WORK." ;;
    *) echo "Production (Retail, Dhiran and the mobile API) is LEFT IN MAINTENANCE, $WORKER stopped, the scheduler cron held at $HELD."
       echo "Evidence and the pre-release copies (.env, config cache, assets, database dump): $WORK."
       echo "Recovery: docs/runbooks/product-promotion-recognition.md, 'Recovery'. Forward with 'resume' once the cause is fixed, or back with 'baseline', which refuses unless the four promotion tables are absent or empty." ;;
  esac
  exit 2
}

# ── gates shared by release and resume ──────────────────────────────────────
verify_migrated() {
  PHASE=migrate
  local added mine
  mine="^table (migrations|sessions|$(IFS='|'; echo "${NEW_TABLES[*]}")) "   # sessions: checked row by row just below
  grep -qi "no pending migrations" <<< "$(ART migrate:status --pending 2>&1)" || fail "a migration is still pending"
  [ "$(new_rows)" = 0 ] || fail "the new tables are not empty"
  fingerprint > "$WORK/live.after.fp" || fail "could not fingerprint the migrated database"
  added=$(comm -13 <(grep '^rel ' "$BEFORE_FP") <(grep '^rel ' "$WORK/live.after.fp"))
  # MIGRATED=1: an earlier attempt applied the migration and returned to the baseline with the empty tables in place.
  if [ "$MIGRATED" = 1 ]; then [ -z "$added" ] || fail "relations were added although the migration was already applied: $(tr '\n' ' ' <<< "$added")"
  else [ "$added" = "$NEW_RELATIONS" ] || fail "the relations added are not exactly the fifteen named in this script: $(tr '\n' ' ' <<< "$added")"; fi
  [ -z "$(comm -23 <(grep '^rel ' "$BEFORE_FP") <(grep '^rel ' "$WORK/live.after.fp"))" ] || fail "a relation that existed before is gone"
  [ "$(awk '$1 == "triggers" { print $2 }' "$WORK/live.after.fp")" = "$(awk '$1 == "triggers" { print $2 }' "$BEFORE_FP")" ] || fail "the trigger count changed"
  [ "$(fp_get "$WORK/live.after.fp" migrations)" = "$(( $(fp_get "$BEFORE_FP" migrations) + 1 - MIGRATED ))" ] || fail "the migrations table did not grow by exactly the one migration"
  # Every table that existed before: same row count, same content. Only `migrations` may differ (one row more).
  cmp -s <(fp_tables "$BEFORE_FP" | grep -vE "$mine") <(fp_tables "$WORK/live.after.fp" | grep -vE "$mine") \
    || fail "a row of an existing table changed: $(fp_differ "$BEFORE_FP" "$WORK/live.after.fp")"
  [ -z "$(sessions_unexplained "$SESSIONS_BEFORE")" ] || fail "the sessions table changed in a way this script does not account for: $(sessions_unexplained "$SESSIONS_BEFORE")"
  schema_live "${EXCLUDE[@]}" > "$WORK/schema.after.sql"
  cmp -s "$BEFORE_SCHEMA" "$WORK/schema.after.sql" || fail "the schema outside the six named relations changed: diff $BEFORE_SCHEMA $WORK/schema.after.sql"
  ok "migration $MIGRATION $([ "$MIGRATED" = 1 ] && echo 'was already applied (not run again)' || echo 'applied: fifteen named relations added'), four empty tables; every existing table has its rows and content (in sessions, nothing but guest rows of this window and expired rows collected); existing schema ($(wc -l < "$WORK/schema.after.sql") lines) identical"
}
configure() {
  PHASE=config
  if ! grep -qE '^(DHIRAN_REGISTER_URL|ERP_REGISTER_URL)=' "$DIR/.env"; then printf '%s' "$ENV_BLOCK" >> "$DIR/.env" || fail "could not write the product addresses"; fi
  [ "$(stat -c '%U:%G %a' "$DIR/.env")" = "$ENV_STAT" ] || fail ".env ownership or mode changed (was $ENV_STAT)"
  # Every line that was in .env is still there, in place; the only addition is the block above.
  cmp -s <(head -c "$(stat -c %s "$ENV_BEFORE")" "$DIR/.env") "$ENV_BEFORE" && [ "$(tail -c +"$(( $(stat -c %s "$ENV_BEFORE") + 1 ))" "$DIR/.env")" = "$(printf '%s' "$ENV_BLOCK")" ] \
    || fail ".env differs from its pre-release copy by more than the two product addresses"
  CFG config:cache >/dev/null || fail "config:cache failed"
  cache_ok || fail "the config cache has no application key or database password"
  [ "$(cfgid)" = "production|$APPURL|$DB" ] || fail "effective config after the release is '$(cfgid)'"
  [ "$(promo)" = "'$DHIRAN_REGISTER_URL'|'$ERP_REGISTER_URL'" ] || fail "the product addresses in effect are $(promo)"
  [ -z "$(diff <(config_hashes) "$CONFIG_BEFORE" | awk '/^[<>]/ { print $2 }' | sort -u)" ] || fail "configuration changed beyond the two product addresses, under: $(diff <(config_hashes) "$CONFIG_BEFORE" | awk '/^[<>]/ { print $2 }' | sort -u | tr '\n' ' ')"
  [ "$(stat -c '%U:%G %a' "$DIR/bootstrap/cache/config.php")" = "$CACHE_STAT" ] || fail "the config cache's ownership or mode changed (was $CACHE_STAT)"
  ok "configuration: both product addresses explicit and in effect; every other setting and every other .env line unchanged"
}
finish() {
  PHASE=caches
  ART route:cache >/dev/null && ART view:clear >/dev/null && ART view:cache >/dev/null || fail "caching failed"
  ART assets:verify-fresh >/dev/null 2>&1 || fail "assets:verify-fresh says the built assets are older than their sources"
  while IFS= read -r f; do [ -e "$DIR/$f" ] || continue; sudo -u www-data test -r "$DIR/$f" || fail "www-data cannot read $f"; done < <(G diff --name-only "$FROM" "$TARGET")
  [ "$(root_owned)" = "$ROOT_OWNED_BEFORE" ] || fail "the root-owned files in the tree changed: $(diff <(echo "$ROOT_OWNED_BEFORE") <(root_owned) | head -3 | tr '\n' ' ')"
  [ "$(untracked)" = "$UNTRACKED_BEFORE" ] || fail "an untracked file was added, removed or changed: $(untracked_differ)"
  ok "routes and views cached; assets fresh; every changed file readable by www-data; no new root-owned file; every untracked file present with its content"

  # ── proof, still in maintenance: the shared FPM pool serves the NEW code on all three hosts ──
  PHASE=freshness
  sleep 6   # opcache: validate_timestamps on, revalidate_freq 2, file_update_protection 2
  JAR=$WORK/bypass.jar
  for h in "$RETAIL" "$WWW" "$DHIRAN"; do [ "$(code "$h" -b "$JAR" -c "$JAR" "https://$h/$SECRET")" = 302 ] || fail "the maintenance bypass did not answer on $h"; done
  for h in "$RETAIL" "$WWW"; do
    LANDING=$(body "$h" -b "$JAR" "https://$h/")
    grep -q 'Start with Retail' <<< "$LANDING" || fail "the new landing page is not served on $h (stale code?)"
    grep -qF "href=\"$DHIRAN_REGISTER_URL\"" <<< "$LANDING" && grep -qF "href=\"https://$DHIRAN/login\"" <<< "$LANDING" || fail "the landing page on $h does not link to the Dhiran address"
    ! grep -qiE 'dhiran\.www\.|staging\.' <<< "$LANDING" || fail "the landing page on $h names a wrong host"
  done
  [ "$(code "$DHIRAN" -b "$JAR" "https://$DHIRAN/")" = 302 ] && [ "$(code "$DHIRAN" -b "$JAR" "https://$DHIRAN/login")" = 200 ] || fail "the Dhiran root does not redirect to a working log-in"
  [ "$(code "$RETAIL" -b "$JAR" "https://$RETAIL/product-preferences")" = 302 ] && [ "$(code "$DHIRAN" -b "$JAR" "https://$DHIRAN/dhiran/product-preferences")" = 302 ] || fail "the new routes are not served in both products"
  [ "$(body "$RETAIL" -b "$JAR" "https://$RETAIL/build/manifest.json" | sha256sum | cut -d' ' -f1)" = "$MANIFEST_SHA" ] || fail "the served asset manifest is not the released one"
  for h in "$RETAIL" "$WWW" "$DHIRAN"; do [ "$(code "$h" "https://$h/")" = 503 ] || fail "maintenance does not hold on $h for a visitor without the bypass"; done
  [ "$(protections)" = "$PROTECTIONS_EXPECTED" ] || fail "a private storage prefix is not refused: $(protections)"
  rm -f "$JAR"
  ok "new code is served on all three hosts without any shared-service reload: landing with the one Dhiran address, new routes, released manifest; visitors still get 503; private storage still refused"

  # ── the synthetic two-product check: only with its own approval ─────────────
  PHASE=check
  if [ "${TAKEOVER_PRODUCTION_CHECK:-}" = approved ]; then
    fingerprint > "$WORK/live.precheck.fp" || fail "could not fingerprint the database before the synthetic check"
    ( cd "$DIR" && sudo -u www-data env TAKEOVER_PRODUCTION_CHECK=approved php tests/Production/verify_takeover_production.php ) > "$WORK/production-check.txt" 2>&1 \
      || fail "the synthetic check failed (see $WORK/production-check.txt); the four tables hold $(new_rows) rows"
    # The exit code alone is not trusted: the script must have run to its last line, with every check passing.
    grep -q '^TAKEOVER PRODUCTION CHECK PASSED' "$WORK/production-check.txt" && ! grep -q '^FAIL' "$WORK/production-check.txt" && [ "$(grep -c '^PASS' "$WORK/production-check.txt")" -ge 18 ] \
      || fail "the synthetic check did not finish with all of its checks passing (see $WORK/production-check.txt); the four tables hold $(new_rows) rows"
    [ "$(new_rows)" = 0 ] || fail "the synthetic check left rows in the new tables"
    fingerprint > "$WORK/live.checked.fp" && cmp -s "$WORK/live.precheck.fp" "$WORK/live.checked.fp" || fail "the synthetic check left a table, relation or trigger changed: $(fp_differ "$WORK/live.precheck.fp" "$WORK/live.checked.fp")"
    ok "synthetic two-product check passed and left no row (every table fingerprinted from outside it, immediately before and after): $(grep -c '^PASS' "$WORK/production-check.txt") checks; lasting effects recorded in $WORK/production-check.txt"
  else
    echo "NOT RUN synthetic two-product check: not approved for this window (TAKEOVER_PRODUCTION_CHECK=approved)"
  fi

  # ── production processes back, then up ──────────────────────────────────────
  PHASE=processes
  mv "$HELD" "$CRON" || fail "could not put the scheduler cron back"
  [ "$(sha256sum "$CRON" | cut -d' ' -f1)" = "$CRON_SHA" ] && [ "$(stat -c '%U:%G %a' "$CRON")" = "$CRON_STAT" ] || fail "the scheduler cron is not byte-identical with its mode"
  ok "scheduler cron back ($CRON_SHA, $CRON_STAT)"
  PHASE=up
  ART up >/dev/null || fail "artisan up failed"
  ok "maintenance off at $(date -u +%H:%M:%SZ)"

  PHASE=smoke
  worker_steady || fail "smoke: $WORKER is not running steadily (active, same main process ten seconds apart)"
  ok "$WORKER started after maintenance ended and is steady"
  [ "$(answers)" = "$ANSWERS_EXPECTED" ] || fail "smoke: a public answer changed: $(answers) (expected $ANSWERS_EXPECTED)"
  [ "$(code "$RETAIL" "https://$RETAIL/")" = 200 ] && [ "$(code "$WWW" "https://$WWW/")" = 200 ] || fail "smoke: the landing page is not 200"
  [ "$(code "$RETAIL" "https://$RETAIL/product-preferences")" = 302 ] && [ "$(code "$DHIRAN" "https://$DHIRAN/dhiran/product-preferences")" = 302 ] || fail "smoke: the new routes did not redirect a guest"
  [ "$(protections)" = "$PROTECTIONS_EXPECTED" ] || fail "smoke: a private storage prefix is not refused: $(protections)"
  NEWERR=$(new_errors)
  [ "$NEWERR" = 0 ] || fail "smoke: $NEWERR new error line(s) in storage/logs/laravel*.log since the mark ($WORK/log.mark)"
  [ "$(shared_state)" = "$SHARED_BEFORE" ] || fail "staging's commit, maintenance state or config cache, a shared service or the web-server site file changed: $(shared_state)"
  [ "$(code "$OTHER_HOST" "https://$OTHER_HOST/health")" = "$OTHER_HEALTH_BEFORE" ] || fail "staging /health changed"
  ok "smoke passed on the three hosts and the mobile API; private storage refused; no new error; staging, php-fpm, nginx and its site file untouched"
  echo "TAKEOVER PRODUCTION RELEASE PASSED: production at $TARGET (from $FROM); manifest $MANIFEST_SHA; backup $BACKUP ($DUMP_SHA); evidence in $WORK"
}

# ── resume: finish a release a gate stopped at or after the migration ───────
if [ "$MODE" = resume ]; then
  echo "########## takeover production resume: $FROM -> $TARGET at $STAMP (UTC), finishing the run in $PREV ##########"
  cd "$DIR" || fail "no directory $DIR"
  [ -f "$PREV/state" ] && [ "$(cat "$PREV/code.before" 2>/dev/null)" = "$FROM" ] || fail "$PREV is not the evidence of a release from $FROM"
  # shellcheck disable=SC1091
  source "$PREV/state"; MIGRATED=${MIGRATED:-0}
  HELD=$PREV/cron.held; BACKUP=$PREV/$DB.dump; BEFORE_FP=$PREV/live.before.fp; BEFORE_SCHEMA=$PREV/schema.before.sql; ENV_BEFORE=$PREV/env.before; CONFIG_BEFORE=$PREV/config.before.hashes; SESSIONS_BEFORE=$PREV/sessions.before
  grep -qE '^APP_ENV=production$' "$DIR/.env" || fail "$DIR/.env is not APP_ENV=production"
  [ -f storage/framework/down ] || fail "production is not in maintenance: there is nothing to resume"
  [ "$(G rev-parse HEAD)" = "$TARGET" ] && [ -z "$(G status --porcelain --untracked-files=no)" ] || fail "production is not cleanly on $TARGET (HEAD $(G rev-parse HEAD), $(G status --porcelain --untracked-files=no | wc -l) tracked path(s) differing): see the procedure, 'A checkout cut short'. Nothing is discarded automatically"
  [ "$(untracked)" = "$UNTRACKED_BEFORE" ] || fail "an untracked file was added, removed or changed since the release began: $(untracked_differ)"
  [ -f "$HELD" ] && [ ! -e "$CRON" ] || fail "the scheduler cron is not held at $HELD"
  [ "$(systemctl is-active "$WORKER")" != active ] && [ "$(artisan_running)" = 0 ] || fail "$WORKER or a scheduled command is running"
  [ -f "$BACKUP" ] && [ "$(sha256sum "$BACKUP" | cut -d' ' -f1)" = "$DUMP_SHA" ] && pg_restore -f /dev/null "$BACKUP" 2>/dev/null || fail "the stopped run's backup is not the one it recorded, or does not read"
  for t in "${NEW_TABLES[@]}"; do [ "$(PSQL "select count(*) from information_schema.tables where table_schema = 'public' and table_name = '$t'")" = 1 ] || fail "table $t does not exist: the migration was not applied, so this is not a case for resume (return to the baseline instead)"; done
  for h in "$RETAIL" "$WWW" "$DHIRAN"; do [ "$(code "$h" "https://$h/")" = 503 ] || fail "$h does not answer 503"; done
  SECRET=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["secret"] ?? "";' "$DIR/storage/framework/down")
  [ -n "$SECRET" ] || fail "the maintenance file holds no bypass secret"
  [ "$(sha256sum "$DIR/public/build/manifest.json" | cut -d' ' -f1)" = "$MANIFEST_SHA" ] || fail "the manifest on disk is not the released $MANIFEST_SHA"
  ok "resuming on $TARGET in maintenance; before-state and backup ($DUMP_SHA) from $PREV"
  configure
  verify_migrated
  finish
  exit 0
fi

# ── tree-check: read-only. What a checkout that was cut short left, path by path ──
if [ "$MODE" = tree-check ]; then
  echo "########## takeover production tree-check at $STAMP (UTC): deployed $FROM, target $TARGET, evidence $PREV. This mode changes nothing. ##########"
  cd "$DIR" || fail "no directory $DIR"
  [ -f "$PREV/state" ] && [ -d "$PREV/candidate.git" ] || fail "$PREV is not the evidence of a release (no saved state, or no throwaway repository of the candidate)"
  # shellcheck disable=SC1091
  source "$PREV/state"
  CAND=$PREV/candidate.git   # holds both commits even if the fetch into the production repository was cut short
  HEAD_NOW=$(G rev-parse HEAD)
  case "$HEAD_NOW" in "$FROM") OTHER=$TARGET ;; "$TARGET") OTHER=$FROM ;; *) fail "HEAD is $HEAD_NOW, neither the deployed commit nor the target" ;; esac
  blob() { C rev-parse --verify --quiet "$1:$2" 2>/dev/null || echo absent; }
  have() { if [ -e "$DIR/$1" ]; then G hash-object --no-filters -- "$1"; else echo absent; fi; }
  UNKNOWN=0; RESTORE=(); REMOVE=()
  echo "HEAD is $HEAD_NOW; the other commit is $OTHER. Paths that differ from HEAD:"
  while IFS= read -r -d '' e; do
    p=${e:3}
    if [ "$(have "$p")" = "$(blob "$OTHER" "$p")" ]; then echo "  the other commit's version    $p"; RESTORE+=("$p")
    else echo "  NEITHER VERSION               $p"; UNKNOWN=$((UNKNOWN + 1)); fi
  done < <(G status --porcelain -z --untracked-files=no)
  NOW=$(untracked)
  while IFS= read -r p; do
    [ -n "$p" ] || continue
    if [ "$(have "$p")" = "$(blob "$OTHER" "$p")" ]; then echo "  added by the other commit     $p"; REMOVE+=("$p")
    else echo "  NOT THERE BEFORE THE RELEASE AND NOT THE OTHER COMMIT'S   $p"; UNKNOWN=$((UNKNOWN + 1)); fi
  done < <(comm -13 <(untracked_paths "$UNTRACKED_BEFORE" | sort) <(untracked_paths "$NOW" | sort))
  while IFS= read -r l; do
    [ -n "$l" ] || continue
    echo "  AN UNTRACKED FILE RECORDED BEFORE THE RELEASE CHANGED OR IS GONE   $(untracked_paths "$l")"; UNKNOWN=$((UNKNOWN + 1))
  done < <(comm -23 <(echo "$UNTRACKED_BEFORE" | sort) <(echo "$NOW" | sort))
  [ ! -e "$DIR/.git/index.lock" ] || echo "note  .git/index.lock is present ($(pgrep -x git >/dev/null && echo 'and a git process IS RUNNING: wait for it' || echo 'no git process is running: it is a left-over'))"
  if [ "$UNKNOWN" -gt 0 ]; then
    echo "STOP: $UNKNOWN path(s) are not explained by the checkout. Restore nothing and remove nothing. Copy those files aside and find out what they are first."
    exit 3
  fi
  if [ $(( ${#RESTORE[@]} + ${#REMOVE[@]} )) = 0 ]; then echo "The tracked tree is clean at $HEAD_NOW and no file was added: nothing to repair. Use resume or baseline."; exit 0; fi
  {
    [ ! -e "$DIR/.git/index.lock" ] || printf 'rm -f -- %q    # only when "pgrep -x git" prints nothing\n' "$DIR/.git/index.lock"
    if [ ${#RESTORE[@]} -gt 0 ]; then printf 'sudo -H -u %q git -C %q restore --source=HEAD --staged --worktree --' "$OWNER" "$DIR"; printf ' %q' "${RESTORE[@]}"; echo; fi
    for p in "${REMOVE[@]}"; do printf 'rm -- %q\n' "$DIR/$p"; done
    printf 'sudo -H -u %q git -C %q --no-optional-locks status --porcelain --untracked-files=no    # must print nothing\n' "$OWNER" "$DIR"
  } > "$WORK/commands.sh"
  echo "Every differing path is, byte for byte, the other commit's version (${#RESTORE[@]} changed, ${#REMOVE[@]} added): nothing but the cut-short checkout is in the tree, and putting those paths back loses nothing that is not in the repository."
  echo "The commands that would do exactly that, and no more, are in $WORK/commands.sh. They have NOT been run:"
  sed 's/^/    /' "$WORK/commands.sh" | cut -c1-400
  echo "Run them yourself only if you agree with the list above. Then: baseline (HEAD is the deployed commit) or resume/baseline (HEAD is the target)."
  exit 0
fi

# ── baseline: back to the release that was running, only while that is permitted ──
if [ "$MODE" = baseline ]; then
  echo "########## takeover production baseline: back to $FROM from an attempt at $TARGET, at $STAMP (UTC); evidence of that attempt: $PREV ##########"
  cd "$DIR" || fail "no directory $DIR"
  [ -f "$PREV/state" ] && [ "$(cat "$PREV/code.before" 2>/dev/null)" = "$FROM" ] || fail "$PREV is not the evidence of a release from $FROM"
  # shellcheck disable=SC1091
  source "$PREV/state"; MIGRATED=${MIGRATED:-0}
  HELD=$PREV/cron.held; BACKUP=$PREV/$DB.dump; BEFORE_FP=$PREV/live.before.fp; ENV_BEFORE=$PREV/env.before; CONFIG_BEFORE=$PREV/config.before.hashes; SESSIONS_BEFORE=$PREV/sessions.before
  grep -qE '^APP_ENV=production$' "$DIR/.env" || fail "$DIR/.env is not APP_ENV=production"
  [ -f storage/framework/down ] || fail "production is not in maintenance. Once it has been up on the new release, owners may have recorded a preference or consent, and the earlier release must not run over those: REFUSED, repair forward"
  [ -f "$BACKUP" ] && [ "$(sha256sum "$BACKUP" | cut -d' ' -f1)" = "$DUMP_SHA" ] || fail "the stopped run's backup is missing or is not the one it recorded"
  [ storage/framework/down -ot "$BACKUP" ] || fail "maintenance was switched on again after that run's backup, so production has been up in between: REFUSED, repair forward"
  HEAD_NOW=$(G rev-parse HEAD)
  [ "$HEAD_NOW" = "$FROM" ] || [ "$HEAD_NOW" = "$TARGET" ] || fail "HEAD is $HEAD_NOW, neither the baseline nor the target"
  [ -z "$(G status --porcelain --untracked-files=no)" ] || fail "$(G status --porcelain --untracked-files=no | wc -l) tracked path(s) differ from HEAD (a checkout cut short?): see the procedure, 'A checkout cut short'. Nothing is discarded automatically"
  [ "$(untracked)" = "$UNTRACKED_BEFORE" ] || fail "an untracked file was added, removed or changed since the release began: $(untracked_differ)"
  [ -f "$HELD" ] && [ ! -e "$CRON" ] || fail "the scheduler cron is not held at $HELD"
  [ "$(systemctl is-active "$WORKER")" != active ] && [ "$(artisan_running)" = 0 ] || fail "$WORKER or a scheduled command is running"
  # The rule. Counted here, in maintenance, with nothing able to write.
  ROWS=0; PRESENT=0
  for t in "${NEW_TABLES[@]}"; do
    if [ "$(PSQL "select count(*) from information_schema.tables where table_schema = 'public' and table_name = '$t'")" = 1 ]; then PRESENT=$((PRESENT + 1)); ROWS=$((ROWS + $(PSQL "select count(*) from $t"))); fi
  done
  [ "$ROWS" = 0 ] || fail "$ROWS row(s) of promotion metadata exist (preferences, exposures, requests or recognitions). The earlier release neither reads the preferences nor withdraws consent on a security change: REFUSED. Recovery is forward repair only"
  [ "$PRESENT" = 0 ] || [ "$PRESENT" = 4 ] || fail "$PRESENT of the four tables exist: a migration cut short, not something this mode repairs"
  BASE_MANIFEST=$(tar -xzOf "$PREV/build.before.tar.gz" build/manifest.json | sha256sum | cut -d' ' -f1)
  ok "permitted: in maintenance without a break since that run's backup ($DUMP_SHA); HEAD is $HEAD_NOW; the four promotion tables are $([ "$PRESENT" = 0 ] && echo absent || echo 'present and empty')"

  PHASE=baseline
  LOG_MARK=$(log_mark); echo "$LOG_MARK" > "$WORK/log.mark"
  if [ "$HEAD_NOW" != "$FROM" ]; then G checkout --quiet --detach "$FROM" || fail "checkout of the baseline failed"; fi
  [ "$(G rev-parse HEAD)" = "$FROM" ] && [ -z "$(G status --porcelain --untracked-files=no)" ] || fail "the baseline did not land cleanly"
  OWNERDO env COMPOSER_ALLOW_SUPERUSER=1 composer -d "$DIR" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --quiet || fail "composer install failed"
  ART package:discover >/dev/null || fail "package:discover failed"
  if [ "$(sha256sum "$DIR/public/build/manifest.json" 2>/dev/null | cut -d' ' -f1)" != "$BASE_MANIFEST" ]; then
    OWNERDO rm -rf "$DIR/public/build.incoming" && OWNERDO mkdir "$DIR/public/build.incoming" && OWNERDO tar -xzf - -C "$DIR/public/build.incoming" < "$PREV/build.before.tar.gz" || fail "could not unpack the pre-release assets"
    if [ -e "$DIR/public/build" ]; then mv "$DIR/public/build" "$WORK/build.abandoned" || fail "could not set the attempted assets aside"; fi
    OWNERDO mv "$DIR/public/build.incoming/build" "$DIR/public/build" && OWNERDO rmdir "$DIR/public/build.incoming" || fail "could not put the pre-release assets back"
  fi
  [ "$(stat -c '%U:%G' "$DIR/public/build")" = "$BUILD_STAT" ] && [ "$(sha256sum "$DIR/public/build/manifest.json" | cut -d' ' -f1)" = "$BASE_MANIFEST" ] || fail "the assets are not the pre-release ones, owned as before"
  if ! cmp -s "$DIR/.env" "$ENV_BEFORE"; then
    cmp -s <(head -c "$(stat -c %s "$ENV_BEFORE")" "$DIR/.env") "$ENV_BEFORE" && [ "$(tail -c +"$(( $(stat -c %s "$ENV_BEFORE") + 1 ))" "$DIR/.env")" = "$(printf '%s' "$ENV_BLOCK")" ] \
      || fail ".env differs from its pre-release copy by more than the two product addresses: not restored automatically"
    cat "$ENV_BEFORE" > "$DIR/.env" || fail "could not restore .env"
  fi
  cmp -s "$DIR/.env" "$ENV_BEFORE" && [ "$(stat -c '%U:%G %a' "$DIR/.env")" = "$ENV_STAT" ] || fail ".env is not its pre-release copy with its ownership and mode ($ENV_STAT)"
  CFG config:cache >/dev/null || fail "config:cache failed"
  cache_ok || fail "the config cache has no application key or database password"
  [ "$(cfgid)" = "production|$APPURL|$DB" ] || fail "effective config is '$(cfgid)'"
  [ -z "$(diff <(config_hashes) "$CONFIG_BEFORE")" ] || fail "the configuration in effect is not what it was before the release, under: $(diff <(config_hashes) "$CONFIG_BEFORE" | awk '/^[<>]/ { print $2 }' | sort -u | tr '\n' ' ')"
  [ "$(stat -c '%U:%G %a' "$DIR/bootstrap/cache/config.php")" = "$CACHE_STAT" ] || fail "the config cache's ownership or mode changed (was $CACHE_STAT)"
  ART route:cache >/dev/null && ART view:clear >/dev/null && ART view:cache >/dev/null || fail "caching failed"
  [ "$(root_owned)" = "$ROOT_OWNED_BEFORE" ] || fail "the root-owned files in the tree changed"
  [ "$(untracked)" = "$UNTRACKED_BEFORE" ] || fail "an untracked file was added, removed or changed: $(untracked_differ)"
  ok "code $FROM, its assets (manifest $BASE_MANIFEST), .env and configuration are back as recorded before the release"

  # The database: nothing of what existed may have moved; the four tables absent, or present and empty.
  fingerprint > "$WORK/live.baseline.fp" || fail "could not fingerprint the database"
  ADDED=$(comm -13 <(grep '^rel ' "$BEFORE_FP") <(grep '^rel ' "$WORK/live.baseline.fp"))
  [ -z "$ADDED" ] || [ "$ADDED" = "$NEW_RELATIONS" ] || fail "relations other than the release's fifteen were added: $(tr '\n' ' ' <<< "$ADDED")"
  [ -z "$(comm -23 <(grep '^rel ' "$BEFORE_FP") <(grep '^rel ' "$WORK/live.baseline.fp"))" ] || fail "a relation that existed before the release is gone"
  [ "$(awk '$1 == "triggers" { print $2 }' "$WORK/live.baseline.fp")" = "$(awk '$1 == "triggers" { print $2 }' "$BEFORE_FP")" ] || fail "the trigger count changed"
  MINE="^table (migrations|sessions|$(IFS='|'; echo "${NEW_TABLES[*]}")) "   # sessions: checked row by row just below
  cmp -s <(fp_tables "$BEFORE_FP" | grep -vE "$MINE") <(fp_tables "$WORK/live.baseline.fp" | grep -vE "$MINE") || fail "a row of an existing table changed since before the release: $(fp_differ "$BEFORE_FP" "$WORK/live.baseline.fp")"
  [ -z "$(sessions_unexplained "$SESSIONS_BEFORE")" ] || fail "the sessions table changed in a way this script does not account for: $(sessions_unexplained "$SESSIONS_BEFORE")"
  [ -z "$(fp_tables "$WORK/live.baseline.fp" | grep -E "^table ($(IFS='|'; echo "${NEW_TABLES[*]}")) " | awk '$3 != 0')" ] || fail "a promotion table is not empty"
  ok "database: every table that existed before the release has its rows and content (sessions: $(fp_get "$BEFORE_FP" sessions) rows before, $(fp_get "$WORK/live.baseline.fp" sessions) now, the difference all guest rows of this window or expired rows collected); relations $([ -z "$ADDED" ] && echo 'exactly as before' || echo 'as before plus the fifteen of the migration, its four tables empty and left in place')"

  PHASE=freshness
  sleep 6
  SECRET=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["secret"] ?? "";' "$DIR/storage/framework/down")
  [ -n "$SECRET" ] || fail "the maintenance file holds no bypass secret"
  JAR=$WORK/bypass.jar
  for h in "$RETAIL" "$WWW" "$DHIRAN"; do [ "$(code "$h" -b "$JAR" -c "$JAR" "https://$h/$SECRET")" = 302 ] || fail "the maintenance bypass did not answer on $h"; done
  for h in "$RETAIL" "$WWW"; do
    [ "$(code "$h" -b "$JAR" "https://$h/")" = 200 ] && ! body "$h" -b "$JAR" "https://$h/" | grep -q 'Start with Retail' || fail "$h does not serve the earlier landing page"
  done
  # (Only the Retail path can tell: on the Dhiran host the earlier release redirects every unknown dhiran/ path to its log-in.)
  [ "$(code "$RETAIL" -b "$JAR" "https://$RETAIL/product-preferences")" = 404 ] || fail "the new release's route still answers"
  [ "$(code "$DHIRAN" -b "$JAR" "https://$DHIRAN/")" = 302 ] && [ "$(code "$DHIRAN" -b "$JAR" "https://$DHIRAN/login")" = 200 ] || fail "the Dhiran root does not redirect to a working log-in"
  [ "$(body "$RETAIL" -b "$JAR" "https://$RETAIL/build/manifest.json" | sha256sum | cut -d' ' -f1)" = "$BASE_MANIFEST" ] || fail "the served asset manifest is not the pre-release one"
  for h in "$RETAIL" "$WWW" "$DHIRAN"; do [ "$(code "$h" "https://$h/")" = 503 ] || fail "maintenance does not hold on $h for a visitor without the bypass"; done
  [ "$(protections)" = "$PROTECTIONS_EXPECTED" ] || fail "a private storage prefix is not refused: $(protections)"
  rm -f "$JAR"
  ok "the earlier release is what is served: its landing page on both Retail hosts, its manifest, no product-preferences route, the Dhiran log-in; visitors still get 503; private storage still refused"

  PHASE=processes
  mv "$HELD" "$CRON" || fail "could not put the scheduler cron back"
  [ "$(sha256sum "$CRON" | cut -d' ' -f1)" = "$CRON_SHA" ] && [ "$(stat -c '%U:%G %a' "$CRON")" = "$CRON_STAT" ] || fail "the scheduler cron is not byte-identical with its mode"
  PHASE=up
  ART up >/dev/null || fail "artisan up failed"
  ok "scheduler cron back ($CRON_SHA, $CRON_STAT); maintenance off at $(date -u +%H:%M:%SZ)"
  PHASE=smoke
  worker_steady || fail "smoke: $WORKER is not running steadily (active, same main process ten seconds apart)"
  ok "$WORKER started after maintenance ended and is steady"
  [ "$(answers)" = "$ANSWERS_EXPECTED" ] || fail "smoke: a public answer changed: $(answers) (expected $ANSWERS_EXPECTED)"
  [ "$(code "$RETAIL" "https://$RETAIL/")" = 200 ] && [ "$(code "$WWW" "https://$WWW/")" = 200 ] || fail "smoke: the landing page is not 200"
  [ "$(protections)" = "$PROTECTIONS_EXPECTED" ] || fail "smoke: a private storage prefix is not refused: $(protections)"
  NEWERR=$(new_errors)
  [ "$NEWERR" = 0 ] || fail "smoke: $NEWERR new error line(s) in storage/logs/laravel*.log since this run began ($WORK/log.mark)"
  [ "$(shared_state)" = "$SHARED_BEFORE" ] || fail "staging's commit, maintenance state or config cache, a shared service or the web-server site file changed: $(shared_state)"
  echo "RETURNED TO BASELINE: production is up at $FROM; the four promotion tables are $([ -z "$ADDED" ] && echo absent || echo 'present and empty (a later release will not run the migration again)'); the attempt's backup is $BACKUP ($DUMP_SHA); evidence in $WORK"
  exit 0
fi

echo "########## takeover production $MODE: $FROM -> $TARGET at $STAMP (UTC) ##########"
cd "$DIR" || fail "no directory $DIR"
if [ "$MODE" = release ]; then [ "${TAKEOVER_RELEASE_APPROVED:-}" = "$TARGET" ] || fail "release needs TAKEOVER_RELEASE_APPROVED=$TARGET (the owner's approval of this exact commit and window)"; fi

# ── preflight 1: identity (nothing is changed) ───────────────────────────────
grep -qE '^APP_ENV=production$' "$DIR/.env" || fail "$DIR/.env is not APP_ENV=production"
[ "$(cfgid)" = "production|$APPURL|$DB" ] || fail "effective (cached) config is '$(cfgid)'"
[ "$(G rev-parse HEAD)" = "$FROM" ] || fail "HEAD is $(G rev-parse HEAD), expected the deployed $FROM"
[ -z "$(G status --porcelain --untracked-files=no)" ] || fail "tracked tree is dirty"
[ ! -f storage/framework/down ] || fail "production is already in maintenance"
sudo -u www-data test -r "$DIR/.env" || fail "www-data cannot read .env"
[ "$(stat -c %U "$DIR")" = "$OWNER" ] || fail "the tree is not owned by $OWNER"
UNTRACKED_BEFORE=$(untracked); ROOT_OWNED_BEFORE=$(root_owned)
ENV_STAT=$(stat -c '%U:%G %a' "$DIR/.env"); CACHE_STAT=$(stat -c '%U:%G %a' "$DIR/bootstrap/cache/config.php"); BUILD_STAT=$(stat -c '%U:%G' "$DIR/public/build")
if grep -qE '^(DHIRAN_REGISTER_URL|ERP_REGISTER_URL)=' "$DIR/.env"; then fail ".env already sets a product address; this release expects to add both"; fi
[ -z "$(tail -c1 "$DIR/.env")" ] || fail ".env does not end with a newline"
SHARED_BEFORE=$(shared_state)
OTHER_HEALTH_BEFORE=$(code "$OTHER_HOST" "https://$OTHER_HOST/health")
[ "$(systemctl is-active "$WORKER")" = active ] || fail "$WORKER is not active"
[ "$(grep -cvE '^\s*(#|$)' "$CRON")" = 1 ] && grep -qE "^\* \* \* \* \* www-data /usr/bin/php $DIR/artisan schedule:run" "$CRON" || fail "$CRON is not the one scheduler line this script knows how to hold"
ok "production is $FROM, clean, up, owned by $OWNER; .env $ENV_STAT, config cache $CACHE_STAT; $(grep -c . <<< "$UNTRACKED_BEFORE") untracked files recorded with their content hashes, $(grep -c . <<< "$ROOT_OWNED_BEFORE") root-owned; $WORKER active; scheduler cron as expected"
ok "staging recorded: ${SHARED_BEFORE%%|*} (health $OTHER_HEALTH_BEFORE); php-fpm, nginx and its site file recorded"

# ── preflight 2: the candidate, in a throwaway repository (production's is not written) ──
CAND=$WORK/candidate.git
git init --quiet --bare "$CAND" && echo "$DIR/.git/objects" > "$CAND/objects/info/alternates" && C update-ref refs/heads/deployed "$FROM" || fail "could not prepare the throwaway repository"
[ -f "$BUNDLE" ] && C bundle verify "$BUNDLE" >/dev/null 2>&1 || fail "the bundle is missing or does not verify against the deployed commit"
C fetch --quiet "$BUNDLE" "+refs/heads/$BRANCH:$REF" || fail "fetch from the bundle failed"
[ "$(C rev-parse "$REF")" = "$TARGET" ] || fail "the bundle's $BRANCH is $(C rev-parse "$REF"), not $TARGET"
C merge-base --is-ancestor "$FROM" "$TARGET" || fail "target does not descend from the deployed commit"
C merge-base --is-ancestor "$FLOOR" "$TARGET" || fail "target predates the security release floor"
C merge-base --is-ancestor "$REVIEWED" "$TARGET" || fail "target does not descend from the reviewed candidate $REVIEWED"
for p in "${RUNTIME[@]}"; do
  [ "$(C rev-parse "$TARGET:$p")" = "$(C rev-parse "$REVIEWED:$p")" ] || fail "$p in the target is not what was reviewed at $REVIEWED"
  [ "$(C rev-parse "$TARGET:$p")" = "$(git -C "$OTHER" rev-parse "HEAD:$p")" ] || fail "$p in the target is not what staging runs"
done
[ "$(C diff --name-status "$FROM" "$TARGET" -- database/migrations)" = $'A\tdatabase/migrations/'"$MIGRATION.php" ] || fail "the migration delta is not exactly $MIGRATION"
[ -z "$(C diff --name-only "$FROM" "$TARGET" -- composer.json composer.lock package.json package-lock.json)" ] || fail "the target changes dependencies"
DEVREF=$(C grep -nE '(^|[^A-Za-z_])(Tests|Faker|PHPUnit)\\|Mockery|fake\(\)' "$TARGET" -- app bootstrap config routes database/migrations | grep -v 'class_exists(' || true)
[ -z "$DEVREF" ] || fail "the target's production code references dev-only code: $DEVREF"
C cat-file -e "$TARGET:tests/Production/verify_takeover_production.php" || fail "the target does not carry the production check script"
# No untracked file may be in the target's way: the same path, a file where the target needs a
# directory, or inside a directory where the target has a file. Git would refuse such a checkout
# (this script never forces one); better to know before the window than inside it.
COLLIDE=$(untracked_paths "$UNTRACKED_BEFORE" | awk '
  NR == FNR { t[$0] = 1; n = split($0, p, "/"); d = ""; for (i = 1; i < n; i++) { d = d (i > 1 ? "/" : "") p[i]; dirs[d] = 1 } next }
  $0 == "" { next }
  ($0 in t) || ($0 in dirs) { print; next }
  { n = split($0, p, "/"); d = ""; for (i = 1; i < n; i++) { d = d (i > 1 ? "/" : "") p[i]; if (d in t) { print; next } } }' <(C ls-tree -r --name-only "$TARGET") -)
[ -z "$COLLIDE" ] || fail "an untracked file here is in the way of a path the target tracks (the checkout would refuse; nothing is overwritten): $(tr '\n' ' ' <<< "$COLLIDE")"
UNWRITABLE=0
while IFS= read -r p; do d=$DIR/$p; while [ ! -e "$d" ]; do d=$(dirname "$d"); done; OWNERDO test -w "$d" || { echo "      not writable by $OWNER: $p"; UNWRITABLE=$((UNWRITABLE + 1)); }; done < <(C diff --name-only "$FROM" "$TARGET")
[ "$UNWRITABLE" = 0 ] || fail "$UNWRITABLE changed path(s) not writable by $OWNER"
ok "candidate $TARGET: on the bundle's branch, above the floor, descends from the reviewed $REVIEWED; every runtime path equals both the reviewed commit and what staging runs"
ok "changes: $(C diff --shortstat "$FROM" "$TARGET"); one additive migration, no dependency change, no dev-only reference; every changed path writable by $OWNER"

# ── preflight 3: assets, database, public answers ────────────────────────────
[ -f "$ASSETS" ] && [ "$(sha256sum "$ASSETS" | cut -d' ' -f1)" = "$ASSETS_SHA" ] || fail "the assets tarball is missing or its SHA-256 is not $ASSETS_SHA"
[ -z "$(tar -tzf "$ASSETS" | grep -vE '^build/([A-Za-z0-9._/-]*)$' | head -1)" ] && ! tar -tzf "$ASSETS" | grep -q '\.\.' || fail "the assets tarball holds a path outside build/"
tar -tzf "$ASSETS" | grep -qx 'build/manifest.json' || fail "the assets tarball has no build/manifest.json"
[ "$(df --output=avail -m /var/www | tail -1)" -gt 2048 ] && [ "$(df --output=avail -m /root | tail -1)" -gt 2048 ] || fail "less than 2 GB free"
grep -qi "no pending migrations" <<< "$(ART migrate:status --pending 2>&1)" || fail "a migration is already pending on the deployed code"
fingerprint > "$WORK/live.before.fp" || fail "could not fingerprint the database"
# Either nothing of the migration exists, or all of it does, applied and empty: the state a permitted
# return to the baseline leaves behind. Anything in between is refused.
case "$(comm -12 <(grep '^rel ' "$WORK/live.before.fp") <(echo "$NEW_RELATIONS") | wc -l)" in
  0) MIGRATED=0 ;;
  15) [ "$(PSQL "select count(*) from migrations where migration = '$MIGRATION'")" = 1 ] && [ "$(new_rows)" = 0 ] || fail "the release's relations exist, but not as an applied migration with four empty tables"
      MIGRATED=1 ;;
  *) fail "some, not all, of the relations this release creates already exist" ;;
esac
schema_live > "$WORK/schema.full.sql"
[ -s "$WORK/schema.full.sql" ] && schema_live | cmp -s - "$WORK/schema.full.sql" || fail "two reads of the schema differ: the schema gate would be meaningless"
[ "$(answers)" = "$ANSWERS_EXPECTED" ] || fail "a public answer is not what this script expects: $(answers) (expected $ANSWERS_EXPECTED)"
[ "$(protections)" = "$PROTECTIONS_EXPECTED" ] || fail "a private storage prefix is not refused with 403: $(protections)"
[ -n "$(find "$NIGHTLY" -maxdepth 1 -name '*.zip' -mmin -1560 -size +1M 2>/dev/null | head -1)" ] || fail "no nightly backup archive from the last 26 hours in $NIGHTLY"
[ "$(php-fpm8.2 -i 2>/dev/null | awk -F' => ' '/^opcache.validate_timestamps/ { print $2 }')" = On ] || fail "opcache does not validate timestamps: this script's no-reload proof would not hold"
ok "assets tarball $ASSETS_SHA: $(tar -tzf "$ASSETS" | grep -vc '/$') files"
ok "database $DB: $(fp_summary "$WORK/live.before.fp"), $(fp_get "$WORK/live.before.fp" migrations) migrations; nothing pending; $([ "$MIGRATED" = 1 ] && echo 'the migration is already applied with four empty tables (an earlier attempt returned to the baseline)' || echo 'none of the fifteen relations exists'); schema $(wc -l < "$WORK/schema.full.sql") lines, stable across two reads"
ok "public answers as expected on $RETAIL, $WWW, $DHIRAN and the mobile API; ten private-storage probes refused; nightly archive present; opcache validates timestamps"

# The backup, and the proof that it restores. In a release the site is quiesced, so every
# table must match. In preflight the site is up: tables written while the dump ran are
# listed and excluded, every other table must match, and the dump is deleted afterwards.
backup_and_prove() {
  local strict=$1 moved='' skip
  sudo -u postgres pg_dump -Fc -d "$DB" > "$WORK/$DB.dump" && chmod 600 "$WORK/$DB.dump" || fail "pg_dump failed"
  pg_restore -f /dev/null "$WORK/$DB.dump" 2>/dev/null || fail "the backup does not read end to end"
  DUMP_SHA=$(sha256sum "$WORK/$DB.dump" | cut -d' ' -f1); DUMP_DATA=$(pg_restore -l "$WORK/$DB.dump" | grep -c 'TABLE DATA')
  fingerprint > "$WORK/live.dumped.fp" || fail "could not fingerprint the database after the dump"
  [ "$strict" = strict ] || moved=$(diff <(fp_tables "$WORK/live.before.fp") <(fp_tables "$WORK/live.dumped.fp") | awk '/^[<>] table/ { print $3 }' | sort -u | tr '\n' ' ')
  [ -n "$moved" ] || cmp -s "$WORK/live.before.fp" "$WORK/live.dumped.fp" || fail "the database changed while it was quiesced"
  isolated_restore "$WORK/$DB.dump" "$WORK/restored.fp" || fail "the backup did not restore in the isolated instance"
  [ "$(grep -c '^table ' "$WORK/restored.fp")" = "$DUMP_DATA" ] || fail "the restored instance does not hold every table of the dump"
  skip=$(tr -s ' ' '|' <<< "$moved" | sed 's/^|*//; s/|*$//'); skip=${skip:-=}
  cmp -s <(fp_tables "$WORK/live.before.fp" | grep -vE "^table ($skip) ") <(fp_tables "$WORK/restored.fp" | grep -vE "^table ($skip) ") \
    || fail "a restored table differs from the live one: $(diff <(fp_tables "$WORK/live.before.fp") <(fp_tables "$WORK/restored.fp") | awk '/^[<>] table/ { print $3 }' | sort -u | tr '\n' ' ')"
  cmp -s <(grep -E '^(rel|triggers) ' "$WORK/live.before.fp") <(grep -E '^(rel|triggers) ' "$WORK/restored.fp") || fail "the restored relations or triggers differ from the live ones"
  pg_restore -s -f - "$WORK/$DB.dump" | norm | cmp -s - "$WORK/schema.full.sql" || fail "the schema inside the backup is not the live schema"
  ok "backup: sha256 $DUMP_SHA, $DUMP_DATA table-data entries, read end to end"
  ok "restored in an isolated instance (own PostgreSQL, throwaway user, no network, no application code): $(( $(grep -c '^table ' "$WORK/restored.fp") - $(wc -w <<< "$moved") )) of $(grep -c '^table ' "$WORK/restored.fp") tables identical to live by row count and content hash${moved:+; written while the dump ran and not compared: $moved}; relations, triggers and the full schema text identical"
}

if [ "$MODE" = preflight ]; then
  backup_and_prove tolerant
  rm -f "$WORK/$DB.dump" && ok "the rehearsal dump was deleted; the fingerprints stay in $WORK"
  window_clear && ok "a window starting now would be clear of the daily scheduled jobs" || echo "note  a window starting now would overlap the daily scheduled jobs (refused between 23:40-03:10 and 05:40-06:10 India time)"
  echo "note  artisan processes of this application running now: $(artisan_running) (the queue worker is one of them)"
  echo "PREFLIGHT PASSED: nothing was changed. Evidence: $WORK"; exit 0
fi

# ── quiesce: Retail, Dhiran and the mobile API together ─────────────────────
PHASE=quiesce
window_clear || fail "the window would overlap the daily scheduled jobs (refused between 23:40-03:10 and 05:40-06:10 India time)"
LOG_MARK=$(log_mark); echo "$LOG_MARK" > "$WORK/log.mark"
SECRET=$(cat /proc/sys/kernel/random/uuid)
QUIESCED=1
ART down --retry=30 --secret="$SECRET" >/dev/null || fail "artisan down failed"
systemctl stop "$WORKER" || fail "could not stop $WORKER"
[ "$(systemctl is-active "$WORKER")" != active ] || fail "$WORKER is still active"
CRON_SHA=$(sha256sum "$CRON" | cut -d' ' -f1); CRON_STAT=$(stat -c '%U:%G %a' "$CRON")
mv "$CRON" "$HELD" || fail "could not hold $CRON"
for i in $(seq 1 120); do [ "$(artisan_running)" = 0 ] && break; sleep 5; done
[ "$(artisan_running)" = 0 ] || fail "a scheduled command of this application is still running after 10 minutes"
sleep 5   # requests already inside PHP finish
for h in "$RETAIL" "$WWW" "$DHIRAN"; do [ "$(code "$h" "https://$h/")" = 503 ] || fail "$h does not answer 503 in maintenance"; done
[ "$(code "$RETAIL" -H 'Accept: application/json' "https://$RETAIL/api/mobile/v1/sessions/me")" = 503 ] || fail "the mobile API does not answer 503 in maintenance"
WINDOW_START=$(date +%s)
SESSION_LIFETIME=$( cd "$DIR" && sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; echo (int) $c["session"]["lifetime"] * 60;' )
[ "$SESSION_LIFETIME" -gt 0 ] 2>/dev/null || fail "could not read the session lifetime from the configuration in effect"
fingerprint > "$WORK/live.before.fp" || fail "could not fingerprint the quiesced database"
sessions_now > "$WORK/sessions.before" || fail "could not record the sessions table"
schema_live > "$WORK/schema.full.sql"; schema_live "${EXCLUDE[@]}" > "$WORK/schema.before.sql"
ok "maintenance on at $(date -u +%H:%M:%SZ) for $RETAIL, $WWW, $DHIRAN and the mobile API; $WORKER stopped; scheduler cron held ($CRON_SHA, $CRON_STAT), no scheduled command running"

# ── backup: fresh, kept on this server, read end to end, restored in isolation ──
PHASE=backup
backup_and_prove strict
BACKUP=$WORK/$DB.dump
cp -p "$DIR/.env" "$WORK/env.before" && cp -p "$DIR/bootstrap/cache/config.php" "$WORK/config.cache.before" && chmod 600 "$WORK/env.before" "$WORK/config.cache.before" || fail "could not copy the configuration"
tar -C "$DIR/public" -czf "$WORK/build.before.tar.gz" build || fail "could not copy the deployed assets"
config_hashes > "$WORK/config.before.hashes" && [ -s "$WORK/config.before.hashes" ] || fail "could not record the configuration in effect"
echo "$FROM" > "$WORK/code.before"
BEFORE_FP=$WORK/live.before.fp; BEFORE_SCHEMA=$WORK/schema.before.sql; ENV_BEFORE=$WORK/env.before; CONFIG_BEFORE=$WORK/config.before.hashes; SESSIONS_BEFORE=$WORK/sessions.before
save LOG_MARK CRON_SHA CRON_STAT DUMP_SHA ENV_STAT CACHE_STAT BUILD_STAT UNTRACKED_BEFORE ROOT_OWNED_BEFORE SHARED_BEFORE OTHER_HEALTH_BEFORE MIGRATED WINDOW_START SESSION_LIFETIME
ok "pre-release copies in $WORK (root only): code $FROM, .env, config cache, public/build, database dump"

# ── release: code, assets, configuration, the one migration ─────────────────
PHASE=checkout
BTMP=$(OWNERDO mktemp -d) && install -o "$OWNER" -g "$OWNER" -m 600 "$BUNDLE" "$BTMP/candidate.bundle" || fail "could not hand the bundle to $OWNER"
G fetch --quiet "$BTMP/candidate.bundle" "+refs/heads/$BRANCH:$REF"; FETCHED=$?; rm -rf "$BTMP"
[ "$FETCHED" = 0 ] && [ "$(G rev-parse "$REF")" = "$TARGET" ] || fail "fetch from the bundle into the production repository failed"
G checkout --quiet --detach "$TARGET" || fail "checkout failed"
[ "$(G rev-parse HEAD)" = "$TARGET" ] && [ -z "$(G status --porcelain --untracked-files=no)" ] || fail "checkout did not land cleanly"
[ "$(untracked)" = "$UNTRACKED_BEFORE" ] || fail "an untracked file was added, removed or changed by the checkout: $(untracked_differ)"
OWNERDO env COMPOSER_ALLOW_SUPERUSER=1 composer -d "$DIR" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --quiet || fail "composer install failed"
ART package:discover >/dev/null || fail "package:discover failed"
PHASE=assets
OWNERDO rm -rf "$DIR/public/build.incoming" && OWNERDO mkdir "$DIR/public/build.incoming" && OWNERDO tar -xzmf - -C "$DIR/public/build.incoming" < "$ASSETS" || fail "could not unpack the assets"
mv "$DIR/public/build" "$WORK/build.replaced" && OWNERDO mv "$DIR/public/build.incoming/build" "$DIR/public/build" && OWNERDO rmdir "$DIR/public/build.incoming" || fail "could not put the assets in place"
[ "$(stat -c '%U:%G' "$DIR/public/build")" = "$BUILD_STAT" ] || fail "public/build is not owned as before ($BUILD_STAT)"
MANIFEST_SHA=$(sha256sum "$DIR/public/build/manifest.json" | cut -d' ' -f1)
save MANIFEST_SHA
ok "checked out $TARGET as $OWNER; assets in place (manifest $MANIFEST_SHA)"
configure
PHASE=migrate
if [ "$MIGRATED" = 1 ]; then
  ok "the migration was applied by an earlier attempt and its four tables are empty: it is not run again"
else
  P=$(ART migrate --pretend --force --path="database/migrations/$MIGRATION.php" 2>&1); echo "$P" > "$WORK/pretend-$MIGRATION.sql"
  [ "$(grep -ci 'create table' <<< "$P")" = 4 ] || fail "the migration does not create exactly four tables (see $WORK/pretend-$MIGRATION.sql)"
  ! grep -qiE 'drop |truncate |delete from|update ' <<< "$P" || fail "the migration holds a destructive statement"
  [ -z "$(grep -oiE 'alter table "[a-z_]+"' <<< "$P" | grep -viE "\"($(IFS='|'; echo "${NEW_TABLES[*]}"))\"" | head -1)" ] || fail "the migration alters an existing table"
  ART migrate --force --path="database/migrations/$MIGRATION.php" > "$WORK/migrate.out" 2>&1 || fail "migration failed (see $WORK/migrate.out)"
fi
verify_migrated
finish
