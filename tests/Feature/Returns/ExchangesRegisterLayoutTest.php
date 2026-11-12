<?php

namespace Tests\Feature\Returns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * UI audit F13: the Exchanges register follows the current register pattern
 * (Schemes / Stock purchases): header search icon, responsive filter panel,
 * compact summary figures, indexed table card, orange primary (no indigo).
 */
class ExchangesRegisterLayoutTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function ownerWithReturnsView(): User
    {
        [$user] = $this->createRetailerTenant();
        $role = Role::withoutTenant()->findOrFail($user->role_id);
        $role->permissions()->sync(Permission::query()->whereIn('name', ['returns.view'])->pluck('id'));

        return $user;
    }

    public function test_register_uses_current_pattern_and_no_indigo(): void
    {
        $user = $this->ownerWithReturnsView();

        $html = $this->actingAs($user)->get(route('exchanges.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('bg-indigo', $html);
        $this->assertStringNotContainsString('ring-indigo', $html);
        $this->assertStringNotContainsString('rgb(79,70,229)', str_replace(' ', '', $html));
        $this->assertStringNotContainsString('#4f46e5', strtolower($html));

        // Same filter component marker as Schemes / Purchases.
        $this->assertStringContainsString('id="exchanges-filter-panel"', $html);
        $this->assertStringContainsString('jf-filter-panel', $html);
        $this->assertStringContainsString('data-filter-toggle', $html);
        $this->assertStringContainsString('customers-directory-search-toggle', $html);
        $this->assertStringContainsString('btn btn-primary btn-sm customers-directory-apply', $html);
        $this->assertStringContainsString('customers-directory-summary', $html);
        $this->assertStringContainsString('name="customer"', $html);
        $this->assertStringContainsString('name="status"', $html);
        $this->assertStringContainsString('name="from"', $html);
        $this->assertStringContainsString('name="to"', $html);
    }

    public function test_empty_state_is_inside_the_table_card(): void
    {
        $user = $this->ownerWithReturnsView();

        $html = $this->actingAs($user)->get(route('exchanges.index'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Row number"', $html);
        $this->assertStringContainsString('No exchanges found', $html);
        $this->assertStringContainsString('jf-table-empty', $html);
    }

    public function test_existing_filters_are_still_accepted(): void
    {
        $user = $this->ownerWithReturnsView();

        $html = $this->actingAs($user)
            ->get(route('exchanges.index', ['status' => 'settled', 'customer' => 'zzz', 'from' => '2026-01-01', 'to' => '2026-12-31']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('value="zzz"', $html);
        $this->assertStringContainsString('value="2026-01-01"', $html);
        $this->assertStringContainsString('customers-directory-clear', $html);
    }
}
