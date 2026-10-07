#!/usr/bin/env bash
# ============================================================================
# Rehearsal of deploy-takeover-production.sh, byte for byte as it would run on
# production, against an ISOLATED DISPOSABLE COPY. Runs on the VPS as root:
#
#   rehearse-release-tooling.sh <from-sha> <target-sha> <assets tarball sha256>
#
# It never points the release script at live production. It builds a copy and
# starts ONE transient unit in which the production paths, names and hosts all
# resolve to that copy:
#
#   /var/www            a directory of this rehearsal (a clone of the repository at the
#                       deployed commit, the same vendor directory, the same built assets,
#                       a GENERATED .env with no real credential, empty storage)
#   /root, /etc/cron.d, /home     directories of this rehearsal
#   /run                an empty memory filesystem: the host's service manager, PostgreSQL
#                       socket and php-fpm socket do not exist in the unit
#   processes           a process namespace of its own: the host's processes (the real queue
#                       worker, php-fpm, PostgreSQL, cron) cannot be seen or signalled
#   network             none (loopback only): jewelflows.com, www and dhiran resolve, via
#                       curl --resolve, to an nginx started inside the unit from the real
#                       site file with a throwaway certificate, and a php-fpm of its own
#   database            PostgreSQL 14 on a memory filesystem, built by the application's
#                       own migrations at the deployed commit, with synthetic shops only
#   the rest of the filesystem    read-only; /var/lib/postgresql, /etc/letsencrypt,
#                       /var/backups, /var/log, /etc/ssh inaccessible
#
# Production's identity guards are NOT loosened: the release script is run unmodified
# and passes them because the copy is production-shaped (APP_ENV=production, the
# production URL, a database named jewelflow, the tree owned by dev). No production
# data, file, secret or service is in the unit. Before anything runs, the driver proves
# it is inside the copy (per-run sentinels in /var/www, /root and /etc/cron.d; no host
# process visible; no host service manager, database or php-fpm socket; loopback only)
# and aborts if not.
#
# Stand-ins, which are the limits of this rehearsal:
#   systemctl    a shim: the queue worker is a real `artisan queue:work` process it starts
#                and stops; php8.2-fpm and nginx report a fixed start time
#   systemd-run  a shim: the script's isolated restore runs as an unprivileged user inside
#                this unit instead of in a transient unit of its own (the real one ran in
#                the production preflights)
#   date         only the India-time reading of the scheduler-window guard can be pinned,
#                so the rehearsal does not depend on the hour it is run
#   TAKEOVER_RELEASE_APPROVED / TAKEOVER_PRODUCTION_CHECK are set here, inside the copy;
#                that approves nothing on production
# ============================================================================
set -uo pipefail
FROM=${1:?from-sha}; TARGET=${2:?target-sha}; ASSETS_SHA=${3:?assets tarball sha256}
[ "$(id -u)" = 0 ] || { echo "run as root"; exit 64; }
EVID=/root/takeover-production; IN=$EVID/incoming
SCRIPT=$IN/deploy-takeover-production.sh
BUNDLE=$IN/takeover-$TARGET.bundle
ASSETS=$(ls /root/takeover-staging/incoming/takeover-assets-*.tar.gz | tail -1)
for f in "$SCRIPT" "$BUNDLE" "$ASSETS"; do [ -f "$f" ] || { echo "missing $f"; exit 2; }; done
OUT=$EVID/tooling-rehearsal-$(date -u +%Y%m%dT%H%M%SZ); mkdir -p "$OUT"; chmod 700 "$OUT"
STAGE=$(mktemp -d /var/tmp/takeover-tooling.XXXXXX)
trap 'rm -rf "$STAGE"' EXIT
TOKEN=$(cat /proc/sys/kernel/random/uuid)
mkdir -p "$STAGE"/{www,root/takeover-production/incoming,crond,home/dev,in,bin}
chmod 755 "$STAGE" "$STAGE"/{www,home,in,bin}; chown dev:dev "$STAGE/home/dev"; chmod 700 "$STAGE/root"
touch "$STAGE/www/.rehearsal-$TOKEN" "$STAGE/root/.rehearsal-$TOKEN" "$STAGE/crond/.rehearsal-$TOKEN"

