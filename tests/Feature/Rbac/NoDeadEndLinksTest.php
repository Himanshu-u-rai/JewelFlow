<?php

namespace Tests\Feature\Rbac;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\TenantRoleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A user is never shown a link that ends on "Access Restricted": every link on every page a limited role can open
 * is followed and must not answer 403. The sidebar, dashboard shortcuts, POS header and page actions used to show
 * every module regardless of permission.
 */
class NoDeadEndLinksTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const PAGES = [
        '/dashboard', '/pos', '/masters', '/customers', '/customers/create', '/invoices', '/returns', '/repairs',
        '/quick-bills', '/quick-bills/create', '/installments', '/loyalty', '/settings?tab=general',
        '/inventory/items', '/inventory/stock-aging', '/inventory/sell-trend', '/inventory/purchases', '/categories',
        '/sub-categories', '/tags', '/reorder', '/vendors', '/karigars', '/job-orders', '/karigar-invoices', '/vault',
        '/schemes', '/catalog', '/cashbook', '/reports', '/exchanges', '/historical',
    ];

    private int $customerId;

    private Shop $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
        [, $this->shop] = $this->createRetailerTenant();
        ShopSubscription::query()->where('shop_id', $this->shop->id)->update(['status' => 'active']);
        $this->customerId = TenantContext::runFor($this->shop->id, fn () => Customer::create([
            'shop_id' => $this->shop->id, 'first_name' => 'Asha', 'last_name' => 'Rao', 'mobile' => '9810011122',
        ]))->id;
    }

    private function userWithRole(string $name, ?array $permissions = null): User
    {
        if ($permissions === null) {
            app(TenantRoleService::class)->ensureDefaultsForShop($this->shop->id);
            $role = Role::withoutTenant()->where('shop_id', $this->shop->id)->where('name', $name)->firstOrFail();
        } else {
            $role = new Role();
            $role->forceFill(['name' => $name, 'display_name' => ucfirst($name), 'shop_id' => $this->shop->id])->save();
            $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        }

        return User::factory()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id, 'is_active' => true]);
    }

    public function test_the_owner_still_sees_every_module_and_shortcut(): void
    {
        [$owner] = [User::query()->where('shop_id', $this->shop->id)->orderBy('id')->firstOrFail()];
        $html = TenantContext::runFor($this->shop->id, fn () => $this->actingAs($owner)->get('/dashboard'))->assertOk()->getContent();
        // Karigars live in the Masters hub since 3621a512 (no sidebar link of their own): one click from the menu.
        $html .= TenantContext::runFor($this->shop->id, fn () => $this->actingAs($owner)->get('/masters'))->assertOk()->getContent();

        foreach (['/pos', '/customers', '/invoices', '/returns', '/schemes', '/catalog', '/inventory/items',
            '/inventory/purchases', '/vault', '/karigars', '/job-orders', '/karigar-invoices', '/repairs', '/reports',
            '/cashbook', '/report/closing', '/inventory/items/create', '/customers/create', '/settings'] as $path) {
            $this->assertMatchesRegularExpression('#href="[^"]*' . preg_quote($path, '#') . '"#', $html, "owner should see {$path}");
        }
    }

    public static function roles(): array
    {
        return [
            'default staff role' => ['staff', null],
            'demo cashier' => ['cashier', ['customers.view', 'customers.create', 'customers.edit', 'customers.delete',
                'repairs.view', 'repairs.create', 'repairs.edit', 'repairs.delete', 'returns.view',
                'sales.view', 'sales.create', 'sales.pos', 'sales.void']],
            'counter only' => ['counter', ['sales.pos']],
        ];
    }

    /** @dataProvider roles */
    public function test_no_link_shown_to_a_limited_role_ends_on_access_restricted(string $roleName, ?array $permissions): void
    {
        $user = $this->userWithRole($roleName, $permissions);
        $links = [];

        foreach ([...self::PAGES, '/customers/' . $this->customerId] as $page) {
            $response = TenantContext::runFor($this->shop->id, fn () => $this->actingAs($user)->get($page));
            if ($response->status() !== 200) {
                continue; // a page the role cannot open is not crawled (its own entry link is what we check)
            }
            preg_match_all('/<a\b[^>]*\bhref="([^"#]+)"/i', $response->getContent(), $m);
            // The dashboard's Ctrl+K palette lists pages as JSON, not <a> tags.
            preg_match('/const pageIndex = (\[.*?\]);/s', $response->getContent(), $palette);
            $paletteUrls = array_column(json_decode($palette[1] ?? '[]', true) ?: [], 'url');
            foreach ([...$m[1], ...$paletteUrls] as $href) {
                $href = html_entity_decode($href);
                $path = parse_url($href, PHP_URL_PATH) ?? '';
                $host = parse_url($href, PHP_URL_HOST);
                if ($path === '' || ($host !== null && ! str_contains(config('app.url'), $host) && $host !== 'localhost')) {
                    continue;
                }
                if (preg_match('#^/(logout|storage|build|catalog/|c/)|\.(pdf|png|jpg|css|js)$#', $path)) {
                    continue;
                }
                $query = parse_url($href, PHP_URL_QUERY);
                $links[$path . ($query ? '?' . $query : '')] ??= $page;
            }
        }

        $dead = [];
        foreach ($links as $link => $from) {
            $status = TenantContext::runFor($this->shop->id, fn () => $this->actingAs($user)->get($link)->status());
            if ($status === 403) {
                $dead[] = "{$link} (shown on {$from})";
            }
        }

        $this->assertNotEmpty($links);
        $this->assertSame([], $dead, "{$roleName}: links that end on Access Restricted");
    }
}
