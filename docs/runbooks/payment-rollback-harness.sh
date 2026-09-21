#!/usr/bin/env bash
#
# S3-07b — ROLLBACK AND MIXED-VERSION harness.
#
# THE QUESTION THIS ANSWERS
# -------------------------
# "Does the old application still boot?" is not the question. The question is
# whether the PREVIOUS, cache-only controller — running against a database that
# already carries durable claims — can record a SECOND payment for a key the new
# code already served. That is a behavioural question about money, and it is
# answered here by recording payments, not by reasoning about deploy order.
#
# WHICH "PREVIOUS" REVISION, RESOLVED FROM GIT RATHER THAN ASSUMED
# ----------------------------------------------------------------
# `git log -- app/Http/Controllers/Api/Mobile/InvoiceController.php` gives three
# candidates, and they are NOT interchangeable:
#
#   70b7116  the revision in the reported DEPLOYED baseline 018b3d8. Cache-only,
#            AND missing the S3-07 authorization guard on the cache-hit path.
#            `git merge-base --is-ancestor` confirms this one is in 018b3d8 and
#            that 0296431 / b677cf6 / 2dd0875 are NOT.
#   0296431  cache-only PLUS the S3-07 guard. On the audit branch only. This is
#            what reverting just the S3-07b commits would reach — it has never
#            been deployed.
#   2dd0875  first durable-claim revision.
#
# A real rollback goes to what is deployed, so this harness drives 70b7116. It
# is checked out as a separate git worktree with its OWN vendor/ and .env, so it
# is a genuinely separate application serving on its own port.
#
# DO NOT SYMLINK vendor/. THE FIRST RUN OF THIS HARNESS REPORTED 5/5 PASS AND
# EVERY ONE OF THOSE PASSES WAS FALSE.
# ---------------------------------------------------------------------------
# vendor/ was originally symlinked into the baseline checkout to save 120 MB.
# Composer derives its PSR-4 base from `$vendorDir = dirname(__DIR__)` inside
# vendor/composer/autoload_psr4.php, and PHP resolves __DIR__ to the REAL path —
# so $baseDir became the NEW tree and `App\` loaded from the NEW app/ directory.
# The "baseline" server was executing HEAD's controller. It answered 200 to
# every retry, never recorded a second payment, and the harness scored that as
# "rollback is safe".
#
# What exposed it: that 200 carried `X-Idempotent-Replay: true`, a header only
# the NEW controller emits. Hence the version probe below. The harness now
# refuses to report anything until it has positively shown that the OLD port is
# running old code, using a probe demonstrated in the same run to be capable of
# detecting new code on the NEW port.
#
# Use `cp -rl` (hardlink copy): same disk cost as a symlink, real directory.
#
# THE FILE CACHE IS SHARED BETWEEN THE TWO CHECKOUTS ON PURPOSE.
# Each Laravel tree has its own storage/framework/cache/data, so leaving them
# separate would hand the old code a guaranteed cache miss and manufacture the
# very result being tested. The setup symlinks the old tree's cache directory at
# the new tree's, which is what a shared production cache looks like. Cache
# absence is then produced EXPLICITLY, by Cache::forget, at the point the
# scenario calls for it.
#
# SCHEMA GOES FORWARD, CODE GOES BACK. The database keeps the
# `invoice_payment_claims` table throughout — the claims are deliberately NOT
# dropped, because retries can still arrive for them. That is exactly the state
# a rollback leaves behind.
#
# SAFETY. Fixtures and reporting go through `security:payment-race`, which
# refuses to run against any database not named `jewelflow_testing`, and whose
# cleanup deletes only rows carrying its own invoice-number marker.

set -uo pipefail

NEW_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
OLD_DIR="${OLD_CTL_DIR:-/tmp/jf-oldctl}"
NEW_PORT="${NEW_PORT:-8123}"
OLD_PORT="${OLD_PORT:-8124}"
NEW="http://127.0.0.1:${NEW_PORT}"
OLD="http://127.0.0.1:${OLD_PORT}"
WORKDIR="$(mktemp -d /tmp/jf-rollback-XXXXXX)"
FAILURES=0

