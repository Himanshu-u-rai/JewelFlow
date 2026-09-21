#!/usr/bin/env bash
#
# S3-07b — REAL concurrency harness for the mobile invoice payment route.
#
# WHAT THIS IS, AND WHY IT IS NOT THE PHPUNIT SUITE
# -------------------------------------------------
# The PHPUnit tests cannot schedule a genuine race: `RefreshDatabase` wraps each
# test body in a single uncommitted transaction, so a second connection cannot
# see the fixtures the test just created. That is a limitation of the SUITE, not
# proof the race is untestable — so this runs it for real:
#
#   * fixtures are COMMITTED by `security:payment-race seed`;
#   * the app is served by `php artisan serve` with PHP_CLI_SERVER_WORKERS, so
#     concurrent requests are handled by SEPARATE OS PROCESSES;
#   * each request is fired by its own backgrounded subshell + curl process;
#   * all workers busy-wait on a shared nanosecond deadline, so they are
#     released together rather than merely launched together;
#   * every worker records its own start/end timestamps, and the runner PRINTS
#     the measured overlap instead of assuming it.
#
# PHP_CLI_SERVER_WORKERS IS ONLY HONOURED WITH --no-reload. Verified in
# vendor/.../Foundation/Console/ServeCommand.php:100-114, which otherwise warns
# and silently falls back to a SINGLE server process. A single-threaded server
# would serialize the requests and every scenario below would pass for the wrong
# reason — the race would never happen. This is the one setting that decides
# whether this harness measures anything at all.
#
# SAFETY. `security:payment-race` refuses to run unless the connected database
# is named `jewelflow_testing`, and cleanup deletes only rows carrying this
# harness's invoice-number marker. Unrelated data is left alone.

set -uo pipefail

PORT="${RACE_PORT:-8123}"
BASE="http://127.0.0.1:${PORT}"
WORKDIR="$(mktemp -d /tmp/jf-race-XXXXXX)"
SERVE_LOG="${WORKDIR}/serve.log"
FAILURES=0
SCENARIOS=0

cd "$(dirname "$0")/../.." || exit 1

cleanup() {
    [ -n "${SERVE_PID:-}" ] && kill "${SERVE_PID}" 2>/dev/null
    php artisan security:payment-race cleanup >/dev/null 2>&1
}
trap cleanup EXIT

# ---------------------------------------------------------------- server

PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload --port="${PORT}" \
    > "${SERVE_LOG}" 2>&1 &
SERVE_PID=$!

# REMOVE THE SERVER FROM THE JOB TABLE, OR THE SCENARIOS HANG FOREVER.
# Each scenario ends with a bare `wait`, which waits for EVERY job in the
# shell — and `php artisan serve` never exits. The first run of this harness
# completed R-C1's two requests correctly and then blocked on `wait` for 10
# minutes until it was killed. `disown` drops the job from the table while
# leaving ${SERVE_PID} valid for the cleanup `kill`.
disown "${SERVE_PID}" 2>/dev/null || true

for _ in $(seq 1 40); do
    curl -s -o /dev/null "${BASE}/up" && break
    sleep 0.25
done

if grep -q 'Unable to respect' "${SERVE_LOG}"; then
    echo "FATAL: server fell back to a single process — the race cannot occur." >&2
    exit 2
fi
echo "server up on ${PORT} (PHP_CLI_SERVER_WORKERS=4, --no-reload)"
echo

# ---------------------------------------------------------------- helpers

# One request, in its own process, released on a shared deadline.
worker() {
    local idx="$1" invoice="$2" token="$3" key="$4" payload="$5" startns="$6"

    # Spin, do not sleep. `sleep` granularity is coarse enough to serialize the
    # very thing being measured.
    while [ "$(date +%s%N)" -lt "${startns}" ]; do :; done

    local t0 t1 code
    t0="$(date +%s%N)"
    code="$(curl -s -o "${WORKDIR}/body.${idx}" -w '%{http_code}' \
        -X POST "${BASE}/api/mobile/invoices/${invoice}/payments" \
        -H "Authorization: Bearer ${token}" \
        -H 'Content-Type: application/json' \
        -H 'Accept: application/json' \
        -H "X-Idempotency-Key: ${key}" \
        --data "${payload}")"
    t1="$(date +%s%N)"

    # Trailing newline is REQUIRED. Without it `cat status.*` concatenates
    # "200" and "201" into "200201" and `sort` sees one line, so the status
    # check failed on every scenario of the first working run while the
    # underlying statuses were fine.
    printf '%s\n' "${code}" > "${WORKDIR}/status.${idx}"
    printf '%s %s' "${t0}" "${t1}" > "${WORKDIR}/time.${idx}"
}

