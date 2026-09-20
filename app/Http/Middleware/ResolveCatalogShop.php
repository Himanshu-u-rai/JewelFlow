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
        //
        // S3-06: the try/finally is not decoration. On this route group the
        // tenant is chosen by an anonymous visitor's URL segment rather than by
        // a session, so it is the last place to leave the release conditional on
        // a happy path. Anything that throws below — the 404 on a shop that
        // never enabled its shopfront, a view error, a failing handler — used to
        // skip the clear and let the rest of the request live pinned to a shop a
        // stranger named. EnsureTenantUser:31-32 already does it this way.
        TenantContext::set($shop->id);

        try {
            $catalogSettings = $shop->catalogWebsiteSettings;

            if (! $catalogSettings?->is_enabled) {
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

            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