if [ ! -f "${OLD_DIR}/artisan" ]; then
    cat >&2 <<EOF
FATAL: no baseline checkout at ${OLD_DIR}.

Create it (from ${NEW_DIR}):

    git worktree add --detach ${OLD_DIR} 70b7116
    cd ${OLD_DIR}
    cp -rl ${NEW_DIR}/vendor vendor          # hardlink copy, NOT a symlink
    cp ${NEW_DIR}/.env .env
    rm -rf storage/framework/cache/data
    ln -s ${NEW_DIR}/storage/framework/cache/data storage/framework/cache/data
EOF
    exit 2
fi

# The exact trap that produced the false pass. Cheap to check, so check it.
if [ -L "${OLD_DIR}/vendor" ]; then
    echo "FATAL: ${OLD_DIR}/vendor is a symlink — the baseline server would load" >&2
    echo "       ${NEW_DIR}/app via composer's resolved \$baseDir. See the header." >&2
    exit 2
fi

OLD_REV="$(git -C "${OLD_DIR}" rev-parse --short=7 HEAD 2>/dev/null)"
if [ "${OLD_REV}" != "70b7116" ]; then
    echo "FATAL: ${OLD_DIR} is at '${OLD_REV}', expected the deployed baseline 70b7116." >&2
    exit 2
fi

# The symlinked cache is what makes the "cache is present" arm of these
# scenarios meaningful. Without it every old-code request is a forced miss.
if [ ! -L "${OLD_DIR}/storage/framework/cache/data" ]; then
    echo "FATAL: ${OLD_DIR} cache dir is not shared with ${NEW_DIR} — see the header." >&2
    exit 2
fi

cd "${NEW_DIR}" || exit 1

cleanup() {
    [ -n "${NEW_PID:-}" ] && kill "${NEW_PID}" 2>/dev/null
    [ -n "${OLD_PID:-}" ] && kill "${OLD_PID}" 2>/dev/null
    php artisan security:payment-race cleanup >/dev/null 2>&1
}
trap cleanup EXIT

# ---------------------------------------------------------------- servers

( cd "${NEW_DIR}" && PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload --port="${NEW_PORT}" ) \
    > "${WORKDIR}/new.log" 2>&1 &
NEW_PID=$!
disown "${NEW_PID}" 2>/dev/null || true

( cd "${OLD_DIR}" && PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload --port="${OLD_PORT}" ) \
    > "${WORKDIR}/old.log" 2>&1 &
OLD_PID=$!
disown "${OLD_PID}" 2>/dev/null || true

for _ in $(seq 1 60); do
    curl -s -o /dev/null "${NEW}/up" && curl -s -o /dev/null "${OLD}/up" && break
    sleep 0.25
done

for f in new old; do
    if grep -q 'Unable to respect' "${WORKDIR}/${f}.log"; then
        echo "FATAL: ${f} server fell back to a single process." >&2
        exit 2
    fi
done
echo "new code on ${NEW_PORT} (HEAD), baseline code on ${OLD_PORT} (70b7116)"
echo

# ---------------------------------------------------------------- helpers

seed() { php artisan security:payment-race seed --total="$1" --users=1 2>/dev/null | tail -1; }
report() { php artisan security:payment-race report --invoice="$1" 2>/dev/null | tail -1; }
jget() { php -r '$j=json_decode(file_get_contents("php://stdin"),true);$k=$argv[1];echo is_array($j[$k]??null)?json_encode($j[$k]):($j[$k]??"");' "$1"; }

# Synchronous request. Prints the status code; body lands in $WORKDIR/body.$6
# and the RESPONSE HEADERS in $WORKDIR/hdr.$6 — the headers are what caught the
# false pass, so they are captured on every request, not just on demand.
post() {
    local base="$1" invoice="$2" token="$3" key="$4" payload="$5" tag="$6"
    curl -s -D "${WORKDIR}/hdr.${tag}" -o "${WORKDIR}/body.${tag}" -w '%{http_code}' \
        -X POST "${base}/api/mobile/invoices/${invoice}/payments" \
        -H "Authorization: Bearer ${token}" \
        -H 'Content-Type: application/json' \
        -H 'Accept: application/json' \
        -H "X-Idempotency-Key: ${key}" \
        --data "${payload}"
}

