<?php

namespace Tests\Feature\CashBook;

use App\Models\CashTransaction;
use App\Models\ShopPaymentMethod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A manual cash book entry paid by UPI, bank or wallet says WHICH of the shop's
 * accounts (as POS, quick bill and karigar payments do), on the web and the app.
 * The account is required once the shop has one of that kind; a shop with none
 * keeps saving as before. The name is kept as it was that day (snapshot).
 */
class CashEntryAccountTest extends TestCase
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

    private function owner(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach (['cash.view', 'cash.create', 'settings.view', 'settings.edit'] as $p) {
            $role->givePermission($p);
        }

        return [$user->fresh(), $shop];
    }

    private function account(int $shopId, string $type, string $name, array $extra = []): ShopPaymentMethod
    {
        return TenantContext::runFor($shopId, fn () => ShopPaymentMethod::create(
            $extra + ['shop_id' => $shopId, 'type' => $type, 'name' => $name, 'is_active' => true],
        ));
    }

    private function postApp(array $body)
    {
        return $this->withHeaders(['X-Idempotency-Key' => 'acct-' . uniqid()])
            ->postJson('/api/mobile/v1/cashbook', $body + ['type' => 'in', 'amount' => 700, 'source_type' => 'customer_payment']);
    }

    public function test_the_app_lists_the_shops_active_accounts_by_kind(): void
    {
        [$user, $shop] = $this->owner();
        $this->account($shop->id, 'upi', 'Shop GPay', ['upi_id' => 'shop@okaxis']);
        $this->account($shop->id, 'bank', 'HDFC current', ['account_number' => '50100012345678']);
        $this->account($shop->id, 'upi', 'Old PhonePe', ['is_active' => false]);
        [, $other] = $this->createRetailerTenant();
        $this->account($other->id, 'upi', 'Not ours');
        Sanctum::actingAs($user);

        $data = $this->getJson('/api/mobile/v1/cashbook/accounts')->assertOk()->json('data.payment_methods');

        $this->assertSame(['Shop GPay'], array_column($data['upi'], 'name'));
        $this->assertSame('shop@okaxis', $data['upi'][0]['upi_id']);
        $this->assertSame(['HDFC current'], array_column($data['bank'], 'name'));
        $this->assertSame([], $data['wallet']);
    }

    public function test_an_upi_entry_records_the_account_and_shows_it_in_the_cash_book(): void
    {
        [$user, $shop] = $this->owner();
        $gpay = $this->account($shop->id, 'upi', 'Shop GPay', ['upi_id' => 'shop@okaxis']);
        Sanctum::actingAs($user);

        $res = $this->postApp(['payment_mode' => 'upi', 'payment_method_id' => $gpay->id])->assertCreated();
        $this->assertSame('Shop GPay (shop@okaxis)', $res->json('data.payment_method_label'));

        TenantContext::set((int) $shop->id);
        $row = CashTransaction::where('shop_id', $shop->id)->sole();
        $this->assertSame($gpay->id, (int) $row->payment_method_id);
        $this->assertSame('Shop GPay (shop@okaxis)', $row->payment_method_label_snapshot);

        // Renaming the account later does not rewrite what the entry said.
        TenantContext::runFor($shop->id, fn () => $gpay->update(['name' => 'Renamed']));
        $this->assertSame('Shop GPay (shop@okaxis)', $this->getJson('/api/mobile/v1/cashbook')->json('data.ledger.0.payment_method_label'));
    }

    public function test_the_account_is_required_once_the_shop_has_one_of_that_kind(): void
    {
        [$user, $shop] = $this->owner();
        Sanctum::actingAs($user);
        // No UPI account set up yet: saves as before.
        $this->postApp(['payment_mode' => 'upi'])->assertCreated();

        $this->account($shop->id, 'upi', 'Shop GPay');
        $this->postApp(['payment_mode' => 'upi'])->assertStatus(422)->assertJsonPath('errors.0.field', 'payment_method_id');
        // Cash never asks for one.
        $this->postApp(['payment_mode' => 'cash'])->assertCreated();
    }

    public function test_a_wrong_account_is_refused(): void
    {
        [$user, $shop] = $this->owner();
        $bank = $this->account($shop->id, 'bank', 'HDFC current');
        $off = $this->account($shop->id, 'upi', 'Old PhonePe', ['is_active' => false]);
        [, $other] = $this->createRetailerTenant();
        $theirs = $this->account($other->id, 'upi', 'Not ours');
        Sanctum::actingAs($user);

        foreach ([
            ['payment_mode' => 'upi', 'payment_method_id' => $bank->id],    // wrong kind
            ['payment_mode' => 'upi', 'payment_method_id' => $theirs->id],  // another shop's
            ['payment_mode' => 'upi', 'payment_method_id' => $off->id],     // switched off
            ['payment_mode' => 'cash', 'payment_method_id' => $bank->id],   // cash has no account
        ] as $body) {
            $this->postApp($body)->assertStatus(422)->assertJsonPath('errors.0.field', 'payment_method_id');
        }
        TenantContext::set((int) $shop->id);
        $this->assertSame(0, CashTransaction::where('shop_id', $shop->id)->count());
    }

    public function test_the_web_entry_form_records_the_account_too(): void
    {
        [$user, $shop] = $this->owner();
        $bank = $this->account($shop->id, 'bank', 'HDFC current', ['account_number' => '50100012345678']);
        $form = ['type' => 'out', 'amount' => 1500, 'source_type' => 'rent', 'payment_mode' => 'bank'];

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->post(self::ERP . '/cashbook', $form))
            ->assertSessionHasErrors('payment_method_id');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->post(self::ERP . '/cashbook', $form + ['payment_method_id' => $bank->id]))
            ->assertSessionHasNoErrors();
        TenantContext::set((int) $shop->id);
        $this->assertSame('HDFC current (****5678)', CashTransaction::where('shop_id', $shop->id)->sole()->payment_method_label_snapshot);
    }

    public function test_the_cash_book_filters_by_account_on_the_app_and_the_web(): void
    {
        [$user, $shop] = $this->owner();
        $gpay = $this->account($shop->id, 'upi', 'Shop GPay');
        $phonepe = $this->account($shop->id, 'upi', 'Counter PhonePe');
        Sanctum::actingAs($user);
        $this->postApp(['payment_mode' => 'upi', 'payment_method_id' => $gpay->id, 'description' => 'via gpay'])->assertCreated();
        $this->postApp(['payment_mode' => 'upi', 'payment_method_id' => $phonepe->id, 'description' => 'via phonepe'])->assertCreated();

        $rows = $this->getJson("/api/mobile/v1/cashbook?payment_method_id={$gpay->id}")->assertOk()->json('data.ledger');
        $this->assertSame(['via gpay'], array_column($rows, 'description'));

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->get(self::ERP . "/cashbook?payment_method_id={$phonepe->id}"))
            ->assertOk()->assertSee('via phonepe')->assertDontSee('via gpay')->assertSee('Account: Counter PhonePe');
    }

    public function test_a_retail_sale_paid_by_upi_puts_the_account_on_its_cash_book_row(): void
    {
        [$user, $shop] = $this->owner();
        $this->seedRetailerPricing($shop, $user);
        $gpay = $this->account($shop->id, 'upi', 'Shop GPay', ['upi_id' => 'shop@okaxis']);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 10000, 'source' => 'purchase']);

        // 10,000 + 3% GST, as PosSalesTest's retail sale.
        $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_ids' => [$item->id], 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'upi', 'amount' => 10300, 'payment_method_id' => $gpay->id]],
        ])->assertOk();

        TenantContext::set((int) $shop->id);
        $row = CashTransaction::where('shop_id', $shop->id)->where('payment_mode', 'upi')->sole();
        $this->assertSame($gpay->id, (int) $row->payment_method_id);
        $this->assertSame('Shop GPay (shop@okaxis)', $row->payment_method_label_snapshot);
    }

    public function test_an_account_used_by_a_cash_entry_cannot_be_deleted(): void
    {
        [$user, $shop] = $this->owner();
        $gpay = $this->account($shop->id, 'upi', 'Shop GPay');
        Sanctum::actingAs($user);
        $this->postApp(['payment_mode' => 'upi', 'payment_method_id' => $gpay->id])->assertCreated();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->delete(self::ERP . "/settings/payment-methods/{$gpay->id}"))
            ->assertSessionHas('error');
        $this->assertNotNull(ShopPaymentMethod::withoutTenant()->find($gpay->id));
    }
}
