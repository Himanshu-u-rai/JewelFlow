<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Reporting\LedgerService;
use App\Reporting\ReceivablesService;
use App\Reporting\ReportPeriod;
use App\Reporting\TaxService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * §7e — joins that follow a stored reference. The reports filter their base
 * table to the caller's shop and then join customers and users by id. A
 * foreign key to `id` does not make the referenced row the same shop's:
 * today's write paths validate it (exists + shop), but a row written before
 * those rules — S3-14 was one — or by any path that skips them would carry
 * another shop's reference. These tests write such rows directly, as an
 * inconsistent reference would sit in the database, beside consistent ones.
 *
 * The joins now also require the referenced row's shop to equal the base
 * row's shop: consistent references join exactly as before; an inconsistent
 * one no longer brings another shop's name, mobile or operator into the
 * report.
 */
class ReportReferenceIntegrityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function invoice(int $shopId, int $customerId, array $extra = []): Invoice
    {
        return TenantContext::runFor($shopId, fn () => Invoice::issue(array_merge([
            'shop_id' => $shopId, 'customer_id' => $customerId, 'gold_rate' => 7200, 'subtotal' => 1000, 'gst' => 0, 'total' => 1000,
            'status' => Invoice::STATUS_FINALIZED, 'finalized_at' => now(),
        ], $extra)));
    }

    /** Shop A with its own customer and user, and shop B's customer and user, whose names must never appear in A's reports. */
    private function shops(): array
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();
        $ownerA->forceFill(['name' => 'AlphaOperator'])->save();
        $ownerB->forceFill(['name' => 'BravoSecretOperator'])->save();
        $customerA = $this->createCustomer((int) $shopA->id, ['first_name' => 'AlphaCustomer', 'mobile' => '9811111111']);
        $customerB = $this->createCustomer((int) $shopB->id, ['first_name' => 'BravoSecretCustomer', 'mobile' => '9822222222']);

        return [$ownerA, $shopA, $customerA, $ownerB, $customerB];
    }

    public function test_an_invoice_naming_another_shops_customer_brings_nothing_of_it_into_the_reports(): void
    {
        [, $shopA, $customerA, , $customerB] = $this->shops();
        $this->invoice((int) $shopA->id, (int) $customerA->id, ['buyer_gstin' => '24AAAAA0000A1Z5']);   // consistent
        $this->invoice((int) $shopA->id, (int) $customerB->id, ['buyer_gstin' => '24BBBBB0000B1Z5']);   // as an inconsistent row would sit
        $period = ReportPeriod::day(now()->toDateString());

        $reports = TenantContext::runFor((int) $shopA->id, fn () => [
            'day book' => json_encode(app(LedgerService::class)->dayBook((int) $shopA->id, $period)),
            'dues aging' => json_encode(app(ReceivablesService::class)->duesAging((int) $shopA->id)),
            'gstr1' => json_encode(app(TaxService::class)->gstr1((int) $shopA->id, $period)),
        ]);

        foreach ($reports as $name => $json) {
            $this->assertStringContainsString('AlphaCustomer', $json, "{$name}: a consistent reference still joins");
            $this->assertStringNotContainsString('BravoSecretCustomer', $json, "{$name}: another shop's customer name");
            $this->assertStringNotContainsString('9822222222', $json, "{$name}: another shop's customer mobile");
        }
    }

    public function test_a_cash_row_naming_another_shops_user_brings_nothing_of_it_into_the_cash_flow(): void
    {
        [$ownerA, $shopA, , $ownerB] = $this->shops();
        foreach ([[(int) $ownerA->id, 'consistent'], [(int) $ownerB->id, 'inconsistent']] as [$userId, $label]) {
            DB::table('cash_transactions')->insert([
                'shop_id' => $shopA->id, 'user_id' => $userId, 'type' => 'in', 'amount' => 100, 'source_type' => 'other',
                'description' => $label, 'payment_mode' => 'cash', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $json = json_encode(TenantContext::runFor((int) $shopA->id,
            fn () => app(LedgerService::class)->cashFlow((int) $shopA->id, ReportPeriod::day(now()->toDateString()))));

        $this->assertStringContainsString('AlphaOperator', $json, 'a consistent reference still joins');
        $this->assertStringNotContainsString('BravoSecretOperator', $json, "another shop's user name");
    }
}
