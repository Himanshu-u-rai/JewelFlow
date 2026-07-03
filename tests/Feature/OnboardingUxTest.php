<?php

namespace Tests\Feature;

use App\Models\OnboardingBatch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShopPreferences;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Onboarding Phase 1 UX — the front-of-house flow that makes opening-balance
 * setup understandable and reachable: landing decision (Start Clean vs Migrate),
 * the start-clean flag, resume/locked states, and the two nav entry points
 * (Import Data card + dashboard first-run prompt). No accounting logic here.
 */
class OnboardingUxTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_owner_sees_landing_with_both_choices(): void
    {
        [$user] = $this->createManufacturerTenant();

        $this->actingAs($user)->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('Start Clean')
            ->assertSee('Migrate Existing Shop');
    }

    public function test_manager_with_imports_permission_still_cannot_access_onboarding(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        $role = new Role();
        $role->forceFill(['name' => 'manager', 'display_name' => 'Manager', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::where('name', 'imports.manage')->pluck('id'));
        $staff = User::factory()->create(['shop_id' => $shop->id, 'role_id' => $role->id, 'is_active' => true]);

        $this->actingAs($staff)->get(route('onboarding.index'))->assertForbidden();
    }

    public function test_start_clean_saves_flag_and_shows_clean_state(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.start-clean'))
            ->assertRedirect(route('onboarding.index'));

        $this->assertNotNull(
            ShopPreferences::withoutTenant()->where('shop_id', $shop->id)->value('opening_setup_skipped_at'),
            'Start Clean must persist the flag.'
        );

        // The front screen now reflects the choice, not the landing decision.
        $this->actingAs($user)->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('Started clean');
    }

    public function test_draft_batch_shows_resume_wording(): void
    {
        [$user] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);

        $this->actingAs($user)->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('resume your migration');
    }

    public function test_locked_batch_shows_summary_not_empty_date_form(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch));

        $this->actingAs($user)->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('Opening balances locked')
            ->assertDontSee('Pick your JewelFlow go-live date');
    }

    public function test_import_data_page_shows_opening_balances_card_for_owner(): void
    {
        [$user] = $this->createManufacturerTenant();

        $this->actingAs($user)->get(route('imports.index'))
            ->assertOk()
            ->assertSee('Migrate Existing Shop');
    }

    public function test_dashboard_prompt_shows_for_fresh_owner(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $prompt = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->get(self::ERP . '/dashboard')
            ->assertOk()
            ->viewData('showOnboardingPrompt'));

        $this->assertTrue($prompt, 'A fresh owner shop must see the opening-balance prompt.');
    }

    public function test_dashboard_prompt_hidden_after_start_clean(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.start-clean'));

        $prompt = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->get(self::ERP . '/dashboard')
            ->assertOk()
            ->viewData('showOnboardingPrompt'));

        $this->assertFalse($prompt, 'Start Clean must suppress the dashboard prompt.');
    }
}