# Concurrent variant, released on a shared nanosecond deadline.
post_at() {
    local base="$1" invoice="$2" token="$3" key="$4" payload="$5" tag="$6" startns="$7"
    while [ "$(date +%s%N)" -lt "${startns}" ]; do :; done
    local t0 t1 code
    t0="$(date +%s%N)"
    code="$(post "${base}" "${invoice}" "${token}" "${key}" "${payload}" "${tag}")"
    t1="$(date +%s%N)"
    printf '%s\n' "${code}" > "${WORKDIR}/status.${tag}"
    printf '%s %s' "${t0}" "${t1}" > "${WORKDIR}/time.${tag}"
}

# Removes the legacy cache entry AND PROVES IT IS GONE. The earlier version
# suppressed all output and asserted nothing, so a silent failure here would
# have been indistinguishable from a successful removal — and "the old code saw
# a cache miss" is the premise every rollback scenario rests on.
forget_legacy() {
    local key="invoice_payment_idempotency:$1:$2" state
    state="$(php artisan tinker --execute=\
"Cache::forget('${key}'); echo Cache::has('${key}') ? 'present' : 'gone';" 2>&1 \
        | tail -1 | tr -d '[:space:]')"
    check "cache_entry_removed" "gone" "${state}"
}

# 'yes' when the response carries X-Idempotent-Replay, which ONLY HEAD emits.
replayed() {
    if grep -qi '^X-Idempotent-Replay:' "${WORKDIR}/hdr.$1"; then echo yes; else echo no; fi
}

check() {
    local label="$1" expected="$2" actual="$3"
    if [ "${expected}" = "${actual}" ]; then
        echo "    ok   ${label}: ${actual}"
    else
        echo "    FAIL ${label}: expected ${expected}, got ${actual}"
        FAILURES=$((FAILURES + 1))
    fi
}

token_of() { printf '%s' "$1" | php -r '$j=json_decode(file_get_contents("php://stdin"),true);echo $j["tokens"][0];'; }

echo "════ RB-0  VERSION PROBE — is port ${OLD_PORT} actually running old code?"
#
# Everything below is worthless if the two ports run the same code, which is
# exactly what happened on the first run. The probe is a replay on each port:
#
#   NEW port  — replay must carry X-Idempotent-Replay. This is the POSITIVE
#               CONTROL: it proves the probe can see the header when it is
#               there, so "absent" on the old port means something.
#   OLD port  — replay is served from the legacy cache and must NOT carry it.
#
# Both replays also confirm the shared cache is working, since the old port
# cannot replay at all unless it can read what it wrote.
SEED="$(seed 10000)"; INV="$(printf '%s' "${SEED}" | jget invoice_id)"; TOK="$(token_of "${SEED}")"
post "${NEW}" "${INV}" "${TOK}" 'rb0-new' '{"mode":"cash","amount":1000}' rb0n1 >/dev/null
post "${NEW}" "${INV}" "${TOK}" 'rb0-new' '{"mode":"cash","amount":1000}' rb0n2 >/dev/null
post "${OLD}" "${INV}" "${TOK}" 'rb0-old' '{"mode":"cash","amount":1000}' rb0o1 >/dev/null
post "${OLD}" "${INV}" "${TOK}" 'rb0-old' '{"mode":"cash","amount":1000}' rb0o2 >/dev/null
check "new_port_emits_replay_header" "yes" "$(replayed rb0n2)"
check "old_port_emits_replay_header" "no"  "$(replayed rb0o2)"
check "both_ports_deduplicated_own_retry" 2 "$(printf '%s' "$(report "${INV}")" | jget payment_count)"
if [ "${FAILURES}" -ne 0 ]; then
    echo
    echo "ABORTING: the baseline port is not demonstrably running baseline code." >&2
    echo "No rollback conclusion can be drawn from this run." >&2
    exit 2
fi
echo

