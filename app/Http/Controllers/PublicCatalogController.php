<?php

namespace App\Http\Controllers;

use App\Models\CatalogWebsiteSettings;
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

        // Redirect to the catalog website if enabled for this shop.
        $shop = $item->shop;
        $isCatalogWebsiteEnabled = $shop
            ? CatalogWebsiteSettings::withoutTenant()
                ->where('shop_id', $shop->id)
                ->whereRaw('is_enabled IS TRUE')
                ->exists()
            : false;

        if ($shop?->catalog_slug && $isCatalogWebsiteEnabled) {
            return redirect()->route('catalog.website.product', [
                'slug'  => $shop->catalog_slug,
                'token' => $token,
            ]);
        }

        $imageUrl = $this->catalog->resolveImageUrl($request, $item);

        return view('public.catalog-item', [
            'item'     => $item,
            'shop'     => $shop,
            'imageUrl' => $imageUrl,
        ]);
    }

    public function showCollection(Request $request, string $token)
    {
        $collection = PublicCatalogCollection::withoutTenant()
            ->with('shop')
            ->where('token', $token)
            ->firstOrFail();

        // Redirect to the catalog website if enabled for this shop.
        $shop = $collection->shop;
        $isCatalogWebsiteEnabled = $shop
            ? CatalogWebsiteSettings::withoutTenant()
                ->where('shop_id', $shop->id)
                ->whereRaw('is_enabled IS TRUE')
                ->exists()
            : false;

        if ($shop?->catalog_slug && $isCatalogWebsiteEnabled) {
            return redirect()->route('catalog.website.collection', [
                'slug'  => $shop->catalog_slug,
                'token' => $token,
            ]);
        }

        // Enforce expiry — return 410 Gone for expired links
        abort_if(
            $collection->expires_at !== null && $collection->expires_at->isPast(),
            410,
            'This catalog link has expired.'
        );

        // Load items via the pivot table (authoritative source)
        $pivotItems = $collection->collectionItems()->with('item.shop')->get();

        $items = $pivotItems
            ->map(fn ($ci) => $ci->item)
            ->filter()
            ->values();

        abort_if($items->isEmpty(), 404);

        $imageUrls     = [];
        $itemShareUrls = [];

        foreach ($items as $item) {
            $imageUrls[$item->id]     = $this->catalog->resolveImageUrl($request, $item);
            $itemShareUrls[$item->id] = blank($item->share_token)
                ? null
                : $this->catalog->buildItemUrl($item->share_token);
        }

        return view('public.catalog-collection', [
            'collection'    => $collection,
            'items'         => $items,
            'shop'          => $shop,
            'imageUrls'     => $imageUrls,
            'itemShareUrls' => $itemShareUrls,
        ]);
    }
}
