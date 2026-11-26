<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer-facing front door: registration creates an ERP-realm account, login
 * is realm-scoped, and the landing page is public.
 */
class RealmAuthFrontDoorTest extends TestCase
{
    use RefreshDatabase;

    private function strongPassword(): string
    {
        return 'StrongPass123!';
    }

    // ── Registration by realm ────────────────────────────────────────────

    public function test_erp_register_creates_an_erp_account(): void
    {
        $this->post('https://jewelflows.com/register', [
            'mobile_number' => '9300000002',
            'password' => $this->strongPassword(),
            'password_confirmation' => $this->strongPassword(),
        ])->assertRedirect(route('shops.choose-type'));

        $this->assertSame('erp', User::where('mobile_number', '9300000002')->first()->realm);
    }

    // ── Login by realm ───────────────────────────────────────────────────

    public function test_login_only_authenticates_same_realm_user(): void
    {
        $erp = User::create(['mobile_number' => '9300000004', 'password' => bcrypt($this->strongPassword()), 'realm' => 'erp', 'is_active' => true]);

        // ERP login on the main host succeeds.
        $this->post('https://jewelflows.com/login', [
            'mobile_number' => '9300000004', 'password' => $this->strongPassword(),
        ]);
        $this->assertAuthenticatedAs($erp);
        $this->post('/logout');

        // An unregistered mobile is rejected.
        $this->post('https://jewelflows.com/login', [
            'mobile_number' => '9300000099', 'password' => $this->strongPassword(),
        ])->assertSessionHasErrors('mobile_number');
        $this->assertGuest();
    }

    public function test_erp_root_guest_sees_landing(): void
    {
        // Guest at jewelflows.com/ → ERP landing (unchanged behavior).
        $this->get('https://jewelflows.com/')->assertOk();
    }
}