echo "════ RB-1  partial payment, cache lost, baseline code receives the retry"
SEED="$(seed 10000)"; INV="$(printf '%s' "${SEED}" | jget invoice_id)"; TOK="$(token_of "${SEED}")"
echo "    new  -> $(post "${NEW}" "${INV}" "${TOK}" 'rb1-key' '{"mode":"cash","amount":3000}' rb1a)"
forget_legacy "${INV}" 'rb1-key'
echo "    old  -> $(post "${OLD}" "${INV}" "${TOK}" 'rb1-key' '{"mode":"cash","amount":3000}' rb1b)"
echo "    old body: $(head -c 200 "${WORKDIR}/body.rb1b")"
REP="$(report "${INV}")"
# THESE EXPECTATIONS DESCRIBE A DOUBLE CHARGE. They are asserted, not lamented,
# because the harness's job is to pin the behaviour: the baseline controller
# consults only the cache, so a durable claim it cannot read does not stop it.
check "payment_count"  2 "$(printf '%s' "${REP}" | jget payment_count)"
check "paid_total"     6000 "$(printf '%s' "${REP}" | jget paid_total)"
check "claim_count"    1 "$(printf '%s' "${REP}" | jget claim_count)"
check "cash_txn_count" 2 "$(printf '%s' "${REP}" | jget cash_txn_count)"
echo "    >> DOUBLE CHARGE: the claim row was ignored, ledger moved twice."
echo

echo "════ RB-2  full settlement, cache lost, baseline code receives the retry"
SEED="$(seed 10000)"; INV="$(printf '%s' "${SEED}" | jget invoice_id)"; TOK="$(token_of "${SEED}")"
echo "    new  -> $(post "${NEW}" "${INV}" "${TOK}" 'rb2-key' '{"mode":"cash","amount":10000}' rb2a)"
forget_legacy "${INV}" 'rb2-key'
echo "    old  -> $(post "${OLD}" "${INV}" "${TOK}" 'rb2-key' '{"mode":"cash","amount":10000}' rb2b)"
echo "    old body: $(head -c 200 "${WORKDIR}/body.rb2b")"
REP="$(report "${INV}")"
# Full settlement is the interesting case: outstanding is already 0, so the
# baseline controller's OWN balance guard may refuse the retry for reasons that
# have nothing to do with idempotency. If it does, the protection is incidental
# — it holds only while the retry would overpay.
check "payment_count" 1 "$(printf '%s' "${REP}" | jget payment_count)"
check "paid_total"    10000 "$(printf '%s' "${REP}" | jget paid_total)"
echo

echo "════ RB-3  partial payment, cache INTACT, baseline code receives the retry"
SEED="$(seed 10000)"; INV="$(printf '%s' "${SEED}" | jget invoice_id)"; TOK="$(token_of "${SEED}")"
echo "    new  -> $(post "${NEW}" "${INV}" "${TOK}" 'rb3-key' '{"mode":"cash","amount":3000}' rb3a)"
echo "    old  -> $(post "${OLD}" "${INV}" "${TOK}" 'rb3-key' '{"mode":"cash","amount":3000}' rb3b)"
REP="$(report "${INV}")"
check "served_from_legacy_cache_not_claim" "no" "$(replayed rb3b)"
check "payment_count" 1 "$(printf '%s' "${REP}" | jget payment_count)"
check "paid_total"    3000 "$(printf '%s' "${REP}" | jget paid_total)"
echo "    >> safe ONLY because the cache entry survived. Contrast RB-1."
echo

echo "════ RB-4  MIXED VERSION: old and new process the same invoice/key together"
SEED="$(seed 10000)"; INV="$(printf '%s' "${SEED}" | jget invoice_id)"; TOK="$(token_of "${SEED}")"
rm -f "${WORKDIR}"/status.* "${WORKDIR}"/time.*
D=$(( $(date +%s%N) + 1500000000 ))
post_at "${NEW}" "${INV}" "${TOK}" 'rb4-key' '{"mode":"cash","amount":3000}' rb4new "${D}" &
post_at "${OLD}" "${INV}" "${TOK}" 'rb4-key' '{"mode":"cash","amount":3000}' rb4old "${D}" &
wait
php -r '
    $iv = [];
    foreach (array_slice($argv, 1) as $f) { [$a,$b] = explode(" ", trim(file_get_contents($f))); $iv[] = [(float)$a,(float)$b]; }
    printf("    OVERLAP: %.3f ms\n", (min(array_column($iv,1)) - max(array_column($iv,0)))/1e6);