# Read-only copies of what is not secret: the repository's objects, the dependency and
# asset directories, the web-server site file (certificate paths replaced), the cron line.
# No .env, no storage, no dump, no certificate, no log.
# (as the repository's owner: git refuses to read another user's repository, and that is not relaxed here)
install -d -o dev -g dev "$STAGE/in/production.git" && sudo -u dev git clone -q --no-hardlinks --no-checkout /var/www/jewelflow "$STAGE/in/production.git" || exit 2
git clone -q --no-hardlinks --no-checkout /var/www/jewelflow-staging "$STAGE/in/staging.git" || exit 2
STAGING_HEAD=$(git -C /var/www/jewelflow-staging rev-parse HEAD)
cp -a /var/www/jewelflow/vendor "$STAGE/in/vendor"
cp -a /var/www/jewelflow/public/build "$STAGE/in/build"
sed -E 's#ssl_certificate /etc/letsencrypt[^;]*;#ssl_certificate /mnt/tls/cert.pem;#; s#ssl_certificate_key /etc/letsencrypt[^;]*;#ssl_certificate_key /mnt/tls/key.pem;#' /etc/nginx/sites-available/jewelflow > "$STAGE/in/nginx-site"
! grep -q letsencrypt "$STAGE/in/nginx-site" || { echo "the site file still names a real certificate"; exit 2; }
install -m 644 /etc/cron.d/jewelflow-scheduler "$STAGE/crond/jewelflow-scheduler"
install -m 700 "$SCRIPT" "$STAGE/root/takeover-production/incoming/deploy-takeover-production.sh"
install -m 600 "$BUNDLE" "$STAGE/root/takeover-production/incoming/"
install -m 600 "$ASSETS" "$STAGE/root/takeover-production/incoming/assets.tar.gz"
chown -R root:root "$STAGE/in"; chmod -R a+rX "$STAGE/in"; chown -R dev:dev "$STAGE/in/production.git"
# The application's database role is given the same standing as production's (superuser or not).
APP_SUPER=$(sudo -u postgres psql -X -A -t -d postgres -c "select bool_or(r.rolsuper) from pg_database d join pg_roles r on r.oid = d.datdba where d.datname = 'jewelflow'")

cat > "$STAGE/bin/systemctl" <<'SH'
#!/bin/bash
# Stand-in for the service manager inside the rehearsal unit (the host's is not reachable).
W=jewelflow-production-ops-alerts
running() { pgrep -u www-data -f '/var/www/jewelflow/artisan queue:work' >/dev/null; }
case "$1" in
  start) [ "$2" = "$W" ] || exit 5
    # (no sudo here: its pseudo-terminal would take the worker down with whatever started it)
    running || ( cd / && nohup setsid setpriv --reuid=www-data --regid=www-data --init-groups /usr/bin/php /var/www/jewelflow/artisan queue:work database --queue=ops-alerts --sleep=3 --tries=3 --backoff=30 --timeout=60 --max-time=3600 >> /mnt/worker.log 2>&1 < /dev/null & )
    sleep 1; exit 0 ;;
  stop) [ "$2" = "$W" ] || exit 5
    pkill -u www-data -f '/var/www/jewelflow/artisan queue:work'; for i in $(seq 1 50); do running || break; sleep 0.2; done; exit 0 ;;
  is-active) if [ "$2" = "$W" ]; then running && { echo active; exit 0; } || { echo inactive; exit 3; }; fi; echo active; exit 0 ;;
  is-failed) echo inactive; exit 1 ;;
  show) cat "/mnt/state/${!#}.started" 2>/dev/null; exit 0 ;;
  *) echo "systemctl stand-in: unsupported: $*" >&2; exit 64 ;;
esac
SH
cat > "$STAGE/bin/systemd-run" <<'SH'
#!/bin/bash
# Stand-in: run the command as an unprivileged user inside this unit, with the -E variables.
envs=()
while [ $# -gt 0 ]; do
  case "$1" in
    -E) envs+=("$2"); shift 2 ;;
    -p) shift 2 ;;
    --) shift; break ;;
    *) shift ;;
  esac
