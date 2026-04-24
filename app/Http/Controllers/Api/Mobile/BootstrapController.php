<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Data\Mobile\AppConfigData;
use App\Data\Mobile\BootstrapData;
use App\Data\Mobile\ShopSummaryData;
use App\Data\Mobile\UserSummaryData;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Mobile\CapabilityResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class BootstrapController extends Controller
{
    public function __construct(private readonly CapabilityResolver $resolver) {}

    public function show(Request $request): BootstrapData
    {
        $user = $request->user()->load('role.permissions', 'shop.subscription.plan');
        /** @var Shop|null $shop */
        $shop = $user->shop;

        return new BootstrapData(
            user: new UserSummaryData(
                id: (int) $user->id,
                name: (string) $user->name,
                email: $user->email,
                mobile: $user->mobile_number,
                role: (string) ($user->role?->name ?? 'unknown'),
            ),
            shop: new ShopSummaryData(
                id: (int) ($shop?->id ?? 0),
                name: (string) ($shop?->name ?? ''),
                shop_type: (string) ($shop?->shop_type ?? 'retailer'),
                gst_number: $shop?->gst_number,
                gst_rate: (float) ($shop?->gst_rate ?? 0),
                // TODO(bootstrap): source currency from shop/locale settings once modeled.
                currency: 'INR',
                logo_url: $shop ? $this->shopLogoUrl($request, $shop) : null,
            ),
            capabilities: $shop
                ? $this->resolver->resolve($shop, $user)
                : $this->emptyCapabilities(),
            config: new AppConfigData(
                server_time: Carbon::now()->toIso8601String(),
                min_mobile_version: (string) config('mobile.min_mobile_version', '1.0.0'),
                api_version: (string) config('mobile.api_version', 'mobile-v1'),
                environment: (string) config('app.env', 'production'),
            ),
        );
    }

    private function shopLogoUrl(Request $request, Shop $shop): ?string
    {
        if (empty($shop->logo_path)) {
            return null;
        }

        $pathOnly = parse_url(Storage::disk('public')->url($shop->logo_path), PHP_URL_PATH);

        return $request->getSchemeAndHttpHost() . '/' . ltrim((string) $pathOnly, '/');
    }

    /**
     * Fallback capabilities payload when a user isn't attached to a shop
     * (e.g. onboarding). All flags off so the mobile UI stays locked until
     * the user completes shop setup.
     */
    private function emptyCapabilities(): \App\Data\Mobile\CapabilitiesData
    {
        return new \App\Data\Mobile\CapabilitiesData(
            items: false,
            stock: false,
            customers: false,
            suppliers: false,
            purchases: false,
            pos: false,
            quick_bill: false,
            invoice: false,
            repairs: false,
            expenses: false,
            catalog: false,
            dashboard: false,
            scanner: false,
            schemes: false,
            loyalty: false,
            installments: false,
            cashbook: false,
        );
    }
}
