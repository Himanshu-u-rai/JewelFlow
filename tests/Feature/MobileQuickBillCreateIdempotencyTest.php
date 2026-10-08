<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\IdempotencyKey;
use App\Models\QuickBill;
use App\Models\QuickBillPayment;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * POST /api/mobile/quick-bills used to ignore the X-Idempotency-Key the app
 * sends: the same request twice booked two bills and two payments. A reply
 * lost after the save, followed by a second tap on Save, did exactly that.
 *
 * The concurrent case cannot be scheduled inside RefreshDatabase's single
 * transaction; it is tests/Concurrency/quick_bill_create_race.php.
 */
class MobileQuickBillCreateIdempotencyTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const KEY = 'qb-create-0001-aaaa-bbbb';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_the_same_request_sent_twice_books_one_bill_and_one_payment(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $first = $this->create(self::KEY, $this->payload());
        $first->assertCreated();

        // The reply to the first request never arrived; the app sends it again.
        $second = $this->create(self::KEY, $this->payload());

        $second->assertCreated();
        $second->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($first->json('quick_bill.id'), $second->json('quick_bill.id'));
        $this->assertSame($first->json('quick_bill.bill_number'), $second->json('quick_bill.bill_number'));
        $this->assertBooked($shop->id, bills: 1, payments: 1);
    }

    public function test_the_same_key_with_a_changed_payload_is_refused(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $this->create(self::KEY, $this->payload())->assertCreated();

        $changed = $this->payload();
        $changed['items'][0]['rate'] = 9999;

        $this->create(self::KEY, $changed)
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'idempotency_key_conflict');
        $this->assertBooked($shop->id, bills: 1, payments: 1);
    }

    public function test_a_retry_is_refused_while_the_first_outcome_is_unrecorded(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $this->create(self::KEY, $this->payload())->assertCreated();

        // The bill committed, then the process died before the outcome was
        // written back to the claim: the claim is still in flight.
        IdempotencyKey::query()->where('key', self::KEY)->update(['response_status' => 0, 'response_body' => null]);

        $this->create(self::KEY, $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'idempotency_in_flight');
        $this->assertBooked($shop->id, bills: 1, payments: 1);
    }

    public function test_a_refused_create_leaves_the_key_usable_for_the_corrected_request(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $invalid = $this->payload();
        $invalid['items'] = [];

        $this->create(self::KEY, $invalid)->assertStatus(422);
        $this->assertBooked($shop->id, bills: 0, payments: 0);

        $this->create(self::KEY, $this->payload())->assertCreated();
        $this->assertBooked($shop->id, bills: 1, payments: 1);
    }

    public function test_one_key_used_in_two_shops_books_one_bill_in_each(): void
    {
        [$userA, $shopA] = $this->createRetailerTenant();
        [$userB, $shopB] = $this->createRetailerTenant();

        Sanctum::actingAs($userA);
        $a = $this->create(self::KEY, $this->payload())->assertCreated();

        Sanctum::actingAs($userB);
        $b = $this->create(self::KEY, $this->payload())->assertCreated();

        // Not a replay of the other shop's bill.
        $this->assertNull($b->headers->get('X-Idempotent-Replay'));
        $this->assertNotSame($a->json('quick_bill.id'), $b->json('quick_bill.id'));
        $this->assertBooked($shopA->id, bills: 1, payments: 1);
        $this->assertBooked($shopB->id, bills: 1, payments: 1);
    }

    public function test_a_user_who_may_not_bill_is_refused_and_books_nothing(): void
    {
        [, $shop] = $this->createRetailerTenant();

        // A role with no permission at all: `can:sales.create` refuses it.
        $role = new Role();
        $role->forceFill(['name' => 'viewer', 'display_name' => 'Viewer', 'shop_id' => $shop->id])->save();
        Sanctum::actingAs($this->createOwnerUser($shop, $role));

        $this->create(self::KEY, $this->payload())->assertForbidden();
        $this->assertBooked($shop->id, bills: 0, payments: 0);
        $this->assertSame(0, IdempotencyKey::query()->where('key', self::KEY)->count());
    }

    public function test_a_client_that_sends_no_key_is_served_as_before(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        // Older builds of the app send no key. They keep working, unprotected.
        $this->postJson('/api/mobile/quick-bills', $this->payload())->assertCreated();
        $this->postJson('/api/mobile/quick-bills', $this->payload())->assertCreated();

        $this->assertBooked($shop->id, bills: 2, payments: 2);
        $this->assertSame(0, IdempotencyKey::query()->where('shop_id', $shop->id)->count());
    }

    private function create(string $key, array $payload)
    {
        return $this->postJson('/api/mobile/quick-bills', $payload, ['X-Idempotency-Key' => $key]);
    }

    private function assertBooked(int $shopId, int $bills, int $payments): void
    {
        $billIds = QuickBill::withoutGlobalScopes()->where('shop_id', $shopId)->pluck('id');

        $this->assertCount($bills, $billIds, 'quick bills');
        $this->assertSame($payments, QuickBillPayment::withoutGlobalScopes()->whereIn('quick_bill_id', $billIds)->count(), 'payments');
        $this->assertSame(
            $bills,
            AuditLog::withoutGlobalScopes()->where('shop_id', $shopId)->where('action', 'quick_bill.created')->count(),
            'audit entries',
        );
    }

    private function payload(): array
    {
        return [
            'bill_date' => now()->toDateString(),
            'pricing_mode' => 'no_gst',
            'gst_rate' => 0,
            'round_off' => 0,
            'save_action' => 'issue',
            'customer_name' => 'Synthetic Walk-in',
            'items' => [[
                'description' => 'Synthetic quick bill item',
                'pcs' => 1,
                'gross_weight' => 1,
                'stone_weight' => 0,
                'net_weight' => 1,
                'rate' => 1000,
                'making_charge' => 0,
                'stone_charge' => 0,
                'wastage_percent' => 0,
                'line_discount' => 0,
            ]],
            'payments' => [[
                'payment_mode' => 'cash',
                'amount' => 1000,
            ]],
        ];
    }
}
