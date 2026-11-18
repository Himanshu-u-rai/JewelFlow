<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class SettingsWorkspaceUiTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_profile_uses_compact_separate_forms_without_changing_their_fields(): void
    {
        [$owner] = $this->createRetailerTenant();
        $response = $this->actingAs($owner)->get(route('settings.edit', ['tab' => 'profile']))->assertOk();
        $response->assertSee('settings-profile-form profile-details-form', false)
            ->assertSee('settings-profile-form profile-password-form', false)
            ->assertSee('settings-profile-mobile', false)
            ->assertSee('profile-fields-row', false)
            ->assertSee('settings-profile-grid', false)
            ->assertSee('settings-profile-column', false);
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $columns = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " settings-profile-grid ")]/*[contains(concat(" ", normalize-space(@class), " "), " settings-profile-column ")]');
        $this->assertSame(2, $columns->length);
        foreach ([
            'profile.update' => ['patch', ['name', 'email']],
            'password.update' => ['put', ['current_password', 'password', 'password_confirmation']],
        ] as $route => [$method, $fields]) {
            $form = $xpath->query('//form[@action="'.route($route).'"]')->item(0);
            $this->assertNotNull($form);
            $this->assertSame(1, $xpath->query('.//input[@name="_method" and @value="'.$method.'"]', $form)->length);
            foreach ($fields as $field) {
                $this->assertSame(1, $xpath->query('.//input[@name="'.$field.'"]', $form)->length);
            }
            // The full-width action footer must not inherit the fields' width cap.
            $this->assertSame(1, $xpath->query('./div[contains(@class,"profile-form-actions")]', $form)->length);
            $this->assertSame(0, $xpath->query('.//div[contains(@class,"profile-fields-row")]//button[@type="submit"]', $form)->length);
        }
    }

    public function test_settings_validation_is_an_inert_toast_source_not_a_layout_banner(): void
    {
        [$owner] = $this->createRetailerTenant();
        $errors = (new \Illuminate\Support\ViewErrorBag())
            ->put('default', new \Illuminate\Support\MessageBag(['email' => 'The email field must be lowercase.']))
            ->put('updatePassword', new \Illuminate\Support\MessageBag(['current_password' => 'The password is incorrect.']));
        $response = $this->actingAs($owner)->withSession(['errors' => $errors])
            ->get(route('settings.edit', ['tab' => 'profile']))->assertOk();
        $response->assertSee('data-settings-validation', false)
            ->assertSee('The email field must be lowercase.')
            ->assertSee('The password is incorrect.')
            ->assertDontSee('class="alert alert-error"', false);
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@data-settings-validation and @hidden and ancestor::turbo-frame]')->length);
    }

    public function test_every_owner_tab_keeps_the_settings_sidebar_and_content_frame(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $before = $shop->fresh()->getAttributes();

        foreach (['general', 'profile', 'shop', 'billing', 'gst', 'subscription',
            'payment-methods', 'preferences', 'return-policy', 'pricing', 'materials',
            'website', 'roles', 'staff', 'audit', 'services', 'devices'] as $tab) {
            $response = $this->actingAs($owner)->get(route('settings.edit', ['tab' => $tab]));
            $response->assertOk()
                ->assertSee('aria-label="Settings sections"', false)
                ->assertSee('data-settings-tab="'.$tab.'"', false)
                ->assertSee('<turbo-frame id="settings-content" data-turbo-action="advance">', false)
                ->assertSee('data-turbo-frame="settings-content"', false)
                ->assertSee('data-settings-unsaved-dialog', false)
                ->assertSee('data-unsaved-keep', false)
                ->assertSee('data-unsaved-discard', false)
                ->assertSee('nav-item active', false);
        }

        $this->assertSame($before, $shop->fresh()->getAttributes());
    }

    public function test_grouped_sections_keep_fields_inside_the_original_save_form(): void
    {
        [$owner] = $this->createRetailerTenant();

        foreach ([
            'shop' => ['settings.update.shop', ['name', 'address_line1', 'owner_email'], 3],
            'billing' => ['settings.update.billing', ['bank_name', 'invoice_suffix', 'second_signature_label'], 7],
            'gst' => ['settings.update.gst', ['gst_number', 'gst_rate', 'hsn_gold', 'igst_mode'], 1],
            'preferences' => ['settings.update.preferences', ['low_stock_threshold', 'quick_bill_enabled', 'loyalty_point_value', 'rounding_method', 'compliance_threshold'], 5],
            'return-policy' => ['settings.update.return-policy', ['refund_making_charges', 'wear_loss_pct', 'return_window_days'], 3],
        ] as $tab => [$route, $fields, $sectionCount]) {
            $response = $this->actingAs($owner)->get(route('settings.edit', ['tab' => $tab]))->assertOk();
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $form = $xpath->query('//form[@action="'.route($route).'" and ancestor::*[@data-settings-tab]]')->item(0);
            $this->assertNotNull($form);
            $this->assertSame($sectionCount, $xpath->query('.//section[contains(@class,"settings-form-section")]', $form)->length);
            foreach ($fields as $name) {
                $this->assertGreaterThan(0, $xpath->query('.//*[@name="'.$name.'"]', $form)->length, $tab.': '.$name);
            }
            $this->assertSame(1, $xpath->query('.//input[@name="_method" and @value="PATCH"]', $form)->length);
        }
    }

    public function test_saved_gst_rates_keep_separate_update_and_delete_forms(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $category = new \App\Models\GstCategory();
        $category->forceFill(['shop_id' => $shop->id, 'name' => 'Gold override',
            'metal_type' => 'gold', 'rate_pct' => 3, 'is_default' => true])->save();

        $response = $this->actingAs($owner)->get(route('settings.edit', ['tab' => 'gst']))->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $update = $xpath->query('//form[@action="'.route('settings.gst-categories.update', $category).'"][.//input[@value="PATCH"]]')->item(0);
        $delete = $xpath->query('//form[@action="'.route('settings.gst-categories.destroy', $category).'"][.//input[@value="DELETE"]]')->item(0);
        $this->assertNotNull($update);
        $this->assertNotNull($delete);
        foreach (['name', 'metal_type', 'rate_pct', 'is_default', '_token'] as $field) {
            $this->assertSame(1, $xpath->query('.//*[@name="'.$field.'"]', $update)->length);
        }
        $this->assertSame(0, $xpath->query('ancestor::form', $delete)->length);
        $this->assertSame('3.00', $category->fresh()->rate_pct);
    }

    public function test_main_save_actions_follow_all_tab_fields_and_target_only_their_original_form(): void
    {
        [$owner] = $this->createRetailerTenant();
        foreach ([
            'shop' => 'settings.update.shop', 'billing' => 'settings.update.billing',
            'preferences' => 'settings.update.preferences', 'return-policy' => 'settings.update.return-policy',
            'gst' => 'settings.update.gst', 'materials' => 'settings.update.materials',
            'website' => 'settings.update.catalog-website', 'pricing' => 'settings.pricing.save-rates',
        ] as $tab => $route) {
            $response = $this->actingAs($owner)->get(route('settings.edit', ['tab' => $tab]))->assertOk();
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $form = $xpath->query('//form[@action="'.route($route).'" and ancestor::*[@data-settings-tab]]')->item(0);
            $this->assertNotNull($form);
            $this->assertNotSame('', $form->getAttribute('id'), $tab);
            $footer = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," settings-page-footer ")]')->item(0);
            $this->assertNotNull($footer, $tab);
            $save = $xpath->query('.//button[@type="submit"]', $footer)->item(0);
            $this->assertNotNull($save, $tab);
            $this->assertSame($form->getAttribute('id'), $save->getAttribute('form'), $tab);
            $this->assertSame(0, $xpath->query('following::*[(self::input[not(@type="hidden")] or self::select or self::textarea) and ancestor::*[@data-settings-tab]]', $footer)->length, $tab);
        }
    }

    public function test_frame_loaded_mutation_forms_use_top_level_redirects(): void
    {
        [$owner] = $this->createRetailerTenant();
        foreach (['pricing', 'payment-methods'] as $tab) {
            $response = $this->actingAs($owner)->get(route('settings.edit', ['tab' => $tab]))->assertOk();
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $forms = $xpath->query('//form[@method="POST" and ancestor::*[@data-settings-tab]]');
            $this->assertGreaterThan(0, $forms->length);
            foreach ($forms as $form) {
                $this->assertSame('_top', $form->getAttribute('data-turbo-frame'), $form->getAttribute('action'));
            }
        }
    }

    public function test_populated_device_cards_retain_the_signout_form_without_revoking_anything(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $token = $owner->createToken('settings-device-test');
        $session = \App\Models\MobileDeviceSession::withoutTenant()->create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'token_id' => $token->accessToken->id,
            'device_name' => 'Counter phone with a long display name', 'platform' => 'android',
            'app_version' => '1.4.0', 'logged_in_at' => now(),
        ]);

        $this->actingAs($owner)->get(route('settings.edit', ['tab' => 'devices']))->assertOk()
            ->assertSee('Counter phone with a long display name')
            ->assertSee('settings-record-card', false)
            ->assertSee(route('settings.devices.destroy', $session), false)
            ->assertSee('Sign out this device');
        $this->assertNull($session->fresh()->logged_out_at);
    }
}