seed() {
    php artisan security:payment-race seed --total="$1" --users="${2:-2}" 2>/dev/null | tail -1
}

report() {
    php artisan security:payment-race report --invoice="$1" 2>/dev/null | tail -1
}

jget() { php -r '$j=json_decode(file_get_contents("php://stdin"),true); $k=$argv[1]; echo is_array($j[$k]??null)?json_encode($j[$k]):($j[$k]??"");' "$1"; }

check() {
    local label="$1" expected="$2" actual="$3"
    if [ "${expected}" = "${actual}" ]; then
        echo "    ok   ${label}: ${actual}"
    else
        echo "    FAIL ${label}: expected ${expected}, got ${actual}"
        FAILURES=$((FAILURES + 1))
    fi
}

# Print the measured overlap so concurrency is evidenced, not asserted.
show_overlap() {
    php -r '
        $files = array_slice($argv, 1);
        $iv = [];
        foreach ($files as $f) {
            [$a, $b] = explode(" ", trim(file_get_contents($f)));
            $iv[] = [(float)$a, (float)$b];
        }
        $latestStart = max(array_column($iv, 0));
        $earliestEnd = min(array_column($iv, 1));
        $overlapMs = ($earliestEnd - $latestStart) / 1e6;
        foreach ($iv as $i => $p) {
            printf("    w%d  %.3f ms wide\n", $i, ($p[1]-$p[0])/1e6);
        }
        printf("    OVERLAP: %.3f ms %s\n", $overlapMs,
            $overlapMs > 0 ? "(requests were genuinely in flight together)"
                           : "(NO OVERLAP — treat results as serialized)");
    ' "$@"
}

# Per-worker status AND body. Counts alone cannot tell a replay apart from a
# fresh creation, nor say WHY a request was refused — and the first working run
# produced a 422 in R-C5 that no count would have explained.
dump_bodies() {
    local f idx
    for f in "${WORKDIR}"/status.*; do
        idx="${f##*.}"
        printf '    w%s  HTTP %s  %s\n' "${idx}" "$(cat "${f}")" \
            "$(head -c 260 "${WORKDIR}/body.${idx}")"
    done
}

statuses_sorted() {
    cat "${WORKDIR}"/status.* | sort | tr '\n' ',' | sed 's/,$//'
}

# ---------------------------------------------------------------- scenarios

scenario() {
    SCENARIOS=$((SCENARIOS + 1))
    echo "── $1"
    rm -f "${WORKDIR}"/status.* "${WORKDIR}"/time.* "${WORKDIR}"/body.*
}

deadline() { echo $(( $(date +%s%N) + 1500000000 )); }   # now + 1.5s

# R-C1 — same invoice, same key, same payload, two processes.
scenario "R-C1  same invoice/key/payload, 2 concurrent processes"
SEED="$(seed 10000 2)"
INV="$(printf '%s' "${SEED}" | jget invoice_id)"
T0="$(printf '%s' "${SEED}" | php -r '$j=json_decode(file_get_contents("php://stdin"),true);echo $j["tokens"][0];')"
D="$(deadline)"
worker 0 "${INV}" "${T0}" 'rc1-key' '{"mode":"cash","amount":3000}' "${D}" &
worker 1 "${INV}" "${T0}" 'rc1-key' '{"mode":"cash","amount":3000}' "${D}" &
wait
show_overlap "${WORKDIR}"/time.*
dump_bodies
REP="$(report "${INV}")"
check "payment_count" 1 "$(printf '%s' "${REP}" | jget payment_count)"
check "paid_total"    3000 "$(printf '%s' "${REP}" | jget paid_total)"
check "outstanding"   7000 "$(printf '%s' "${REP}" | jget outstanding)"
check "claim_count"   1 "$(printf '%s' "${REP}" | jget claim_count)"
check "cash_txn_count" 1 "$(printf '%s' "${REP}" | jget cash_txn_count)"
check "audit_count"   1 "$(printf '%s' "${REP}" | jget audit_count)"
check "statuses"      "200,201" "$(statuses_sorted)"
echo