' "${WORKDIR}"/time.rb4*
echo "    new -> $(cat "${WORKDIR}/status.rb4new")   old -> $(cat "${WORKDIR}/status.rb4old")"
REP="$(report "${INV}")"
RB4_N="$(printf '%s' "${REP}" | jget payment_count)"
# DELIBERATELY NOT ASSERTED. The outcome turns on whether the old request reads
# the cache before the new request's Cache::put lands — a race. Pinning either
# value would make the harness flaky, and worse, a green "1" here would be a
# timing accident dressed up as a guarantee. The number is reported instead.
if [ "${RB4_N}" -gt 1 ]; then
    echo "    payments: ${RB4_N}  paid: $(printf '%s' "${REP}" | jget paid_total)  >> DOUBLE CHARGE"
else
    echo "    payments: ${RB4_N}  >> single payment this run — the old request happened"
    echo "       to read the cache entry after the new request wrote it. Timing, not safety."
fi
echo

echo "════ RB-5  does a legacy replay REFRESH the 24h TTL?"
#
# Measured behaviourally rather than by decoding the file store's on-disk
# layout, which would only be testing a guess about Laravel's key hashing.
# The entry is rewritten with a 3-second TTL, replayed, and then checked after
# it should have expired. If a replay refreshed the TTL the entry would still
# be there. The positive control is in the same scenario: the replay must not
# record a payment, which is what proves it WAS served from the cache.
SEED="$(seed 10000)"; INV="$(printf '%s' "${SEED}" | jget invoice_id)"; TOK="$(token_of "${SEED}")"
CK="invoice_payment_idempotency:${INV}:rb5-key"
post "${OLD}" "${INV}" "${TOK}" 'rb5-key' '{"mode":"cash","amount":3000}' rb5a >/dev/null
check "ttl_rewritten_to_3s" "present" "$(php artisan tinker --execute=\
"Cache::put('${CK}', Cache::get('${CK}'), 3); echo Cache::has('${CK}') ? 'present' : 'missing';" \
    2>&1 | tail -1 | tr -d '[:space:]')"
echo "    replay -> $(post "${OLD}" "${INV}" "${TOK}" 'rb5-key' '{"mode":"cash","amount":3000}' rb5b)"
REP="$(report "${INV}")"
check "replay_came_from_legacy_cache" "no" "$(replayed rb5b)"
check "replay_recorded_no_payment" 1 "$(printf '%s' "${REP}" | jget payment_count)"
sleep 4
STILL="$(php artisan tinker --execute="echo Cache::has('${CK}') ? 'present' : 'expired';" 2>/dev/null | tail -1 | tr -d '[:space:]')"
echo "    entry 4s after a replay of a 3s-TTL entry: ${STILL}"
check "replay_does_not_refresh_ttl" "expired" "${STILL}"
echo

echo "rollback scenarios: 5 (+ RB-0 version probe)   failed checks: ${FAILURES}"
echo
cat <<'VERDICT'
VERDICT — ROLLBACK TO 70b7116 IS UNSAFE WHILE RETRIES CAN STILL ARRIVE.
  RB-1 records a second payment and a second cash transaction for a key the new
  code already served. The durable claim is present and is simply not consulted
  by code that predates it. RB-3 shows the only thing standing between a
  rollback and a double charge is the legacy cache entry, which is best-effort
  by construction: a cache flush, an evicted key, a restarted cache container or
  an expired TTL each remove it.

  This is a MEASUREMENT, NOT AN AUTHORIZATION. Nothing here is a release or a
  rollback; see the runbook for the constraints that would have to hold first.
VERDICT
[ "${FAILURES}" -eq 0 ] || exit 1
