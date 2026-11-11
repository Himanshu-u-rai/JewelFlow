<?php

namespace Tests\Feature\Tables;

use App\Models\Vendor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class VendorEditorTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_frame_requests_return_the_form_only_with_existing_values_and_unchanged_fields(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $vendor = $this->vendor($shop->id, "O'Neil & Sons");
        $this->withoutVite();
        foreach ([route('vendors.create'), route('vendors.edit', $vendor)] as $url) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get($url, ['Turbo-Frame' => 'vendor-editor-frame']))->assertOk();
            $response->assertDontSee('<html', false)->assertDontSee('Enterprise System');
            $xp = $this->xpath($response->getContent());
            $this->assertSame(1, $xp->query('//turbo-frame[@id="vendor-editor-frame"]//form[@data-turbo-frame="_top"]')->length);
            $this->assertSame(1, $xp->query('//input[@name="_token"]')->length);
            $this->assertSame('1', $xp->evaluate('string(//input[@name="_vendor_editor"]/@value)'));
            $this->assertSame(0, $xp->query('//input[@name="is_active"]')->length);
            if ($url === route('vendors.edit', $vendor)) {
                $this->assertSame("O'Neil & Sons", $xp->evaluate('string(//input[@name="name"]/@value)'));
                $this->assertSame('PUT', $xp->evaluate('string(//input[@name="_method"]/@value)'));
                $this->assertSame('My locality', $xp->evaluate('string(//input[@name="city"]/@value)'));
            }
        }
        $this->get(route('vendors.create'))->assertRedirect(route('vendors.index', ['editor' => 'create']));
        TenantContext::runFor($shop->id, fn () => $this->get(route('vendors.edit', $vendor)))->assertRedirect(route('vendors.index', ['editor' => $vendor->id]));
        $this->get(route('vendors.create', ['full' => 1]))->assertOk()->assertSee('<html', false);
        TenantContext::runFor($shop->id, fn () => $this->get(route('vendors.edit', ['vendor' => $vendor, 'full' => 1])))->assertOk()->assertSee('<html', false);
        TenantContext::runFor($shop->id, fn () => $this->get(route('vendors.index', ['editor' => $vendor->id])))->assertOk()->assertSee('data-restore="true"', false)->assertSee("O&#039;Neil &amp; Sons", false);
    }

    public function test_invalid_create_restores_popup_values_without_creating_a_vendor(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite();
        $response = $this->actingAs($owner)->post(route('vendors.store'), ['_vendor_editor' => '1', 'name' => '', 'contact_person' => 'Unsaved contact']);
        $response->assertSessionHasErrors('name')->assertRedirect(route('vendors.index', ['editor' => 'create']));
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('data-restore="true"', false)->assertSee('Unsaved contact');
        $this->assertSame(0, TenantContext::runFor($shop->id, fn () => Vendor::count()));
    }

    public function test_invalid_edit_restores_the_right_vendor_and_does_not_change_its_availability(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $vendor = $this->vendor($shop->id, 'Archived supplier', false);
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->put(route('vendors.update', $vendor), [
            '_vendor_editor' => '1', 'name' => 'Unsaved new name', 'gst_number' => 'invalid', 'is_active' => true,
        ]));
        $response->assertSessionHasErrors('gst_number')->assertRedirect(route('vendors.index', ['editor' => $vendor->id]));
        $html = $this->get($response->headers->get('Location'))->assertOk()->assertSee('Unsaved new name')->getContent();
        $this->assertSame(route('vendors.update', $vendor), $this->xpath($html)->evaluate('string(//turbo-frame[@id="vendor-editor-frame"]/form/@action)'));
        $this->assertFalse(TenantContext::runFor($shop->id, fn () => $vendor->fresh()->is_active));
        $this->assertSame('Archived supplier', TenantContext::runFor($shop->id, fn () => $vendor->fresh()->name));
    }

    public function test_popup_create_and_update_return_to_directory_and_keep_normal_and_json_contracts(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->actingAs($owner)->post(route('vendors.store'), ['_vendor_editor' => '1', 'name' => 'Popup supplier'])->assertRedirect(route('vendors.index'));
        $vendor = TenantContext::runFor($shop->id, fn () => Vendor::where('name', 'Popup supplier')->firstOrFail());
        TenantContext::runFor($shop->id, fn () => $this->put(route('vendors.update', $vendor), ['_vendor_editor' => '1', 'name' => 'Popup updated', 'is_active' => false]))->assertRedirect(route('vendors.index'));
        $this->assertTrue(TenantContext::runFor($shop->id, fn () => $vendor->fresh()->is_active));
        TenantContext::runFor($shop->id, fn () => $this->put(route('vendors.update', $vendor), ['name' => 'Direct updated']))->assertRedirect(route('vendors.show', $vendor));
        $this->postJson(route('vendors.store'), ['name' => 'JSON supplier'])->assertOk()->assertJsonPath('name', 'JSON supplier');
    }

    public function test_unauthorized_users_and_foreign_vendor_ids_cannot_load_or_restore_editor_data(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();
        $foreign = $this->vendor($otherShop->id, 'Foreign private supplier');
        $this->withoutVite();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('vendors.edit', $foreign), ['Turbo-Frame' => 'vendor-editor-frame']))->assertNotFound()->assertDontSee('Foreign private supplier');
        TenantContext::runFor($shop->id, fn () => $this->get(route('vendors.index', ['editor' => $foreign->id])))->assertNotFound()->assertDontSee('Foreign private supplier');
        $this->grantOnlyPermissions($owner, ['vendors.view']);
        $owner->unsetRelation('role');
        TenantContext::runFor($shop->id, fn () => $this->get(route('vendors.create'), ['Turbo-Frame' => 'vendor-editor-frame']))->assertForbidden();
        TenantContext::runFor($shop->id, fn () => $this->get(route('vendors.index')))->assertOk()->assertDontSee('id="vendor-editor"', false);
    }

    private function vendor(int $shopId, string $name, bool $active = true): Vendor
    {
        return TenantContext::runFor($shopId, fn () => Vendor::forceCreate(['shop_id' => $shopId, 'name' => $name, 'city' => 'My locality', 'is_active' => $active]));
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($dom);
    }
}
