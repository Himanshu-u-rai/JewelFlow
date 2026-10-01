#!/usr/bin/env bash
# The restore box for real: restore_isolated from
# docs/runbooks/operator-steps-security-batch.sh run as a transient unit under
# this user's systemd manager, with this machine's PostgreSQL binaries. No
# stubs. A throwaway PostgreSQL instance on 127.0.0.1 stands in for production:
# it makes the genuine dump, and it is what the box must not be able to reach.
# Nothing here touches a server.
#
# Two different things are checked, and kept apart:
#   scanner      tampered() refuses a shape before anything runs (advisory)
#   containment  the payload is given to the box unscanned, RUNS, and can do nothing
#
#   bash tests/Runbooks/restore_sandbox_test.sh    exit 0 = every check passed; 77 = cannot run here
set -u
cd "$(dirname "$0")/../.." || exit 2
B=$(ls -d /usr/lib/postgresql/*/bin 2>/dev/null | sort -V | tail -1)
{ [ -x "$B/initdb" ] && systemd-run --user --quiet --wait --pipe --collect -p PrivateNetwork=yes true 2>/dev/null; } \
  || { echo "SKIP: needs PostgreSQL server binaries and a systemd user manager with user namespaces"; exit 77; }

T=$(mktemp -d); PORT=54329; CANARY=$HOME/.jf-restore-canary-$$; ESC=jf-restore-escape-$$
cleanup() { "$B/pg_ctl" -D "$T/src" -s -m immediate stop >/dev/null 2>&1; rm -rf "$T" "$CANARY" "$HOME/.$ESC" "/var/tmp/$ESC" "/tmp/$ESC"; }
trap cleanup EXIT
PASS=0; FAILN=0
check() { if eval "$2"; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAILN=$((FAILN + 1)); echo "FAIL  $1"; fi; }

OPS_LIB=1 source docs/runbooks/operator-steps-security-batch.sh
set +o pipefail
ROLE=jewelflow; COUNTED="shops users"; PROD_PORT=$PORT
SANDBOX_MUST_NOT_SEE="$CANARY /var/lib/postgresql /run/postgresql"
echo "CANARY-$RANDOM$RANDOM" > "$CANARY"

# ── the stand-in for production, and a genuine dump of it ───────────────────
"$B/initdb" -D "$T/src" -U postgres -A trust -E UTF8 --locale=C.UTF-8 >/dev/null
"$B/pg_ctl" -D "$T/src" -s -w -l "$T/src.log" -o "-c listen_addresses=127.0.0.1 -c port=$PORT -c unix_socket_directories=$T" start >/dev/null
SRC() { "$B/psql" -X -q -A -t -v ON_ERROR_STOP=1 -h "$T" -p "$PORT" -U postgres "$@"; }
SRC -d postgres -c "create role jewelflow" -c "create database jewelflow owner jewelflow"
SRC -d jewelflow <<'SQL'
create table public.shops (id bigint primary key, name text);
create table public.users (id bigint primary key, note text);
alter table public.shops owner to jewelflow; alter table public.users owner to jewelflow;
insert into public.shops values (1, E'Alpha \\ Jewellers'), (2, E' \\N leading space, then a backslash: data');
insert into public.users values (1, 'a'), (2, 'b'), (3, E'a COPY line TO PROGRAM in text');
create function public.t() returns trigger language plpgsql as $$ begin return new; end $$;
create trigger trg before insert on public.shops for each row execute function public.t();
SQL
"$B/pg_dump" -h "$T" -p "$PORT" -U postgres jewelflow > "$T/genuine.sql"
(exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; check "setup: the stand-in for production answers on 127.0.0.1:$PORT from outside the box" '[ $? = 0 ]'

# ── genuine restore ─────────────────────────────────────────────────────────
OUT=$(restore_isolated jewelflow < "$T/genuine.sql" 2> "$T/err"); RC=$?
check "genuine: a real pg_dump restores in the box (exit 0)" '[ "$RC" = 0 ]'
check "genuine: the row and trigger counts come back (shops 2, users 3, triggers 1)" \
  '[ "$(printf "%s\n" "$OUT" | tr "\n" ",")" = "count shops 2,count users 3,triggers 1," ]'
check "genuine: the box checked itself before restoring" 'grep -q "^sandbox: uid [0-9]*, network lo only, production paths and port unreachable" "$T/err"'
check "scanner: a genuine pg_dump is not called tampered" '! tampered "$T/genuine.sql"'

# ── scanner: refused before anything runs ───────────────────────────────────
for shape in 'SET SESSION ROLE postgres;' ' \! true' '\! id' 'SET ROLE postgres;' 'RESET ROLE;' "COPY t TO PROGRAM 'id';" 'SET SESSION AUTHORIZATION postgres;' 'CREATE ROLE x SUPERUSER;'; do
  printf 'select 1;\n%s\n' "$shape" > "$T/shape.sql"
  check "scanner: refuses: $shape" 'tampered "$T/shape.sql"'
done

# ── containment: the payload runs in the box, unscanned, and can do nothing ─
cat > "$T/evil.sql" <<SQL
SET SESSION ROLE postgres;
 \\! echo PAYLOAD-RAN >&2
\\! cat $CANARY >&2 2>/dev/null || echo READ-BLOCKED >&2
\\! (echo x > $HOME/.$ESC) 2>/dev/null || echo WRITE-HOME-BLOCKED >&2
\\! (touch /var/tmp/$ESC /tmp/$ESC) 2>/dev/null || echo WRITE-FS-BLOCKED >&2
\\! (exec 3<>/dev/tcp/127.0.0.1/$PORT) 2>/dev/null && echo NET-REACHED >&2 || echo NET-BLOCKED >&2
\\! ls /run/postgresql >/dev/null 2>&1 && echo SOCKET-VISIBLE >&2 || echo SOCKET-HIDDEN >&2
\\! $B/psql -h 127.0.0.1 -p $PORT -U postgres -d jewelflow -c 'drop table public.shops' >/dev/null 2>&1 && echo PROD-CHANGED >&2 || echo PROD-UNREACHABLE >&2
CREATE TABLE public.shops (id int); CREATE TABLE public.users (id int);
COPY (select 1) TO PROGRAM 'touch /var/tmp/$ESC';
SQL
OUT=$(restore_isolated jewelflow < "$T/evil.sql" 2> "$T/err"); RC=$?
check "containment: the payload really ran inside the box (this is not a scanner refusal)" 'grep -q "^PAYLOAD-RAN$" "$T/err"'
check "containment: it could not read a file outside (the canary), and the canary is in no output" \
  'grep -q "^READ-BLOCKED$" "$T/err" && ! grep -qF "$(cat "$CANARY")" "$T/err" && ! grep -qF "$(cat "$CANARY")" <<< "$OUT"'
check "containment: it could not write to the home directory, /tmp or /var/tmp" \
  'grep -q "^WRITE-HOME-BLOCKED$" "$T/err" && grep -q "^WRITE-FS-BLOCKED$" "$T/err" && [ ! -e "$HOME/.$ESC" ] && [ ! -e "/var/tmp/$ESC" ] && [ ! -e "/tmp/$ESC" ]'
check "containment: it had no route to the stand-in for production (TCP), nor to a PostgreSQL socket directory" \
  'grep -q "^NET-BLOCKED$" "$T/err" && grep -q "^SOCKET-HIDDEN$" "$T/err" && grep -q "^PROD-UNREACHABLE$" "$T/err" && ! grep -qE "NET-REACHED|SOCKET-VISIBLE|PROD-CHANGED" "$T/err"'
check "containment: the stand-in for production is untouched (shops still has 2 rows)" '[ "$(SRC -d jewelflow -c "select count(*) from public.shops")" = 2 ]'
check "containment: COPY ... TO PROGRAM ran as the box's user and failed; the restore is reported as failed" '[ "$RC" != 0 ] && grep -q "program .* failed" "$T/err"'

# ── the box refuses to restore when it is not a box ─────────────────────────
OUT=$(SANDBOX_MUST_NOT_SEE="/etc/hostname" restore_isolated jewelflow < "$T/genuine.sql" 2> "$T/err"); RC=$?
check "self-check: a box that can read a path it must not see refuses to restore (exit 97, no counts)" \
  '[ "$RC" = 97 ] && grep -q "^sandbox: can read /etc/hostname$" "$T/err" && [ -z "$OUT" ]'
OUT=$(PROD_PORT=$PORT systemd-run --user --quiet --wait --pipe --collect -E ROLES=jewelflow -E OWNER=jewelflow -E "COUNTED=$COUNTED" -E PROD_PORT=$PORT -E MUST_NOT_SEE=/nonexistent -- /bin/bash -c "$SANDBOX_SH" < "$T/genuine.sql" 2> "$T/err"); RC=$?
check "self-check: the same script without the unit's isolation refuses to restore (it can reach the port)" \
  '[ "$RC" = 97 ] && grep -q "^sandbox: can reach 127.0.0.1:$PORT$" "$T/err" && [ -z "$OUT" ]'

# ── control: what the box is for ────────────────────────────────────────────
# The same payload through a psql that can reach the stand-in, which is how the
# old procedure ran a dump (psql as postgres on the production host). Last,
# because it does the damage.
"$B/psql" -X -q -h "$T" -p "$PORT" -U postgres -d postgres -f "$T/evil.sql" > "$T/out" 2> "$T/err"
check "control: without the box the same payload reads the canary, writes outside and changes the stand-in" \
  'grep -qF "$(cat "$CANARY")" "$T/err" && [ -e "$HOME/.$ESC" ] && [ -e "/tmp/$ESC" ] && grep -q "^PROD-CHANGED$" "$T/err" && [ "$(SRC -d jewelflow -c "select count(*) from pg_class where relname = \$\$shops\$\$ and relkind = \$\$r\$\$")" = 0 ]'

echo "== $PASS passed, $FAILN failed"
[ "$FAILN" = 0 ]
