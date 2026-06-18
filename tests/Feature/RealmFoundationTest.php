<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Realm;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dhiran customer-facing separation — Phase 0 foundation.
 *
 * Verifies the realm column, per-realm uniqueness of mobile_number, the User
 * realm helpers, and the Realm host-detection helper — without touching any ERP
 * auth/UI behaviour (realm defaults to 'erp', so existing flows are unchanged).
 */
class RealmFoundationTest extends TestCase
{
    use RefreshDatabase;


    private function makeUser(string $mobile, ?string $realm = null): User
    {
        $attrs = ['name' => 'T', 'mobile_number' => $mobile, 'password' => bcrypt('x')];
        if ($realm !== null) {
            $attrs['realm'] = $realm;
        }
        return User::create($attrs);
    }

    /** 1. A user created without a realm defaults to 'erp' (existing behaviour preserved). */
    public function test_user_defaults_to_erp_realm(): void
    {
        $u = $this->makeUser('9000000001');
        $this->assertSame('erp', $u->fresh()->realm);
    }

    /** 2. The SAME mobile number can exist once in erp and once in dhiran. */
    public function test_same_mobile_can_exist_in_both_realms(): void
    {
        $erp = $this->makeUser('9000000002', 'erp');
        $dhiran = $this->makeUser('9000000002', 'dhiran');

        $this->assertSame('erp', $erp->fresh()->realm);
        $this->assertSame('dhiran', $dhiran->fresh()->realm);
        $this->assertSame(2, User::where('mobile_number', '9000000002')->count());
    }

    /** 3. A duplicate mobile within the SAME erp realm is rejected. */
    public function test_duplicate_mobile_within_erp_is_rejected(): void
    {
        $this->makeUser('9000000003', 'erp');
        $this->expectException(QueryException::class);
        $this->makeUser('9000000003', 'erp');
    }

    /** 4. A duplicate mobile within the SAME dhiran realm is rejected. */
    public function test_duplicate_mobile_within_dhiran_is_rejected(): void
    {
        $this->makeUser('9000000004', 'dhiran');
        $this->expectException(QueryException::class);
        $this->makeUser('9000000004', 'dhiran');
    }

    /** 5. Email behaviour: users.email has NO unique index, so the same email is
     *  allowed across (and within) realms — documents the audited reality. */
    public function test_email_is_not_globally_unique(): void
    {
        User::create(['name' => 'A', 'mobile_number' => '9000000051', 'email' => 'same@ex.com', 'password' => bcrypt('x'), 'realm' => 'erp']);
        User::create(['name' => 'B', 'mobile_number' => '9000000052', 'email' => 'same@ex.com', 'password' => bcrypt('x'), 'realm' => 'dhiran']);
        $this->assertSame(2, User::where('email', 'same@ex.com')->count());
    }

    /** 6. User model realm helpers. */
    public function test_user_realm_helpers(): void
    {
        $erp = $this->makeUser('9000000006', 'erp');
        $dhiran = $this->makeUser('9000000007', 'dhiran');

        $this->assertTrue($erp->isErp());
        $this->assertFalse($erp->isDhiran());
        $this->assertTrue($dhiran->isDhiran());
        $this->assertFalse($dhiran->isErp());

        // scopeRealm
        $this->assertTrue(User::realm('dhiran')->where('id', $dhiran->id)->exists());
        $this->assertFalse(User::realm('erp')->where('id', $dhiran->id)->exists());
    }

    /** 7. Realm helper detects the Dhiran host correctly. */
    public function test_realm_helper_detects_host(): void
    {
        $this->assertSame('dhiran', Realm::fromHost('dhiran.jewelflows.com'));
        $this->assertSame('dhiran', Realm::fromHost('dhiran.localhost'));
        $this->assertSame('erp', Realm::fromHost('jewelflows.com'));
        $this->assertSame('erp', Realm::fromHost('www.jewelflows.com'));
        $this->assertSame('erp', Realm::fromHost(null));
        // findUserByMobile is realm-scoped (login must never resolve cross-realm)
        $this->makeUser('9000000008', 'erp');
        $this->makeUser('9000000008', 'dhiran');
        $this->assertSame('erp', Realm::findUserByMobile('9000000008', 'erp')->realm);
        $this->assertSame('dhiran', Realm::findUserByMobile('9000000008', 'dhiran')->realm);
        $this->assertNull(Realm::findUserByMobile('9999999999', 'erp'));
    }

    /** 8. ERP regression: a plain user still works exactly as before — defaults erp,
     *  unique within erp, and the realm-scoped unique rule mirrors the index. */
    public function test_erp_regression_unchanged(): void
    {
        $u = $this->makeUser('9000000009'); // no realm → erp
        $this->assertSame('erp', $u->realm);

        // The realm-scoped uniqueness rule allows the other realm, blocks same realm.
        $erpRule = Realm::uniqueMobileRule('erp');
        $dhiranRule = Realm::uniqueMobileRule('dhiran');

        $failErp = validator(['m' => '9000000009'], ['m' => [$erpRule]]);
        $passDhiran = validator(['m' => '9000000009'], ['m' => [$dhiranRule]]);

        $this->assertTrue($failErp->fails(), 'same mobile in erp must fail the erp rule');
        $this->assertFalse($passDhiran->fails(), 'same mobile in dhiran must pass the dhiran rule');
    }
}
