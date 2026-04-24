<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\PublicCatalogCollection;
use App\Services\CatalogShareService;
use Illuminate\Http\Request;

class PublicCatalogController extends Controller
{
    public function __construct(private CatalogShareService $catalog) {}

    public function show(Request $request, string $token)
    {
        $item = Item::withoutTenant()
            ->with('shop')
            ->where('share_token', $token)
            ->firstOrFail();

        $shop = $this->catalog->ensureCatalogWebsiteConfigured($item->shop);
        abort_unless($this->catalog->canUseCatalogWebsite($shop), 404);

        return redirect()->route('catalog.website.product', [
            'slug'  => $shop->catalog_slug,
            'token' => $token,
        ]);
    }

    public function showCollection(Request $request, string $token)
    {
        $collection = PublicCatalogCollection::withoutTenant()
            ->with('shop')
            ->where('token', $token)
            ->firstOrFail();

        $shop = $this->catalog->ensureCatalogWebsiteConfigured($collection->shop);
        abort_unless($this->catalog->canUseCatalogWebsite($shop), 404);

        // Enforce expiry — return 410 Gone for expired links
        abort_if(
            $collection->expires_at !== null && $collection->expires_at->isPast(),
            410,
            'This catalog link has expired.'
        );

        return redirect()->route('catalog.website.collection', [
            'slug'  => $shop->catalog_slug,
            'token' => $token,
        ]);
    }
}