done
# In a mount namespace of its own where the copy's tree, /root and the database socket are masked,
# as the real transient unit masks production's.
exec unshare --mount -- /bin/bash -c 'for p in /var/www /root /run/postgresql; do mount -t tmpfs -o mode=000,size=4k tmpfs "$p" || exit 98; done; exec setpriv --reuid=nobody --regid=nogroup --clear-groups env -i PATH=/usr/bin:/bin "$@"' restore "${envs[@]}" "$@"
SH
cat > "$STAGE/bin/date" <<'SH'
#!/bin/bash
# Stand-in for ONE reading: India time as the scheduler-window guard asks for it.
if [ -n "${REHEARSAL_IST:-}" ] && [ "${TZ:-}" = Asia/Kolkata ] && [ "$*" = "+%H %M" ]; then echo "$REHEARSAL_IST"; else exec /usr/bin/date "$@"; fi
SH
chmod 755 "$STAGE"/bin/*

cat > "$STAGE/driver.sh" <<'DRIVER'
set -uo pipefail
die() { echo "REHEARSAL ABORTED: $*"; exit 90; }
say() { echo; echo "===== $* ====="; }
# ── prove this is the disposable copy before anything else ──────────────────
[ -e "/var/www/.rehearsal-$TOKEN" ] && [ -e "/root/.rehearsal-$TOKEN" ] && [ -e "/etc/cron.d/.rehearsal-$TOKEN" ] || die "the rehearsal's own directories are not mounted over /var/www, /root and /etc/cron.d"
[ -z "$(ls -A /var/www | grep -v '^\.rehearsal-')" ] || die "/var/www is not the empty rehearsal directory"
[ ! -e /run/systemd/private ] && [ ! -e /run/dbus ] && [ ! -e /run/postgresql ] && [ ! -e /run/php ] && [ "$(ls -A /run | tr '\n' ' ')" = "systemd " ] || die "the host's service manager, database socket or php-fpm socket is visible"
[ "$(awk 'NR > 2 { sub(/:.*/, ""); gsub(/[[:space:]]/, ""); printf "%s ", $0 }' /proc/net/dev)" = "lo " ] || die "there is a network interface besides loopback"
# (root can list a masked directory; what matters is that there is nothing in it)
for p in /var/lib/postgresql /etc/letsencrypt /var/backups /var/log /etc/ssh; do [ -z "$(ls -A "$p" 2>/dev/null)" ] || die "live data, certificates, backups, logs or keys are visible under $p"; done
# Not one process of the host may be visible: the release script stops, counts and signals processes by name.
[ "$(ps -e -o comm= | grep -cE '^(systemd|sshd|postgres|nginx|php-fpm8\.2|cron|php)$')" = 0 ] && [ "$(ps -e -o pid= | wc -l)" -lt 12 ] || die "processes of the host are visible: $(ps -e -o comm= | sort -u | head -8 | tr '\n' ' ')"
if (exec 3<>/dev/tcp/127.0.0.1/5432) 2>/dev/null || (exec 3<>/dev/tcp/127.0.0.1/443) 2>/dev/null; then die "something already answers on the database or https port"; fi
export PATH=/stage/bin:$PATH
[ "$(command -v systemctl)" = /stage/bin/systemctl ] && [ "$(command -v systemd-run)" = /stage/bin/systemd-run ] || die "the stand-ins are not first in PATH"
echo "isolation proven: per-run sentinels present; /var/www was empty; own process namespace ($(ps -e -o pid= | wc -l) processes visible, none of the host's); no host service manager, database or php-fpm socket; loopback only; live data, certificates and backups not visible"

D=/var/www/jewelflow; B=/usr/lib/postgresql/14/bin
S=/root/takeover-production/incoming/deploy-takeover-production.sh
ARGS="$FROM $TARGET /root/takeover-production/incoming/takeover-$TARGET.bundle /root/takeover-production/incoming/assets.tar.gz $ASSETS_SHA"
MIG=2026_10_05_000001_create_product_promotion_tables
ART() { ( cd "$D" && sudo -u www-data php artisan "$@" ); }
PS() { sudo -u postgres psql -X -A -t -q -v ON_ERROR_STOP=1 -d "${PGDB:-jewelflow}" -c "$1"; }
export REHEARSAL_IST="22 30"

