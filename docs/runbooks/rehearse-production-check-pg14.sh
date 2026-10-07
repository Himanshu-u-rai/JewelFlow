#!/usr/bin/env bash
# Rehearsal of tests/Production/verify_takeover_production.php, exactly as committed in the
# candidate, on PostgreSQL 14 in production mode - WITHOUT production. Runs on the VPS as root
# (the only PostgreSQL 14 available) but inside a unit that can reach nothing: no network, its
# own PostgreSQL 14 on a memory filesystem, a throwaway user, /var/www, /root, the live data
# directory and its sockets hidden. The application tree inside is the candidate commit exported
# from the bundle, the --no-dev vendor directory of the same composer.lock, and the released
# assets. Its .env is generated here; it holds no real credential. The database is created empty
# by the application's own migrations and given a few committed synthetic shops.
set -uo pipefail
CAND=${1:?candidate sha}
EVID=/root/takeover-production
WORK=$EVID/rehearsal-$(date -u +%Y%m%dT%H%M%SZ); mkdir -p "$WORK"; chmod 700 "$WORK"
REPO=$(ls -d $EVID/preflight-*/candidate.git | tail -1)
STAGE=$(mktemp -d /var/tmp/takeover-rehearsal.XXXXXX); chmod 700 "$STAGE"
trap 'rm -rf "$STAGE"' EXIT
git --git-dir="$REPO" archive --format=tar "$CAND" | tar -xf - -C "$STAGE" || { echo "could not export $CAND"; exit 2; }
[ "$(sha256sum < "$STAGE/composer.lock")" = "$(sha256sum < /var/www/jewelflow-staging/composer.lock)" ] || { echo "composer.lock differs from the vendor directory's"; exit 2; }
cp -a /var/www/jewelflow-staging/vendor "$STAGE/vendor"
tar -xzf "$(ls /root/takeover-staging/incoming/takeover-assets-*.tar.gz)" -C "$STAGE/public"
cat > "$STAGE/seed-existing.php" <<'PHP'
<?php
// Committed synthetic "existing" shops and promotion rows, so the before/after comparison is
// not made over empty tables. Only ever inside the isolated instance.
use App\Models\{Category, Role, Shop, User};
use App\Services\{ProductPromotionService, TenantRoleService};
use App\Support\TenantContext;
use Illuminate\Support\Facades\{DB, Hash};
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://localhost'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
str_starts_with((string) config('database.connections.pgsql.host'), '/mnt/') or exit("refused: not the isolated instance\n");
config(['hashing.bcrypt.rounds' => 4]);
$owner = function (string $tag, string $realm) {
    $shop = Shop::create(['name' => "EXISTING-{$tag}", 'shop_type' => $realm === 'dhiran' ? 'dhiran' : 'retailer', 'phone' => '9000000000', 'owner_first_name' => 'Existing',
        'owner_last_name' => $tag, 'owner_mobile' => '9'.random_int(100000000, 999999999), 'gst_rate' => 3.00, 'wastage_recovery_percent' => 100.00, 'access_mode' => 'active', 'is_active' => true]);
    app(TenantRoleService::class)->ensureDefaultsForShop((int) $shop->id);
    $u = new User;
    $u->forceFill(['shop_id' => $shop->id, 'role_id' => Role::withoutTenant()->where('shop_id', $shop->id)->where('name', 'owner')->value('id'), 'realm' => $realm,
        'name' => "Existing {$tag}", 'mobile_number' => '9'.random_int(100000000, 999999999), 'password' => Hash::make('existing-password'), 'is_active' => true])->save();
    TenantContext::runFor((int) $shop->id, function () use ($shop) { foreach (range(1, 12) as $n) { Category::forceCreate(['shop_id' => $shop->id, 'name' => "Existing {$n}"]); } });
    return $u->fresh();
};
$r = $owner('RETAIL', 'erp'); $d = $owner('DHIRAN', 'dhiran'); $o = $owner('RETAIL-OPTED-OUT', 'erp');
$s = app(ProductPromotionService::class);
$code = $s->start($r, 'existing-password');
$id = $s->requests($r)->whereNull('consumed_at')->value('id');
$s->approve($d, 'existing-password', $code);
$s->finish($r, 'existing-password', $id);
$s->choose($o, 'opt_out');
foreach (['shops', 'users', 'categories', 'product_promotion_preferences', 'product_recognition_requests', 'product_recognitions'] as $t) { echo $t.' '.DB::table($t)->count().'; '; }
echo "\n";
PHP
# Only for trying a correction before it is committed; the recorded rehearsal runs without it.
if [ -n "${CHECK_UNDER_TEST:-}" ]; then cp "$CHECK_UNDER_TEST" "$STAGE/tests/Production/verify_takeover_production.php"; echo "NOTE: check script replaced by an uncommitted copy under test" | tee "$WORK/result.txt"; fi
echo "candidate $CAND; check script sha256 $(sha256sum "$STAGE/tests/Production/verify_takeover_production.php" | cut -d' ' -f1); vendor dev packages: $(ls "$STAGE/vendor" | grep -cE '^(phpunit|mockery|fakerphp)$')" | tee -a "$WORK/result.txt"
SANDBOX=$(cat <<'SH'
set -u
fail() { echo "sandbox: $*"; exit 97; }
[ "$(id -u)" != 0 ] || fail "running as root"
for p in $MUST_NOT_SEE; do if [ -r "$p" ] || ls "$p" >/dev/null 2>&1; then fail "can read $p"; fi; done
if (exec 3<>"/dev/tcp/127.0.0.1/5432") 2>/dev/null; then fail "can reach the live PostgreSQL port"; fi
if (exec 3<>"/dev/tcp/1.1.1.1/443") 2>/dev/null; then fail "can reach the internet"; fi
[ "$(awk 'NR > 2 { sub(/:.*/, ""); gsub(/[[:space:]]/, ""); printf "%s ", $0 }' /proc/net/dev)" = "lo " ] || fail "has a network interface besides lo"
echo "isolation: not root; live .env files, data directory and sockets unreadable; no route to the live database or the internet; loopback only"
B=/usr/lib/postgresql/14/bin
W=$(mktemp -d /mnt/pg.XXXXXX); A=/mnt/app; mkdir "$A"
tar -xf - -C "$A" || fail "could not unpack the application"
"$B/initdb" -D "$W/data" -U postgres -A trust -E UTF8 --locale=C.UTF-8 </dev/null >/dev/null
"$B/pg_ctl" -D "$W/data" -s -w -l "$W/log" -o "-c listen_addresses='' -c unix_socket_directories=$W -c fsync=off -c synchronous_commit=off -c full_page_writes=off -c shared_buffers=128MB" start </dev/null >/dev/null
P() { "$B/psql" -X -q -A -t -v ON_ERROR_STOP=1 -h "$W" -U postgres -d jewelflow "$@" </dev/null; }
"$B/psql" -X -q -h "$W" -U postgres -d postgres -c "create database jewelflow" </dev/null
cd "$A"
cat > .env <<ENV
APP_NAME=JewelFlows
APP_ENV=production
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
APP_DEBUG=false
APP_URL=https://jewelflows.com
PLATFORM_ENFORCE_SUBSCRIPTIONS=true
DB_CONNECTION=pgsql
DB_HOST=$W
DB_PORT=5432
DB_DATABASE=jewelflow
DB_USERNAME=postgres
DB_PASSWORD=
SESSION_DRIVER=database
SESSION_DOMAIN=.jewelflows.com
QUEUE_CONNECTION=sync
CACHE_STORE=file
MAIL_MAILER=array
LOG_CHANNEL=daily
DHIRAN_REGISTER_URL=https://dhiran.jewelflows.com/register
ERP_REGISTER_URL=https://jewelflows.com/register
ENV
PHP=/usr/bin/php8.2
echo "runtime: PHP $($PHP -r 'echo PHP_VERSION;'); $(P -c 'select version()' | cut -d' ' -f1-2)"
$PHP artisan package:discover >/dev/null 2>&1 || fail "package:discover failed: $($PHP artisan package:discover 2>&1 | tail -3)"
$PHP artisan migrate --force > /mnt/migrate.out 2>&1 || { tail -15 /mnt/migrate.out; fail "migrations failed"; }
echo "schema built by the application's migrations: $(P -c 'select count(*) from migrations') migrations, $(P -c "select count(*) from information_schema.tables where table_schema = 'public'") tables, $(P -c 'select count(*) from pg_trigger where not tgisinternal') triggers"
SEEDED=$($PHP seed-existing.php 2>&1 | tail -1); case "$SEEDED" in shops\ 3\;*) echo "committed synthetic data: $SEEDED" ;; *) fail "seeding failed: $SEEDED" ;; esac
$PHP artisan config:cache >/dev/null 2>&1 && $PHP artisan route:cache >/dev/null 2>&1 && $PHP artisan view:cache >/dev/null 2>&1 || fail "caching failed"
$PHP artisan down --retry=30 --secret="$(cat /proc/sys/kernel/random/uuid)" >/dev/null 2>&1 || fail "artisan down failed"
echo "as production runs it: APP_ENV $($PHP -r '$c = require "bootstrap/cache/config.php"; echo $c["app"]["env"].", database ".$c["database"]["connections"]["pgsql"]["database"].", config, routes and views cached, maintenance ".(is_file("storage/framework/down") ? "on" : "OFF");')"
export PGOPTIONS="$FP_OPTS"
P -c "$FP_SQL" > /mnt/before.fp
P -c "select sequencename||' '||coalesce(last_value, 0) from pg_sequences where schemaname = 'public' order by 1" > /mnt/before.seq
find storage bootstrap/cache -type f -printf '%p %s %T@\n' | sort > /mnt/before.files
echo "----- the check script's own output -----"
RC=0; TAKEOVER_PRODUCTION_CHECK=approved $PHP tests/Production/verify_takeover_production.php > /mnt/check.out 2>&1 || RC=$?
cat /mnt/check.out
echo "----- end of its output; exit code $RC -----"
if [ "$RC" = 0 ] && grep -q '^TAKEOVER PRODUCTION CHECK PASSED' /mnt/check.out && ! grep -q '^FAIL' /mnt/check.out && [ "$(grep -c '^PASS' /mnt/check.out)" -ge 18 ]; then VERDICT="REHEARSAL PASSED: $(grep -c '^PASS' /mnt/check.out) checks, the release script's own acceptance rule met"; else VERDICT="REHEARSAL FAILED"; RC=1; fi
P -c "$FP_SQL" > /mnt/after.fp
P -c "select sequencename||' '||coalesce(last_value, 0) from pg_sequences where schemaname = 'public' order by 1" > /mnt/after.seq
find storage bootstrap/cache -type f -printf '%p %s %T@\n' | sort > /mnt/after.files
echo "measured from outside the PHP process:"
if cmp -s /mnt/before.fp /mnt/after.fp; then echo "  tables: all $(grep -c '^table ' /mnt/after.fp) identical by row count and content hash ($(awk '$1 == "table" && $3 > 0' /mnt/after.fp | wc -l) holding rows); relations and triggers identical"; else echo "  TABLES DIFFER: $(diff /mnt/before.fp /mnt/after.fp | awk '/^[<>]/ { print $3 }' | sort -u | tr '\n' ' ')"; fi
echo "  sequences advanced: $(join /mnt/before.seq /mnt/after.seq | awk '$2 != $3 { printf "%s +%d, ", $1, $3 - $2 }')"
NEWFILES=$(diff /mnt/before.files /mnt/after.files | awk '/^>/ { print $2 }')
echo "  files changed or added: $(grep -c . <<< "$NEWFILES") - cache entries $(grep -c 'framework/cache/data' <<< "$NEWFILES"), compiled views $(grep -c 'framework/views' <<< "$NEWFILES"), logs $(grep -c 'storage/logs' <<< "$NEWFILES"), other $(grep -vcE 'framework/cache/data|framework/views|storage/logs|^$' <<< "$NEWFILES")"
echo "  cache entries written hold: $(for f in $(grep 'framework/cache/data' <<< "$NEWFILES"); do head -c 160 "$f" | tr -c '[:print:]' ' ' | cut -c11-150; echo -n ' / '; done)"
echo "  inline templates written: $(for f in $(grep 'framework/views/.*\.blade\.php' <<< "$NEWFILES"); do head -c 90 "$f" | tr -c '[:print:]' ' '; echo -n ' / '; done)"
echo "  compiled views written are of: $(for f in $(grep 'framework/views' <<< "$NEWFILES"); do grep -aoE 'PATH [^ ]+ ENDPATH' "$f" | sed -E 's#PATH /mnt/app/##; s# ENDPATH##'; done | sort -u | tr '\n' ' ')"
echo "  four promotion tables after the run: $(P -c 'select (select count(*) from product_promotion_preferences) + (select count(*) from product_promotion_exposures) + (select count(*) from product_recognition_requests) + (select count(*) from product_recognitions)') rows, all of them the committed synthetic ones from before the run"
echo "  error lines logged: $(cat storage/logs/laravel*.log 2>/dev/null | grep -cE '\.(ERROR|CRITICAL|EMERGENCY|ALERT):')"
echo "$VERDICT"
"$B/pg_ctl" -D "$W/data" -s -m immediate stop </dev/null >/dev/null || true
exit $RC
SH
)
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
tar -C "$STAGE" -cf - . | systemd-run -p DynamicUser=yes --quiet --wait --pipe --collect \
    -p PrivateNetwork=yes -p PrivateIPC=yes -p PrivateTmp=yes -p ProtectProc=invisible -p ProtectSystem=strict -p ProtectHome=yes \
    -p NoNewPrivileges=yes -p CapabilityBoundingSet= -p "InaccessiblePaths=-/var/www -/var/lib/postgresql -/etc/postgresql -/run/postgresql -/var/backups -/var/log -/var/tmp -/etc/nginx -/etc/letsencrypt -/etc/ssh -/root" \
    -p "TemporaryFileSystem=/mnt:mode=1777,size=2G" -p MemoryMax=3G -p TasksMax=512 -p RuntimeMaxSec=1500 -p LimitFSIZE=2G -p Nice=10 -p CPUWeight=20 \
    -E "FP_SQL=$FP_SQL" -E "FP_OPTS=$FP_OPTS" -E "MUST_NOT_SEE=/var/www/jewelflow/.env /var/www/jewelflow-staging/.env /var/lib/postgresql /run/postgresql /root" \
    -- /bin/bash -c "$SANDBOX" 2>&1 | tee -a "$WORK/result.txt"
echo "rehearsal exit ${PIPESTATUS[1]}; evidence $WORK/result.txt" | tee -a "$WORK/result.txt"
