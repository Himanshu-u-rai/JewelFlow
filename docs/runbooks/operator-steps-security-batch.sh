#!/usr/bin/env bash
# ============================================================================
# Security batch — the steps a human operator runs.
#
# Why a human: the Claude session that prepared this batch works under the
# safety rules in its system prompt ("Action categories → Prohibited"), which
# forbid it — "even when the user explicitly asks … or says they authorize
# it" — from "Modifying system or security settings" (here: nginx access rules,
# permissions on a secrets file, a database role's password) and from entering
# "passwords … into any field". It ran everything else itself: code, deploys,
# and the read-only verification.
#
# ONE sequence, on the VPS, as root, from an interactive terminal:
#
#   operator-steps-security-batch.sh run [from-step]   steps 1-4 in order, gated
#   operator-steps-security-batch.sh verify            read-only re-check of all of them
#   operator-steps-security-batch.sh verify-edge       read-only: the Cloudflare side
#
#   1 env-read  R9     .env dev:dev 640 -> dev:www-data 640: the scheduler's user
#                      (www-data) may read it, not write it; others still nothing.
#   2 rotate    S3-22  new password for role jewelflow. The role is set from a
#                      SCRAM verifier sent on stdin, so the password is never in
#                      a process argument, a server log or a trace. About a
#                      minute of maintenance; the php8.2-fpm reload touches
#                      staging too (checked).
#   3 backup    R9     backup:run as www-data; the archive this run produced is
#                      checked (entries, exclusions, .env equal to the live one)
#                      and its database dump restored into a scratch database
#                      and compared with production; the scratch copy is removed.
#   4 origin    R7/R6  nginx denies /storage/kyc/ and /storage/signatures/ on
#                      production's vhost; verified in the running config and
#                      with harmless probes on all three hosts. Origin only.
#   Then it prints the Cloudflare package (edge rule, purge list, check). The
#   edge stays PARTIAL until that package is applied.
#
# A failed gate stops the run and prints the state it left and the recovery.
# Recovery never removes protection a completed step added: the rollback points
# saved after a step include that step's protection.
# ============================================================================
{ set +x; } 2>/dev/null          # never traced: step 2 holds a secret in memory
set -u -o pipefail
umask 077

BASE=018b3d810e37d534f498033ab582ee41f3197c27  # its phpunit.xml carried production's password
PROD=/var/www/jewelflow
VHOST=/etc/nginx/sites-available/jewelflow
PGLOG=/var/log/postgresql/postgresql-14-main.log
NGINX_ERRLOG=/var/log/nginx/error.log
WORKER=jewelflow-production-ops-alerts
ROLE=jewelflow
WEB=www-data        # the user of the scheduler, PHP-FPM and the worker
HOSTS="jewelflows.com www.jewelflows.com dhiran.jewelflows.com"
PREFIXES="kyc signatures"
COUNTED="shops users customers invoices invoice_items invoice_payments cash_transactions karigar_invoices stock_purchases shop_billing_settings loyalty_transactions report_exports idempotency_keys platform_admins"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
WORK=/root/security-batch/operator-$STAMP
STEP=start

say()  { printf '%s\n' "$*"; }
ok()   { printf 'ok    %s\n' "$*"; }
# stop <what failed> <state left behind> <recovery>
stop() { printf '!!!!! FAILED [%s]: %s\n      STATE:    %s\n      RECOVERY: %s\n' "$STEP" "$1" "$2" "$3"; exit 1; }
sha()  { sha256sum | cut -c1-64; }
# A root-only copy; prints its actual owner and mode (the claim is the stat).
keep() { cp "$1" "$2" && cmp -s "$1" "$2" && [ "$(stat -c '%U:%G %a' "$2")" = "$(id -un):$(id -gn) 600" ] \
           || stop "could not keep a verified copy of $1" "nothing changed by this step" "check $WORK"
         ok "kept $2 ($(stat -c '%U:%G %a' "$2"), sha256 $(sha < "$2" | cut -c1-12))"; }