# ── the copy: database, tree, web server ────────────────────────────────────
mkdir -p /run/postgresql /run/php /mnt/pgdata /mnt/tls /mnt/nginx /mnt/state /mnt/pristine
chown postgres:www-data /run/postgresql; chmod 2750 /run/postgresql; chown postgres /mnt/pgdata; chown www-data /mnt/nginx
sudo -u postgres "$B/initdb" -D /mnt/pgdata -A trust -E UTF8 --locale=C.UTF-8 >/dev/null 2>&1 || die "initdb"
sudo -u postgres "$B/pg_ctl" -D /mnt/pgdata -s -w -l /mnt/pg.log -o "-c listen_addresses='' -c unix_socket_directories=/run/postgresql -c fsync=off -c synchronous_commit=off -c full_page_writes=off" start >/dev/null 2>&1 || die "PostgreSQL did not start"
PGDB=postgres PS "create role jewelflow_app login $([ "$APP_SUPER" = t ] && echo superuser || echo nosuperuser)" && PGDB=postgres PS "create database jewelflow owner jewelflow_app" || die "database"
install -d -o dev -g dev "$D"
sudo -u dev -H git clone -q /stage/in/production.git "$D" 2>/dev/null && sudo -u dev -H git -C "$D" checkout -q --detach "$FROM" && sudo -u dev -H git -C "$D" remote remove origin || die "clone of the deployed commit"
cp -a /stage/in/vendor "$D/vendor" && cp -a /stage/in/build "$D/public/build" && chown -R dev:dev "$D/vendor" "$D/public/build" || die "vendor and assets"
cat > "$D/.env" <<ENV
APP_NAME=JewelFlows
APP_ENV=production
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
APP_DEBUG=false
APP_URL=https://jewelflows.com
PLATFORM_ENFORCE_SUBSCRIPTIONS=true
LOG_CHANNEL=daily
DB_CONNECTION=pgsql
DB_HOST=/run/postgresql
DB_PORT=5432
DB_DATABASE=jewelflow
DB_USERNAME=jewelflow_app
DB_PASSWORD=rehearsal-only-not-a-credential
SESSION_DRIVER=database
SESSION_DOMAIN=.jewelflows.com
QUEUE_CONNECTION=sync
CACHE_STORE=file
MAIL_MAILER=array
ENV
chown dev:www-data "$D/.env"; chmod 640 "$D/.env"
# Untracked files laid out as on production (names only, dummy content), including a file in a
# directory the target starts to track.
sudo -u dev sh -c "cd $D && cp .env .env.save && cp .env .env.pre-subscription-alert-email-20260813T094745Z && echo note > REPORT_EXPORT_GAP_AUDIT.md && mkdir -p docs/superpowers/plans .claude && echo note > docs/superpowers/plans/2026-06-20-jewelflow-erp-theme-guardrails.md && echo '{}' > .claude/settings.local.json"
chown -R www-data:www-data "$D/storage" "$D/bootstrap/cache"
ART package:discover >/dev/null 2>&1 || die "package:discover at the deployed commit: $(ART package:discover 2>&1 | tail -3)"
ART migrate --force > /mnt/migrate.from.out 2>&1 || { tail -12 /mnt/migrate.from.out; die "migrations at the deployed commit"; }
cat > /mnt/seed.php <<'PHP'
<?php
use App\Models\{Category, Role, Shop, User};
use App\Services\TenantRoleService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\{DB, Hash};
require '/var/www/jewelflow/vendor/autoload.php';
$app = require '/var/www/jewelflow/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://localhost'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
config('database.connections.pgsql.host') === '/run/postgresql' && is_file('/var/www/.rehearsal-'.getenv('TOKEN')) or exit("refused: not the rehearsal copy\n");
config(['hashing.bcrypt.rounds' => 4]);
foreach ([['RETAIL', 'erp'], ['DHIRAN', 'dhiran'], ['RETAIL-2', 'erp']] as [$tag, $realm]) {
    $shop = Shop::create(['name' => "EXISTING-{$tag}", 'shop_type' => $realm === 'dhiran' ? 'dhiran' : 'retailer', 'phone' => '9000000000', 'owner_first_name' => 'Existing',
        'owner_last_name' => $tag, 'owner_mobile' => '9'.random_int(100000000, 999999999), 'gst_rate' => 3.00, 'wastage_recovery_percent' => 100.00, 'access_mode' => 'active', 'is_active' => true]);
    app(TenantRoleService::class)->ensureDefaultsForShop((int) $shop->id);
    $u = new User;
    $u->forceFill(['shop_id' => $shop->id, 'role_id' => Role::withoutTenant()->where('shop_id', $shop->id)->where('name', 'owner')->value('id'), 'realm' => $realm,
        'name' => "Existing {$tag}", 'mobile_number' => '9'.random_int(100000000, 999999999), 'password' => Hash::make('existing-password'), 'is_active' => true])->save();
    TenantContext::runFor((int) $shop->id, function () use ($shop) { foreach (range(1, 12) as $n) { Category::forceCreate(['shop_id' => $shop->id, 'name' => "Existing {$n}"]); } });
}
echo 'shops '.DB::table('shops')->count().', users '.DB::table('users')->count().', categories '.DB::table('categories')->count()."\n";
PHP
SEEDED=$(cd "$D" && sudo -u www-data env TOKEN="$TOKEN" php /mnt/seed.php 2>&1 | tail -1); case "$SEEDED" in shops\ 3,*) ;; *) die "seeding: $SEEDED" ;; esac
ART config:cache >/dev/null 2>&1 && ART route:cache >/dev/null 2>&1 && ART view:cache >/dev/null 2>&1 || die "caches at the deployed commit"
install -d -o www-data -g www-data "$D/storage/app/private/JewelFlows" && head -c 2M /dev/zero > "$D/storage/app/private/JewelFlows/nightly.zip" && chown www-data:www-data "$D/storage/app/private/JewelFlows/nightly.zip"
git clone -q /stage/in/staging.git /var/www/jewelflow-staging 2>/dev/null && git -C /var/www/jewelflow-staging checkout -q --detach "$STAGING_HEAD" && mkdir -p /var/www/jewelflow-staging/bootstrap/cache && touch /var/www/jewelflow-staging/bootstrap/cache/config.php || die "staging stand-in"
[ -z "$(find "$D" -xdev -user root | head -1)" ] || die "the copy holds a root-owned file: $(find "$D" -xdev -user root | head -2 | tr '\n' ' ')"
openssl req -x509 -newkey rsa:2048 -nodes -keyout /mnt/tls/key.pem -out /mnt/tls/cert.pem -days 2 -subj "/CN=jewelflows.com" >/dev/null 2>&1 || die "throwaway certificate"
cat > /mnt/nginx.conf <<'NGX'
user www-data; worker_processes 1; pid /run/nginx.pid; error_log /mnt/nginx.error.log;
events { worker_connections 256; }
http {
  include /etc/nginx/mime.types; default_type application/octet-stream; access_log off; gzip on;
  client_body_temp_path /mnt/nginx/body; proxy_temp_path /mnt/nginx/proxy; fastcgi_temp_path /mnt/nginx/fastcgi; uwsgi_temp_path /mnt/nginx/uwsgi; scgi_temp_path /mnt/nginx/scgi;
  include /etc/nginx/sites-enabled/jewelflow;
}
NGX
ln -s /etc/nginx/snippets /mnt/snippets; ln -s /etc/nginx/fastcgi.conf /mnt/fastcgi.conf; ln -s /etc/nginx/fastcgi_params /mnt/fastcgi_params
printf '[global]\npid = /run/php/fpm.pid\nerror_log = /mnt/fpm.log\ndaemonize = yes\n[www]\nuser = www-data\ngroup = www-data\nlisten = /run/php/php8.2-fpm.sock\nlisten.owner = www-data\nlisten.group = www-data\npm = static\npm.max_children = 3\n' > /mnt/fpm.conf
php-fpm8.2 -y /mnt/fpm.conf >/dev/null 2>&1 || die "php-fpm"
nginx -c /mnt/nginx.conf 2>/dev/null || { nginx -c /mnt/nginx.conf -t 2>&1 | tail -3; die "nginx"; }
date -u '+%a %Y-%m-%d %H:%M:%S UTC' | tee /mnt/state/nginx.started > /mnt/state/php8.2-fpm.started
cp -p "$D/.env" /mnt/pristine/env; cp -p /etc/cron.d/jewelflow-scheduler /mnt/pristine/cron; PGDB=postgres PS "select pg_terminate_backend(pid) from pg_stat_activity where datname = 'jewelflow'" >/dev/null; PGDB=postgres PS "create database jewelflow_pristine template jewelflow" || die "pristine template"
systemctl start jewelflow-production-ops-alerts
cat > /mnt/fp.sql <<'SQL'
select line from (
  select 'table '||c.relname||' '||(xpath('/row/n/text()', x))[1]::text||' '||(xpath('/row/h/text()', x))[1]::text as line
  from pg_class c join pg_namespace n on n.oid = c.relnamespace,
  lateral query_to_xml(format('select count(*) as n, md5(coalesce(string_agg(md5(t::text), '''' order by md5(t::text) collate "C"), '''')) as h from %I.%I t', n.nspname, c.relname), false, true, '') x
  where n.nspname = 'public' and c.relkind = 'r' and c.relname <> 'sessions'
  union all select 'rel '||c.relkind||' '||c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind in ('r','S','i','v')
) s order by line collate "C";
SQL
FPRINT() { sudo -u postgres env PGOPTIONS='-c timezone=UTC -c datestyle=ISO,YMD' psql -X -A -t -q -v ON_ERROR_STOP=1 -d jewelflow -f /mnt/fp.sql; }
FPRINT > /mnt/pristine/fp
echo "the copy: $(sudo -u dev -H git -C "$D" rev-parse HEAD) owned by $(stat -c %U "$D"), .env $(stat -c '%U:%G %a' "$D/.env"); PHP $(php -r 'echo PHP_VERSION;'), $(PS 'select version()' | cut -d' ' -f1-2); $(PS 'select count(*) from migrations') migrations, $(grep -c '^table ' /mnt/pristine/fp) tables besides sessions; synthetic data: $SEEDED"
echo "script under rehearsal: sha256 $(sha256sum "$S" | cut -d' ' -f1)"

