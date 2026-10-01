#!/usr/bin/env bash
# Failure-path checks for docs/runbooks/operator-steps-security-batch.sh.
# Local only: sources the script's functions (OPS_LIB=1) into a sandbox and
# replaces every side effect (sudo, systemctl, systemd-run, nginx, artisan, psql,
# curl) with
# a stub that records its arguments. Real awk/sed/grep/php/cp/stat run, wrapped
# so their argv is recorded too. Nothing here touches a server.
#
#   bash tests/Runbooks/operator_steps_test.sh      exit 0 = every check passed
set -u -o pipefail
cd "$(dirname "$0")/../.." || exit 2
SCRIPT=${SCRIPT:-docs/runbooks/operator-steps-security-batch.sh}
T=$(mktemp -d); [ -n "${KEEP:-}" ] && echo "sandbox kept: $T" || trap 'rm -rf "$T"' EXIT
PASS=0; FAILN=0
check() { if eval "$2"; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAILN=$((FAILN + 1)); echo "FAIL  $1"; fi; }

OLDPW=CommittedOld-7f3a91    # stands in for the committed (leaked) password
NEWPW=$(printf 'a%.0s' {1..32})$(printf 'b%.0s' {1..32})   # what the stubbed openssl "generates"

# Independent SCRAM-SHA-256 reference (Python), itself checked against RFC 7677.
cat > "$T/scram.py" <<'PY'
import base64, hashlib, hmac, sys
def keys(pw, salt, i):
    s = hashlib.pbkdf2_hmac("sha256", pw.encode(), salt, i, 32)
    ck = hmac.new(s, b"Client Key", hashlib.sha256).digest()
    return ck, hashlib.sha256(ck).digest(), hmac.new(s, b"Server Key", hashlib.sha256).digest()
if sys.argv[1] == "rfc7677":
    ck, st, sv = keys("pencil", base64.b64decode("W22ZaJ0SNY7soEsUEjb6gQ=="), 4096)
    am = (b"n=user,r=rOprNGfwEbeRWgbNEkqO,"
          b"r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096,"
          b"c=biws,r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0")
    proof = bytes(a ^ b for a, b in zip(ck, hmac.new(st, am, hashlib.sha256).digest()))
    ok = (base64.b64encode(proof) == b"dHzbZapWIk4jUhN+Ute9ytag9zjfMHgsqmmiz7AndVQ="
          and base64.b64encode(hmac.new(sv, am, hashlib.sha256).digest()) == b"6rriTRBi23WpRR/wtup+mMhUZUn/dB5nLTJRsjl95G4=")
    sys.exit(0 if ok else 1)
# verify <verifier-file>: password on stdin
v = open(sys.argv[2]).read().strip()
head, rest = v.split("$", 1)
it_salt, st_sv = rest.split("$")
it, salt = it_salt.split(":"); st, sv = st_sv.split(":")
_, st2, sv2 = keys(sys.stdin.read(), base64.b64decode(salt), int(it))
sys.exit(0 if head == "SCRAM-SHA-256" and base64.b64encode(st2).decode() == st and base64.b64encode(sv2).decode() == sv else 1)
PY
check "reference SCRAM implementation reproduces RFC 7677's proof and server signature" 'python3 "$T/scram.py" rfc7677'

