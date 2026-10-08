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
 * Retries of PUT /api/mobile/quick-bills/{id}.
 *
 * The same edit sent twice in a row leaves the same bill, because an edit
 * replaces the bill's lines and payments. That was measured first and said
 * nothing about a retry that arrives LATE: edit A, then a different edit B,
 * then A again. That one was applied a second time and undid B (total, line
 * and payment all back to A's). The route now runs behind the idempotency
 * middleware, key optional as on create, so a retried edit is answered with
 * its first reply and applied once. Which edit should win when two people
 * edit a bill is a separate policy and is not touched here.
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

        // And it is one edit, logged once: the second request was a replay.
        $second->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('shop_id', $shop->id)->where('action', 'quick_bill.updated')->count());
    }

    /**
     * The retry that matters arrives late. Edit A is saved but its reply is
     * lost; the user makes a different edit B, which is saved; then the app's
     * queued retry of A goes out with A's key. A is not a new instruction: it
     * was already carried out, and carrying it out again would silently undo
     * B. With the key the server knows that and replays A's first reply.
     */
    public function test_a_late_retry_of_an_earlier_edit_does_not_undo_the_edit_made_since(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/mobile/quick-bills', $this->payload(1000))->assertCreated()->json('quick_bill.id');
        $put = fn (array $payload, ?string $key) => TenantContext::runFor($shop->id, fn () => $this->putJson(
            "/api/mobile/quick-bills/{$id}", $payload, $key === null ? [] : ['X-Idempotency-Key' => $key]
        ));

        $a = $this->payload(1500, 'Edit A item');
        $b = $this->payload(2500, 'Edit B item');

        $first = $put($a, 'qb-edit-key-A')->assertOk()->assertJsonPath('quick_bill.totals.total_amount', 1500);
        $put($b, 'qb-edit-key-B')->assertOk()->assertJsonPath('quick_bill.totals.total_amount', 2500);
        $afterB = $this->records($id);
        $this->assertSame('2500.00', $afterB['total']);
        $this->assertSame(['Edit B item'], $afterB['lines']);
        $this->assertSame(['2500.00'], $afterB['payments']);

        $retry = $put($a, 'qb-edit-key-A');

        // The records first: this is the effect that matters.
        $this->assertSame($afterB, $this->records($id), 'the late retry of edit A overwrote edit B');
        $retry->assertOk()->assertHeader('X-Idempotent-Replay', 'true')->assertJsonPath('quick_bill.totals.total_amount', 1500);
        // The whole receipt, value for value and type for type. Only the order of
        // the keys is set aside: the stored reply comes back from a jsonb column,
        // which keeps its own key order.
        $this->assertSame(self::keysSorted($first->json('quick_bill')), self::keysSorted($retry->json('quick_bill')), 'the retry is answered with what edit A was answered');
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('shop_id', $shop->id)->where('action', 'quick_bill.updated')->count(), 'the retry was logged as a third edit');
    }

    public function test_an_edit_without_a_key_is_applied_as_before(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/mobile/quick-bills', $this->payload(1000))->assertCreated()->json('quick_bill.id');
        $put = fn (array $payload) => TenantContext::runFor($shop->id, fn () => $this->putJson("/api/mobile/quick-bills/{$id}", $payload));

        // Older builds send no key: every edit they send is applied, in order.
        $put($this->payload(1500, 'Edit A item'))->assertOk();
        $put($this->payload(2500, 'Edit B item'))->assertOk();
        $put($this->payload(1500, 'Edit A item'))->assertOk();

        $this->assertSame(['Edit A item'], $this->records($id)['lines']);
    }

    public function test_another_shops_user_cannot_edit_the_bill_with_or_without_a_key(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$stranger, $other] = $this->createRetailerTenant();

        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/mobile/quick-bills', $this->payload(1000))->assertCreated()->json('quick_bill.id');
        $before = $this->records($id);

        Sanctum::actingAs($stranger);
        foreach ([[], ['X-Idempotency-Key' => 'qb-edit-stranger']] as $headers) {
            TenantContext::runFor($other->id, fn () => $this->putJson("/api/mobile/quick-bills/{$id}", $this->payload(9999, 'Not yours'), $headers))
                ->assertNotFound();
        }

        $this->assertSame($before, $this->records($id));
    }

    private static function keysSorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::keysSorted(...), $value);
    }

    /** The bill's own records, not counts: what each line says and what each payment is for. */
    private function records(int $id): array
    {
        $bill = QuickBill::withoutGlobalScopes()->findOrFail($id);

        return [
            'total' => (string) $bill->total_amount,
            'paid' => (string) $bill->paid_amount,
            'lines' => QuickBillItem::withoutGlobalScopes()->where('quick_bill_id', $id)->orderBy('id')->pluck('description')->all(),
            'payments' => QuickBillPayment::withoutGlobalScopes()->where('quick_bill_id', $id)->orderBy('id')->pluck('amount')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all(),
        ];
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

    private function payload(int $rate, string $description = 'Synthetic item'): array
    {
        return [
            'bill_date' => now()->toDateString(),
            'pricing_mode' => 'no_gst',
            'gst_rate' => 0,
            'round_off' => 0,
            'save_action' => 'issue',
            'customer_name' => 'Synthetic Walk-in',
            'items' => [['description' => $description, 'pcs' => 1, 'gross_weight' => 1, 'net_weight' => 1, 'rate' => $rate]],
            'payments' => [['payment_mode' => 'cash', 'amount' => $rate]],
        ];
    }
}
