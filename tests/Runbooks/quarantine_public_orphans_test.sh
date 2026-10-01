#!/usr/bin/env bash
# Checks for docs/runbooks/quarantine-public-orphans.sh. Local only: a sandbox
# web root on this machine; the database, nginx and Cloudflare are stubs that
# answer from the sandbox's own files. Nothing here touches a server.
#
#   bash tests/Runbooks/quarantine_public_orphans_test.sh     exit 0 = every check passed
set -u -o pipefail
cd "$(dirname "$0")/../.." || exit 2
SCRIPT=docs/runbooks/quarantine-public-orphans.sh
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
PASS=0; FAILN=0
check() { if eval "$2"; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAILN=$((FAILN + 1)); echo "FAIL  $1"; fi; }

# setup <commands>: a fresh sandbox, then the commands run with the script's functions and the stubs.
setup() {
  S=$(mktemp -d "$T/sb.XXXX"); P=$S/prod/storage/app/public
  mkdir -p "$P/karigar-invoices/7" "$P/purchases" "$P/shop-logos" "$S/prod/storage/app/private"
  echo "karigar pdf" > "$P/karigar-invoices/7/aaa.pdf"; echo "purchase one" > "$P/purchases/bbb.pdf"; echo "purchase two" > "$P/purchases/ccc.pdf"
  echo "a purchase a row still names" > "$P/purchases/live.pdf"; echo logo > "$P/shop-logos/logo.png"
  echo "purchases/live.pdf" > "$S/referenced"
  (
    QUARANTINE_LIB=1 source "$SCRIPT"
    PROD=$S/prod WEB=$(id -un) STAMP=TEST
    chown() { :; }                      # the sandbox is not owned by www-data
    install() { command mkdir -p "${@: -1}"; }
    refs() { command grep -cxF -- "$2" "$S/referenced" || true; }
    serve() { local path=${1%%\?*}   # what nginx does with the sandbox web root
      case "$path" in
        /login) echo 200 ;;
        /storage/kyc/*|/storage/signatures/*) [ -n "${DENY_LOST:-}" ] && echo 404 || echo 403 ;;
        /storage/*) [ -f "$P/${path#/storage/}" ] && echo 200 || echo 404 ;;
        *) echo 404 ;;
      esac; }
    origin() { serve "$2"; }
    edge() { if [ -n "${EDGE_STALE:-}" ] && [ "$2" = "/storage/$EDGE_STALE" ]; then echo 200; else serve "$2"; fi; }   # one cache key still held at the edge
    logged() { echo "      1 16/Sep/2026 other 404"; }
    eval "$1"
  ) > "$S/out" 2>&1 < /dev/null
  RC=$?
}
out_has() { grep -qF -- "$1" "$S/out"; }
Q() { echo "$S/prod/storage/app/private/quarantine/public-orphans"; }

setup plan
check "plan: three orphans would move, the file a row names stays; nothing is changed" \
  '[ "$RC" = 0 ] && out_has "3 orphan file(s) would move" && out_has "1 file(s) named by a row stay" && [ "$(find "$P" -type f | wc -l)" = 5 ] && [ ! -e "$(Q)" ]'
setup 'B=$(sha256sum "$P/purchases/bbb.pdf" | cut -c1-64); move; echo "sha-before=$B"'
check "move: the three orphans are in the quarantine at the same relative paths, byte for byte" \
  '[ "$RC" = 0 ] && out_has "MOVED 3 file(s)" && [ -f "$(Q)/karigar-invoices/7/aaa.pdf" ] && [ -f "$(Q)/purchases/ccc.pdf" ] && out_has "sha-before=$(sha256sum "$(Q)/purchases/bbb.pdf" | cut -c1-64)"'
check "move: they are gone from the web root; the file a row names and the public logo are untouched" \
  '[ ! -e "$P/purchases/bbb.pdf" ] && [ ! -e "$P/karigar-invoices/7/aaa.pdf" ] && [ -f "$P/purchases/live.pdf" ] && [ -f "$P/shop-logos/logo.png" ] && out_has "left in place (named by 1 row(s))"'
check "move: nothing was deleted (5 files before, 5 after, plus the manifest with 3 entries)" \
  '[ "$(find "$S/prod" -type f ! -name MANIFEST.tsv | wc -l)" = 5 ] && [ "$(wc -l < "$(Q)/MANIFEST.tsv")" = 4 ]'
setup 'move; verify'
check "verify after a move: copies intact, 404 on every host for the plain and the query-string URL, controls hold (exit 0)" \
  '[ "$RC" = 0 ] && out_has "3 quarantined file(s): copy intact" && [ "$(grep -c "controls: /login 200, a public catalogue file 200" "$S/out")" = 3 ]'
setup 'move; move'
check "move twice: the second run moves nothing and the manifest keeps its 3 entries" '[ "$RC" = 0 ] && out_has "MOVED 0 file(s)" && [ "$(wc -l < "$(Q)/MANIFEST.tsv")" = 4 ]'
setup 'move; echo x >> "$(dest)/purchases/bbb.pdf"; verify'
check "verify: a quarantined copy that changed is a failure" '[ "$RC" = 1 ] && out_has "missing or changed"'
setup 'move; cp "$(dest)/purchases/bbb.pdf" "$P/purchases/bbb.pdf"; verify'
check "verify: an original back in the web root is a failure" '[ "$RC" = 1 ] && out_has "still under the web root"'
setup 'move; EDGE_STALE=purchases/ccc.pdf; verify'
check "verify: a copy the edge still serves is a failure (the origin's 404 is not enough)" '[ "$RC" = 1 ] && out_has "through the edge: 200 404"'
setup 'move; DENY_LOST=1; verify'
check "verify: a lost KYC or signature deny shows in the controls (failure)" '[ "$RC" = 1 ] && out_has "controls: login=200 public=200 kyc=404"'
setup 'echo x > "$P/purchases/a b.pdf"; move'
check "a file name with a space stops the run before anything moves" '[ "$RC" = 1 ] && out_has "characters this script will not put in a query" && [ -f "$P/purchases/bbb.pdf" ]'
setup 'move; echo again > "$P/purchases/bbb.pdf"; move'
check "a new orphan with the name of one already quarantined is not moved over it" '[ "$RC" = 1 ] && out_has "already holds a file at that path" && [ "$(cat "$(Q)/purchases/bbb.pdf")" = "purchase one" ]'

echo "== $PASS passed, $FAILN failed"
[ "$FAILN" = 0 ]