# ── sandbox + stubs ─────────────────────────────────────────────────────────
setup() {
  S=$(mktemp -d "$T/sb.XXXX")
  mkdir -p "$S/prod/storage/app/private/JewelFlows" "$S/work" "$S/prod/storage/app/public/kyc/1"
  chmod 700 "$S/work" "$S/prod/storage/app/private/JewelFlows"
  printf 'APP_KEY=base64:dGVzdA==\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=jewelflow\nDB_USERNAME=jewelflow\nDB_PASSWORD=%s\n' "$OLDPW" > "$S/prod/.env"
  chmod 640 "$S/prod/.env"
  cat > "$S/vhost" <<'NG'
server {
    if ($host = www.jewelflows.com) {
        return 301 https://$host$request_uri;
    }
    listen 80;
    server_name jewelflows.com www.jewelflows.com;
    return 301 https://jewelflows.com$request_uri;
}
server {
    listen 443 ssl;
server_name jewelflows.com www.jewelflows.com dhiran.jewelflows.com;
    root /var/www/jewelflow/public;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
    }
}
NG
  cp "$S/vhost" "$S/running.conf"; : > "$S/pg.log"; : > "$S/nginx-error.log"
  : > "$S/argv.log"; : > "$S/events.log"; : > "$S/pgsu.stdin"; : > "$S/sandbox.stdin"; : > "$S/nginx-access.log"
  (
    OPS_LIB=1 source "$SCRIPT"
    PROD=$S/prod VHOST=$S/vhost PGLOG=$S/pg.log NGINX_ERRLOG=$S/nginx-error.log NGINX_ACCESS=$S/nginx-access.log WORK=$S/work WEB=$(id -gn) STAMP=TESTSTAMP
    rec() { printf '%s\n' "$*" >> "$S/argv.log"; }
    ev()  { printf '%s\n' "$*" >> "$S/events.log"; }
    for c in awk sed grep cut cp cmp stat cat tail php sha256sum chgrp chmod diff find unzip; do
      eval "$c() { rec $c \"\$@\"; command $c \"\$@\"; }"
    done
    sleep() { :; }
    openssl() { rec openssl "$@"; [ "$1" = rand ] && printf '%s\n' "$NEWPW"; }
    git() { rec git "$@"; printf '<env name="DB_PASSWORD" value="%s"/>\n' "$OLDPW"; }
    sudo() { rec sudo "$@"; # sudo -u www-data test -r|-w FILE: readable iff group matches and g+r
      if [ "$1 $2 $3" = "-u www-data test" ]; then
        local m g; m=$(command stat -c '%a' "$5"); g=$(command stat -c '%G' "$5")
        [ "$g" = "$WEB" ] || return 1
        case "$4" in -r) [ $(( ${m:1:1} & 4 )) -ne 0 ] ;; -w) [ $(( ${m:1:1} & 2 )) -ne 0 ] ;; esac; return; fi
      case "$*" in *createdb*) ev createdb ;; *dropdb*) ev dropdb ;; esac; return 0; }
    ART() { rec ART "$@"; ev "ART $1"; case " ${ART_FAIL:-} " in *" $1 "*) return 1 ;; esac
      [ "$1" = backup:run ] && [ -n "${MAKE_ZIP:-}" ] && eval "$MAKE_ZIP"; return 0; }
    CFG() { ART "$@"; }
    PGSU() { rec PGSU "$@"; local sql; case " $* " in *" -c "*) sql=${*: -1} ;; *) sql=$(command cat) ;; esac
      printf '%s\n' "$sql" >> "$S/pgsu.stdin"
      case "$sql" in
        *"ALTER ROLE jewelflow PASSWORD '"*) [ -n "${ALTER_FAIL:-}" ] && return 1
          printf '%s' "$sql" | command sed -E "s/.*PASSWORD '([^']+)'.*/\1/" > "$S/role.verifier"; ev "ALTER ROLE" ;;
        *"select count(*) from pg_trigger"*) echo 17 ;;
        *"select count(*) from"*) echo 5 ;;
        *"from pg_roles"*) echo jewelflow_staging ;;
      esac; return 0; }
    # The box: records what it was given (argv and the dump on stdin), answers like the real one.
    systemd-run() { rec systemd-run "$@"; command cat > "$S/sandbox.stdin"; ev sandbox; echo "sandbox: uid 61535, network lo only, production paths and port unreachable" >&2; [ -z "${SANDBOX_FAIL:-}" ] || { echo "psql:<stdin>:3: ERROR:  boom" >&2; return 3; }
      local t; for t in $COUNTED; do echo "count $t ${SANDBOX_COUNT:-5}"; done; echo "triggers 17"; }
    db_connects() { rec db_connects "$@"; local pw; pw=$(command cat)
      [ -n "${CONNECT_FAIL:-}" ] && [ "$pw" = "$NEWPW" ] && return 1
      [ -f "$S/role.verifier" ] || { [ "$pw" = "$OLDPW" ]; return; }
      printf '%s' "$pw" | python3 "$T/scram.py" verify "$S/role.verifier"; }
    cached_password_sha() { rec cached_password_sha; if [ -n "${CACHE_STALE:-}" ]; then printf %s "$OLDPW" | command sha256sum | command cut -c1-64; else env_value DB_PASSWORD "$PROD/.env" | tr -d '\n' | command sha256sum | command cut -c1-64; fi; }
    systemctl() { rec systemctl "$@"; ev "systemctl $*"
      case "$*" in
        "reload php8.2-fpm") [ -z "${FPM_FAIL:-}" ] ;;
        "restart jewelflow-production-ops-alerts") [ -z "${WORKER_FAIL:-}" ] ;;
        "reload nginx") [ -n "${RELOAD_NOOP:-}" ] || command cp "$S/vhost" "$S/running.conf" ;;
        *) return 0 ;;
      esac; }
    nginx() { rec nginx "$@"; case "$1" in -t) [ -z "${NGINX_T_FAIL:-}" ] ;; -T) command cat "$S/running.conf" ;; esac; }
    stale() { [ -n "${RACE:-}" ] && [ "$(command cat "$S/race" 2>/dev/null || echo 0)" -lt "$RACE" ]; }   # old workers still answering
    code() { rec code "$@"
      case "$2" in
        /storage/kyc/*|/storage/signatures/*|/storage/karigar-invoices/*|/storage/purchases/*) if [ -n "${PROBE_OPEN:-}" ] || stale; then echo 404; else
            printf 'access forbidden by rule, request: "GET %s"\n' "$2" >> "$NGINX_ERRLOG"; echo 403; fi ;;
        /storage/*) echo 404 ;;
        /login) [ -n "${LOGIN_FAIL:-}" ] && grep -q "ART up" "$S/events.log" && echo 500 || echo 200 ;;
        /health) [ "$1" = staging.jewelflows.com ] && [ -n "${STAGING_FAIL:-}" ] && echo 502 || echo 200 ;;
      esac; }
    who() { case "$2" in /storage/kyc/*|/storage/signatures/*|/storage/karigar-invoices/*|/storage/purchases/*)
        if stale; then echo $(( $(command cat "$S/race" 2>/dev/null || echo 0) + 1 )) > "$S/race"; echo app
        elif [ -n "${PROBE_OPEN:-}" ]; then echo app; else echo nginx; fi ;; *) echo app ;; esac; }
    [ -n "${PGLOG_FAILS:-}" ] && ART() { rec ART "$@"; ev "ART $1"; [ "$1" = up ] && printf 'FATAL:  password authentication failed for user "jewelflow"\n' >> "$PGLOG"; return 0; }
    eval "$1"
  ) > "$S/out" 2>&1 < /dev/null
  RC=$?
}
out_has() { grep -qF -- "$1" "$S/out"; }
no_secret_anywhere() {   # neither password in any argv, psql input, output or trace
  ! grep -qF -e "$NEWPW" -e "$OLDPW" "$S/argv.log" "$S/out" "$S/pgsu.stdin" ${1:+"$1"}
}

# ── rotate ──────────────────────────────────────────────────────────────────
setup step_rotate
check "rotate: succeeds end to end (exit 0)" '[ "$RC" = 0 ] && out_has "ROTATED:"'
check "rotate: .env carries the new password; owner and mode kept" \
  '[ "$(grep ^DB_PASSWORD= "$S/prod/.env")" = "DB_PASSWORD=$NEWPW" ] && [ "$(stat -c %a "$S/prod/.env")" = 640 ]'
check "rotate: the role got a SCRAM verifier of the new password (independent check), not the password" \
  'printf %s "$NEWPW" | python3 "$T/scram.py" verify "$S/role.verifier" && grep -q "PASSWORD .SCRAM-SHA-256\$4096:" "$S/pgsu.stdin"'
check "rotate: neither password appears in any process argument, psql input or output" 'no_secret_anywhere'
check "rotate: the kept .env copy is what it claims (owner = runner, mode 600, identical)" \
  '[ "$(stat -c "%U:%G %a" "$S/work/env.before-rotate")" = "$(id -un):$(id -gn) 600" ] && out_has "kept $S/work/env.before-rotate ($(id -un):$(id -gn) 600"'
check "rotate: order is down, ALTER ROLE, config:cache, fpm reload, worker restart, CLI check, up" \
  '[ "$(grep -oE "ART down|ALTER ROLE|ART config:cache|systemctl reload php8.2-fpm|systemctl restart jewelflow-production-ops-alerts|ART migrate:status|ART up" "$S/events.log" | tr "\n" "|")" = "ART down|ALTER ROLE|ART config:cache|systemctl reload php8.2-fpm|systemctl restart jewelflow-production-ops-alerts|ART migrate:status|ART up|" ]'

# Traced by the caller: the function turns tracing off for itself.
setup 'exec 9>"$S/trace"; BASH_XTRACEFD=9; set -x; step_rotate; set +x'
check "rotate: under a caller's set -x no password reaches the trace (and the run still succeeds)" \
  '[ "$RC" = 0 ] && out_has "ROTATED:" && grep -q "step_rotate" "$S/trace" && ! grep -qF -e "$NEWPW" -e "$OLDPW" "$S/trace"'

for f in "CONFIG|ART_FAIL=config:cache|config:cache failed" "FPM|FPM_FAIL=1|php8.2-fpm reload failed" \
         "WORKER|WORKER_FAIL=1|restarting jewelflow-production-ops-alerts failed" "CACHE|CACHE_STALE=1|the config cache does not carry the new password"; do
  IFS='|' read -r name assign msg <<< "$f"
  export "${assign%%=*}=${assign#*=}"; setup step_rotate; unset "${assign%%=*}"
  check "rotate/$name: fails (exit 1), stays in maintenance, says so" \
    '[ "$RC" = 1 ] && out_has "$msg" && out_has "STATE:    MAINTENANCE ON" && ! grep -q "ART up" "$S/events.log"'
done
export LOGIN_FAIL=1; setup step_rotate; unset LOGIN_FAIL
check "rotate/health: /login failing after up is a failure, and maintenance goes back on" \
  '[ "$RC" = 1 ] && out_has "did not answer 200" && out_has "MAINTENANCE ON again" && [ "$(tail -1 "$S/events.log")" = "ART down" ]'
export STAGING_FAIL=1; setup step_rotate; unset STAGING_FAIL
check "rotate/staging: staging /health failing after the shared FPM reload is a failure" '[ "$RC" = 1 ] && out_has "staging /health is not 200"'
export CONNECT_FAIL=1; setup step_rotate; unset CONNECT_FAIL
check "rotate/connect: new password not accepted -> role set back to the old one, .env untouched, site up" \
  '[ "$RC" = 1 ] && out_has "role set back to the previous password" && printf %s "$OLDPW" | python3 "$T/scram.py" verify "$S/role.verifier" \
   && grep -q "^DB_PASSWORD=$OLDPW\$" "$S/prod/.env" && [ "$(tail -1 "$S/events.log")" = "ART up" ] && no_secret_anywhere'
export ALTER_FAIL=1; setup step_rotate; unset ALTER_FAIL
check "rotate/alter: ALTER ROLE failing leaves everything unchanged and lifts maintenance" \
  '[ "$RC" = 1 ] && out_has "ALTER ROLE failed" && grep -q "^DB_PASSWORD=$OLDPW\$" "$S/prod/.env"'
export PGLOG_FAILS=1; setup step_rotate; unset PGLOG_FAILS
check "rotate/consumer: a failed login for the role after the switch is a failure" '[ "$RC" = 1 ] && out_has "failed login(s) for role jewelflow"'

# ── env-read ────────────────────────────────────────────────────────────────
setup 'chmod 600 "$PROD/.env"; step_env_read'
check "env-read: 600 -> group-readable 640, not writable (exit 0)" '[ "$RC" = 0 ] && [ "$(stat -c %a "$S/prod/.env")" = 640 ]'
setup 'chmod 660 "$PROD/.env"; step_env_read'
check "env-read: a group-WRITABLE .env is refused, not accepted as readable" '[ "$RC" = 1 ] && out_has "expected group"'

# ── origin ──────────────────────────────────────────────────────────────────
setup step_origin
check "origin: applies both denies inside the :443 block, before location /" \
  '[ "$RC" = 0 ] && awk "/listen 443/{a=1} a&&/location \\^~ \\/storage\\/kyc\\/ \\{/{k=NR} a&&/location \\^~ \\/storage\\/signatures\\/ \\{/{s=NR} a&&/location \\/ \\{/{l=NR} END{exit !(k&&s&&k<l&&s<l)}" "$S/vhost"'
check "origin: the printed rollback keeps the denies; the pre-change copy is marked never-restore" \
  'out_has "rollback point that KEEPS the denies: cat $S/work/jewelflow.vhost.protected" && grep -qF "location ^~ /storage/kyc/ {" "$S/work/jewelflow.vhost.protected" && out_has "never restore jewelflow.vhost.before-origin"'
check "origin: kept copies are root-style 600 as claimed" \
  '[ "$(stat -c %a "$S/work/jewelflow.vhost.protected")" = 600 ] && [ "$(stat -c %a "$S/work/jewelflow.vhost.before-origin")" = 600 ]'
setup '(step_origin) >/dev/null; : > "$NGINX_ERRLOG"; PROBE_OPEN=1; step_origin'
check "origin: marker already present but probes show the origin open -> failure, denies kept" \
  '[ "$RC" = 1 ] && out_has "already denies" && out_has "did not answer as expected" && grep -qF "location ^~ /storage/kyc/ {" "$S/vhost"'
setup '(step_origin) >/dev/null; sed "/storage\/kyc\/ {/,+2d" "$VHOST" > "$S/running.conf"; RELOAD_NOOP=1; step_origin'
check "origin: marker in the file but not in the configuration nginx loads (reload not effective) -> failure" \
  '[ "$RC" = 1 ] && out_has "the configuration it loads lacks"'
export NGINX_T_FAIL=1; setup 'cp "$VHOST" "$S/vhost.before"; step_origin'; unset NGINX_T_FAIL

check "origin: nginx -t rejecting the change restores the file byte for byte and never reloads" \
  '[ "$RC" = 1 ] && cmp -s "$S/vhost" "$S/vhost.before" && ! grep -q "reload nginx" "$S/events.log" && out_has "not yet protected"'
export RACE=3; setup step_origin; unset RACE
check "origin: nginx's asynchronous reload (old workers answer the first requests) is waited out, not misread" \
  '[ "$RC" = 0 ] && out_has "ORIGIN:" && [ "$(cat "$S/race")" = 3 ]'
setup 'run_steps origin <<< YES'
check "run: from a step, YES runs it and ends with the Cloudflare package and ALL STEPS PASSED" \
  '[ "$RC" = 0 ] && out_has "CLOUDFLARE (zone jewelflows.com)" && out_has "ALL STEPS PASSED (origin)"'
setup 'run_steps origin <<< yes'
check "run: anything but YES changes nothing" '[ "$RC" = 1 ] && out_has "Stopped. Nothing changed." && ! grep -q "reload nginx" "$S/events.log"'

# ── backup ──────────────────────────────────────────────────────────────────
mkzip() { python3 - "$@" <<'PY'
import os, sys, zipfile
z = zipfile.ZipFile(sys.argv[1], "w")
base = "var/www/jewelflow/" if len(sys.argv) < 4 else sys.argv[3]
for n in ["artisan", "composer.lock", "app/X.php", "bootstrap/app.php", "config/app.php", "database/migrations/m.php",
          "lang/en.json", "public/index.php", "resources/v.blade.php", "routes/web.php", "storage/app/public/x"]:
    z.writestr(base + n, "x")
z.writestr(base + ".env", open(sys.argv[2]).read())
z.writestr("db-dumps/postgresql-jewelflow.sql", os.environ.get("DUMP", "select 1;\n"))
for extra in sys.argv[4:]:
    z.writestr(base + extra, "x")
for i in range(int(os.environ.get("LONG", "0"))):   # a listing longer than a pipe buffer
    z.writestr(base + "storage/app/public/f%d" % i, "x")
z.close()
PY
}
export ART_FAIL=backup:run; setup step_backup; unset ART_FAIL
check "backup: backup:run failing is a failure with no archive claimed" '[ "$RC" = 1 ] && out_has "backup:run failed as www-data"'
export MAKE_ZIP='mkzip "$PROD/storage/app/private/JewelFlows/b.zip" "$PROD/.env" "${PROD#/}/" bootstrap/cache/config.php'
setup step_backup
check "backup: an archive holding bootstrap/cache (plaintext secrets) is refused" '[ "$RC" = 1 ] && out_has "excluded path"'
export MAKE_ZIP='mkzip "$PROD/storage/app/private/JewelFlows/b.zip" "$PROD/.env" "${PROD#/}/" storage/app/backup-temp/'
setup step_backup
check "backup: an EMPTY excluded directory entry (storage/app/backup-temp/, as on production 2026-09-26) is not content" '[ "$RC" = 0 ]'
export MAKE_ZIP='mkzip "$PROD/storage/app/private/JewelFlows/b.zip" "$PROD/.env" "${PROD#/}/" storage/app/backup-temp/temp/db.sql'
setup step_backup
check "backup: a FILE under an excluded directory is refused" '[ "$RC" = 1 ] && out_has "excluded path: storage/app/backup-temp/temp/db.sql"'
export MAKE_ZIP='printf "DB_PASSWORD=other\n" > "$S/other.env"; mkzip "$PROD/storage/app/private/JewelFlows/b.zip" "$S/other.env" "${PROD#/}/"'
setup step_backup
check "backup: an archived .env different from the live one is refused" '[ "$RC" = 1 ] && out_has "differs from the live one"'
unset MAKE_ZIP
export MAKE_ZIP='mkzip "$PROD/storage/app/private/JewelFlows/b.zip" "$PROD/.env" "${PROD#/}/"'
setup step_backup
check "backup: a complete archive passes: restored in the box, compared, extracted dump removed" \
  '[ "$RC" = 0 ] && out_has "row counts of 14 tables match production" && grep -q sandbox "$S/events.log" && grep -q "select 1;" "$S/sandbox.stdin" && [ ! -e "$S/work/restore" ]'
check "backup: the run shows the box's own check (uid, network, reach)" 'out_has "sandbox: uid 61535, network lo only, production paths and port unreachable"'
check "backup: the dump goes to the box only: no psql against production sees it, no scratch database, no extension is created" \
  '! grep -q "select 1;" "$S/pgsu.stdin" && ! grep -qE "createdb|dropdb" "$S/events.log" "$S/argv.log" && ! grep -qiE "create extension|SET ROLE" "$S/pgsu.stdin"'
check "backup: the box is a transient unit with no network, private IPC, read-only system, hidden homes and production paths, a capped tmpfs" \
  'for w in "--wait --pipe --collect" "PrivateNetwork=yes" "PrivateIPC=yes" "ProtectSystem=strict" "ProtectHome=yes" "NoNewPrivileges=yes" "CapabilityBoundingSet=" "InaccessiblePaths=-/var/www -/var/lib/postgresql -/etc/postgresql -/run/postgresql" "TemporaryFileSystem=/mnt:mode=1777,size=2G" "MemoryMax=3G" "RuntimeMaxSec=900" "ROLES=jewelflow jewelflow_staging"; do grep -qF -- "$w" "$S/argv.log" || { echo "   missing: $w"; exit 1; }; done'
export SANDBOX_FAIL=1; setup step_backup; unset SANDBOX_FAIL
check "backup: a dump the box cannot restore is a failure; its last lines are shown; nothing is left" \
  '[ "$RC" = 1 ] && out_has "does not restore in the isolated instance" && out_has "ERROR:  boom" && [ ! -e "$S/work/restore" ]'
export SANDBOX_COUNT='5; drop table x'; setup step_backup; unset SANDBOX_COUNT
check "backup: the box's output is data: a count that is not digits is no count" '[ "$RC" = 1 ] && out_has "shops: no rows restored"'
unset MAKE_ZIP
export MAKE_ZIP='mkzip "$PROD/storage/app/private/JewelFlows/b.zip" "$PROD/.env" "${PROD#/}/"'
export DUMP=$'select 1;\n\\! id > /tmp/pwned\n'; setup step_backup
check "backup: a dump carrying a psql shell escape is refused and never restored (SEC-001)" \
  '[ "$RC" = 1 ] && out_has "psql meta-commands" && ! grep -q sandbox "$S/events.log" && [ ! -s "$S/sandbox.stdin" ]'
export DUMP=$'COPY public.t (a) TO PROGRAM \'id\';\n'; setup step_backup
check "backup: a dump running COPY ... TO PROGRAM is refused (SEC-001)" '[ "$RC" = 1 ] && out_has "psql meta-commands"'
export DUMP=$'COPY public.notes (body) FROM stdin;\nthe gold savings program\n\\.\n'; setup step_backup
check "backup: a genuine dump (COPY data mentioning a program, the \\. terminator) is not called tampered" \
  '[ "$RC" = 0 ] && grep -q "the gold savings program" "$S/sandbox.stdin"'
unset DUMP
setup 'mkdir -p "$PROD/storage/app/public/kyc/1" "$PROD/storage/app/public/signatures"; : > "$PROD/storage/app/public/kyc/1/a.jpg"; : > "$PROD/storage/app/public/signatures/s.png"; edge_package'
check "edge: the package lists every public private-prefix file on every host, and the WAF expression" \
  '[ "$(wc -l < "$S/work/edge-purge-urls.txt")" = 6 ] && grep -qx "https://dhiran.jewelflows.com/storage/kyc/1/a.jpg" "$S/work/edge-purge-urls.txt" && out_has "contains \"/storage/signatures/\""'

# ── pipefail: an early-exiting grep -q must not decide a check ──────────────
# Under pipefail, `producer | grep -q X` exits 141 when grep stops reading while
# the producer still writes (a long archive listing, a big dump): a match reads
# as "absent". Measured 2026-10-01 before the fix: a forbidden entry at the top
# of a long listing went unreported in 3 of 3 runs.
export MAKE_ZIP='mkzip "$PROD/storage/app/private/JewelFlows/b.zip" "$PROD/.env" "${PROD#/}/"'
export LONG=6000; setup step_backup
check "backup: a complete archive with a 6000-entry listing passes (no false 'lacks' from SIGPIPE)" '[ "$RC" = 0 ]'
export MAKE_ZIP='mkzip "$PROD/storage/app/private/JewelFlows/b.zip" "$PROD/.env" "${PROD#/}/" .claude/settings.json'
setup step_backup
check "backup: a forbidden file at the top of a 6000-entry listing is refused (missed under pipefail)" \
  '[ "$RC" = 1 ] && out_has "excluded path: .claude/settings.json"'
unset LONG
export MAKE_ZIP='mkzip "$PROD/storage/app/private/JewelFlows/b.zip" "$PROD/.env" "${PROD#/}/"'
export DUMP=$'select 1;\n\\! id > /tmp/pwned\n'"$(printf '\\.\n%.0s' {1..20000})"; setup step_backup
check "backup: a shell escape followed by 20000 pg_dump terminators is refused, never restored (missed under pipefail)" \
  '[ "$RC" = 1 ] && out_has "psql meta-commands" && ! grep -q sandbox "$S/events.log"'
unset DUMP MAKE_ZIP
check "no runbook or staging script decides a check with 'producer | grep -q' (pipefail + SIGPIPE)" \
  '[ -z "$(grep -nE "(^|[^|])\|[[:space:]]*grep -[a-zA-Z]*q" docs/runbooks/*.sh tests/Staging/*.sh | grep -v -- "-f -")" ]'

# ── verify (read-only) ──────────────────────────────────────────────────────
# Healthy unless a test breaks one thing: rotated .env, a fresh non-empty
# archive, the scheduler's cron line and the 00:00 backup:run, origin denies.
VOK='command sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$NEWPW/" "$PROD/.env"; db_connects() { command cat >/dev/null; }
  echo x > "$PROD/storage/app/private/JewelFlows/b.zip"; CRON=$S/cron.d; mkdir -p "$CRON"
  echo "* * * * * www-data /usr/bin/php $PROD/artisan schedule:run >> /dev/null 2>&1" > "$CRON/jewelflow-scheduler"
  ART() { [ "$1" = schedule:list ] && echo "  0    0 * * *  php artisan backup:run ........... Next Due: 19 hours from now"; return 0; }
  effective_ok() { :; }; probe_origin() { :; }; verify_edge() { return 3; }'
setup "$VOK; verify_all"
check "verify: healthy, edge PARTIAL (origin refuses, no WAF rule) -> exit 0" '[ "$RC" = 0 ]'
setup "$VOK; verify_edge() { return 1; }; verify_all"
check "verify: an edge probe that is NOT blocked fails verify (was swallowed by || true)" '[ "$RC" = 1 ]'
setup "$VOK; rm \"\$CRON/jewelflow-scheduler\"; verify_all"
check "verify: no cron line running schedule:run as www-data fails verify (was printed only)" '[ "$RC" = 1 ] && out_has "scheduler: MISSING"'
setup "$VOK; ART() { return 0; }; verify_all"
check "verify: backup:run missing from the schedule fails verify (was printed only)" '[ "$RC" = 1 ] && out_has "schedule: MISSING"'
setup "$VOK; : > \"\$PROD/storage/app/private/JewelFlows/b.zip\"; verify_all"
check "verify: an EMPTY newest archive fails verify" '[ "$RC" = 1 ] && out_has "STALE"'
setup "$VOK; touch -d \"-27 hours\" \"\$PROD/storage/app/private/JewelFlows/b.zip\"; verify_all"
check "verify: a newest archive older than 26 h fails verify" '[ "$RC" = 1 ] && out_has "STALE"'
# curl as the edge: ES = status, EVIA = server header (default cloudflare), ERC = curl's
# exit, ENOHDR = no headers at all, EB = body. EREACH = what the origin logs for a probe:
# deny (nginx's rule refused it), app (it reached the origin), none. ECTL = the control.
EDGE='curl() { local u= h=; while [ $# -gt 0 ]; do case "$1" in -D) h=$2; shift ;; https://*) u=$1 ;; esac; shift; done
  local path=/${u#https://*/} st=$ES reach=${EREACH-none}
  case "$path" in *-ctl.jpg) st=404; reach=${ECTL-app} ;; /storage/signatures/*) reach=${EREACH_SIG-$reach} ;; esac
  [ "${ERC:-0}" = 0 ] || return "$ERC"
  if [ -n "${ENOHDR:-}" ]; then : > "$h"; else printf "HTTP/2 %s\r\n%s\r\n\r\n" "$st" "${EVIA-server: cloudflare}" > "$h"; fi
  case "$reach" in
    deny) printf "[error] access forbidden by rule, request: \"GET %s HTTP/1.1\"\n" "$path" >> "$NGINX_ERRLOG"; printf "\"GET %s HTTP/1.1\" 403\n" "$path" >> "$NGINX_ACCESS" ;;
    app)  printf "\"GET %s HTTP/1.1\" %s\n" "$path" "$st" >> "$NGINX_ACCESS" ;;
  esac
  printf %s "${EB:-}"; }'
setup "$EDGE; ES=404 EREACH=app EB=\"<title>Not Found</title>\"; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: a probe the application answers (not blocked) returns 1, not PARTIAL" 'out_has "edge-rc=1" && out_has "NOT blocked"'
setup "$EDGE; ES=502 EB=\"error code: 1020 Cloudflare Ray ID\"; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: a Cloudflare-branded 502 is not protection (returns 1)" 'out_has "edge-rc=1"'

# ── review of 04e36e1: reproduced against step_backup and verify_edge ───────
export MAKE_ZIP='mkzip "$PROD/storage/app/private/JewelFlows/b.zip" "$PROD/.env" "${PROD#/}/"'
export DUMP=$'select 1;\nSET SESSION ROLE postgres;\n'; setup step_backup
check "scanner: SET SESSION ROLE is refused; the dump reaches no psql" '[ "$RC" = 1 ] && out_has "psql meta-commands" && ! grep -q "SESSION ROLE" "$S/pgsu.stdin" "$S/sandbox.stdin"'
export DUMP=$'select 1;\n \\! true\n'; setup step_backup
check "scanner: a shell escape after leading whitespace is refused; the dump reaches no psql" '[ "$RC" = 1 ] && out_has "psql meta-commands" && ! grep -qF "\\! true" "$S/pgsu.stdin" "$S/sandbox.stdin"'
export DUMP=$'select 1; SET SESSION ROLE postgres; \\! true\n'; setup step_backup
check "scanner is advisory: a shape it misses (mid-line) still goes nowhere but the box" '[ "$RC" = 0 ] && grep -q "SESSION ROLE" "$S/sandbox.stdin" && ! grep -q "SESSION ROLE" "$S/pgsu.stdin"'
export DUMP=$'\\restrict abc123\nselect 1;\n\\unrestrict abc123\n'; setup step_backup
check "scanner: the restrict and unrestrict lines pg_dump writes are not called tampered" '[ "$RC" = 0 ]'
unset DUMP
setup 'eval "$MAKE_ZIP"; restore_check'
check "restore-check: the newest archive is restored in the box and compared; no backup is taken" \
  '[ "$RC" = 0 ] && out_has "RESTORE-CHECK: b.zip restores in an isolated instance" && out_has "shops: 5 restored, 5 in production now" && ! grep -q "ART backup:run" "$S/events.log" && [ ! -e "$S/work/restore" ]'
unset MAKE_ZIP

# ── verify-edge: transport, status and the origin's own logs decide ─────────
setup "$EDGE; ES=403 EREACH=deny; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: 403 through Cloudflare, refused by nginx's deny rule (its log says so) -> origin only, 3" 'out_has "edge-rc=3" && out_has "EDGE: PARTIAL"'
setup "$EDGE; ES=403 EREACH=none; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: 403 through Cloudflare, never reached the origin while the control did -> edge, 0" 'out_has "edge-rc=0" && out_has "blocked at the edge"'
setup "$EDGE; ES=403 EREACH=none EREACH_SIG=deny; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: edge on one prefix, origin on the other -> 3, each line says which" 'out_has "edge-rc=3" && out_has "blocked at the edge" && out_has "deny rule, through the edge"'
setup "$EDGE; ES=403 EREACH=none ECTL=none; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: 403 but neither probe nor control in the origin log -> cannot tell, 1" 'out_has "edge-rc=1" && out_has "cannot tell"'
setup "$EDGE; ES=403 EREACH=app; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: 403 that reached the origin without the deny rule logging it -> 1" 'out_has "edge-rc=1" && out_has "some other way"'
setup "$EDGE; ES=502 EREACH=deny EB=\"<center>nginx</center>\"; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: a 502 carrying nginx's footer is not an origin refusal (returns 1)" 'out_has "edge-rc=1" && out_has "HTTP 502"'
for st in 200 404; do
  setup "$EDGE; ES=$st EREACH=app; verify_edge; echo \"edge-rc=\$?\""
  check "verify-edge: nginx answering $st -> 1" "out_has 'edge-rc=1' && out_has 'HTTP $st'"
done
setup "$EDGE; ES=522 EREACH=none; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: a Cloudflare 522 -> 1" 'out_has "edge-rc=1" && out_has "HTTP 522"'
setup "$EDGE; ES=403 EVIA= EREACH=deny; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: a 403 that did not come through Cloudflare -> 1" 'out_has "edge-rc=1" && out_has "not through Cloudflare"'
setup "$EDGE; ES=403 ENOHDR=1 EREACH=deny; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: no response headers -> 1" 'out_has "edge-rc=1" && out_has "no HTTP status line"'
setup "$EDGE; ES=403 ERC=7 EREACH=deny; verify_edge; echo \"edge-rc=\$?\""
check "verify-edge: connection failure -> 1" 'out_has "edge-rc=1" && out_has "curl exit 7"'

# ── the two prefixes added 2026-10-01 (orphan attachments still served) ─────
setup 'PREFIXES="kyc signatures" step_origin >/dev/null 2>&1; effective_ok; echo "eff-rc=$?"'
check "origin: a vhost with only the first two denies (production on 2026-10-01) is reported as missing the other two" \
  'out_has "missing: karigar-invoices purchases" && out_has "eff-rc=1"'
setup 'PREFIXES="kyc signatures" step_origin >/dev/null 2>&1; step_origin'
check "origin: the missing two denies are added next to the existing ones, each exactly once; all four probed" \
  '[ "$RC" = 0 ] && [ "$(grep -c "location ^~ /storage/" "$S/vhost")" = 4 ] && for p in kyc signatures karigar-invoices purchases; do [ "$(grep -c "location ^~ /storage/$p/ {" "$S/vhost")" = 1 ] || exit 1; done && out_has "ORIGIN: /storage/{kyc,signatures,karigar-invoices,purchases}/ denied"'
setup 'for d in kyc/1 signatures karigar-invoices/1 purchases; do mkdir -p "$PROD/storage/app/public/$d"; : > "$PROD/storage/app/public/$d/f.pdf"; done; edge_package'
check "edge: the purge list and the rule cover all four prefixes (4 files x 3 hosts)" \
  '[ "$(wc -l < "$S/work/edge-purge-urls.txt")" = 12 ] && grep -qx "https://www.jewelflows.com/storage/purchases/f.pdf" "$S/work/edge-purge-urls.txt" && out_has "contains \"/storage/karigar-invoices/\"" && out_has "contains \"/storage/purchases/\"" && out_has "http.host in {\"jewelflows.com\" \"www.jewelflows.com\" \"dhiran.jewelflows.com\"}"'

echo "== $PASS passed, $FAILN failed"
[ "$FAILN" = 0 ]
