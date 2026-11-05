<?php

namespace Tests\Feature\Tables;

use App\Models\Karigar;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class KarigarEditorTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_address_component_offers_states_and_preserves_existing_values_in_both_forms(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = TenantContext::runFor($shop->id, fn () => Karigar::forceCreate(['shop_id' => $shop->id, 'name' => 'Synthetic artisan', 'city' => 'My locality', 'state' => 'Legacy state spelling', 'pincode' => '411001']));
        $this->withoutVite();
        foreach ([route('karigars.create'), route('karigars.edit', $karigar)] as $url) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get($url, ['Turbo-Frame' => 'karigar-editor-frame']))->assertOk();
            $dom = new \DOMDocument();
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            $this->assertSame(36, $xpath->query('//datalist[@id="kf-states"]/option')->length);
            $this->assertSame(1, $xpath->query('//input[@name="state" and @list="kf-states"]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="kf-state-menu" and @popover="auto" and @role="listbox"]')->length);
            $this->assertSame(1, $xpath->query('//input[@name="pincode" and @inputmode="numeric"]')->length);
            $this->assertSame(1, $xpath->query('//input[@name="opening_balance_at" and @type="date" and @data-date-editable and not(@data-native-date) and not(@readonly) and not(@disabled)]')->length);
            if ($url === route('karigars.edit', $karigar)) {
                $response->assertSee('value="My locality"', false)->assertSee('value="Legacy state spelling"', false)->assertSee('value="411001"', false);
            }
        }
    }

    public function test_existing_pin_proxy_is_available_to_signed_in_karigar_managers_and_keeps_manual_fallback(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Http::preventStrayRequests();
        Http::fake([
            'api.postalpincode.in/pincode/411001' => Http::response([['Status' => 'Success', 'PostOffice' => [['District' => 'Pune', 'State' => 'Maharashtra']]]]),
            'api.postalpincode.in/pincode/560001' => Http::response([], 503),
        ]);
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->getJson(route('shops.pincode-lookup', '411001')))
            ->assertOk()->assertExactJson(['found' => true, 'city' => 'Pune', 'state' => 'Maharashtra']);
        $this->getJson(route('shops.pincode-lookup', '123'))->assertStatus(422)->assertExactJson(['found' => false, 'reason' => 'invalid']);
        $this->getJson(route('shops.pincode-lookup', '560001'))->assertOk()->assertExactJson(['found' => false, 'reason' => 'unavailable']);
    }

    public function test_pin_proxy_does_not_cache_malformed_provider_replies_as_a_missing_pin(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Http::preventStrayRequests();
        Http::fake(['api.postalpincode.in/pincode/370201' => Http::sequence()
            ->push(['unexpected' => 'body'])
            ->push([['Status' => 'Success', 'PostOffice' => [['District' => 'Kachchh', 'State' => 'Gujarat']]]])]);
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->getJson(route('shops.pincode-lookup', '370201')))
            ->assertOk()->assertExactJson(['found' => false, 'reason' => 'unavailable']);
        $this->getJson(route('shops.pincode-lookup', '370201'))->assertOk()
            ->assertExactJson(['found' => true, 'city' => 'Kachchh', 'state' => 'Gujarat']);
    }

    public function test_pin_proxy_reports_a_genuine_unknown_pin_distinctly(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Http::preventStrayRequests();
        Http::fake(['api.postalpincode.in/*' => Http::response([['Status' => 'Error', 'PostOffice' => null]])]);
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->getJson(route('shops.pincode-lookup', '999999')))
            ->assertOk()->assertExactJson(['found' => false, 'reason' => 'not_found']);
    }

    public function test_authorized_frames_reuse_all_fields_and_standalone_routes_still_work(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = TenantContext::runFor($shop->id, fn () => Karigar::forceCreate(['shop_id' => $shop->id, 'name' => 'Synthetic artisan', 'city' => 'Testville', 'notes' => 'Synthetic note']));
        $this->withoutVite();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigars.index')))->assertOk()->assertDontSee('Synthetic note');
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigars.create')))->assertOk()->assertSee('Add Karigar')->assertDontSee('name="_karigar_editor"', false);
        foreach ([route('karigars.create'), route('karigars.edit', $karigar)] as $url) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get($url, ['Turbo-Frame' => 'karigar-editor-frame']))->assertOk();
            $response->assertSee('<turbo-frame id="karigar-editor-frame"', false)->assertSee('data-turbo-frame="_top"', false)
                ->assertSee('name="_karigar_editor"', false)->assertSee('name="opening_balance"', false)->assertSee('name="notes"', false);
            $response->assertDontSee('<html', false);
        }
    }

    public function test_editor_validation_returns_to_directory_and_restores_attempted_values(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = TenantContext::runFor($shop->id, fn () => Karigar::forceCreate(['shop_id' => $shop->id, 'name' => 'Synthetic artisan', 'city' => 'Original city']));
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->from(route('karigars.edit', $karigar))->put(route('karigars.update', $karigar), ['_karigar_editor' => '1', 'name' => '', 'city' => 'Attempted city']));
        $response->assertRedirect(route('karigars.index', ['editor' => $karigar->id]))->assertSessionHasErrors('name');
        TenantContext::runFor($shop->id, fn () => $this->get($response->headers->get('Location')))->assertOk()
            ->assertSee('data-restore="true"', false)->assertSee('Attempted city')->assertSee('name="_method" value="PUT"', false);
        $this->assertSame('Original city', $karigar->fresh()->city);
        $this->assertSame('Synthetic artisan', $karigar->fresh()->name);
    }

    public function test_save_returns_to_directory_but_standalone_edit_keeps_profile_redirect(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = TenantContext::runFor($shop->id, fn () => Karigar::forceCreate(['shop_id' => $shop->id, 'name' => 'Synthetic artisan']));
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->post(route('karigars.store'), ['_karigar_editor' => '1', 'name' => 'Synthetic new artisan']))->assertRedirect(route('karigars.index'));
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->put(route('karigars.update', $karigar), ['_karigar_editor' => '1', 'name' => 'Synthetic edited', 'city' => 'My locality', 'state' => 'Maharashtra', 'pincode' => '411001', 'opening_balance_at' => '2026-11-05']))->assertRedirect(route('karigars.index'));
        $this->assertSame('Synthetic edited', $karigar->fresh()->name);
        $this->assertSame('My locality', $karigar->fresh()->city);
        $this->assertSame('Maharashtra', $karigar->fresh()->state);
        $this->assertSame('411001', $karigar->fresh()->pincode);
        $this->assertSame('2026-11-05', $karigar->fresh()->opening_balance_at->toDateString());
        TenantContext::runFor($shop->id, fn () => $this->put(route('karigars.update', $karigar), ['name' => 'Synthetic direct edit']))->assertRedirect(route('karigars.show', $karigar));
    }

    public function test_invalid_create_keeps_values_and_never_creates_a_record(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->post(route('karigars.store'), ['_karigar_editor' => '1', 'name' => '', 'shop_name' => 'Synthetic attempted workshop']));
        $response->assertRedirect(route('karigars.index', ['editor' => 'create']))->assertSessionHasErrors('name');
        TenantContext::runFor($shop->id, fn () => $this->get($response->headers->get('Location')))->assertOk()
            ->assertSee('data-restore="true"', false)->assertSee('Synthetic attempted workshop');
        $this->assertSame(0, TenantContext::runFor($shop->id, fn () => Karigar::count()));
    }

    public function test_read_only_users_and_foreign_shop_cannot_load_editor_data(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();
        $foreign = TenantContext::runFor($otherShop->id, fn () => Karigar::forceCreate(['shop_id' => $otherShop->id, 'name' => 'Foreign private artisan']));
        $this->withoutVite();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigars.edit', $foreign), ['Turbo-Frame' => 'karigar-editor-frame']))->assertNotFound()->assertDontSee('Foreign private artisan');
        $this->grantOnlyPermissions($owner, ['karigar.view']);
        $owner->unsetRelation('role');
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigars.create'), ['Turbo-Frame' => 'karigar-editor-frame']))->assertForbidden();
        TenantContext::runFor($shop->id, fn () => $this->get(route('karigars.index')))->assertOk()->assertDontSee('id="karigar-editor"', false);
    }
}
