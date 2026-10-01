#!/usr/bin/env bash
# Checks for tests/Staging/secure_demo_accounts.php. Local only: it rebuilds the
# LOCAL test database (jewelflow_testing), seeds the demo shop and a control
# account in another shop, and runs the staging procedure with local pins.
#
#   bash tests/Staging/secure_demo_accounts_test.sh     exit 0 = every check passed; 77 = cannot run here
set -u
cd "$(dirname "$0")/../.." || exit 2
grep -q '^DB_DATABASE=jewelflow_testing$' .env && grep -qE '^APP_ENV=(local|testing)$' .env \
  || { echo "SKIP: needs the local checkout on database jewelflow_testing"; exit 77; }
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
PASS=0; FAILN=0
check() { if eval "$2"; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAILN=$((FAILN + 1)); echo "FAIL  $1"; fi; }
tinker() { php artisan tinker --execute="$1" 2>&1 | tail -1; }

fresh() {
  php artisan cache:clear > /dev/null 2>&1   # the login rate limiter lives in the cache
  php artisan migrate:fresh --force > "$T/migrate.log" 2>&1 && php artisan db:seed --class=PilotDemoSeeder --force >> "$T/migrate.log" 2>&1 || { tail -5 "$T/migrate.log"; exit 2; }
  # A legitimate account in another shop, with the same published password: it must not be touched.
  tinker '$s = \App\Models\Shop::first()->replicate(); $s->name = "Control Shop"; $s->owner_mobile = "9876500000"; $s->shop_code = "CTRL01"; $s->catalog_slug = null; $s->save();
    $o = \App\Models\User::withoutGlobalScopes()->orderBy("id")->first(); $r = \App\Models\Role::withoutGlobalScopes()->find($o->role_id)->replicate(); $r->shop_id = $s->id; $r->save();
    $u = $o->replicate(); $u->shop_id = $s->id; $u->role_id = $r->id; $u->mobile_number = "9876500001"; $u->name = "Control Owner"; $u->save(); echo "ok";' > /dev/null
  PINS=$(tinker '$s = \App\Models\Shop::where("owner_mobile", "9000000111")->first(); $a = [];
    foreach (\App\Models\User::withoutGlobalScopes()->where("shop_id", $s->id)->orderBy("id")->get() as $u) $a[$u->id] = [$u->mobile_number, $u->name];
    echo json_encode(["shop" => ["id" => $s->id, "name" => $s->name, "owner_mobile" => "9000000111"], "accounts" => $a]);')
}
# Each run starts with an empty login rate limiter, as a run on staging a minute after the last does.
run() { php artisan cache:clear > /dev/null 2>&1
  [ -z "${LIMITED-}" ] || tinker 'for ($i = 0; $i < 6; $i++) { \Illuminate\Support\Facades\RateLimiter::hit("9000000111|127.0.0.1", 60); } echo "ok";' > /dev/null
  SECURE_DEMO_LOCAL_TESTING=${OPTIN-1} SECURE_DEMO_LOCAL_PINS="${USE_PINS-$PINS}" SESSION_DRIVER="${DRIVER-database}" php tests/Staging/secure_demo_accounts.php "$1" > "$T/out" 2>&1; RC=$?; }
state() { tinker '$d = (new ReflectionClassConstant(\Database\Seeders\PilotDemoSeeder::class, "DEMO_PASSWORD"))->getValue(); $o = [];
  foreach (\App\Models\User::withoutGlobalScopes()->orderBy("id")->get() as $u) $o[] = $u->mobile_number.":".($u->is_active ? "active" : "inactive").":".(\Illuminate\Support\Facades\Hash::check($d, $u->password) ? "opens" : "closed");
  echo implode(" ", $o);'; }

# Staging's platform admin is not the seeder's. (It cannot be deleted here: a protected trigger keeps the last super admin.)
no_admin() { tinker '\App\Models\Platform\PlatformAdmin::where("mobile_number", "9000000100")->update(["mobile_number" => "9876599999"]); echo "ok";' > /dev/null; }

fresh
BEFORE=$(state)
run apply
check "the seeder's platform admin present (staging has none): ABORT, nothing changed" '[ "$RC" = 3 ] && grep -q "platform admin exists" "$T/out" && [ "$(state)" = "$BEFORE" ]'
no_admin
check "fixture: three demo accounts and one control account, all active, all opened by the published password" \
  '[ "$BEFORE" = "9000000111:active:opens 9000000112:active:opens 9000000113:active:opens 9876500001:active:opens" ]'

OPTIN=0 run apply
check "outside staging and without the explicit local opt-in it refuses (exit 2) and changes nothing" '[ "$RC" = 2 ] && grep -q "^REFUSED" "$T/out" && [ "$(state)" = "$BEFORE" ]'
run plan
check "plan: every pin matched, exit 0, nothing changed" '[ "$RC" = 0 ] && grep -q "Every pin matched" "$T/out" && [ "$(state)" = "$BEFORE" ]'
USE_PINS=${PINS/Demo Jewellers/Other Jewellers} run apply
check "a shop whose name is not the pinned one: ABORT (exit 3), nothing changed" '[ "$RC" = 3 ] && grep -q "^ABORT" "$T/out" && [ "$(state)" = "$BEFORE" ]'
USE_PINS=${PINS/Demo Manager/Somebody Else} run apply
check "an account whose name is not the pinned one: ABORT, nothing changed" '[ "$RC" = 3 ] && grep -q "is not the pinned one" "$T/out" && [ "$(state)" = "$BEFORE" ]'
DRIVER=file run apply
check "a session driver other than database: ABORT, nothing changed" '[ "$RC" = 3 ] && grep -q "session driver is .file." "$T/out" && [ "$(state)" = "$BEFORE" ]'
tinker '$u = \App\Models\User::withoutGlobalScopes()->where("mobile_number", "9000000113")->first()->replicate(); $u->mobile_number = "9000000114"; $u->name = "Extra"; $u->save(); echo "ok";' > /dev/null
run apply
check "a fourth account in the demo shop: ABORT, nothing changed" '[ "$RC" = 3 ] && grep -q "^ABORT" "$T/out" && grep -q "9000000113:active:opens" <<< "$(state)"'
tinker '\App\Models\User::withoutGlobalScopes()->where("mobile_number", "9000000114")->update(["shop_id" => \App\Models\Shop::where("name", "Control Shop")->value("id"), "role_id" => \App\Models\User::withoutGlobalScopes()->where("mobile_number", "9876500001")->value("role_id")]); echo "ok";' > /dev/null
run apply
check "a demo-range mobile in another shop: ABORT, nothing changed" '[ "$RC" = 3 ] && grep -q "demo-range mobile" "$T/out" && grep -q "9000000113:active:opens" <<< "$(state)"'

fresh; no_admin
LIMITED=1 run apply
check "the exposure cannot be shown (login rate limited): it stops before changing anything (exit 1)" \
  '[ "$RC" = 1 ] && grep -q "^STOPPED" "$T/out" && ! grep -q "^applied:" "$T/out" && grep -q "9000000111:active:opens 9000000112:active:opens 9000000113:active:opens" <<< "$(state)"'

fresh; no_admin
run apply
check "apply: exit 0, and it says every check passed" '[ "$RC" = 0 ] && grep -q "^DEMO ACCOUNTS: secured, every check passed" "$T/out" && ! grep -q "^FAIL" "$T/out"'
check "apply: it showed the exposure first (web session and mobile token accepted)" \
  'grep -q "^PASS  before: the published password signs in on the web and the session is accepted" "$T/out" && grep -q "^PASS  before: the published password signs in on the mobile API and the token is accepted" "$T/out"'
check "apply: it tried a web sign-in with the published password for each of the three, and none got a session" \
  '[ "$(grep -c "^PASS  account .*: a web sign-in with the published password gets no session" "$T/out")" = 3 ]'
check "apply: the same session and the same token are refused afterwards" \
  'grep -q "^PASS  after: the session accepted before is sent to /login" "$T/out" && grep -q "^PASS  after: the token accepted before is refused" "$T/out"'
check "apply: the three demo accounts are inactive and closed; the control account is untouched" \
  '[ "$(state)" = "9000000111:inactive:closed 9000000112:inactive:closed 9000000113:inactive:closed 9876500001:active:opens" ]'
check "apply: no token-shaped string in its output" '! grep -qE "[0-9]+\|[A-Za-z0-9]{40}" "$T/out"'
run verify
check "verify: exit 0 on the secured state; a mobile sign-in was tried for each of the three and none got a token" \
  '[ "$RC" = 0 ] && grep -q "every check passed" "$T/out" && [ "$(grep -c "^PASS  account .*: a mobile sign-in with the published password gets no token" "$T/out")" = 3 ]'
run apply
check "apply again: nothing to do, still exit 0" '[ "$RC" = 0 ] && ! grep -q "^applied:" "$T/out"'
tinker '\App\Models\User::withoutGlobalScopes()->where("mobile_number", "9000000112")->first()->forceFill(["employment_status" => "active", "is_active" => true])->save(); echo "ok";' > /dev/null
run verify
check "verify: a demo account switched back on is reported (exit 1)" '[ "$RC" = 1 ] && grep -q "^FAIL  account .*: disabled" "$T/out"'

echo "== $PASS passed, $FAILN failed"
[ "$FAILN" = 0 ]
