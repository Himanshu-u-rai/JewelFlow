<?php

namespace Tests\Feature\FinancialYear;

use App\Models\JobOrder;
use App\Models\JobOrderReceipt;
use App\Models\KarigarPayment;
use App\Models\MetalLot;
use App\Services\MetalRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Backdated entry into a locked period for job orders: the issue date and the receipt date are user-chosen on
 * the web, and the issue date is optional-but-accepted on the mobile API. All of them share JobOrderService.
 */
class PeriodLockJobOrderCoverageTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase, SetsPeriodLock;

    private $user;

    private int $shopId;

    private int $karigarId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
        [$this->user, $shop] = $this->createRetailerTenant();
        $this->shopId = (int) $shop->id;
        $this->karigarId = (int) DB::table('karigars')->insertGetId([
            'shop_id' => $this->shopId, 'name' => 'Lock Karigar', 'default_wastage_percent' => 2,
            'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        MetalRegistry::clearShopCache();
        parent::tearDown();
    }

    private function act(callable $fn)
    {
        return TenantContext::runFor($this->shopId, fn () => $fn($this->actingAs($this->user)));
    }

    private function vaultLot(float $fine = 100.0): MetalLot
    {
        $lot = new MetalLot();
        $lot->forceFill(['shop_id' => $this->shopId, 'source' => 'purchase', 'metal_type' => 'gold', 'purity' => 22.00, 'fine_weight_total' => $fine, 'fine_weight_remaining' => $fine, 'cost_per_fine_gram' => 5000]);
        $lot->save();

        return $lot;
    }

    private function jobCount(): int
    {
        return JobOrder::withoutGlobalScopes()->where('shop_id', $this->shopId)->count();
    }

    private function issuePayload(string $date, array $extra = []): array
    {
        return array_merge([
            'karigar_id' => $this->karigarId, 'metal_type' => 'gold', 'purity' => 22, 'allowed_wastage_percent' => 5,
            'issue_date' => $date, 'metal_source' => 'none', 'job_type' => 'repair',
        ], $extra);
    }

    private function openJob(string $issueDate): int
    {
        return (int) DB::table('job_orders')->insertGetId([
            'shop_id' => $this->shopId, 'karigar_id' => $this->karigarId, 'job_order_number' => 'JO-'.uniqid(),
            'challan_number' => 'CH-'.uniqid(), 'metal_type' => 'gold', 'purity' => 22, 'issued_gross_weight' => 50,
            'issued_fine_weight' => 45.83, 'expected_return_fine_weight' => 44.93, 'allowed_wastage_percent' => 2,
            'status' => 'issued', 'issue_date' => $issueDate, 'created_at' => now(), 'updated_at' => now(),
            'created_by_user_id' => $this->user->id,
        ]);
    }

    private function receiptPayload(string $date): array
    {
        return [
            'receipt_date' => $date,
            'items' => [['description' => 'Ring', 'pieces' => 1, 'gross_weight' => 5, 'net_weight' => 5, 'purity' => 22]],
        ];
    }

    private function receiptCount(): int
    {
        return JobOrderReceipt::withoutGlobalScopes()->where('shop_id', $this->shopId)->count();
    }

    // ───────────────────────── web: issue ─────────────────────────

    public function test_web_issue_dated_inside_the_locked_period_is_refused_and_nothing_is_written(): void
    {
        $lot = $this->vaultLot();
        $this->setLock($this->shopId);

        $payload = $this->issuePayload($this->insideLock(), [
            'metal_source' => 'vault', 'job_type' => null,
            'issuances' => [['metal_lot_id' => $lot->id, 'gross_weight' => 10, 'fine_weight' => 9.166, 'purity' => 22]],
            'advance_amount' => 500, 'advance_mode' => 'cash',
        ]);
        $this->act(fn ($t) => $t->post(route('job-orders.store'), $payload))->assertSessionHasErrors('issue_date');

        $this->assertSame(0, $this->jobCount());
        $this->assertEqualsWithDelta(100.0, (float) $lot->fresh()->fine_weight_remaining, 0.0001);
        $this->assertSame(0, KarigarPayment::withoutGlobalScopes()->where('shop_id', $this->shopId)->count());
    }

    public function test_web_issue_exactly_on_the_lock_date_is_refused(): void
    {
        $this->setLock($this->shopId);

        $this->act(fn ($t) => $t->post(route('job-orders.store'), $this->issuePayload($this->lockDate())))
            ->assertSessionHasErrors('issue_date');

        $this->assertSame(0, $this->jobCount());
    }

    public function test_web_issue_after_the_lock_today_and_with_no_lock_are_accepted(): void
    {
        $this->setLock($this->shopId);
        foreach ([$this->dayAfterLock(), now()->toDateString()] as $date) {
            $this->act(fn ($t) => $t->post(route('job-orders.store'), $this->issuePayload($date)))->assertSessionHasNoErrors();
        }
        $this->assertSame(2, $this->jobCount());

        DB::table('shop_rules')->where('shop_id', $this->shopId)->update(['financial_lock_date' => null]);
        $this->act(fn ($t) => $t->post(route('job-orders.store'), $this->issuePayload($this->insideLock())))->assertSessionHasNoErrors();
        $this->assertSame(3, $this->jobCount());
    }

    // ───────────────────────── web: receipt ─────────────────────────

    public function test_web_receipt_dated_inside_the_locked_period_is_refused_and_nothing_is_written(): void
    {
        $jobId = $this->openJob(now()->toDateString());
        $this->setLock($this->shopId);

        foreach ([$this->insideLock(), $this->lockDate()] as $date) {
            $this->act(fn ($t) => $t->post(route('job-orders.receive.store', $jobId), $this->receiptPayload($date)))
                ->assertSessionHasErrors('receipt_date');
        }

        $this->assertSame(0, $this->receiptCount());
        $this->assertSame('issued', DB::table('job_orders')->where('id', $jobId)->value('status'));
    }

    public function test_web_receipt_after_the_lock_is_accepted_even_for_a_job_issued_inside_the_locked_period(): void
    {
        $jobId = $this->openJob($this->insideLock());
        $this->setLock($this->shopId);

        $this->act(fn ($t) => $t->post(route('job-orders.receive.store', $jobId), $this->receiptPayload($this->dayAfterLock())))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->receiptCount());
    }

    public function test_web_receipt_with_no_lock_is_accepted(): void
    {
        $jobId = $this->openJob(now()->toDateString());

        $this->act(fn ($t) => $t->post(route('job-orders.receive.store', $jobId), $this->receiptPayload($this->insideLock())))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->receiptCount());
    }

    // ───────────────────────── mobile API ─────────────────────────

    private function mobileUser(): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($this->user->role_id);
        $role->givePermission('job_order.manage');
        Sanctum::actingAs($this->user);
        TenantContext::set($this->shopId);
    }

    private function key(): array
    {
        return ['X-Idempotency-Key' => 'lock-'.uniqid()];
    }

    private function mobileIssue(?string $date, MetalLot $lot): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'karigar_id' => $this->karigarId, 'metal_type' => 'gold', 'purity' => 22,
            'issuances' => [['metal_lot_id' => $lot->id, 'gross_weight' => 10, 'fine_weight' => 9.166]],
        ];
        if ($date !== null) {
            $payload['issue_date'] = $date;
        }

        return $this->postJson('/api/mobile/v1/job-orders', $payload, $this->key());
    }

    public function test_mobile_issue_dated_inside_the_locked_period_is_a_422_and_nothing_is_written(): void
    {
        $lot = $this->vaultLot();
        $this->mobileUser();
        $this->setLock($this->shopId);

        foreach ([$this->insideLock(), $this->lockDate()] as $date) {
            $response = $this->mobileIssue($date, $lot);
            $response->assertStatus(422);
            $this->assertStringContainsString('Financial lock is active', json_encode($response->json()));
        }

        $this->assertSame(0, $this->jobCount());
        $this->assertEqualsWithDelta(100.0, (float) $lot->fresh()->fine_weight_remaining, 0.0001);
    }

    public function test_mobile_issue_after_the_lock_with_no_date_and_with_no_lock_are_accepted(): void
    {
        $lot = $this->vaultLot();
        $this->mobileUser();
        $this->setLock($this->shopId);

        $this->mobileIssue($this->dayAfterLock(), $lot)->assertStatus(201);
        $this->mobileIssue(null, $lot)->assertStatus(201); // defaults to today, which is open

        DB::table('shop_rules')->where('shop_id', $this->shopId)->update(['financial_lock_date' => null]);
        $this->mobileIssue($this->insideLock(), $lot)->assertStatus(201);

        $this->assertSame(3, $this->jobCount());
    }

    public function test_mobile_receipt_is_refused_when_today_itself_is_locked(): void
    {
        $jobId = $this->openJob(now()->toDateString());
        $this->mobileUser();
        $this->setLock($this->shopId, now()->toDateString());

        $this->postJson('/api/mobile/v1/job-orders/'.$jobId.'/receipt', [
            'items' => [['gross_weight' => 5, 'pieces' => 1]],
        ], $this->key())->assertStatus(422);

        $this->assertSame(0, $this->receiptCount());
    }
}
