<?php

namespace Tests\Feature\Reporting;

use App\Models\Permission;
use App\Models\Reporting\ReportingPreset;
use App\Models\Role;
use App\Models\User;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\Support\Reporting\StubReportService;
use Tests\TestCase;

/**
 * Shop-wide export presets (frozen §8, §21; GAP 1).
 *
 * A preset is a convenience that pre-fills the export panel. It is shop-scoped,
 * owner/manager-managed, and can NEVER bypass the export permission gate — the
 * real export still runs through ExportRequest::authorize(). These tests lock
 * the scoping, the management gate, and the no-privilege-escalation guarantee.
 */
class ExportPresetTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();

        // StubReportService is an Accounting report with a sensitive 'cost'
        // column, default permissions (view/export/export_sensitive).
        app(ReportRegistry::class)->register(StubReportService::KEY, StubReportService::class);

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);
    }

    private const KEY = StubReportService::KEY;

    /** Set the user's role to EXACTLY these permissions (within tenant). */
    private function restrictTo(User $user, array $names): void
    {
        $ids = Permission::whereIn('name', $names)->pluck('id');
        TenantContext::runFor((int) $user->shop_id, function () use ($user, $ids) {
            $user->role->permissions()->sync($ids);
        });
        $user->unsetRelation('role');
    }

    /** A non-owner user (named role) in the given shop with the given permissions. */
    private function makeRoleUser(int $shopId, string $roleName, array $perms): User
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $roleName, $perms) {
            $role = new Role();
            $role->forceFill(['name' => $roleName, 'display_name' => ucfirst($roleName), 'shop_id' => $shopId])->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::factory()->create(['shop_id' => $shopId, 'role_id' => $role->id, 'is_active' => true]);
        });
    }

    private function postAs(User $user, string $url, array $data = [])
    {
        return TenantContext::runFor((int) $user->shop_id, fn () => $this->actingAs($user)->post($url, $data));
    }

    private function getJsonAs(User $user, string $url)
    {
        return TenantContext::runFor((int) $user->shop_id, fn () => $this->actingAs($user)->getJson($url));
    }

    private function presetUrl(?int $id = null): string
    {
        $base = '/reports/' . self::KEY . '/export-presets';

        return $id ? "{$base}/{$id}" : $base;
    }

    // 1. Owner can save a preset for their shop.
    public function test_owner_can_save_preset(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();

        $this->postAs($owner, $this->presetUrl(), [
            'name' => 'Monthly CA Export', 'profile' => 'detailed', 'format' => 'csv',
            'filters' => ['date_preset' => 'last_month'],
        ])->assertRedirect();

        $preset = ReportingPreset::withoutTenant()->where('shop_id', $shop->id)->first();
        $this->assertNotNull($preset);
        // NormalizeHumanTextInput title-cases `name` inputs app-wide, so the
        // stored value is the normalized form (consistent with every other name field).
        $this->assertSame('Monthly Ca Export', $preset->name);
        $this->assertSame('csv', $preset->format);
        $this->assertSame($shop->id, $preset->shop_id);
        $this->assertSame($owner->id, $preset->created_by);
    }

    // 2. Owner can load/apply their preset (panel pre-fills from it).
    public function test_owner_can_apply_preset(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $preset = TenantContext::runFor($shop->id, fn () => ReportingPreset::create([
            'name' => 'Standard', 'report_key' => self::KEY, 'profile' => 'summary',
            'format' => 'excel', 'filters' => ['date_preset' => 'this_fy'], 'created_by' => $owner->id,
        ]));

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/reports/' . self::KEY . '/export?preset=' . $preset->id));

        $res->assertOk();
        $res->assertViewHas('appliedPreset', fn ($p) => $p !== null && $p->id === $preset->id);
    }

    // 3. Owner can delete their preset.
    public function test_owner_can_delete_preset(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $preset = TenantContext::runFor($shop->id, fn () => ReportingPreset::create([
            'name' => 'Throwaway', 'report_key' => self::KEY, 'created_by' => $owner->id,
        ]));

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->delete($this->presetUrl($preset->id)))->assertRedirect();

        $this->assertNull(ReportingPreset::withoutTenant()->find($preset->id));
    }

    // 4. Preset is scoped to the current shop (index returns only own-shop rows).
    public function test_preset_index_is_shop_scoped(): void
    {
        [$ownerA, $shopA] = $this->createManufacturerTenant();
        [$ownerB, $shopB] = $this->createManufacturerTenant();

        TenantContext::runFor($shopA->id, fn () => ReportingPreset::create(['name' => 'A-preset', 'report_key' => self::KEY, 'created_by' => $ownerA->id]));
        TenantContext::runFor($shopB->id, fn () => ReportingPreset::create(['name' => 'B-preset', 'report_key' => self::KEY, 'created_by' => $ownerB->id]));

        $res = $this->getJsonAs($ownerA, $this->presetUrl());
        $res->assertOk();
        $names = collect($res->json('presets'))->pluck('name');
        $this->assertContains('A-preset', $names->all());
        $this->assertNotContains('B-preset', $names->all());
    }

    // 5. Another shop cannot view / apply / delete the preset.
    public function test_other_shop_cannot_touch_preset(): void
    {
        [$ownerA, $shopA] = $this->createManufacturerTenant();
        [$ownerB, $shopB] = $this->createManufacturerTenant();

        $presetA = TenantContext::runFor($shopA->id, fn () => ReportingPreset::create(['name' => 'A-only', 'report_key' => self::KEY, 'created_by' => $ownerA->id]));

        // B applying A's preset id: the panel must not pre-fill it (cross-shop → null).
        $apply = TenantContext::runFor($shopB->id, fn () => $this->actingAs($ownerB)
            ->get('/reports/' . self::KEY . '/export?preset=' . $presetA->id));
        $apply->assertOk();
        $apply->assertViewHas('appliedPreset', null);

        // B deleting A's preset id → 404 (BelongsToShop scope + shop backstop).
        TenantContext::runFor($shopB->id, fn () => $this->actingAs($ownerB)
            ->delete($this->presetUrl($presetA->id)))->assertNotFound();

        $this->assertNotNull(ReportingPreset::withoutTenant()->find($presetA->id));
    }

    // 6. A preset cannot bypass the sensitive-column gate at export time.
    public function test_preset_cannot_bypass_sensitive_gate_at_export(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        // Owner without sensitive permission, but with view+export.
        $this->restrictTo($owner, ['reports.view', 'reports.export']);

        // Even if a request directly asks for the sensitive column, ExportRequest
        // hard-403s — a preset is just request input, so it gets the same gate.
        $res = $this->postAs($owner, '/reports/' . self::KEY . '/export', [
            'profile' => 'detailed', 'format' => 'csv',
            'date_preset' => 'this_month', 'sensitive' => ['cost'],
        ]);
        $res->assertForbidden();
    }

    // 7. Saving a preset with sensitive columns drops them if the saver lacks permission.
    public function test_saving_preset_strips_sensitive_columns_without_permission(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->restrictTo($owner, ['reports.view', 'reports.export']); // no export_sensitive

        $this->postAs($owner, $this->presetUrl(), [
            'name' => 'Tries Sensitive', 'profile' => 'detailed', 'format' => 'csv',
            'columns' => ['a', 'cost'], // 'cost' is sensitive
        ])->assertRedirect();

        $preset = ReportingPreset::withoutTenant()->where('shop_id', $shop->id)->first();
        $this->assertNotNull($preset);
        $this->assertContains('a', $preset->columns);
        $this->assertNotContains('cost', $preset->columns); // stripped
    }

    // 8. Invalid dataset/report key is rejected (404 via registry).
    public function test_invalid_report_key_rejected(): void
    {
        [$owner] = $this->createManufacturerTenant();

        TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)
            ->post('/reports/no-such-report/export-presets', ['name' => 'X']))
            ->assertNotFound();
    }

    // 9. Invalid format is rejected (must be one the report offers).
    public function test_invalid_format_rejected(): void
    {
        [$owner] = $this->createManufacturerTenant();

        $this->postAs($owner, $this->presetUrl(), [
            'name' => 'Bad Format', 'profile' => 'summary', 'format' => 'pptx',
        ])->assertSessionHasErrors('format');
    }

    // 9b. Invalid profile is rejected.
    public function test_invalid_profile_rejected(): void
    {
        [$owner] = $this->createManufacturerTenant();

        $this->postAs($owner, $this->presetUrl(), [
            'name' => 'Bad Profile', 'profile' => 'nonsense', 'format' => 'csv',
        ])->assertSessionHasErrors('profile');
    }

    // 10. A non-owner with export can USE presets but cannot manage them.
    public function test_non_owner_can_use_but_not_manage(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $preset = TenantContext::runFor($shop->id, fn () => ReportingPreset::create(['name' => 'Shared', 'report_key' => self::KEY, 'created_by' => $owner->id]));

        $cashier = $this->makeRoleUser($shop->id, 'cashier', ['reports.view', 'reports.export']);

        // Use (apply/index) → allowed.
        $this->getJsonAs($cashier, $this->presetUrl())->assertOk();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($cashier)
            ->get('/reports/' . self::KEY . '/export?preset=' . $preset->id))->assertOk();

        // Manage (save/delete) → 403.
        $this->postAs($cashier, $this->presetUrl(), ['name' => 'Sneaky', 'format' => 'csv'])->assertForbidden();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($cashier)
            ->delete($this->presetUrl($preset->id)))->assertForbidden();
        $this->assertNotNull(ReportingPreset::withoutTenant()->find($preset->id));
    }

    // 10b. A manager (non-owner) CAN manage presets (frozen §8: owner OR manager).
    public function test_manager_can_manage_presets(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $manager = $this->makeRoleUser($shop->id, 'manager', ['reports.view', 'reports.export']);

        $this->postAs($manager, $this->presetUrl(), ['name' => 'Mgr Preset', 'format' => 'csv'])
            ->assertRedirect();
        $this->assertNotNull(ReportingPreset::withoutTenant()->where('name', 'Mgr Preset')->first());
    }

    // 11. A user with no export permission cannot reach preset routes at all.
    public function test_no_export_permission_blocks_preset_routes(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->restrictTo($owner, ['reports.view']); // no reports.export

        $this->getJsonAs($owner, $this->presetUrl())->assertForbidden();
        $this->postAs($owner, $this->presetUrl(), ['name' => 'X', 'format' => 'csv'])->assertForbidden();
    }
}