# ── helpers ─────────────────────────────────────────────────────────────────
N=0
state() { echo "   state: HEAD $(sudo -u dev -H git -C "$D" rev-parse --short=12 HEAD); maintenance $([ -e "$D/storage/framework/down" ] && echo on || echo off); cron $([ -e /etc/cron.d/jewelflow-scheduler ] && echo in-place || echo held); worker $(systemctl is-active jewelflow-production-ops-alerts); product addresses in .env $(grep -cE '^(DHIRAN|ERP)_REGISTER_URL=' "$D/.env"); promotion tables $(PS "select count(*) from information_schema.tables where table_schema = 'public' and (table_name like 'product_promotion%' or table_name like 'product_recognition%')") holding $(PS "select coalesce(sum((xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I', table_name), false, true, '')))[1]::text::int), 0) from information_schema.tables where table_schema = 'public' and (table_name like 'product_promotion%' or table_name like 'product_recognition%')") rows; landing $(curl -sk -m 20 --resolve jewelflows.com:443:127.0.0.1 https://jewelflows.com/ | grep -c 'Start with Retail') new-page marker(s), http $(curl -sk -o /dev/null -w '%{http_code}' -m 20 --resolve jewelflows.com:443:127.0.0.1 https://jewelflows.com/)"; }
run() { # label, expected exit (0 or "fail"), expected text, command...
  local label=$1 want=$2 text=$3; shift 3; N=$((N + 1)); local log; log=/out/$(printf '%02d' "$N")-$label.log
  "$@" > "$log" 2>&1; local rc=$?
  local verdict=UNEXPECTED
  if { [ "$want" = 0 ] && [ "$rc" = 0 ]; } || { [ "$want" = fail ] && [ "$rc" != 0 ]; }; then grep -qF -- "$text" "$log" && verdict=AS-EXPECTED; fi
  echo "[$verdict] $label: exit $rc; $(grep -E '^(script |!!!!! |TAKEOVER PRODUCTION|RETURNED TO BASELINE|PREFLIGHT PASSED|NOT RUN)' "$log" | cut -c1-260 | tr '\n' '|')"
  [ "$verdict" = AS-EXPECTED ] || { echo "   --- last lines of $log"; tail -8 "$log" | cut -c1-300; FAILED=$((FAILED + 1)); }
  state
}
killed_after() { # label, trigger command (true when the kill should land), environment...
  local label=$1 trigger=$2; shift 2; N=$((N + 1)); local log; log=/out/$(printf '%02d' "$N")-$label.log
  local last; last=$(release_dir)
  setsid env "$@" bash "$S" release $ARGS > "$log" 2>&1 & local pid=$!
  # the trigger is only looked at once this run has its own evidence directory
  while kill -0 "$pid" 2>/dev/null; do if [ "$(release_dir)" != "$last" ] && eval "$trigger" >/dev/null 2>&1; then kill -9 -- "-$pid" 2>/dev/null; break; fi; sleep 0.1; done
  wait "$pid" 2>/dev/null; local rc=$?
  for i in $(seq 1 100); do pgrep -u www-data -f artisan >/dev/null || break; sleep 0.2; done   # children sudo had moved to their own session finish what they were doing
  echo "[KILLED] $label: SIGKILL to the release script and its session (exit $rc); last line it wrote: $(grep -E '^(ok|script|#####)' "$log" | tail -1 | cut -c1-150)"
  state
}
release_dir() { ls -d /root/takeover-production/release-* 2>/dev/null | tail -1; }
reset_copy() {
  systemctl stop jewelflow-production-ops-alerts
  rm -f "$D/storage/framework/down"; [ -e /etc/cron.d/jewelflow-scheduler ] || cp -p /mnt/pristine/cron /etc/cron.d/jewelflow-scheduler
  sudo -u dev -H git -C "$D" checkout -q --detach "$FROM"; sudo -u dev -H git -C "$D" update-ref -d refs/takeover/candidate 2>/dev/null
  rm -rf "$D/public/build" "$D/public/build.incoming"; cp -a /stage/in/build "$D/public/build"; chown -R dev:dev "$D/public/build"
  cat /mnt/pristine/env > "$D/.env"
  sudo -u dev -H env COMPOSER_ALLOW_SUPERUSER=1 composer -d "$D" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --quiet; ART package:discover >/dev/null 2>&1
  PGDB=postgres PS "select pg_terminate_backend(pid) from pg_stat_activity where datname = 'jewelflow'" >/dev/null; PGDB=postgres PS "drop database jewelflow"; PGDB=postgres PS "create database jewelflow template jewelflow_pristine owner jewelflow_app"
  ART config:cache >/dev/null 2>&1; ART route:cache >/dev/null 2>&1; ART view:clear >/dev/null 2>&1; ART view:cache >/dev/null 2>&1
  systemctl start jewelflow-production-ops-alerts; sleep 3
  echo "   (copy reset to the deployed commit and the pristine database)"
}
FAILED=0
APPROVED="TAKEOVER_RELEASE_APPROVED=$TARGET"
migrated='[ "$(PS "select count(*) from migrations where migration = '"'$MIG'"'")" = 1 ]'
assets_in='[ -e "$(release_dir)/build.replaced" ] && [ -e "$D/public/build/manifest.json" ]'

say "A. guards that must refuse, changing nothing"
run preflight 0 "PREFLIGHT PASSED" bash "$S" preflight $ARGS
run release-without-approval fail "release needs TAKEOVER_RELEASE_APPROVED" bash "$S" release $ARGS
run release-in-the-scheduler-window fail "would overlap the daily scheduled jobs" env "$APPROVED" REHEARSAL_IST="23 50" bash "$S" release $ARGS

say "B. the whole release, uninterrupted, with the synthetic check"
run release-complete 0 "TAKEOVER PRODUCTION RELEASE PASSED" env "$APPROVED" TAKEOVER_PRODUCTION_CHECK=approved bash "$S" release $ARGS
echo "   synthetic check inside it: $(grep -c '^PASS' "$(release_dir)/production-check.txt") PASS, $(grep -c '^FAIL' "$(release_dir)/production-check.txt") FAIL; $(grep -E '^ok +synthetic' /out/04-release-complete.log | cut -c1-170)"
run baseline-after-a-completed-release fail "production is not in maintenance" bash "$S" baseline "$FROM" "$TARGET" "$(release_dir)"
reset_copy

say "C. a release killed after the migration, then resume"
killed_after release-killed-after-migration "$migrated" "$APPROVED" TAKEOVER_PRODUCTION_CHECK=approved
run resume 0 "TAKEOVER PRODUCTION RELEASE PASSED" env TAKEOVER_PRODUCTION_CHECK=approved bash "$S" resume "$FROM" "$TARGET" "$(release_dir)"
reset_copy

say "D. a release killed after the migration; return to baseline refused while a row exists, permitted once empty; then released again"
killed_after release-killed-after-migration "$migrated" "$APPROVED"
STOPPED=$(release_dir)
PS "insert into product_promotion_preferences (environment, realm, shop_id, user_id, target, choice, created_at, updated_at) select 'production', 'erp', u.shop_id, u.id, 'dhiran', 'opt_out', now(), now() from users u order by u.id limit 1"
echo "   one synthetic preference row inserted (an owner's 'do not show again')"
BEFORE_REFUSAL=$(sudo -u dev -H git -C "$D" rev-parse HEAD; sha256sum "$D/.env" "$D/public/build/manifest.json" "$D/bootstrap/cache/config.php" | cut -d' ' -f1; PS "select count(*) from product_promotion_preferences")
run baseline-with-a-recorded-preference fail "row(s) of promotion metadata exist" bash "$S" baseline "$FROM" "$TARGET" "$STOPPED"
[ "$BEFORE_REFUSAL" = "$(sudo -u dev -H git -C "$D" rev-parse HEAD; sha256sum "$D/.env" "$D/public/build/manifest.json" "$D/bootstrap/cache/config.php" | cut -d' ' -f1; PS "select count(*) from product_promotion_preferences")" ] && echo "   the refusal changed nothing: commit, .env, assets, config cache and the row are as they were" || { echo "   THE REFUSAL CHANGED SOMETHING"; FAILED=$((FAILED + 1)); }
PS "delete from product_promotion_preferences"; echo "   the synthetic row removed (rehearsal only: on production a recorded row is never deleted to make a rollback possible)"
run baseline-with-empty-tables 0 "RETURNED TO BASELINE" bash "$S" baseline "$FROM" "$TARGET" "$STOPPED"
cmp -s "$D/.env" /mnt/pristine/env && echo "   .env is byte-identical to the pre-release one" || { echo "   .ENV DIFFERS"; FAILED=$((FAILED + 1)); }
run release-again-after-baseline 0 "TAKEOVER PRODUCTION RELEASE PASSED" env "$APPROVED" TAKEOVER_PRODUCTION_CHECK=approved bash "$S" release $ARGS
grep -E "already applied|not run again" /out/*-release-again-after-baseline.log | cut -c1-200 | sed 's/^/   /'
reset_copy

say "E. a release killed before the migration; resume refused; return to baseline with nothing of the migration present"
killed_after release-killed-before-migration "$assets_in" "$APPROVED"
STOPPED=$(release_dir)
run resume-before-migration fail "this is not a case for resume" bash "$S" resume "$FROM" "$TARGET" "$STOPPED"
run baseline-before-migration 0 "RETURNED TO BASELINE" bash "$S" baseline "$FROM" "$TARGET" "$STOPPED"
FPRINT > /mnt/after.fp
cmp -s /mnt/pristine/fp /mnt/after.fp && echo "   database: every table (sessions aside) and every relation identical to the pristine copy" || { echo "   DATABASE DIFFERS: $(diff /mnt/pristine/fp /mnt/after.fp | awk '/^[<>]/ { print $2" "$3 }' | sort -u | head -5 | tr '\n' ';')"; FAILED=$((FAILED + 1)); }
cmp -s "$D/.env" /mnt/pristine/env && echo "   .env is byte-identical to the pre-release one" || { echo "   .ENV DIFFERS"; FAILED=$((FAILED + 1)); }

say "result"
echo "scenarios with an unexpected outcome: $FAILED"
echo "error lines in the copy's application log: $(cat "$D"/storage/logs/laravel*.log 2>/dev/null | grep -cE '\.(ERROR|CRITICAL|EMERGENCY|ALERT):')"
for d in /root/takeover-production/*-2*; do [ -f "$d/run.log" ] && cp "$d/run.log" "/out/evidence-$(basename "$d").run.log"; [ -f "$d/production-check.txt" ] && cp "$d/production-check.txt" "/out/evidence-$(basename "$d").production-check.txt"; done
nginx -c /mnt/nginx.conf -s stop 2>/dev/null; pkill php-fpm8.2 2>/dev/null; systemctl stop jewelflow-production-ops-alerts; sudo -u postgres "$B/pg_ctl" -D /mnt/pgdata -s -m immediate stop >/dev/null 2>&1
[ "$FAILED" = 0 ] && echo "TOOLING REHEARSAL PASSED" || echo "TOOLING REHEARSAL FAILED"
exit "$FAILED"
DRIVER

echo "rehearsal of $(sha256sum "$SCRIPT" | cut -d' ' -f1) ($SCRIPT): $FROM -> $TARGET; bundle $(sha256sum "$BUNDLE" | cut -d' ' -f1); assets $ASSETS_SHA" | tee "$OUT/result.txt"
# ProtectKernelTunables and ProtectControlGroups are deliberately absent: with either, this systemd
# leaves the host's /run visible under the tmpfs asked for (found by the driver's own check).
systemd-run --quiet --wait --pipe --collect \
    -p PrivateNetwork=yes -p PrivateIPC=yes -p PrivateTmp=yes -p PrivateMounts=yes -p ProtectSystem=strict \
    -p "BindPaths=$STAGE/www:/var/www $STAGE/root:/root $STAGE/crond:/etc/cron.d $STAGE/home:/home $OUT:/out" \
    -p "BindReadOnlyPaths=$STAGE:/stage $STAGE/in/nginx-site:/etc/nginx/sites-available/jewelflow" \
    -p "TemporaryFileSystem=/run:mode=0755" -p "TemporaryFileSystem=/mnt:mode=1777,size=1500M" \
    -p "InaccessiblePaths=-/var/lib/postgresql -/etc/letsencrypt -/var/backups -/etc/ssh -/var/log" \
    -p MemoryMax=3G -p TasksMax=1024 -p RuntimeMaxSec=2400 -p Nice=10 -p CPUWeight=20 \
    -E "TOKEN=$TOKEN" -E "FROM=$FROM" -E "TARGET=$TARGET" -E "ASSETS_SHA=$ASSETS_SHA" -E "STAGING_HEAD=$STAGING_HEAD" -E "APP_SUPER=$APP_SUPER" \
    -- /usr/bin/unshare --pid --fork --mount-proc --kill-child /bin/bash /stage/driver.sh 2>&1 | tee -a "$OUT/result.txt"
RC=${PIPESTATUS[0]}
echo "rehearsal exit $RC; evidence $OUT" | tee -a "$OUT/result.txt"
echo "production after the rehearsal: HEAD $(sudo -u dev git -C /var/www/jewelflow --no-optional-locks rev-parse HEAD), maintenance $([ -e /var/www/jewelflow/storage/framework/down ] && echo ON || echo off), worker $(systemctl is-active jewelflow-production-ops-alerts), cron $(sha256sum /etc/cron.d/jewelflow-scheduler | cut -c1-12), .env mtime $(stat -c %y /var/www/jewelflow/.env | cut -c1-19)" | tee -a "$OUT/result.txt"
exit "$RC"
