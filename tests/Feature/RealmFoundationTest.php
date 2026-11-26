<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Realm;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Realm foundation: the realm column, per-realm uniqueness of mobile_number,
 * the User realm helpers and the Realm lookup helpers (realm defaults to 'erp').
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

    /** 3. A duplicate mobile within the SAME erp realm is rejected. */
    public function test_duplicate_mobile_within_erp_is_rejected(): void
    {
        $this->makeUser('9000000003', 'erp');
        $this->expectException(QueryException::class);
        $this->makeUser('9000000003', 'erp');
    }

    /** 5. Email behaviour: users.email has NO unique index, so the same email is
     *  allowed twice — documents the audited reality. */
    public function test_email_is_not_globally_unique(): void
    {
        User::create(['name' => 'A', 'mobile_number' => '9000000051', 'email' => 'same@ex.com', 'password' => bcrypt('x'), 'realm' => 'erp']);
        User::create(['name' => 'B', 'mobile_number' => '9000000052', 'email' => 'same@ex.com', 'password' => bcrypt('x'), 'realm' => 'erp']);
        $this->assertSame(2, User::where('email', 'same@ex.com')->count());
    }

    /** 6. User model realm helpers. */
    public function test_user_realm_helpers(): void
    {
        $erp = $this->makeUser('9000000006', 'erp');

        $this->assertTrue($erp->isErp());

        // scopeRealm
        $this->assertTrue(User::realm('erp')->where('id', $erp->id)->exists());
        $this->assertFalse(User::realm('other')->where('id', $erp->id)->exists());
    }

    /** 7. Realm::current() is always erp; findUserByMobile is realm-scoped. */
    public function test_realm_helper_is_erp(): void
    {
        $this->assertSame('erp', Realm::current());
        // findUserByMobile is realm-scoped (login must never resolve cross-realm)
        $this->makeUser('9000000008', 'erp');
        $this->assertSame('erp', Realm::findUserByMobile('9000000008', 'erp')->realm);
        $this->assertNull(Realm::findUserByMobile('9000000008', 'other'));
        $this->assertNull(Realm::findUserByMobile('9999999999', 'erp'));
    }

    /** 8. ERP regression: a plain user still works exactly as before — defaults erp,
     *  unique within erp, and the realm-scoped unique rule mirrors the index. */
    public function test_erp_regression_unchanged(): void
    {
        $u = $this->makeUser('9000000009'); // no realm → erp
        $this->assertSame('erp', $u->realm);

        // The realm-scoped uniqueness rule blocks the same realm and allows another.
        $erpRule = Realm::uniqueMobileRule('erp');
        $otherRule = Realm::uniqueMobileRule('other');

        $failErp = validator(['m' => '9000000009'], ['m' => [$erpRule]]);
        $passOther = validator(['m' => '9000000009'], ['m' => [$otherRule]]);

        $this->assertTrue($failErp->fails(), 'same mobile in erp must fail the erp rule');
        $this->assertFalse($passOther->fails(), 'same mobile in another realm must pass that realm rule');
    }
}
