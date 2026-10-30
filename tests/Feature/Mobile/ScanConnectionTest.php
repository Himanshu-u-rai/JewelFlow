<?php

namespace Tests\Feature\Mobile;

use App\Models\AuditLog;
use App\Models\ScanEvent;
use App\Models\ScanSession;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Connect with PC: a phone feeds barcodes to the PC's POS cart. A scan of a
 * barcode that is not an in-stock item of the shop is refused at once (the PC
 * could not sell it), on the app and on the web's phone scanner page alike, so
 * neither phone claims "added". Staying connected writes no audit row.
 */
class ScanConnectionTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function pairedShop(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createItem($shop->id, null, ['barcode' => 'RING-1', 'status' => 'in_stock']);
        $this->createItem($shop->id, null, ['barcode' => 'RING-2', 'status' => 'sold']);
        $this->createItem($shop->id, null, ['barcode' => '4501', 'status' => 'in_stock']);
        $session = TenantContext::runFor($shop->id, fn () => ScanSession::create([
            'shop_id' => $shop->id, 'token' => Str::random(48), 'status' => 'active', 'expires_at' => now()->addHours(8),
        ]));
        // A real token: each scan records the token that sent it (foreign key).
        $this->bearer = $owner->createToken('mobile-app')->plainTextToken;
        TenantContext::set($shop->id);

        return [$owner, $shop, $session];
    }

    private string $bearer;

    private function send(ScanSession $session, string $barcode)
    {
        TenantContext::set($session->shop_id); // each request ends the tenant context

        return $this->withToken($this->bearer)->postJson('/api/mobile/scan/send', ['token' => $session->token, 'barcode' => $barcode]);
    }

    public function test_the_app_is_told_at_once_when_a_barcode_cannot_go_in_the_cart(): void
    {
        [, , $session] = $this->pairedShop();

        $this->send($session, 'RING-1')->assertOk()->assertJsonPath('status', 'queued');
        // A scanner may add leading zeros to a numeric code; the POS matches it the same way.
        $this->send($session, '004501')->assertOk();

        $this->send($session, 'RING-2')->assertStatus(422)->assertJsonPath('message', 'RING-2 is not in stock (sold).');
        $this->send($session, 'NOPE-9')->assertStatus(422)->assertJsonPath('message', 'No item with barcode NOPE-9 in your stock.');

        $this->assertSame(['RING-1', '004501'], ScanEvent::where('scan_session_id', $session->id)->orderBy('id')->pluck('barcode')->all());
    }

    public function test_the_web_phone_page_refuses_the_same_barcodes(): void
    {
        [, , $session] = $this->pairedShop();

        $this->postJson('/api/scan', ['token' => $session->token, 'barcode' => 'RING-1'])->assertOk()->assertJsonPath('status', 'ok');
        $this->postJson('/api/scan', ['token' => $session->token, 'barcode' => 'RING-2'])
            ->assertStatus(422)->assertJsonPath('message', 'RING-2 is not in stock (sold).');
        $this->assertSame(1, ScanEvent::where('scan_session_id', $session->id)->count());
    }

    public function test_a_user_of_another_shop_cannot_use_this_shops_qr(): void
    {
        [, $shopA, $session] = $this->pairedShop();
        [$outsider, $shopB] = $this->createRetailerTenant();
        $this->createItem($shopB->id, null, ['barcode' => 'RING-1', 'status' => 'in_stock']); // same code in their own stock
        $theirs = $outsider->createToken('mobile-app')->plainTextToken;

        foreach (['connect' => [], 'send' => ['barcode' => 'RING-1'], 'status' => ['event_ids' => []], 'unpair' => []] as $call => $extra) {
            TenantContext::set($shopB->id);
            $this->withToken($theirs)->postJson("/api/mobile/scan/{$call}", ['token' => $session->token] + $extra)
                ->assertStatus(404);
        }

        $this->assertSame(0, ScanEvent::where('scan_session_id', $session->id)->count());
        $fresh = ScanSession::find($session->id);
        $this->assertNull($fresh->connected_user_id);
        $this->assertNull($fresh->unpaired_at, "an outsider can't disconnect this shop's phone either");
    }

    public function test_staying_connected_writes_one_audit_row_not_one_per_check(): void
    {
        [, $shop, $session] = $this->pairedShop();

        foreach (range(1, 3) as $_) {
            TenantContext::set($shop->id);
            $this->withToken($this->bearer)->postJson('/api/mobile/scan/connect', ['token' => $session->token])->assertOk();
        }

        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'mobile_scan_connected')->count());
    }
}
