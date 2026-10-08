#!/usr/bin/env bash
# ============================================================================
# Brings a deployed tree to a later commit that differs from it ONLY in
# documentation, tests and operator tooling. Runs ON the VPS as root:
#
#   sync-release-docs.sh <staging|production> <from-sha (deployed now)> <target-sha> <bundle> <branch-in-bundle>
#
# No maintenance, no dependency install, no cache rebuild, no PHP reload, no
# worker restart, no migration: nothing the application runs changes, and the
# script proves that before it touches anything. If a single path outside
# docs/ and tests/ differs, it refuses: that is a release, and goes through
# deploy-stabilization.sh.
#
# Afterwards it checks that the things a release would have changed did NOT
# change: the built assets, the config cache, .env, the worker's process, the
# time PHP-FPM last started or reloaded, and the answers of every host.
# ============================================================================
set -u -o pipefail

ENVN=${1:?usage: env from-sha target-sha bundle branch}; FROM=${2:?from-sha}; TARGET=${3:?target-sha}; BUNDLE=${4:?bundle}; BRANCH=${5:?branch in the bundle}
case "$ENVN" in
  staging)    DIR=/var/www/jewelflow-staging; OWNER=root; HOSTS=(staging.jewelflows.com); WORKER=jewelflow-staging-ops-alerts ;;
  production) DIR=/var/www/jewelflow;         OWNER=dev;  HOSTS=(jewelflows.com www.jewelflows.com dhiran.jewelflows.com); WORKER=jewelflow-production-ops-alerts ;;
  *) echo "unknown environment: $ENVN"; exit 64 ;;
esac
STAMP=$(date -u +%Y%m%dT%H%M%SZ); WORK=/root/stabilization/$ENVN-sync-$STAMP
mkdir -p "$WORK" && chmod 700 /root/stabilization "$WORK"
exec > >(tee -a "$WORK/run.log") 2>&1
TEE=$!; trap 'exec >&- 2>&-; wait "$TEE"' EXIT
umask 022

G() { if [ "$OWNER" = root ]; then git -C "$DIR" "$@"; else sudo -u "$OWNER" env HOME="$(getent passwd "$OWNER" | cut -d: -f6)" git -C "$DIR" "$@"; fi; }
OWNERDO() { if [ "$OWNER" = root ]; then "$@"; else sudo -u "$OWNER" env HOME="$(getent passwd "$OWNER" | cut -d: -f6)" "$@"; fi; }
code() { local h=$1; shift; curl -sk -o /dev/null -w '%{http_code}' -m 20 --resolve "$h:443:127.0.0.1" "$@"; }
untracked() { G ls-files --others --exclude-standard -z | ( cd "$DIR" && xargs -0 -r sha256sum -- ) | sort -k2 | sha256sum | cut -d' ' -f1; }
runtime_tree() { G ls-tree -r "$1" | grep -v -P '\t(docs|tests)/' | sha256sum | cut -d' ' -f1; }
# Everything a release changes and this must not.
untouched() {
  printf '%s %s %s %s %s %s' "$(sha256sum < "$DIR/.env" | cut -c1-16)" "$(sha256sum < "$DIR/bootstrap/cache/config.php" | cut -c1-16)" \
    "$(find "$DIR/public/build" -type f -exec sha256sum {} + | sort -k2 | sha256sum | cut -c1-16)" \
    "$(systemctl show -p MainPID --value "$WORKER")" "$(systemctl show -p ActiveEnterTimestampMonotonic --value php8.2-fpm)" \
    "$(journalctl -u php8.2-fpm --since today --no-pager 2>/dev/null | grep -ci reload)"
}
errors() { cat "$DIR"/storage/logs/laravel*.log 2>/dev/null | grep -cE '\.(ERROR|CRITICAL|EMERGENCY|ALERT):'; }
ok() { echo "ok    $*"; }
CHANGED=0
fail() { echo "!!!!! REFUSED: $*"; [ "$CHANGED" = 0 ] && echo "Nothing was changed." || echo "The checkout HAD moved to $TARGET. Nothing the application runs differs from $FROM. Inspect: $WORK."; exit 2; }
echo "########## documentation and tooling sync: $ENVN $FROM -> $TARGET at $STAMP (UTC); script $(sha256sum "$0" | cut -d' ' -f1) ##########"

