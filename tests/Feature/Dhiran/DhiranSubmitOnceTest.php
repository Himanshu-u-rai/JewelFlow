<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranCashEntry;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner 2026-11-12 (smaller issues):
 *  - the same money form sent twice (double click, two tabs, browser retry) is recorded once; a key reused with
 *    different values is refused; a refused attempt frees its key; New Loan is covered too (no duplicate loan/payout);
 *  - PAN must be a real PAN (5 letters, 4 digits, 1 letter), saved in capitals;
 *  - renewal accepts card like every other Dhiran payment.
 */
class DhiranSubmitOnceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $owner;
    private Customer $customer;
    private const HOST = 'https://dhiran.jewelflows.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390016000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390016000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390016000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'o@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
        $this->customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Once', 'last_name' => 'Only', 'mobile' => '9815550001']));
    }

    private function send(string $path, array $data)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->post(self::HOST . $path, $data));
    }

    private function loan(): DhiranLoan
    {
        return TenantContext::runFor($this->shop->id, fn () => app(DhiranService::class)->createLoan($this->shop, $this->customer, [[
            'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
        ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id]));
    }

    private function paymentsIn(DhiranLoan $loan): int
    {
        return DhiranPayment::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->where('direction', 'in')->count();
    }

    public function test_the_same_payment_form_sent_twice_is_recorded_once(): void
    {
        $loan = $this->loan();
        $form = ['amount' => 5000, 'payment_method' => 'cash', 'idempotency_key' => 'form-key-0001'];

        $this->send("/dhiran/loans/{$loan->id}/repay", $form)->assertRedirect()->assertSessionHas('success');
        $this->send("/dhiran/loans/{$loan->id}/repay", $form)->assertRedirect()->assertSessionHas('success', 'Already recorded: this form was sent before.');

        $this->assertSame(1, $this->paymentsIn($loan));
        $this->assertSame(1, DhiranCashEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->where('type', 'in')->count());

        // Same key, different amount: refused, nothing recorded.
        $this->send("/dhiran/loans/{$loan->id}/repay", ['amount' => 7000] + $form)->assertSessionHas('error');
        $this->assertSame(1, $this->paymentsIn($loan));
    }

    public function test_a_refused_attempt_frees_its_key(): void
    {
        $loan = $this->loan();
        $this->send("/dhiran/loans/{$loan->id}/repay", ['amount' => 999999, 'idempotency_key' => 'form-key-0002'])->assertSessionHas('error');
        $this->assertSame(0, $this->paymentsIn($loan));
        $this->send("/dhiran/loans/{$loan->id}/repay", ['amount' => 1000, 'idempotency_key' => 'form-key-0002'])->assertSessionHas('success');
        $this->assertSame(1, $this->paymentsIn($loan));
    }

    public function test_money_forms_need_a_key_and_every_form_carries_one(): void
    {
        $loan = $this->loan();
        $this->send("/dhiran/loans/{$loan->id}/pay-interest", ['amount' => 100])->assertSessionHasErrors('idempotency_key');

        $show = TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(self::HOST . "/dhiran/loans/{$loan->id}")->getContent());
        foreach (['pay-interest', 'repay', 'release-item', 'pre-close', 'renew'] as $action) {
            $this->assertMatchesRegularExpression('#action="[^"]*/loans/' . $loan->id . '/' . $action . '"[^>]*>.*?name="idempotency_key" value="[0-9a-f-]{36}"#s', $show, "{$action} form carries a key");
        }
    }

    public function test_new_loan_sent_twice_creates_one_loan(): void
    {
        $form = ['customer_id' => $this->customer->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000, 'idempotency_key' => 'form-key-0003',
            'items' => [['description' => 'Ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000]]];
        $this->send('/dhiran', $form)->assertRedirect();
        $this->send('/dhiran', $form)->assertRedirect();
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
    }

    public function test_pan_must_be_a_real_pan_and_is_saved_in_capitals(): void
    {
        $base = ['customer_id' => $this->customer->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000,
            'items' => [['description' => 'Ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000]]];
        $this->send('/dhiran', $base + ['pan' => 'HELLO', 'idempotency_key' => 'form-key-0004'])->assertSessionHasErrors('pan');
        $this->send('/dhiran', $base + ['pan' => 'abcde1234f', 'idempotency_key' => 'form-key-0005'])->assertRedirect();
        $this->assertSame('ABCDE1234F', DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->value('kyc_pan'));
    }

    public function test_renewal_accepts_card(): void
    {
        $loan = $this->loan();
        $show = TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(self::HOST . "/dhiran/loans/{$loan->id}")->getContent());
        $this->assertSame(5, substr_count($show, '<option value="card">Card</option>'), 'every payment dropdown offers card');

        $this->send("/dhiran/loans/{$loan->id}/renew", ['payment_method' => 'card', 'idempotency_key' => 'form-key-0006'])->assertSessionHasNoErrors();
        $this->assertSame('renewed', $loan->refresh()->status);
    }
}
