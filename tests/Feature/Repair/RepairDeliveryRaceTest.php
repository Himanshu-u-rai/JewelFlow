<?php

namespace Tests\Feature\Repair;

use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Repair;
use App\Models\Shop;
use App\Models\User;
use App\Services\InvoiceAccountingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit H1 (2026-11-13): the web read a repair's status before its transaction and never locked the row, so a
 * delivery landing in between (a second click, or the app) was billed again: two invoices and two cash entries for
 * one repair, the first invoice orphaned. A status change or edit racing a delivery could also put a billed repair
 * back to "ready", where it could be delivered and billed again.
 *
 * Each race is reproduced the way it happens: the request has already loaded the repair (route binding) when
 * another cashier's delivery commits.
 */
class RepairDeliveryRaceTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    /** @return array{User, Shop, Customer, Repair} */
    private function readyRepair(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->post(self::ERP . '/repairs', [
            'customer_id' => $customer->id, 'item_description' => 'Gold chain clasp', 'gross_weight' => 5.0,
        ]))->assertRedirect();
        $repair = Repair::withoutGlobalScopes()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
        DB::table('repairs')->where('id', $repair->id)->update(['status' => 'ready']);

        return [$owner, $shop, $customer, $repair->fresh()];
    }

    /** Another cashier's delivery commits right after this request has loaded the repair. */
    private function deliverElsewhereOnceLoaded(Repair $repair): \stdClass
    {
        $other = new \stdClass();
        $other->invoiceId = null;
        Event::listen('eloquent.retrieved: ' . Repair::class, function (Repair $loaded) use ($repair, $other) {
            if ($other->invoiceId !== null || $loaded->id !== $repair->id) {
                return;
            }
            $invoice = InvoiceAccountingService::createFinalized([
                'shop_id' => $repair->shop_id, 'customer_id' => $repair->customer_id, 'gold_rate' => 0,
                'subtotal' => 700, 'gst' => 0, 'gst_rate' => 0, 'wastage_charge' => 0, 'total' => 700, 'round_off' => 0,
            ]);
            $other->invoiceId = $invoice->id;
            DB::table('repairs')->where('id', $repair->id)
                ->update(['status' => 'delivered', 'invoice_id' => $invoice->id, 'final_cost' => 700]);
        });

        return $other;
    }

    private function invoiceCount(Shop $shop): int
    {
        return Invoice::withoutGlobalScopes()->where('shop_id', $shop->id)->count();
    }

    private function repairCashRows(Shop $shop): int
    {
        return CashTransaction::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where('description', 'like', 'Repair delivery%')->count();
    }

    public function test_web_delivery_refuses_a_repair_delivered_after_the_request_loaded_it(): void
    {
        [$owner, $shop, , $repair] = $this->readyRepair();
        $other = $this->deliverElsewhereOnceLoaded($repair);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/repairs/' . $repair->id . '/deliver', ['amount' => 500, 'include_gst' => 0]))
            ->assertSessionHas('error');

        $this->assertNotNull($other->invoiceId, 'the race was staged');
        $this->assertSame(1, $this->invoiceCount($shop), 'billed once, by the delivery that landed first');
        $this->assertSame(0, $this->repairCashRows($shop), 'no second cash entry');
        $this->assertSame($other->invoiceId, (int) $repair->fresh()->invoice_id, 'the first invoice is not orphaned');
    }

    public function test_web_status_change_cannot_undo_a_delivery_that_landed_meanwhile(): void
    {
        [$owner, $shop, , $repair] = $this->readyRepair();
        $this->deliverElsewhereOnceLoaded($repair);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->patch(self::ERP . '/repairs/' . $repair->id . '/status', ['status' => 'in_repair']))
            ->assertSessionHas('error');

        $this->assertSame('delivered', $repair->fresh()->status);
    }

    public function test_web_edit_cannot_undo_a_delivery_that_landed_meanwhile(): void
    {
        [$owner, $shop, $customer, $repair] = $this->readyRepair();
        $this->deliverElsewhereOnceLoaded($repair);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->put(self::ERP . '/repairs/' . $repair->id, [
                'customer_id' => $customer->id, 'item_description' => 'Edited', 'gross_weight' => 5.0, 'status' => 'ready',
            ]))
            ->assertSessionHas('error');

        $fresh = $repair->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertSame('Gold chain clasp', $fresh->item_description, 'a billed repair is not edited');
    }

    public function test_web_delete_cannot_remove_a_repair_delivered_meanwhile(): void
    {
        [$owner, $shop, , $repair] = $this->readyRepair();
        $this->deliverElsewhereOnceLoaded($repair);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->delete(self::ERP . '/repairs/' . $repair->id))
            ->assertSessionHas('error');

        $this->assertNotNull(Repair::withoutGlobalScopes()->find($repair->id), 'the billed repair still exists');
    }

    public function test_app_delivery_refuses_a_repair_delivered_after_the_request_loaded_it(): void
    {
        [$owner, $shop, , $repair] = $this->readyRepair();
        $token = $this->appToken($owner, $shop);
        $this->deliverElsewhereOnceLoaded($repair);

        $this->app['auth']->forgetGuards();
        TenantContext::set($shop->id);
        $this->json('POST', "/api/mobile/repairs/{$repair->id}/deliver", ['amount' => 500],
            ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'X-Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable();

        $this->assertSame(1, $this->invoiceCount($shop));
        $this->assertSame(0, $this->repairCashRows($shop));
    }

    public function test_app_edit_cannot_change_a_repair_delivered_meanwhile(): void
    {
        [$owner, $shop, , $repair] = $this->readyRepair();
        $token = $this->appToken($owner, $shop);
        $this->deliverElsewhereOnceLoaded($repair);

        $this->app['auth']->forgetGuards();
        TenantContext::set($shop->id);
        $this->json('PUT', "/api/mobile/repairs/{$repair->id}", ['item_description' => 'Edited'],
            ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'X-Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable();

        $this->assertSame('Gold chain clasp', $repair->fresh()->item_description);
    }

    /** Found while fixing H1: the web edit deleted the old photo before saving, so a refused edit lost it. */
    public function test_a_refused_web_edit_keeps_the_repairs_photo(): void
    {
        [$owner, $shop, $customer, $repair] = $this->readyRepair();
        \Illuminate\Support\Facades\Storage::fake(Repair::imageWriteDisk());
        \Illuminate\Support\Facades\Storage::disk(Repair::imageWriteDisk())->put('repairs/old.jpg', 'old');
        DB::table('repairs')->where('id', $repair->id)->update(['image_path' => 'repairs/old.jpg', 'image' => 'repairs/old.jpg']);
        $this->deliverElsewhereOnceLoaded($repair);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->put(self::ERP . '/repairs/' . $repair->id, [
                'customer_id' => $customer->id, 'item_description' => 'Edited', 'gross_weight' => 5.0, 'status' => 'ready',
                'image' => \Illuminate\Http\UploadedFile::fake()->image('new.jpg'),
            ]))
            ->assertSessionHas('error');

        $disk = \Illuminate\Support\Facades\Storage::disk(Repair::imageWriteDisk());
        $this->assertTrue($disk->exists('repairs/old.jpg'), 'the photo of the billed repair is kept');
        $this->assertSame(['repairs/old.jpg'], $disk->allFiles('repairs'), 'the refused upload is not left behind');
    }

    /** Web and app deliver through one path: same invoice, payment and cash rules. */
    public function test_web_and_app_delivery_bill_the_same_way(): void
    {
        [$owner, $shop, $customer, $web] = $this->readyRepair();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/repairs/' . $web->id . '/deliver', ['amount' => 1000, 'include_gst' => 1, 'gst_rate' => 3]))
            ->assertRedirect();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->post(self::ERP . '/repairs', [
            'customer_id' => $customer->id, 'item_description' => 'Ring sizing', 'gross_weight' => 2.0,
        ]))->assertRedirect();
        $app = Repair::withoutGlobalScopes()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
        $token = $this->appToken($owner, $shop);
        $this->app['auth']->forgetGuards();
        TenantContext::set($shop->id);
        $this->json('POST', "/api/mobile/repairs/{$app->id}/deliver", ['amount' => 1000, 'include_gst' => true, 'gst_rate' => 3],
            ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'X-Idempotency-Key' => (string) Str::uuid()])
            ->assertOk();

        $webInvoice = Invoice::withoutGlobalScopes()->findOrFail($web->fresh()->invoice_id);
        $appInvoice = Invoice::withoutGlobalScopes()->findOrFail($app->fresh()->invoice_id);
        foreach (['subtotal', 'gst', 'gst_rate', 'total', 'round_off', 'status'] as $field) {
            $this->assertEquals($webInvoice->{$field}, $appInvoice->{$field}, $field);
        }
        $this->assertSame(2, $this->repairCashRows($shop), 'cash is recorded for both');
    }

    // ── The repair itself refuses impossible states, whoever writes it ──────────

    public function test_a_delivered_repair_cannot_go_back_to_an_earlier_stage(): void
    {
        [, , , $repair] = $this->readyRepair();
        $this->markDelivered($repair);

        $this->expectException(\DomainException::class);
        $repair->fresh()->update(['status' => 'ready']);
    }

    public function test_a_repair_cannot_carry_an_invoice_unless_it_is_delivered(): void
    {
        [, , , $repair] = $this->readyRepair();
        $invoiceId = $this->markDelivered($repair, persist: false);

        $this->expectException(\DomainException::class);
        $repair->fresh()->forceFill(['invoice_id' => $invoiceId])->save();
    }

    public function test_a_delivered_repairs_invoice_cannot_be_swapped(): void
    {
        [, , , $repair] = $this->readyRepair();
        $this->markDelivered($repair);
        $second = $this->markDelivered($repair, persist: false);

        $this->expectException(\DomainException::class);
        $repair->fresh()->forceFill(['invoice_id' => $second])->save();
    }

    public function test_a_delivered_repair_cannot_be_deleted(): void
    {
        [, , , $repair] = $this->readyRepair();
        $this->markDelivered($repair);

        $this->expectException(\DomainException::class);
        $repair->fresh()->delete();
    }

    /**
     * Found by breaking H1 with real processes: a writer holding a copy loaded BEFORE the delivery sees "ready" as
     * the original, so the model's own check cannot stop it. Only the database knows the row is billed.
     */
    public function test_a_stale_copy_cannot_move_a_billed_repair_back(): void
    {
        [, , , $repair] = $this->readyRepair();
        $stale = $repair->fresh();
        $this->markDelivered($repair);

        $stale->status = 'in_repair';
        try {
            DB::transaction(fn () => $stale->save()); // savepoint: the refused write leaves the test usable
            $this->fail('A stale copy moved a billed repair back to in_repair.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('delivered repair is billed', $e->getMessage());
        }
        $this->assertSame('delivered', $repair->fresh()->status);
    }

    public function test_a_stale_copy_cannot_delete_a_billed_repair(): void
    {
        [, , , $repair] = $this->readyRepair();
        $stale = $repair->fresh();
        $this->markDelivered($repair);

        try {
            DB::transaction(fn () => $stale->delete());
            $this->fail('A stale copy deleted a billed repair.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('delivered repair is billed', $e->getMessage());
        }
        $this->assertNotNull(Repair::withoutGlobalScopes()->find($repair->id));
    }

    public function test_the_database_refuses_an_invoice_on_an_open_repair_and_a_delivery_without_one(): void
    {
        [, , , $repair] = $this->readyRepair();
        $invoiceId = $this->markDelivered($repair, persist: false);

        foreach ([['invoice_id' => $invoiceId], ['status' => 'delivered']] as $change) {
            try {
                DB::transaction(fn () => DB::table('repairs')->where('id', $repair->id)->update($change));
                $this->fail('The database accepted ' . json_encode($change));
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertMatchesRegularExpression('/(only a delivered repair carries|delivered without an invoice)/i', $e->getMessage());
            }
        }
        // Delivering with the invoice in one write is the legitimate path.
        DB::table('repairs')->where('id', $repair->id)->update(['status' => 'delivered', 'invoice_id' => $invoiceId]);
        $this->assertSame('delivered', $repair->fresh()->status);
    }

    private function markDelivered(Repair $repair, bool $persist = true): int
    {
        TenantContext::set($repair->shop_id);
        $invoice = InvoiceAccountingService::createFinalized([
            'shop_id' => $repair->shop_id, 'customer_id' => $repair->customer_id, 'gold_rate' => 0,
            'subtotal' => 100, 'gst' => 0, 'gst_rate' => 0, 'wastage_charge' => 0, 'total' => 100, 'round_off' => 0,
        ]);
        if ($persist) {
            DB::table('repairs')->where('id', $repair->id)->update(['status' => 'delivered', 'invoice_id' => $invoice->id]);
        }

        return $invoice->id;
    }

    private function appToken(User $owner, Shop $shop): string
    {
        $this->app['auth']->forgetGuards();
        TenantContext::set($shop->id);

        return $this->json('POST', '/api/mobile/auth/login', ['mobile_number' => $owner->mobile_number, 'password' => 'password'],
            ['Accept' => 'application/json', 'X-Idempotency-Key' => (string) Str::uuid()])->json('token');
    }
}
