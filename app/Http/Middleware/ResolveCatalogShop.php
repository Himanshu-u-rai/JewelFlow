<?php

namespace App\Http\Middleware;

use App\Models\Item;
use App\Models\Shop;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCatalogShop
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('slug');

        $shop = Shop::where('catalog_slug', $slug)
            ->active()
            ->first();

        if (! $shop) {
            abort(404);
        }

        // Set tenant context BEFORE querying tenant-scoped models.
        TenantContext::set($shop->id);

        $catalogSettings = $shop->catalogWebsiteSettings;

        if (! $catalogSettings?->is_enabled) {
            TenantContext::clear();
            abort(404);
        }

        // Build navigation data.
        $navCategories = Item::where('status', 'in_stock')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $catalogPages = $shop->catalogPages()
            ->published()
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        view()->share('shop', $shop);
        view()->share('catalogSettings', $catalogSettings);
        view()->share('navCategories', $navCategories);
        view()->share('catalogPages', $catalogPages);

        $response = $next($request);

        TenantContext::clear();

        return $response;
    }
}