# The value of KEY in an env file, with no trailing newline (it is hashed).
env_value() { local v; v=$(grep -E "^$1=" "$2" | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'); printf '%s' "$v"; }

# Commands with side effects go through these, so the tests can replace them.
ART()  { ( cd "$PROD" && sudo -u www-data php artisan "$@" ); }
CFG()  { if sudo -u www-data test -r "$PROD/.env"; then ART "$@"; else ( cd "$PROD" && umask 022 && php artisan "$@" ); fi; }
PGSU() { sudo -u postgres psql -X -A -t -q -v ON_ERROR_STOP=1 "$@"; }   # secrets only ever on stdin
code() { curl -sk -o /dev/null -w '%{http_code}' -m 15 --resolve "$1:443:127.0.0.1" "https://$1$2"; }
# Who answered: nginx's own error page has its "<center>nginx" footer; the
# application answers an unmatched /storage/ path itself (404 page).
who()  { curl -sk -m 15 --resolve "$1:443:127.0.0.1" "https://$1$2" | grep -qi '<center>nginx' && echo nginx || echo app; }
cached_password_sha() { ( cd "$PROD" && sudo -u www-data php -r '$c = require "bootstrap/cache/config.php"; echo hash("sha256", (string) $c["database"]["connections"]["pgsql"]["password"]);' ); }

# SCRAM-SHA-256 verifier for the password on stdin (what psql's \password
# computes client-side), so ALTER ROLE never carries the password itself.
scram() { php -r '$p = stream_get_contents(STDIN); $s = random_bytes(16); $i = 4096;
  $k = hash_pbkdf2("sha256", $p, $s, $i, 32, true); $c = hash_hmac("sha256", "Client Key", $k, true);
  echo "SCRAM-SHA-256\$", $i, ":", base64_encode($s), "\$", base64_encode(hash("sha256", $c, true)), ":",
       base64_encode(hash_hmac("sha256", "Server Key", $k, true));'; }
# A fresh connection with the password on stdin; arguments carry no secret.
db_connects() { php -r '$p = stream_get_contents(STDIN);
  try { new PDO("pgsql:host={$argv[1]};port={$argv[2]};dbname={$argv[3]}", $argv[4], $p, [PDO::ATTR_TIMEOUT => 5]); }
  catch (Throwable $e) { fwrite(STDERR, "connection failed: ".$e->getCode()."\n"); exit(1); }' \
  "$(env_value DB_HOST "$PROD/.env")" "$(env_value DB_PORT "$PROD/.env")" "$(env_value DB_DATABASE "$PROD/.env")" "$(env_value DB_USERNAME "$PROD/.env")"; }
set_role() { printf "ALTER ROLE %s PASSWORD '%s';\n" "$ROLE" "$1" | PGSU >/dev/null; }   # $1 = a verifier
counts() { local t; for t in $COUNTED; do printf '%s=%s ' "$t" "$(PGSU -d "$1" -c "select count(*) from $t")"; done; }

# ── 1  .env readable by the scheduler's user ────────────────────────────────
step_env_read() {
  STEP=env-read
  local f=$PROD/.env before now
  before=$(stat -c '%U:%G %a' "$f") || stop "cannot stat $f" "nothing changed" "check the path"
  if ! sudo -u www-data test -r "$f"; then
    chgrp "$WEB" "$f" && chmod 640 "$f" \
      || stop "chgrp/chmod failed" "$f is $(stat -c '%U:%G %a' "$f")" "chgrp www-data $f && chmod 640 $f"
  fi
  now=$(stat -c '%G %a' "$f")
  [ "$now" = "$WEB 640" ] || stop "$f is $(stat -c '%U:%G %a' "$f"), expected group www-data, mode 640" \
    "only group/mode of $f may have changed" "chgrp www-data $f && chmod 640 $f"
  sudo -u www-data test -r "$f" || stop "www-data still cannot read $f" "$f is $(stat -c '%U:%G %a' "$f")" "check the parent directories"
  ! sudo -u www-data test -w "$f" || stop "www-data can WRITE $f" "$f is $(stat -c '%U:%G %a' "$f")" "chmod 640 $f"
  ok "$f is $(stat -c '%U:%G %a' "$f") (was $before): www-data reads, cannot write; others nothing"
}

# ── 2  rotate the exposed database password ─────────────────────────────────
step_rotate() {
  STEP=rotate
  local -; { set +x; } 2>/dev/null            # tracing stays off here whatever the caller set
  local env=$PROD/.env old committed new ver oldver hnew mode content mark fails n
  old=$(env_value DB_PASSWORD "$env")
  committed=$(git -C "$PROD" show "$BASE:phpunit.xml" 2>/dev/null | sed -n 's/.*name="DB_PASSWORD" value="\([^"]*\)".*/\1/p')
  [ -n "$committed" ] || stop "cannot read the committed value from $BASE:phpunit.xml" "nothing changed" "git -C $PROD cat-file -e $BASE"
  if [ "$(printf %s "$old" | sha)" != "$(printf %s "$committed" | sha)" ]; then
    printf %s "$old" | db_connects || stop "the current password does not connect" "nothing changed by this run" "fix $env"
    ok "already rotated: production's password is not the committed one, and it connects"; return 0
  fi
  [ -r "$PGLOG" ] || stop "cannot read $PGLOG (needed to catch consumers of the old password)" "nothing changed" "set PGLOG in this script to the cluster's log"
  keep "$env" "$WORK/env.before-rotate"
  mode=$(stat -c '%U:%G %a' "$env")
  new=$(openssl rand -hex 32) && [ ${#new} = 64 ] || stop "could not generate a password" "nothing changed" "check openssl"
  hnew=$(printf %s "$new" | sha)
  ver=$(printf %s "$new" | scram) && oldver=$(printf %s "$old" | scram) \
    && [[ $ver == SCRAM-SHA-256\$4096:* && $oldver == SCRAM-SHA-256\$4096:* ]] \
    || stop "could not compute SCRAM verifiers" "nothing changed" "check php"

  ART down --retry=30 >/dev/null || stop "artisan down failed" "nothing changed; the site is up" "none needed"
  ok "maintenance on at $(date -u +%H:%M:%SZ)"
  set_role "$ver" || { ART up >/dev/null; stop "ALTER ROLE failed" "role and .env unchanged; maintenance lifted" "none needed"; }
  if ! printf %s "$new" | db_connects; then
    set_role "$oldver" && ART up >/dev/null \
      && stop "a fresh connection with the new password failed" "role set back to the previous password; .env unchanged; maintenance lifted" "none needed; report it"
    stop "a fresh connection with the new password failed, and setting the previous one back failed" \
      "MAINTENANCE ON; the role has a new password nobody knows; .env has the previous one" \
      "sudo -u postgres psql -c '\\password $ROLE' and enter the DB_PASSWORD from $WORK/env.before-rotate; then: sudo -u www-data php artisan up"
  fi
  content=$(NEWPW=$new awk '/^DB_PASSWORD=/ { print "DB_PASSWORD=" ENVIRON["NEWPW"]; n++; next } { print } END { exit n == 1 ? 0 : 3 }' "$env") \
    || { set_role "$oldver"; ART up >/dev/null; stop "$env has no single DB_PASSWORD line" "role set back; .env unchanged; maintenance lifted" "fix $env"; }
  printf '%s\n' "$content" > "$env"   # rewrites in place: owner, group and mode stay
  content=
  if [ "$(env_value DB_PASSWORD "$env" | sha)" != "$hnew" ] || [ "$(stat -c '%U:%G %a' "$env")" != "$mode" ]; then
    cat "$WORK/env.before-rotate" > "$env"; set_role "$oldver"; ART up >/dev/null
    stop "writing .env did not produce the expected file" "role and .env set back; maintenance lifted" "none needed; report it"
  fi
  ok "role $ROLE and $env carry the new password ($mode kept); a fresh connection with it works"

  local rec="cd $PROD && php artisan config:cache && systemctl reload php8.2-fpm && systemctl restart $WORKER && sudo -u www-data php artisan up"
  local st="MAINTENANCE ON; role and .env carry the new password"
  CFG config:cache >/dev/null || stop "config:cache failed" "$st; the config cache is missing or stale" "$rec"
  [ "$(cached_password_sha)" = "$hnew" ] || stop "the config cache does not carry the new password" "$st" "$rec"
  systemctl reload php8.2-fpm && systemctl is-active --quiet php8.2-fpm \
    || stop "php8.2-fpm reload failed (it serves staging too)" "$st; config cache rebuilt" "systemctl restart php8.2-fpm; check both sites; then sudo -u www-data php artisan up"
  systemctl restart "$WORKER" || stop "restarting $WORKER failed" "$st; FPM reloaded" "systemctl restart $WORKER; then sudo -u www-data php artisan up"
  n=0; while [ "$n" -lt 10 ]; do systemctl is-active --quiet "$WORKER" && break; sleep 1; n=$((n + 1)); done
  systemctl is-active --quiet "$WORKER" || stop "$WORKER is not active after its restart" "$st; FPM reloaded" "journalctl -u $WORKER -n 30; then sudo -u www-data php artisan up"
  mark=$(stat -c %s "$PGLOG" 2>/dev/null || echo 0)
  ART migrate:status >/dev/null 2>&1 || stop "a fresh CLI process cannot use the database" "$st; FPM reloaded; worker restarted" "check the config cache, then sudo -u www-data php artisan up"
  ART up >/dev/null || stop "artisan up failed" "MAINTENANCE ON; the new password works from the CLI" "sudo -u www-data php artisan up"
  ok "maintenance off at $(date -u +%H:%M:%SZ)"

  # Through FPM: /login reads and writes the database session store.
  if [ "$(code jewelflows.com /login)" != 200 ] || [ "$(code jewelflows.com /health)" != 200 ]; then
    ART down --retry=30 >/dev/null
    stop "after the rotation /login or /health did not answer 200" \
      "MAINTENANCE ON again; the new password works from the CLI; FPM may still hold the old configuration" \
      "systemctl restart php8.2-fpm, recheck /login, then sudo -u www-data php artisan up. Last resort only: cat $WORK/env.before-rotate > $env, then set the role's password back to that value with sudo -u postgres psql -c '\\password $ROLE', then config:cache, reload FPM, up"
  fi
  [ "$(code staging.jewelflows.com /health)" = 200 ] || stop "staging /health is not 200 after the FPM reload" \
    "production rotated and up" "systemctl status php8.2-fpm; check staging"
  sleep 5   # let the worker and the scheduler open fresh connections
  fails=$(tail -c +"$((mark + 1))" "$PGLOG" 2>/dev/null | grep -c "password authentication failed for user \"$ROLE\"")
  [ "$fails" = 0 ] || stop "$fails failed login(s) for role $ROLE after the switch" \
    "production rotated and up; a consumer still uses the old password" "grep 'authentication failed' $PGLOG; update that consumer, not the role"
  new=; ver=; oldver=; old=; committed=
  ok "ROTATED: config cache, php8.2-fpm, $WORKER and a fresh CLI use the new password; /login and /health 200 (production), /health 200 (staging); no failed login for $ROLE since the switch"
}

# ── 3  a backup by the scheduler's user, verified ───────────────────────────
step_backup() {
  STEP=backup
  local dir=$PROD/storage/app/private/JewelFlows start zip rel entries before after scratch dump t c b a want
  sudo -u www-data test -r "$PROD/.env" || stop "www-data cannot read .env" "no backup taken" "run step env-read"
  start=$(date +%s); before=$(counts jewelflow)
  ART backup:run || stop "backup:run failed as www-data (the scheduler's user)" "no new archive; production unchanged" "read the output above"
  after=$(counts jewelflow)
  zip=$(find "$dir" -maxdepth 1 -type f -name '*.zip' -newermt "@$start")
  [ -n "$zip" ] && [ "$(printf '%s\n' "$zip" | wc -l)" = 1 ] || stop "not exactly one new archive in $dir" "a backup:run completed" "ls -la $dir"
  ok "archive $zip ($(du -h "$zip" | cut -f1)): file $(stat -c '%U:%G %a' "$zip") in directory $(stat -c '%U:%G %a' "$dir")"
  [ "$(stat -c '%U %a' "$dir")" = "$WEB 700" ] || stop "$dir is $(stat -c '%U:%G %a' "$dir"), not www-data 700" "the archive exists" "chmod 700 $dir"
  unzip -tq "$zip" >/dev/null || stop "the archive fails its integrity test" "the archive exists" "run backup:run again"
  rel="${PROD#/}/"
  entries=$(unzip -Z1 "$zip" | sed "s#^$rel##")
  for want in .env artisan composer.lock app/ bootstrap/app.php config/ database/migrations/ lang/ public/ resources/ routes/ storage/app/; do
    printf '%s\n' "$entries" | grep -q "^$want" || stop "the archive lacks $want" "the archive exists" "check config/backup.php and App\\Support\\BackupScope"
  done
  printf '%s\n' "$entries" | grep -qE '^db-dumps/[^/]+\.sql$' || stop "the archive has no database dump" "the archive exists" "check the backup output"
  # Files only: an empty directory entry (spatie archives storage/app/backup-temp/
  # empty, its temp/ subdirectory excluded) carries no data.
  if printf '%s\n' "$entries" | grep -v '/$' | grep -qE '^(\.git/|\.claude/|vendor/|node_modules/|bootstrap/cache/|storage/logs/|storage/framework/|storage/app/private/JewelFlows?/|storage/app/backup-temp/|tests/|docs/|output/|\.mcp\.json|\.env\.)'; then
    stop "the archive contains an excluded path: $(printf '%s\n' "$entries" | grep -v '/$' | grep -E '^(\.git/|\.claude/|vendor/|node_modules/|bootstrap/cache/|storage/logs/|storage/framework/|storage/app/private/JewelFlows?/|storage/app/backup-temp/|tests/|docs/|output/|\.mcp\.json|\.env\.)' | head -3 | tr '\n' ' ')" \
      "the archive exists" "check App\\Support\\BackupScope"
  fi
  [ "$(unzip -p "$zip" "$rel.env" | sha)" = "$(sha < "$PROD/.env")" ] || stop "the archived .env differs from the live one" "the archive exists" "run backup:run again after step rotate"
  ok "entries: required paths present; excluded paths absent; the archived .env equals the live one (APP_KEY and the new DB password)"

  scratch="jf_backup_verify_$(date -u +%Y%m%d%H%M%S)"
  case "$scratch" in jf_backup_verify_[0-9]*) ;; *) stop "bad scratch name" "the archive exists" "-" ;; esac
  dump=$(printf '%s\n' "$entries" | grep -E '^db-dumps/[^/]+\.sql$' | head -1)
  mkdir -m 700 "$WORK/restore" && unzip -p "$zip" "$dump" > "$WORK/restore/dump.sql" \
    || stop "could not extract the dump" "the archive exists" "check disk space"
  sudo -u postgres createdb "$scratch" || { rm -f "$WORK/restore/dump.sql"; stop "createdb $scratch failed" "the archive exists" "-"; }
  if ! PGSU -d "$scratch" < "$WORK/restore/dump.sql" >/dev/null; then
    sudo -u postgres dropdb "$scratch"; rm -f "$WORK/restore/dump.sql"
    stop "the dump does not restore into a scratch database" "the archive exists; scratch database dropped" "inspect the dump"
  fi
  for t in $COUNTED; do
    c=$(PGSU -d "$scratch" -c "select count(*) from $t"); b=$(printf '%s' "$before" | grep -oE "(^| )$t=[0-9]+" | cut -d= -f2); a=$(printf '%s' "$after" | grep -oE "(^| )$t=[0-9]+" | cut -d= -f2)
    { [ "$c" -ge "$(( b < a ? b : a ))" ] && [ "$c" -le "$(( b > a ? b : a ))" ]; } \
      || { sudo -u postgres dropdb "$scratch"; rm -f "$WORK/restore/dump.sql"; stop "$t: $c rows restored, production had $b..$a" "the archive exists; scratch dropped" "inspect the dump"; }
  done
  c=$(PGSU -d "$scratch" -c "select count(*) from pg_trigger where not tgisinternal"); b=$(PGSU -d jewelflow -c "select count(*) from pg_trigger where not tgisinternal")
  sudo -u postgres dropdb "$scratch"; rm -f "$WORK/restore/dump.sql"; rmdir "$WORK/restore"
  [ "$c" = "$b" ] || stop "restored $c triggers, production has $b" "the archive exists; scratch dropped" "inspect the dump"
  ! PGSU -c "select 1 from pg_database where datname = '$scratch'" | grep -q 1 && [ ! -e "$WORK/restore" ] \
    || stop "the scratch copy was not removed" "the archive exists" "sudo -u postgres dropdb $scratch; rm -rf $WORK/restore"
  ok "the dump restored into a scratch database: row counts of $(printf '%s' "$COUNTED" | wc -w) tables match production; $c triggers; scratch database and extracted dump removed"
}

# ── 4  origin: nginx denies the two private prefixes ────────────────────────
# Exit 0 when every :443 server block that serves jewelflows.com denies every
# prefix in the RUNNING configuration (nginx -T), not merely in the file.
effective_ok() {
  nginx -T 2>/dev/null | awk -v prefixes="$PREFIXES" '
    BEGIN { n = split(prefixes, want, " ") }
    function flush(  i) { if (in443 && ours) { blocks++; for (i = 1; i <= n; i++) if (!(want[i] in got)) miss = miss " " want[i] } }
    {
      line = $0; sub(/[[:space:]]*#.*/, "", line)
      if (depth == 0 && line ~ /^[[:space:]]*server[[:space:]]*\{/) { in443 = 0; ours = 0; loc = ""; split("", got) }
      if (depth >= 1 && line ~ /listen[[:space:]]+443/) in443 = 1
      if (depth >= 1 && line ~ /server_name/ && line ~ / jewelflows\.com/) ours = 1
      if (loc != "" && depth == locdepth && line ~ /(^|[[:space:];{])deny[[:space:]]+all;/) got[loc] = 1
      for (i = 1; i <= n; i++) if (index(line, "location ^~ /storage/" want[i] "/ {")) {
        loc = want[i]; locdepth = depth + 1; if (line ~ /\{[[:space:]]*deny[[:space:]]+all;/) got[loc] = 1 }
      tmp = line; o = gsub(/\{/, "", tmp); c = gsub(/\}/, "", tmp); was = depth; depth += o - c
      if (loc != "" && depth < locdepth) loc = ""
      if (was > 0 && depth == 0) flush()
    }
    END { if (!blocks) { print "no :443 server block for jewelflows.com"; exit 2 }
          if (miss != "") { print "missing:" miss; exit 1 } }'
}
probe_origin() {   # prints each probe; exit 0 only if every deny and control is as expected
  local h p u r failed=0
  for h in $HOSTS; do
    for p in $PREFIXES; do
      u="/storage/$p/probe-$STAMP-$RANDOM.jpg"; r="$(code "$h" "$u") $(who "$h" "$u")"
      say "      $h $u -> $r (expect 403 nginx)"; [ "$r" = "403 nginx" ] || failed=1
    done
    u="/storage/probe-$STAMP-$RANDOM.jpg"; r="$(code "$h" "$u") $(who "$h" "$u")"
    say "      $h $u -> $r (control: expect the application)"; [ "${r##* }" = app ] || failed=1
    r=$(code "$h" /login); say "      $h /login -> $r (expect 200)"; [ "$r" = 200 ] || failed=1
  done
  return "$failed"
}
step_origin() {
  STEP=origin
  local before=$WORK/jewelflow.vhost.before-origin protected=$WORK/jewelflow.vhost.protected new missing p n
  if missing=$(effective_ok); then
    ok "the running nginx configuration already denies /storage/{${PREFIXES// /,}}/ for jewelflows.com"
  else
    say "the running configuration: ${missing:-unreadable}"
    keep "$VHOST" "$before"
    new=$(cat "$VHOST")
    for p in $PREFIXES; do
      grep -qF "location ^~ /storage/$p/ {" <<< "$new" && continue
      new=$(printf '%s\n' "$new" | awk -v p="$p" '
        /listen[[:space:]]+443/ { in443 = 1 }
        in443 && !done && /^[[:space:]]*location \/ \{/ {
          print "    # never serve /storage/" p "/ from the public tree (security batch, origin containment)"
          print "    location ^~ /storage/" p "/ {"; print "        deny all;"; print "    }"; print ""; done = 1 }
        { print }')
    done
    n=0; for p in $PREFIXES; do grep -qF "location ^~ /storage/$p/ {" <<< "$new" && n=$((n + 1)); done
    [ "$n" = "$(wc -w <<< "$PREFIXES")" ] || stop "the insertion point was not found" "nothing changed" "edit $VHOST by hand"
    diff -u "$before" <(printf '%s\n' "$new")
    printf '%s\n' "$new" > "$VHOST"
    if ! nginx -t; then
      cat "$before" > "$VHOST"
      stop "nginx -t rejected the change" "file restored; nginx never reloaded; the origin is as before (not yet protected)" "read the nginx -t output and re-run: run origin"
    fi
    if ! { systemctl reload nginx && systemctl is-active --quiet nginx; }; then
      stop "nginx reload failed" "the file has the denies and passes nginx -t; nginx did not reload" "systemctl status nginx; systemctl reload nginx; then run origin (it verifies)"
    fi
    missing=$(effective_ok) || stop "nginx reloaded but the running configuration lacks: $missing" "the file has the denies" "nginx -T | grep -n 'storage/'; systemctl reload nginx"
  fi
  local mark; mark=$(stat -c %s "$NGINX_ERRLOG" 2>/dev/null || echo 0)
  say "   probes (nothing real is fetched; every path is a random name):"
  probe_origin || stop "a probe or control did not answer as expected (above)" \
    "the denies are in place and stay in place; nothing was rolled back" \
    "read the probe lines; do not remove the denies to recover — they match only /storage/kyc/ and /storage/signatures/"
  n=$(tail -c +"$((mark + 1))" "$NGINX_ERRLOG" 2>/dev/null | grep -c "access forbidden by rule.*probe-$STAMP")
  [ "$n" -ge "$(( $(wc -w <<< "$HOSTS") * $(wc -w <<< "$PREFIXES") ))" ] || stop "nginx logged $n denied probe(s) in $NGINX_ERRLOG" \
    "the denies answer 403 but the log does not show nginx refusing them" "grep 'access forbidden' $NGINX_ERRLOG"
  keep "$VHOST" "$protected"
  ok "ORIGIN: /storage/kyc/ and /storage/signatures/ denied on $HOSTS (running config, 403 from nginx, $n refusals logged; /login 200; unmatched /storage/ still reaches the application)"
  say "      rollback point that KEEPS the denies: cat $protected > $VHOST && nginx -t && systemctl reload nginx"
  say "      never restore ${before##*/} or an older copy: it serves those prefixes again"
}

# ── the Cloudflare package (the edge is not reachable from here) ─────────────
edge_package() {
  local list=$WORK/edge-purge-urls.txt h f
  : > "$list"
  for f in $(cd "$PROD/storage/app/public" && find kyc signatures -type f 2>/dev/null | sort); do
    for h in $HOSTS; do printf 'https://%s/storage/%s\n' "$h" "$f" >> "$list"; done
  done
  say ""
  say "CLOUDFLARE (zone jewelflows.com). The edge is PARTIAL until 1 and 2 are done."
  say " 1. Security > WAF > Custom rules > Create rule. Name: private storage. Edit expression:"
  say '      (http.host in {"jewelflows.com" "www.jewelflows.com" "dhiran.jewelflows.com"} and (lower(url_decode(http.request.uri.path)) contains "/storage/kyc/" or lower(url_decode(http.request.uri.path)) contains "/storage/signatures/"))'
  say "    (If the editor rejects lower()/url_decode(): http.request.uri.path contains \"/storage/kyc/\" or ... contains \"/storage/signatures/\""
  say "     is enough — with the origin deny in place an encoded variant reaches nginx and is refused there.)"
  say "    Action: Block. Deploy. (It runs before the cache, so cached copies stop being served at once.)"
  say " 2. Caching > Configuration > Purge cache > Custom purge > URL: the $(wc -l < "$list") URLs in $list"
  say "    (root-only; they name real files: paste them, do not open them)."
  say " 3. Check: $0 verify-edge   (random paths only; expects Cloudflare's block page on every host)"
}
verify_edge() {
  local h p u hdr body fails=0 origin=0
  for h in $HOSTS; do for p in $PREFIXES; do
    u="https://$h/storage/$p/probe-$(date -u +%s)-$RANDOM.jpg"
    body=$(curl -s -m 20 -D "$WORK/edge.hdr" "$u" 2>/dev/null); hdr=$(tr -d '\r' < "$WORK/edge.hdr" 2>/dev/null); rm -f "$WORK/edge.hdr"
    if grep -qi '^server: cloudflare' <<< "$hdr" && grep -qiE 'you have been blocked|error code: 1020|Cloudflare Ray ID' <<< "$body" && ! grep -qi '<center>nginx' <<< "$body"; then
      say "   $h /storage/$p/<random> -> blocked at the edge"
    elif grep -qi '<center>nginx' <<< "$body"; then say "   $h /storage/$p/<random> -> 403 from the origin through the edge (edge rule not active)"; origin=1
    else say "   $h /storage/$p/<random> -> $(head -1 <<< "$hdr") (NOT blocked)"; fails=1; fi
  done; done
  [ "$fails" = 0 ] && [ "$origin" = 0 ] && { say "EDGE: blocked on every host and prefix (confirm the purge completed in Cloudflare)"; return 0; }
  say "EDGE: PARTIAL"; return 3
}

verify_all() {
  local rc=0 f=$PROD/.env committed
  STEP=verify
  say "== 1 env-read: $(stat -c '%U:%G %a' "$f"); www-data reads: $(sudo -u www-data test -r "$f" && echo yes || echo NO); www-data writes: $(sudo -u www-data test -w "$f" && echo YES || echo no)"
  [ "$(stat -c '%G %a' "$f")" = "$WEB 640" ] || rc=1
  committed=$(git -C "$PROD" show "$BASE:phpunit.xml" 2>/dev/null | sed -n 's/.*name="DB_PASSWORD" value="\([^"]*\)".*/\1/p')
  if [ -n "$committed" ] && [ "$(env_value DB_PASSWORD "$f" | sha)" != "$(printf %s "$committed" | sha)" ] \
     && [ "$(cached_password_sha)" = "$(env_value DB_PASSWORD "$f" | sha)" ] && env_value DB_PASSWORD "$f" | tr -d '\n' | db_connects; then
    say "== 2 rotate: the password is not the committed one; the config cache carries it; a fresh connection works"
  else say "== 2 rotate: NOT DONE or not consistent"; rc=1; fi
  committed=
  say "== 3 backup: newest $(ls -t "$PROD/storage/app/private/JewelFlows/"*.zip 2>/dev/null | head -1 | xargs -r -I{} sh -c 'basename {}; stat -c " %y" {}' | tr '\n' ' '); directory $(stat -c '%U:%G %a' "$PROD/storage/app/private/JewelFlows")"
  say "      scheduler: $(grep -hE 'www-data .*/var/www/jewelflow/artisan schedule:run' /etc/cron.d/* 2>/dev/null | head -1 | cut -c1-80)"
  say "      schedule: $(ART schedule:list 2>/dev/null | grep -oE '0 +0 \* \* \* +php artisan backup:run' | head -1)"
  if missing=$(effective_ok); then say "== 4 origin: running config denies /storage/{${PREFIXES// /,}}/"; probe_origin || rc=1
  else say "== 4 origin: NOT DONE ($missing)"; rc=1; fi
  say "== edge:"; verify_edge || true
  return "$rc"
}

[ "${OPS_LIB:-}" = 1 ] && return 0 2>/dev/null   # sourced by the tests: functions only
[ "$(id -u)" = 0 ] || { echo "REFUSED: run as root."; exit 64; }
mkdir -p -m 700 /root/security-batch && mkdir -m 700 "$WORK" || { echo "cannot create $WORK"; exit 1; }
case "${1:-}" in
  run)
    [ -t 0 ] || { echo "REFUSED: run this from an interactive terminal."; exit 64; }
    exec > >(tee -a "$WORK/run.log") 2>&1
    STEPS="env-read rotate backup origin"; [ -n "${2:-}" ] && STEPS=${STEPS#*"${2}"} && STEPS="$2$STEPS"
    say "security batch operator run $STAMP (UTC); steps: $STEPS; evidence: $WORK"
    read -r -p "Run these steps? 'rotate' puts production in maintenance for about a minute and reloads php8.2-fpm (staging too). Type YES: " a
    [ "$a" = YES ] || { say "Stopped. Nothing changed."; exit 1; }
    for s in $STEPS; do case "$s" in
      env-read) step_env_read ;; rotate) step_rotate ;; backup) step_backup ;; origin) step_origin ;;
      *) stop "unknown step $s" "nothing further changed" "use: env-read rotate backup origin" ;;
    esac; done
    edge_package
    say "ALL STEPS PASSED ($STEPS). Edge: PARTIAL until the Cloudflare package above is applied." ;;
  verify)      verify_all ;;
  verify-edge) verify_edge ;;
  *) sed -n '2,38p' "$0"; exit 64 ;;
esac
