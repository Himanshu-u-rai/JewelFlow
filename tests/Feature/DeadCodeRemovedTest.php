<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Code that nothing could reach was removed (2026-11-27) and must not come back:
 * - routes/api.php: /api/items, /api/customers, /api/sale sat behind the web
 *   session guard in the session-less api group, so they always answered 401;
 * - controllers with no route, classes with no caller (incl. the stub OTP
 *   service), and views nothing renders (reports moved to the generic screen).
 * The app's own API lives under api/mobile and is untouched.
 */
class DeadCodeRemovedTest extends TestCase
{
    public function test_the_unreachable_legacy_pos_api_routes_are_gone(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());

        foreach (['api/items', 'api/customers', 'api/sale'] as $uri) {
            $this->assertFalse($uris->contains($uri), "$uri is still registered");
        }
        $this->assertTrue($uris->contains('api/mobile/customers'), 'the app API must stay');
    }

    public function test_unreferenced_classes_are_gone(): void
    {
        foreach ([
            \App\Http\Controllers\Api\PosController::class,
            \App\Http\Controllers\ItemStoneController::class,
            \App\Http\Controllers\Reporting\SalesRegisterController::class,
            \App\Services\OtpService::class,
            \App\Models\Otp::class,
            \App\Mail\AdminLoginOtpMail::class,
            \App\Support\IndianNumber::class,
            \App\Exports\FullShopExport::class,
        ] as $class) {
            $this->assertFalse(class_exists($class), "$class still exists");
        }
    }

    public function test_views_nothing_renders_are_gone(): void
    {
        foreach ([
            'inventory.items.pending-listing', 'inventory.pending-stock', 'operational-health', 'schemes.payment-receipt',
            'emails.admin-login-otp',
            'reports.dead-stock', 'reports.dues-aging', 'reports.inventory-valuation', 'reports.karigar-settlement',
            'reports.metal-liability', 'reports.operator-performance', 'reports.payment-reconciliation',
            'reports.purchase-efficiency', 'reports.scheme-liability', 'reports.shrinkage', 'reports.suspicious-activity',
        ] as $view) {
            $this->assertFalse(view()->exists($view), "$view still exists");
        }
        $this->assertFileDoesNotExist(resource_path('views/pos.blade.php.bak'));
        $this->assertTrue(view()->exists('reports.hub'), 'the live reports hub must stay');
    }
}
