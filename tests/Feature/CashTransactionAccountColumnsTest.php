<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\ShopPaymentMethod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Found in the tenant query review (2026-11-14): CashTransaction::accountColumns() looked a payment method up by id
 * alone and copied its account label into the cash row. Every caller validated the id against the shop first, so
 * nothing leaked, but one caller skipping that check would have written another shop's account details into this
 * shop's cash book. The lookup is now bound to the shop the row belongs to, and refuses anything else.
 */
class CashTransactionAccountColumnsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function method(int $shopId, string $name): ShopPaymentMethod
    {
        return TenantContext::runFor($shopId, fn () => ShopPaymentMethod::create([
            'shop_id' => $shopId, 'type' => 'upi', 'name' => $name, 'upi_id' => strtolower($name) . '@upi',
            'is_active' => true, 'sort_order' => 0,
        ]));
    }

    public function test_the_shops_own_method_fills_the_columns(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $own = $this->method($shop->id, 'OwnPay');

        $columns = CashTransaction::accountColumns($shop->id, $own->id);

        $this->assertSame($own->id, $columns['payment_method_id']);
        $this->assertSame($own->account_label, $columns['payment_method_label_snapshot']);
    }

    public function test_another_shops_method_is_refused(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $foreign = $this->method($shopB->id, 'OtherPay');

        $this->expectException(LogicException::class);
        CashTransaction::accountColumns($shopA->id, $foreign->id);
    }

    public function test_no_method_leaves_the_columns_empty(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $this->assertSame(['payment_method_id' => null, 'payment_method_label_snapshot' => null], CashTransaction::accountColumns($shop->id, null));
    }
}