# R-C2 — same invoice and key, two DIFFERENT authorized users.
scenario "R-C2  same invoice/key, two different authorized users"
SEED="$(seed 10000 2)"
INV="$(printf '%s' "${SEED}" | jget invoice_id)"
TA="$(printf '%s' "${SEED}" | php -r '$j=json_decode(file_get_contents("php://stdin"),true);echo $j["tokens"][0];')"
TB="$(printf '%s' "${SEED}" | php -r '$j=json_decode(file_get_contents("php://stdin"),true);echo $j["tokens"][1];')"
D="$(deadline)"
worker 0 "${INV}" "${TA}" 'rc2-key' '{"mode":"cash","amount":2500}' "${D}" &
worker 1 "${INV}" "${TB}" 'rc2-key' '{"mode":"cash","amount":2500}' "${D}" &
wait
show_overlap "${WORKDIR}"/time.*
dump_bodies
REP="$(report "${INV}")"
check "payment_count" 1 "$(printf '%s' "${REP}" | jget payment_count)"
check "paid_total"    2500 "$(printf '%s' "${REP}" | jget paid_total)"
check "claim_count"   1 "$(printf '%s' "${REP}" | jget claim_count)"
check "statuses"      "200,201" "$(statuses_sorted)"
echo

# R-C3 — same invoice and key, DIFFERENT payloads.
scenario "R-C3  same invoice/key, different payloads → one accepted, one conflict"
SEED="$(seed 10000 2)"
INV="$(printf '%s' "${SEED}" | jget invoice_id)"
T0="$(printf '%s' "${SEED}" | php -r '$j=json_decode(file_get_contents("php://stdin"),true);echo $j["tokens"][0];')"
D="$(deadline)"
worker 0 "${INV}" "${T0}" 'rc3-key' '{"mode":"cash","amount":3000}' "${D}" &
worker 1 "${INV}" "${T0}" 'rc3-key' '{"mode":"cash","amount":4500}' "${D}" &
wait
show_overlap "${WORKDIR}"/time.*
dump_bodies
REP="$(report "${INV}")"
check "payment_count" 1 "$(printf '%s' "${REP}" | jget payment_count)"
check "claim_count"   1 "$(printf '%s' "${REP}" | jget claim_count)"
check "statuses"      "201,409" "$(statuses_sorted)"
echo

# R-C4 — DISTINCT keys must remain independent payments.
scenario "R-C4  distinct keys, concurrent → two independent payments"
SEED="$(seed 10000 2)"
INV="$(printf '%s' "${SEED}" | jget invoice_id)"
T0="$(printf '%s' "${SEED}" | php -r '$j=json_decode(file_get_contents("php://stdin"),true);echo $j["tokens"][0];')"
D="$(deadline)"
worker 0 "${INV}" "${T0}" 'rc4-key-a' '{"mode":"cash","amount":3000}' "${D}" &
worker 1 "${INV}" "${T0}" 'rc4-key-b' '{"mode":"cash","amount":4500}' "${D}" &
wait
show_overlap "${WORKDIR}"/time.*
dump_bodies
REP="$(report "${INV}")"
check "payment_count" 2 "$(printf '%s' "${REP}" | jget payment_count)"
check "paid_total"    7500 "$(printf '%s' "${REP}" | jget paid_total)"
check "outstanding"   2500 "$(printf '%s' "${REP}" | jget outstanding)"
check "claim_count"   2 "$(printf '%s' "${REP}" | jget claim_count)"
check "statuses"      "201,201" "$(statuses_sorted)"
echo

# R-C5 — FULL settlement under the same key, concurrently.
scenario "R-C5  full settlement, same key, 3 concurrent processes"
SEED="$(seed 10000 2)"
INV="$(printf '%s' "${SEED}" | jget invoice_id)"
T0="$(printf '%s' "${SEED}" | php -r '$j=json_decode(file_get_contents("php://stdin"),true);echo $j["tokens"][0];')"
D="$(deadline)"
worker 0 "${INV}" "${T0}" 'rc5-key' '{"mode":"cash","amount":10000}' "${D}" &
worker 1 "${INV}" "${T0}" 'rc5-key' '{"mode":"cash","amount":10000}' "${D}" &
worker 2 "${INV}" "${T0}" 'rc5-key' '{"mode":"cash","amount":10000}' "${D}" &
wait
show_overlap "${WORKDIR}"/time.*
dump_bodies
REP="$(report "${INV}")"
check "payment_count" 1 "$(printf '%s' "${REP}" | jget payment_count)"
check "paid_total"    10000 "$(printf '%s' "${REP}" | jget paid_total)"
check "outstanding"   0 "$(printf '%s' "${REP}" | jget outstanding)"
check "claim_count"   1 "$(printf '%s' "${REP}" | jget claim_count)"
check "statuses"      "200,200,201" "$(statuses_sorted)"
echo

# ---------------------------------------------------------------- result

echo "scenarios: ${SCENARIOS}   failed checks: ${FAILURES}"
[ "${FAILURES}" -eq 0 ] || exit 1
