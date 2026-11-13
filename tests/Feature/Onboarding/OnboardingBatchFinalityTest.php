<?php

namespace Tests\Feature\Onboarding;

use App\Models\CashTransaction;
use App\Models\OnboardingBatch;
use App\Models\OnboardingEntry;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N14 (2026-11-13): opening balances are posted once per shop, and a posted (locked) batch is final.
 *
 * Every change to a batch (cancel, entries, customers, cash, stock, vault, balances, imports) checked "editable" on
 * the copy loaded with the page and then wrote: a cancel that waited for a posting's lock turned "locked" into
 * "cancelled" with the opening rows already posted, after which a second batch could be started and posted, doubling
 * the opening position. store() itself refused only a batch in progress, so a direct request could start a second
 * onboarding after one was posted.
 */
class OnboardingBatchFinalityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{User, Shop, OnboardingBatch} a draft with one cash entry */
    private function draft(): array
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->where('shop_id', $shop->id)->firstOrFail();
        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind' => OnboardingEntry::KIND_CASH, 'payment_mode' => 'cash', 'amount' => 5000,
        ])->assertSessionHasNoErrors();

        return [$user, $shop, $batch->fresh()];
    }

    private function lock(User $user, Shop $shop, OnboardingBatch $batch): void
    {
        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch))->assertRedirect(route('onboarding.index'));
    }

    /** The posting commits right after this request has loaded the batch (as "draft"). */
    private function postedOnceLoaded(OnboardingBatch $batch): void
    {
        $done = false;
        Event::listen('eloquent.retrieved: ' . OnboardingBatch::class, function (OnboardingBatch $loaded) use ($batch, &$done) {
            if ($done || $loaded->id !== $batch->id) {
                return;
            }
            $done = true;
            DB::table('onboarding_batches')->where('id', $batch->id)->update(['status' => 'locked', 'locked_at' => now()]);
        });
    }

    private function openingCash(Shop $shop): int
    {
        return CashTransaction::withoutGlobalScopes()->where('shop_id', $shop->id)->where('source_type', 'opening_balance')->count();
    }

    public function test_a_cancel_cannot_undo_a_posting_that_landed_meanwhile(): void
    {
        [$user, $shop, $batch] = $this->draft();
        $this->postedOnceLoaded($batch);

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.cancel', $batch))->assertStatus(422);

        $this->assertSame('locked', $batch->fresh()->status);
    }

    public function test_an_entry_cannot_be_added_to_a_batch_posted_meanwhile(): void
    {
        [$user, $shop, $batch] = $this->draft();
        $this->postedOnceLoaded($batch);

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind' => OnboardingEntry::KIND_CASH, 'payment_mode' => 'cash', 'amount' => 999,
        ])->assertStatus(422);

        $this->assertSame(1, OnboardingEntry::withoutGlobalScopes()->where('onboarding_batch_id', $batch->id)->count());
    }

    public function test_opening_balances_are_posted_once_per_shop(): void
    {
        [$user, $shop, $batch] = $this->draft();
        $this->lock($user, $shop, $batch);
        $this->assertSame(1, $this->openingCash($shop));

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-09-01'])->assertSessionHasErrors('start_date');
        $this->assertSame(1, OnboardingBatch::withoutTenant()->where('shop_id', $shop->id)->count(), 'no second onboarding');
        $this->assertSame(1, $this->openingCash($shop));
    }

    /** The lock wraps the whole change: an action that fails halfway leaves nothing behind. */
    public function test_a_change_that_fails_halfway_is_rolled_back(): void
    {
        [$user, $shop, $batch] = $this->draft();
        \Illuminate\Support\Facades\Route::middleware(['web', \App\Http\Middleware\LockEditableOnboardingBatch::class])
            ->post('/__test/onboarding/{onboarding}/half', function (OnboardingBatch $onboarding) {
                OnboardingEntry::create([
                    'shop_id' => $onboarding->shop_id, 'onboarding_batch_id' => $onboarding->id,
                    'kind' => OnboardingEntry::KIND_CASH, 'payload' => ['payment_mode' => 'cash', 'amount' => 1],
                ]);
                throw new \RuntimeException('failed after the first write');
            });
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        TenantContext::set($shop->id);
        $this->actingAs($user)->post("/__test/onboarding/{$batch->id}/half")->assertStatus(500);

        $this->assertSame(1, OnboardingEntry::withoutGlobalScopes()->where('onboarding_batch_id', $batch->id)->count(), 'the half-done entry is gone');
    }

    public function test_the_database_keeps_a_posted_batch_final_and_single(): void
    {
        [$user, $shop, $batch] = $this->draft();
        $this->lock($user, $shop, $batch);

        foreach ([
            'reopen' => fn () => DB::table('onboarding_batches')->where('id', $batch->id)->update(['status' => 'draft']),
            'cancel' => fn () => DB::table('onboarding_batches')->where('id', $batch->id)->update(['status' => 'cancelled']),
            'delete' => fn () => DB::table('onboarding_batches')->where('id', $batch->id)->delete(),
            'second locked' => fn () => DB::table('onboarding_batches')->insert([
                'shop_id' => $shop->id, 'as_of_date' => '2026-08-30', 'start_date' => '2026-08-31', 'status' => 'locked',
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]),
            'entry after posting' => fn () => DB::table('onboarding_entries')->where('onboarding_batch_id', $batch->id)->update(['updated_at' => now()]),
        ] as $what => $write) {
            $refused = false;
            try {
                DB::transaction($write);
            } catch (\Illuminate\Database\QueryException) {
                $refused = true;
            }
            $this->assertTrue($refused, "the database allowed: {$what}");
        }
        $this->assertSame('locked', $batch->fresh()->status);
    }
}
