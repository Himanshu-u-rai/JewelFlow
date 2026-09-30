#!/usr/bin/env bash
# Checks for tests/Staging/cookie_isolation_probe.sh. Local only: the probe runs
# against a fake curl (first on PATH) that answers every request with one status
# and, optionally, one fixed CSRF token. No network.
#
#   bash tests/Staging/cookie_isolation_probe_test.sh [probe]    exit 0 = every check passed
set -u
P=${1:-$(dirname "$0")/cookie_isolation_probe.sh}
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
cat > "$T/curl" <<'SH'
#!/usr/bin/env bash
while [ $# -gt 0 ]; do [ "$1" = -D ] && { printf 'HTTP/2 %s\r\n\r\n' "$FAKE_STATUS" > "$2"; shift; }; shift; done
[ -n "$FAKE_TOKEN" ] && printf '<meta name="csrf-token" content="%s">\n' "$FAKE_TOKEN"
exit 0
SH
chmod +x "$T/curl"
PASS=0; FAILN=0
check() { if eval "$2"; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAILN=$((FAILN + 1)); echo "FAIL  $1"; fi; }
run() { FAKE_STATUS=$1 FAKE_TOKEN=$2 PATH="$T:$PATH" bash "$P" > "$T/out" 2>&1; RC=$?; }

run 503 ''
check "503 pages without a token: exit 1, no survival claimed" '[ "$RC" = 1 ] && ! grep -qE "SURVIVED|one session" "$T/out"'
run 503 fixed-token
check "503 pages repeating one token: exit 1, no survival claimed" '[ "$RC" = 1 ] && ! grep -qE "SURVIVED|one session" "$T/out"'
run 200 fixed-token
check "200 pages repeating one token: the survival check still reads them" 'grep -q "SURVIVED" "$T/out"'

echo "== $PASS passed, $FAILN failed"
[ "$FAILN" = 0 ]
