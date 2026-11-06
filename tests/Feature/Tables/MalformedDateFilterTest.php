<?php

namespace Tests\Feature\Tables;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The stock purchase register and the admin platform invoice register passed date filters straight to
 * PostgreSQL, so a hand-edited or stale link (?date_from=bad) was a 500. Like the other registers, an
 * unreadable date is ignored.
 */
class MalformedDateFilterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    public function test_stock_purchase_register_ignores_an_unreadable_date(): void
    {
        $this->withoutVite();
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)->get('/inventory/purchases?date_from=bad&date_to=2026-13-45')->assertOk();
    }

    public function test_admin_invoice_register_ignores_an_unreadable_date(): void
    {
        $this->withoutVite();

        $this->actingAs($this->createPlatformAdmin(), 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->get('/admin/invoices?date_from=bad&date_to=2026-13-45')
            ->assertOk();
    }
}
