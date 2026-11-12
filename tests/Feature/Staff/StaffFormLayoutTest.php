<?php

namespace Tests\Feature\Staff;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\TenantRoleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * UI audit F10: Add/Edit Staff use the compact two-column layout, the standard
 * orange primary button, and keep every field name and label association.
 */
class StaffFormLayoutTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{0:User,1:Shop,2:array} */
    private function tenant(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $roles = TenantContext::runFor($shop->id, fn () => app(TenantRoleService::class)->ensureDefaultsForShop($shop->id));
        TenantContext::runFor($shop->id, fn () => $owner->forceFill(['role_id' => $roles['owner']->id])->save());

        return [$owner->fresh(), $shop, $roles];
    }

    private function page(string $which): string
    {
        [$owner, $shop, $roles] = $this->tenant();
        $path = '/staff/create';
        if ($which === 'edit') {
            $staff = User::create([
                'name' => 'Layout Staff', 'mobile_number' => '9876600077', 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $roles['staff']->id,
            ]);
            $path = '/staff/' . $staff->id . '/edit';
        }

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . $path));
        $res->assertOk();

        return $res->getContent();
    }

    public static function pages(): array
    {
        return [
            'create' => ['create', ['name', 'mobile_number', 'email', 'role_id', 'password', 'password_confirmation']],
            'edit' => ['edit', ['name', 'mobile_number', 'email', 'role_id', 'password', 'password_confirmation', '_method']],
        ];
    }

    /** @dataProvider pages */
    public function test_compact_layout_standard_primary_and_field_contract(string $which, array $fields): void
    {
        $html = $this->page($which);

        // Two-column group container holds the fields.
        $this->assertStringContainsString('staff-form-grid', $html);
        $this->assertStringContainsString('staff-form-footer', $html);

        // Standard orange primary, no dark/teal inline primary.
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*class="[^"]*btn-primary/', $html);
        // Scope the "no dark primary" check to the form (the app shell may use those classes).
        $this->assertTrue(preg_match('/<form[^>]*staff-form-body.*?<\/form>/s', $html, $m) === 1);
        foreach (['btn-dark', 'bg-slate-900', 'bg-gray-900', 'background: #0d9488'] as $bad) {
            $this->assertStringNotContainsString($bad, $m[0]);
        }

        // Same field names as before.
        foreach ($fields as $name) {
            $this->assertMatchesRegularExpression('/<input[^>]*name="' . preg_quote($name, '/') . '"/', $html, "missing field {$name}");
        }

        // Every visible control has a label[for] or aria-label (radios sit inside a wrapping label).
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new \DOMXPath($dom);
        $labelFor = [];
        foreach ($xp->query('//label[@for]') as $l) {
            $labelFor[$l->getAttribute('for')] = true;
        }
        $form = $xp->query('//form[contains(@class,"staff-form-body")]')->item(0);
        $this->assertNotNull($form, 'staff form not found');
        foreach ($xp->query('.//input|.//select|.//textarea', $form) as $el) {
            $type = $el->getAttribute('type');
            if (in_array($type, ['hidden', 'submit'], true)) {
                continue;
            }
            $labelled = ($el->getAttribute('id') !== '' && isset($labelFor[$el->getAttribute('id')]))
                || $el->getAttribute('aria-label') !== ''
                || $xp->query('ancestor::label', $el)->length > 0;
            $this->assertTrue($labelled, 'unlabelled control: ' . $el->getAttribute('name'));
        }
    }
}
