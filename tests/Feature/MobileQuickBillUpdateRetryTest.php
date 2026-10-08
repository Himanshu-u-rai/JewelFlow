<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\QuickBill;
use App\Models\QuickBillItem;
use App\Models\QuickBillPayment;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * What a repeated PUT /api/mobile/quick-bills/{id} actually does. The app
 * sends an idempotency key with it and the route has no idempotency
 * middleware; that alone does not show money is processed twice. Measured:
 * an edit replaces the bill's lines and payments with the ones sent, so the
 * same edit sent twice leaves the same bill. Only the audit trail gains a
 * second entry.
 */
class MobileQuickBillUpdateRetryTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_the_same_edit_sent_twice_leaves_one_set_of_lines_and_payments(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/mobile/quick-bills', $this->payload(1000))->assertCreated()->json('quick_bill.id');

        // The bill is bound through the tenant scope, as on a real request.
        $edit = fn () => TenantContext::runFor($shop->id, fn () => $this->putJson("/api/mobile/quick-bills/{$id}", $this->payload(1500), ['X-Idempotency-Key' => 'qb-update-0001']));

        $first = $edit()->assertOk();
        $afterFirst = $this->state($id);

        $second = $edit()->assertOk();

        $this->assertSame($afterFirst, $this->state($id), 'the repeated edit changed the bill');
        $this->assertSame($first->json('quick_bill.bill_number'), $second->json('quick_bill.bill_number'));
        $this->assertSame(1, QuickBill::withoutGlobalScopes()->where('shop_id', $shop->id)->count());

        // The one lasting difference: each accepted edit is logged.
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('shop_id', $shop->id)->where('action', 'quick_bill.updated')->count());
    }

    private function state(int $id): array
    {
        $bill = QuickBill::withoutGlobalScopes()->findOrFail($id);

        return [
            'number' => $bill->bill_number,
            'status' => $bill->status,
            'total' => (string) $bill->total_amount,
            'paid' => (string) $bill->paid_amount,
            'due' => (string) $bill->due_amount,
            'items' => QuickBillItem::withoutGlobalScopes()->where('quick_bill_id', $id)->count(),
            'payments' => QuickBillPayment::withoutGlobalScopes()->where('quick_bill_id', $id)->count(),
            'payment_sum' => (string) QuickBillPayment::withoutGlobalScopes()->where('quick_bill_id', $id)->sum('amount'),
        ];
    }

    private function payload(int $rate): array
    {
        return [
            'bill_date' => now()->toDateString(),
            'pricing_mode' => 'no_gst',
            'gst_rate' => 0,
            'round_off' => 0,
            'save_action' => 'issue',
            'customer_name' => 'Synthetic Walk-in',
            'items' => [['description' => 'Synthetic item', 'pcs' => 1, 'gross_weight' => 1, 'net_weight' => 1, 'rate' => $rate]],
            'payments' => [['payment_mode' => 'cash', 'amount' => $rate]],
        ];
    }
}
