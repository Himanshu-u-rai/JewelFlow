<?php

namespace Tests\Feature\Security;

use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * §7e, families no cross-shop test referenced (tests/Inventory/test_family_map.php):
 * the mobile search and lookup endpoints, the stock screen, the catalog, POS
 * bootstrap, the vendor ledger, and the stock batch-status write.
 *
 * Both shops' records share one search marker, so a leak cannot hide behind a
 * search that simply did not match: shop A's own record must come back, and
 * nothing of shop B's.
 */
class MobileLookupIsolationTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const MARK = 'XRISO';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** One shop's records, each named MARK + $tag. */
    private function shop(string $tag): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer((int) $shop->id, ['first_name' => self::MARK.$tag.'Cust', 'mobile' => '98'.random_int(10000000, 99999999)]);
        $item = $this->createItem((int) $shop->id, null, ['design' => self::MARK.$tag.'Item', 'barcode' => self::MARK.$tag.'BC', 'status' => 'in_stock']);
        $vendor = (int) DB::table('vendors')->insertGetId(['shop_id' => $shop->id, 'name' => self::MARK.$tag.'Vend', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('karigars')->insert(['shop_id' => $shop->id, 'name' => self::MARK.$tag.'Kar', 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()]);

        return compact('owner', 'shop', 'customer', 'item', 'vendor');
    }

    /** A mobile request as $user, with the tenant context production sets before binding. */
    private function asMobile(User $user, Shop $shop, string $method, string $uri, array $body = [])
    {
        Sanctum::actingAs($user, ['*']);

        return TenantContext::runFor((int) $shop->id, fn () => $this->json($method, $uri, $body, [
            'X-Idempotency-Key' => 'lookup-'.bin2hex(random_bytes(8)),
        ]));
    }

    public function test_mobile_lookups_return_the_callers_records_and_nothing_of_another_shop(): void
    {
        $a = $this->shop('Alpha');
        $this->shop('Bravo');

        $reads = [
            ['/api/mobile/customers/search?search='.self::MARK, 'AlphaCust'],
            ['/api/mobile/items/search?search='.self::MARK, 'AlphaItem'],
            ['/api/mobile/vendors/lookup', 'AlphaVend'],
            ['/api/mobile/karigars/lookup', 'AlphaKar'],
            ['/api/mobile/vendors', 'AlphaVend'],
            ['/api/mobile/stock', 'AlphaItem'],
            ['/api/mobile/catalog/items?search='.self::MARK, 'AlphaItem'],
            ['/api/mobile/pos/bootstrap', null],
        ];
        foreach ($reads as [$uri, $own]) {
            $response = $this->asMobile($a['owner'], $a['shop'], 'GET', $uri);
            $body = (string) $response->getContent();
            $this->assertSame(200, $response->getStatusCode(), "{$uri}: ".mb_substr($body, 0, 200));
            if ($own !== null) {
                $this->assertStringContainsString($own, $body, "{$uri} returns the caller's own record");
            }
            $this->assertStringNotContainsString('Bravo', $body, "{$uri} shows nothing of shop B");
        }
    }

    public function test_another_shops_vendor_ledger_is_not_found(): void
    {
        $a = $this->shop('Alpha');
        $b = $this->shop('Bravo');

        $this->asMobile($a['owner'], $a['shop'], 'GET', "/api/mobile/vendors/{$a['vendor']}/ledger")->assertOk();
        $foreign = $this->asMobile($a['owner'], $a['shop'], 'GET', "/api/mobile/vendors/{$b['vendor']}/ledger");

        $foreign->assertNotFound();
        $this->assertStringNotContainsString('Bravo', (string) $foreign->getContent());
    }

    public function test_a_batch_status_naming_another_shops_item_changes_nothing_in_either_shop(): void
    {
        $a = $this->shop('Alpha');
        $b = $this->shop('Bravo');

        $mixed = $this->asMobile($a['owner'], $a['shop'], 'POST', '/api/mobile/stock/batch-status',
            ['item_ids' => [$a['item']->id, $b['item']->id], 'status' => 'sold']);

        $mixed->assertStatus(422);
        $this->assertSame('in_stock', DB::table('items')->where('id', $a['item']->id)->value('status'));
        $this->assertSame('in_stock', DB::table('items')->where('id', $b['item']->id)->value('status'));

        $own = $this->asMobile($a['owner'], $a['shop'], 'POST', '/api/mobile/stock/batch-status',
            ['item_ids' => [$a['item']->id], 'status' => 'sold']);
        $own->assertOk();
        $this->assertSame('sold', DB::table('items')->where('id', $a['item']->id)->value('status'), 'control: the own item changes');
        $this->assertSame('in_stock', DB::table('items')->where('id', $b['item']->id)->value('status'));
    }
}
