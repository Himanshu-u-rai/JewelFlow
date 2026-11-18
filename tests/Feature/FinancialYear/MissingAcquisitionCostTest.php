<?php

namespace Tests\Feature\FinancialYear;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AcquisitionCostService;
use App\Services\FiscalYearSnapshotService;
use App\Services\YearEndChecklist;
use App\Support\FinancialYear;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * "Items without a purchase cost": the screen where the owner fills in the real cost of pieces whose cost was never
 * recorded, so year-end stock can be valued. Cost is confidential (inventory.edit AND reports.money). A cost is set once;
 * the database trigger items_acquisition_cost_guard_trigger refuses any later change.
 */
class MissingAcquisitionCostTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, SnapshotTestHelpers;

    private $owner;
    private $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->today();
    }

    private function unknown(array $attrs = [])
    {
        return $this->createItem($this->shop->id, null, $attrs + ['acquisition_cost' => null]);
    }

    private function userWith(array $permissions, string $mobile): User
    {
        $role = new Role();
        $role->forceFill(['name' => 'r' . $mobile, 'display_name' => 'R', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id, 'is_active' => true, 'mobile_number' => $mobile]);
    }

    private function as(User $u, string $method, string $url, array $data = [])
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($u)->{$method}($url, $data));
    }

    private function cost(int $id): ?string
    {
        return DB::table('items')->where('id', $id)->value('acquisition_cost');
    }

    private function source(int $id): ?string
    {
        return DB::table('items')->where('id', $id)->value('acquisition_cost_source');
    }

    private function save(array $costs, ?User $u = null, array $extra = [])
    {
        return $this->as($u ?? $this->owner, 'post', route('inventory.missing-costs.store', [], false), ['costs' => $costs] + $extra);
    }

    // ---- the list -------------------------------------------------------------------------------------------

    public function test_list_shows_only_unknown_cost_in_stock_items_of_this_shop(): void
    {
        $a = $this->unknown(['barcode' => 'MISSING-A']);
        $this->createItem($this->shop->id, null, ['barcode' => 'HAS-COST', 'acquisition_cost' => 1000, 'acquisition_cost_source' => 'entered']);
        $this->unknown(['barcode' => 'SOLD-ONE', 'status' => 'sold']);
        [, $otherShop] = $this->createRetailerTenant();
        $this->createItem($otherShop->id, null, ['barcode' => 'OTHER-SHOP', 'acquisition_cost' => null]);

        $html = $this->as($this->owner, 'get', route('inventory.missing-costs', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('MISSING-A', $html);
        $this->assertStringNotContainsString('HAS-COST', $html);
        $this->assertStringNotContainsString('SOLD-ONE', $html);
        $this->assertStringNotContainsString('OTHER-SHOP', $html);
        $this->assertStringContainsString('costs[' . $a->id . ']', $html);
    }

    public function test_search_by_barcode_and_design(): void
    {
        $this->unknown(['barcode' => 'NK-001', 'design' => 'Temple Necklace']);
        $this->unknown(['barcode' => 'RG-002', 'design' => 'Plain Ring']);

        $byBarcode = $this->as($this->owner, 'get', route('inventory.missing-costs', ['search' => 'NK-001'], false))->assertOk()->getContent();
        $this->assertStringContainsString('NK-001', $byBarcode);
        $this->assertStringNotContainsString('RG-002', $byBarcode);

        $byDesign = $this->as($this->owner, 'get', route('inventory.missing-costs', ['search' => 'plain ring'], false))->assertOk()->getContent();
        $this->assertStringContainsString('RG-002', $byDesign);
        $this->assertStringNotContainsString('NK-001', $byDesign);
    }

    public function test_list_is_paginated(): void
    {
        for ($i = 1; $i <= 32; $i++) {
            $this->unknown(['barcode' => sprintf('PG-%03d', $i)]);
        }
        $page1 = $this->as($this->owner, 'get', route('inventory.missing-costs', [], false))->assertOk()->getContent();
        $page2 = $this->as($this->owner, 'get', route('inventory.missing-costs', ['page' => 2], false))->assertOk()->getContent();

        $this->assertSame(25, substr_count($page1, 'data-missing-cost-row'));
        $this->assertSame(7, substr_count($page2, 'data-missing-cost-row'));
    }

    public function test_hint_prefers_the_purchase_rate_and_falls_back_to_cost_price_always_labelled_suggestion(): void
    {
        $bought = $this->unknown(['barcode' => 'BOUGHT-1', 'cost_price' => 51111]);
        $plain = $this->unknown(['barcode' => 'PLAIN-1', 'cost_price' => 52222]);
        $purchaseId = DB::table('stock_purchases')->insertGetId($this->purchaseRow());
        DB::table('stock_purchase_items')->insert([
            'stock_purchase_id' => $purchaseId, 'shop_id' => $this->shop->id, 'item_id' => $bought->id,
            'purchase_rate_per_gram' => 6123.5, 'purchase_line_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->as($this->owner, 'get', route('inventory.missing-costs', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('6,123.50', $html);   // purchase rate hint
        $this->assertStringContainsString('52,222', $html);     // cost_price hint
        $this->assertStringContainsString('Suggestion', $html);
        // never pre-filled: the cost inputs carry no value
        $this->assertDoesNotMatchRegularExpression('/name="costs\[\d+\]"[^>]*value="[^"]+"/', $html);
        $this->assertNull($this->cost($plain->id));
    }

    private function purchaseRow(): array
    {
        $cols = DB::getSchemaBuilder()->getColumnListing('stock_purchases');
        $row = ['shop_id' => $this->shop->id, 'status' => 'confirmed', 'purchase_date' => '2026-01-10', 'created_at' => now(), 'updated_at' => now()];
        foreach (['user_id', 'created_by'] as $c) {
            if (in_array($c, $cols, true)) {
                $row[$c] = $this->owner->id;
            }
        }

        return array_intersect_key($row, array_flip($cols)) + $this->requiredPurchaseDefaults();
    }

    private function requiredPurchaseDefaults(): array
    {
        $out = [];
        foreach (DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'stock_purchases' AND is_nullable = 'NO' AND column_default IS NULL") as $c) {
            if (in_array($c->column_name, ['id', 'shop_id', 'status', 'purchase_date', 'created_at', 'updated_at', 'user_id', 'created_by'], true)) {
                continue;
            }
            $out[$c->column_name] = match (true) {
                str_contains($c->data_type, 'int'), $c->data_type === 'numeric' => 0,
                $c->data_type === 'date' => '2026-01-10',
                str_starts_with($c->data_type, 'timestamp') => now(),
                default => 'x',
            };
        }

        return $out;
    }

    // ---- saving ---------------------------------------------------------------------------------------------

    public function test_save_sets_cost_with_source_entered_and_writes_an_audit_row(): void
    {
        $item = $this->unknown();

        $this->save([$item->id => '41,234.50'], null, ['note' => 'from purchase bill 118'])->assertRedirect();

        $this->assertSame('41234.50', $this->cost($item->id));
        $this->assertSame('entered', $this->source($item->id));
        $log = AuditLog::withoutGlobalScopes()->where('action', 'item.acquisition_cost_filled')->where('model_id', $item->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($this->shop->id, (int) $log->shop_id);
        $this->assertSame($this->owner->id, (int) $log->user_id);
        $data = is_array($log->data) ? $log->data : json_decode((string) $log->data, true);
        $this->assertEqualsWithDelta(41234.50, (float) $data['amount'], 0.001);
        $this->assertSame('from purchase bill 118', $data['note']);
    }

    public function test_second_save_on_the_same_item_is_refused_with_a_message_and_value_unchanged(): void
    {
        $item = $this->unknown();
        $this->save([$item->id => '1000'])->assertRedirect();

        $r = $this->save([$item->id => '2000']);

        $r->assertRedirect()->assertSessionHasErrors(['costs.' . $item->id]);
        $this->assertStringContainsString('already', strtolower(session('errors')->first('costs.' . $item->id)));
        $this->assertSame('1000.00', $this->cost($item->id));
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'item.acquisition_cost_filled')->where('model_id', $item->id)->count());
    }

    public function test_zero_negative_and_non_numeric_amounts_are_refused(): void
    {
        foreach (['0', '-5', 'abc', '1e9', '12 34', '1,2,3', '1,23,4','0.00', '99999999999999999'] as $bad) {
            $item = $this->unknown();
            $this->save([$item->id => $bad])->assertSessionHasErrors(['costs.' . $item->id]);
            $this->assertNull($this->cost($item->id), "'{$bad}' must not be saved");
            $this->assertSame('unknown', $this->source($item->id));
        }
    }

    public function test_bulk_save_is_per_row_valid_rows_saved_invalid_rows_reported_blank_rows_ignored(): void
    {
        $ok1 = $this->unknown();
        $ok2 = $this->unknown();
        $bad = $this->unknown();
        $blank = $this->unknown();
        $done = $this->createItem($this->shop->id, null, ['acquisition_cost' => 500, 'acquisition_cost_source' => 'entered']);

        $r = $this->save([$ok1->id => '100', $ok2->id => '250.5', $bad->id => '-1', $blank->id => '', $done->id => '999']);

        $r->assertSessionHasErrors(['costs.' . $bad->id, 'costs.' . $done->id]);
        $this->assertSame('100.00', $this->cost($ok1->id));
        $this->assertSame('250.50', $this->cost($ok2->id));
        $this->assertNull($this->cost($bad->id));
        $this->assertNull($this->cost($blank->id));
        $this->assertSame('500.00', $this->cost($done->id));
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('action', 'item.acquisition_cost_filled')->count());
        $this->assertSame(2, (int) session('missing_cost_saved'));
    }

    public function test_row_save_button_saves_only_that_row(): void
    {
        $a = $this->unknown();
        $b = $this->unknown();

        $this->save([$a->id => '100', $b->id => '200'], null, ['only' => $a->id])->assertRedirect();

        $this->assertSame('100.00', $this->cost($a->id));
        $this->assertNull($this->cost($b->id));
    }

    public function test_indian_and_western_grouping_and_rupee_sign_are_accepted(): void
    {
        $a = $this->unknown();
        $b = $this->unknown();
        $c = $this->unknown();
        $this->save([$a->id => '₹ 12,34,567.5', $b->id => '1,234,567', $c->id => '  250  '])->assertSessionHasNoErrors();
        $this->assertSame('1234567.50', $this->cost($a->id));
        $this->assertSame('1234567.00', $this->cost($b->id));
        $this->assertSame('250.00', $this->cost($c->id));
    }

    public function test_a_sold_piece_cannot_be_given_a_cost_here_and_a_bad_row_key_is_ignored(): void
    {
        $sold = $this->unknown(['status' => 'sold']);
        $this->save([$sold->id => '500', 'abc' => '5', '1; DROP TABLE items' => '5'])->assertSessionHasErrors(['costs.' . $sold->id]);
        $this->assertNull($this->cost($sold->id));
    }

    public function test_item_text_is_escaped_in_the_list(): void
    {
        $this->unknown(['design' => '<script>alert(1)</script>']);
        $html = $this->as($this->owner, 'get', route('inventory.missing-costs', [], false))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_an_empty_submission_saves_nothing_and_does_not_error(): void
    {
        $a = $this->unknown();
        $this->save([$a->id => ''])->assertRedirect();
        $this->assertNull($this->cost($a->id));
    }

    public function test_service_refuses_non_stock_cross_shop_and_already_set_items(): void
    {
        $svc = app(AcquisitionCostService::class);
        [, $otherShop] = $this->createRetailerTenant();
        $foreign = $this->createItem($otherShop->id, null, ['acquisition_cost' => null]);

        $this->expectException(\DomainException::class);
        TenantContext::runFor($this->shop->id, fn () => $svc->fill(Item::withoutTenant()->findOrFail($foreign->id), 10, $this->owner));
    }

    public function test_service_fill_leaves_the_current_cost_price_alone(): void
    {
        $item = $this->unknown(['cost_price' => 77777]);
        TenantContext::runFor($this->shop->id, fn () => app(AcquisitionCostService::class)->fill($item, 1234, $this->owner, null));

        $this->assertSame('77777.00', (string) DB::table('items')->where('id', $item->id)->value('cost_price'));
        $this->assertSame('1234.00', $this->cost($item->id));
    }

    // ---- effect on year-end ---------------------------------------------------------------------------------

    public function test_filling_a_cost_drops_the_checklist_count_and_the_closing_stock_valuation_includes_it(): void
    {
        $this->rate($this->shop->id, '2026-03-31', 'gold', 22.0, 6600);
        $this->at('2026-02-01 10:00:00');
        $known = $this->createItem($this->shop->id, null, ['acquisition_cost' => 40000, 'acquisition_cost_source' => 'entered']);
        $item = $this->unknown();
        $this->today();
        $fy = FinancialYear::fromLabel('2025-26');
        $count = fn () => collect(app(YearEndChecklist::class)->for($this->shop->id, $fy))->firstWhere('key', 'unknown_cost_items')['count'];
        $this->assertSame(1, $count());

        $this->save([$item->id => '30000'])->assertRedirect();

        $this->assertSame(0, $count());
        $snap = app(FiscalYearSnapshotService::class)->take($this->shop->id, $fy, 'closing');
        $t = is_string($snap->totals) ? json_decode($snap->totals, true) : (array) $snap->totals;
        $this->assertSame(0, $t['unknown_cost_count']);
        $this->assertEqualsWithDelta(70000.0, (float) $t['total_acquisition_cost'], 0.001);
        $this->assertNotNull($known);
    }

    // ---- permissions ----------------------------------------------------------------------------------------

    public function test_users_without_reports_money_or_inventory_edit_get_403_on_page_and_save(): void
    {
        $item = $this->unknown();
        $noMoney = $this->userWith(['inventory.view', 'inventory.edit', 'reports.view'], '9810002001');
        $noEdit = $this->userWith(['inventory.view', 'reports.view', 'reports.money'], '9810002002');

        foreach ([$noMoney, $noEdit] as $u) {
            $this->as($u, 'get', route('inventory.missing-costs', [], false))->assertForbidden();
            $this->save([$item->id => '500'], $u)->assertForbidden();
        }
        $this->assertNull($this->cost($item->id));
    }

    public function test_user_with_both_permissions_may_use_it(): void
    {
        $item = $this->unknown();
        $mgr = $this->userWith(['inventory.view', 'inventory.edit', 'reports.view', 'reports.money'], '9810002003');

        $this->as($mgr, 'get', route('inventory.missing-costs', [], false))->assertOk();
        $this->save([$item->id => '500'], $mgr)->assertRedirect();
        $this->assertSame('500.00', $this->cost($item->id));
    }

    public function test_another_shops_item_id_is_refused_and_untouched(): void
    {
        [, $otherShop] = $this->createRetailerTenant();
        $foreign = $this->createItem($otherShop->id, null, ['acquisition_cost' => null]);

        $r = $this->save([$foreign->id => '500']);

        $this->assertTrue(in_array($r->getStatusCode(), [302, 403, 404], true));
        $this->assertNull($this->cost($foreign->id));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'item.acquisition_cost_filled')->count());
    }

    // ---- markup ---------------------------------------------------------------------------------------------

    public function test_form_breaks_out_of_turbo_frames_and_phone_rows_carry_data_labels(): void
    {
        $this->unknown();
        $html = $this->as($this->owner, 'get', route('inventory.missing-costs', [], false))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form[^>]*method="POST"[^>]*data-turbo-frame="_top"/', $html);
        foreach (['Barcode', 'Design', 'Net weight', 'Purchase cost'] as $label) {
            $this->assertStringContainsString('data-label="' . $label . '"', $html);
        }
    }

    public function test_link_is_shown_to_allowed_users_only_on_year_end_stock_and_closing_stock_notice(): void
    {
        $this->rate($this->shop->id, '2026-03-31', 'gold', 22.0, 6600);
        $this->at('2026-02-01 10:00:00');
        $this->unknown();
        $this->today();
        $url = route('inventory.missing-costs', [], false);

        $yearEnd = $this->as($this->owner, 'get', '/books/year-end/2025-26')->assertOk()->getContent();
        $this->assertStringContainsString('href="' . url($url) . '"', $yearEnd);

        $stock = $this->as($this->owner, 'get', route('inventory.items.index', [], false))->assertOk()->getContent();
        $this->assertStringContainsString($url, $stock);

        $noMoney = $this->userWith(['inventory.view', 'inventory.edit', 'reports.view'], '9810002004');
        $stockNoMoney = $this->as($noMoney, 'get', route('inventory.items.index', [], false))->assertOk()->getContent();
        $this->assertStringNotContainsString('missing-costs', $stockNoMoney);
    }

    public function test_stock_page_has_no_notice_when_every_cost_is_known(): void
    {
        $this->createItem($this->shop->id, null, ['acquisition_cost' => 10, 'acquisition_cost_source' => 'entered']);
        $stock = $this->as($this->owner, 'get', route('inventory.items.index', [], false))->assertOk()->getContent();
        $this->assertStringNotContainsString('missing-costs', $stock);
    }
}
