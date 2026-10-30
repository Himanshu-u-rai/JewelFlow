<?php

namespace Tests\Feature\Mobile;

use App\Models\Repair;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Impossible input is refused cleanly, never a server error: a text or
 * out-of-range id in a link is "not found" (404), an out-of-range number in a
 * form is a validation answer (422). On the app's API and the web alike.
 */
class MobileBadInputTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function ownerToken(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $token = $this->send(null, 'POST', '/api/mobile/auth/login', $shop->id, ['mobile_number' => $owner->mobile_number, 'password' => 'password'])->json('token');

        return [$owner, $shop, $token];
    }

    private function send(?string $token, string $method, string $uri, int $shopId, array $body = [])
    {
        $this->app['auth']->forgetGuards();
        TenantContext::set($shopId);
        $headers = ['Accept' => 'application/json', 'X-Idempotency-Key' => 'bad-' . Str::uuid()];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->json($method, $uri, $body, $headers);
    }

    public function test_a_bad_id_in_a_link_is_not_found(): void
    {
        [, $shop, $token] = $this->ownerToken();
        $crashed = [];
        foreach (['abc', '99999999999999999999', "1'--", '-1', '1.5'] as $bad) {
            foreach (['/api/mobile/invoices/', '/api/mobile/items/', '/api/mobile/vendors/', '/api/mobile/quick-bills/',
                '/api/mobile/v1/customers/', '/api/mobile/repairs/', '/api/mobile/v1/installments/', '/api/mobile/v1/returns/'] as $base) {
                $status = $this->send($token, 'GET', $base . urlencode($bad), $shop->id)->status();
                if ($status !== 404) {
                    $crashed[] = "{$base}{$bad} → {$status}";
                }
            }
        }
        $this->assertSame([], $crashed);
    }

    public function test_the_website_answers_a_bad_id_with_not_found_too(): void
    {
        [$owner] = $this->ownerToken();
        $this->actingAs($owner);
        foreach (['/customers/abc', '/customers/99999999999999999999', '/inventory/items/abc', '/repairs/99999999999999999999'] as $uri) {
            TenantContext::set((int) $owner->shop_id);
            $this->get($uri)->assertNotFound();
        }
    }

    public function test_the_website_item_edit_without_a_metal_type_is_a_validation_answer(): void
    {
        [$owner, $shop] = $this->ownerToken();
        $item = $this->createItem($shop->id, null, ['barcode' => 'EDIT-1', 'status' => 'in_stock']);
        TenantContext::set($shop->id);

        $this->actingAs($owner)->putJson("/inventory/items/{$item->id}", ['barcode' => 'EDIT-1', 'category' => 'Ring', 'gross_weight' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('metal_type');
        $this->assertFalse(\App\Services\MetalRegistry::isSupported(''), 'an empty metal type is simply not supported');
    }

    public function test_an_out_of_range_number_in_a_form_is_refused_not_a_crash(): void
    {
        [$owner, $shop, $token] = $this->ownerToken();
        $customer = $this->createCustomer($shop->id);

        $this->send($token, 'POST', '/api/mobile/repairs', $shop->id, [
            'customer_id' => '99999999999999999999', 'item_description' => 'x', 'gross_weight' => 1, 'purity' => 22, 'estimated_cost' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $this->send($token, 'POST', '/api/mobile/quick-bills', $shop->id, [
            'customer_id' => ['nested' => 'x'], 'bill_date' => now()->toDateString(), 'pricing_mode' => 'no_gst', 'items' => [],
        ])->assertUnprocessable();

        $this->send($token, 'POST', '/api/mobile/repairs', $shop->id, [
            'customer_id' => $customer->id, 'item_description' => 'Chain', 'gross_weight' => 1, 'purity' => 22, 'estimated_cost' => 1,
        ])->assertCreated();
        $repairId = Repair::withoutGlobalScopes()->where('shop_id', $shop->id)->value('id');
        $this->send($token, 'POST', "/api/mobile/repairs/{$repairId}/deliver", $shop->id, ['amount' => '99999999999999999999'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }
}