[ "$(id -u)" = 0 ] || fail "run as root"
cd "$DIR" || fail "no directory $DIR"
[ "$(G rev-parse HEAD)" = "$FROM" ] || fail "HEAD is $(G rev-parse HEAD), expected the deployed $FROM"
[ -z "$(G status --porcelain --untracked-files=no)" ] || fail "tracked tree is dirty"
[ ! -f storage/framework/down ] || fail "the site is in maintenance: a release is in progress or was stopped"
[ -f "$BUNDLE" ] && git bundle verify "$BUNDLE" >/dev/null 2>&1 || fail "the bundle is missing or does not verify"
[ "$(git bundle list-heads "$BUNDLE" "refs/heads/$BRANCH" | cut -d' ' -f1)" = "$TARGET" ] || fail "the bundle's $BRANCH is not $TARGET"
BTMP=$(OWNERDO mktemp -d) && install -o "$OWNER" -g "$OWNER" -m 600 "$BUNDLE" "$BTMP/sync.bundle" || fail "could not hand the bundle to $OWNER"
G fetch --quiet "$BTMP/sync.bundle" "+refs/heads/$BRANCH:refs/remotes/release/$BRANCH"; FETCHED=$?; rm -rf "$BTMP"
[ "$FETCHED" = 0 ] && [ "$(G rev-parse "refs/remotes/release/$BRANCH")" = "$TARGET" ] || fail "fetch from the bundle failed"
G merge-base --is-ancestor "$FROM" "$TARGET" || fail "target does not descend from the deployed commit"
OUTSIDE=$(G diff --name-only "$FROM" "$TARGET" | grep -v -E '^(docs|tests)/' | head -5 | tr '\n' ' ')
[ -z "$OUTSIDE" ] || fail "the target changes paths outside docs/ and tests/ ($OUTSIDE): that is a release, not a sync"
[ "$(runtime_tree "$FROM")" = "$(runtime_tree "$TARGET")" ] || fail "the trees differ outside docs/ and tests/"
N=$(G diff --name-only "$FROM" "$TARGET" | wc -l)
[ "$N" -gt 0 ] || fail "nothing differs"
for h in "${HOSTS[@]}"; do [ "$(code "$h" "https://$h/health")" = 200 ] || fail "$h /health is not 200 before the sync"; done
UNWRITABLE=0
while IFS= read -r f; do p="$DIR/$f"; while [ ! -e "$p" ]; do p=$(dirname "$p"); done; OWNERDO test -w "$p" || UNWRITABLE=$((UNWRITABLE + 1)); done < <(G diff --name-only "$FROM" "$TARGET")
[ "$UNWRITABLE" = 0 ] || fail "$UNWRITABLE changed path(s) not writable by $OWNER"
UNTRACKED_BEFORE=$(untracked); UNTOUCHED_BEFORE=$(untouched); ERRORS_BEFORE=$(errors)
ok "target $TARGET descends from $FROM; $N path(s) differ, all under docs/ or tests/; everything else is the same tree ($(runtime_tree "$TARGET" | cut -c1-16))"

G checkout --quiet --detach "$TARGET" || fail "checkout failed"
CHANGED=1
[ "$(G rev-parse HEAD)" = "$TARGET" ] && [ -z "$(G status --porcelain --untracked-files=no)" ] || fail "checkout did not land cleanly"
[ "$(untracked)" = "$UNTRACKED_BEFORE" ] || fail "an untracked file was added, removed or changed"
[ "$(untouched)" = "$UNTOUCHED_BEFORE" ] || fail ".env, the config cache, the built assets, the worker's process or PHP-FPM changed"
[ ! -f storage/framework/down ] || fail "the site went into maintenance"
for h in "${HOSTS[@]}"; do
  [ "$(code "$h" "https://$h/health")" = 200 ] && [ "$(code "$h" "https://$h/login")" = 200 ] || fail "$h /health or /login not 200 after the sync"
done
[ "$(errors)" = "$ERRORS_BEFORE" ] || fail "a new error line appeared in storage/logs"
ok "checked out $TARGET as $OWNER; .env, config cache, built assets, worker process and PHP-FPM exactly as before; no maintenance; ${HOSTS[*]} answer 200; no new error"
echo "SYNC PASSED: $ENVN at $TARGET (from $FROM); evidence in $WORK"
