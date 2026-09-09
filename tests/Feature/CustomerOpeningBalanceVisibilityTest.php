<?php

namespace Tests\Feature;

use App\Models\CustomerOpeningBalance;
use App\Models\OnboardingBatch;
use App\Models\OnboardingEntry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * An opening receivable is money the customer genuinely owes from before the
 * shop went live. It only materialises at onboarding batch LOCK — until then the
 * amount sits in onboarding_entries as a draft and correctly shows nowhere.
 *
 * These tests pin where a POSTED balance is visible, so the "is this a bug or did
 * I just never lock the batch?" question has a mechanical answer.
 */
class CustomerOpeningBalanceVisibilityTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Shop, 2: \App\Models\Customer} */
    private function shopWithPostedReceivable(float $amount)
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind'        => OnboardingEntry::KIND_CUSTOMER_RECEIVABLE,
            'customer_id' => $customer->id,
            'amount'      => $amount,
        ])->assertSessionHasNoErrors();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch));

        return [$user, $shop, $customer];
    }

    public function test_an_unlocked_batch_posts_no_opening_balance(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind'        => OnboardingEntry::KIND_CUSTOMER_RECEIVABLE,
            'customer_id' => $customer->id,
            'amount'      => 5000,
        ])->assertSessionHasNoErrors();

        // Staged but never locked: the ledger row does not exist yet.
        $this->assertSame(0, CustomerOpeningBalance::withoutTenant()->count());

        // And the shop cannot even browse customers mid-onboarding —
        // EnsureOpeningSetupCompleted bounces every operational route. So
        // "I set an opening balance and it never appeared" is expected until
        // the batch is locked; there is no page on which it could have shown.
        TenantContext::set($shop->id);
        $this->actingAs($user)->get(route('customers.show', $customer))
            ->assertRedirect(route('onboarding.index'));
    }

    public function test_a_posted_opening_balance_shows_on_the_customer_page(): void
    {
        [$user, $shop, $customer] = $this->shopWithPostedReceivable(5000);

        $this->assertSame(1, CustomerOpeningBalance::withoutTenant()->count(),
            'lock must post the receivable into customer_opening_balances');

        TenantContext::set($shop->id);
        $this->actingAs($user)->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Opening Balance')
            // Receivable = customer owes the shop = Dr.
            ->assertSee('5,000.00 Dr');
    }
}
