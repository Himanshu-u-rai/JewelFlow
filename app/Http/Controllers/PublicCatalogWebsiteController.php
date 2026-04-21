<?php

namespace App\Http\Controllers;

use App\Models\CatalogPage;
use App\Models\Item;
use App\Models\PublicCatalogCollection;
use App\Services\CatalogShareService;
use Illuminate\Http\Request;

class PublicCatalogWebsiteController extends Controller
{
    public function __construct(private CatalogShareService $catalog) {}

    /**
     * Home / landing page — hero, featured categories, recent items.
     */
    public function home(Request $request, string $slug)
    {
        $shop     = view()->shared('shop');
        $settings = view()->shared('catalogSettings');

        // Distinct categories with a representative image.
        $categories = Item::where('status', 'in_stock')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->selectRaw('category, MIN(id) as sample_id')
            ->groupBy('category')
            ->orderBy('category')
            ->get();

        $sampleIds = $categories->pluck('sample_id')->filter();
        $sampleItems = Item::whereIn('id', $sampleIds)->get()->keyBy('id');

        $categoryData = $categories->map(function ($row) use ($sampleItems, $request) {
            $sample = $sampleItems->get($row->sample_id);
            return (object) [
                'name'      => $row->category,
                'image_url' => $sample ? $this->catalog->resolveImageUrl($request, $sample) : null,
                'count'     => Item::where('status', 'in_stock')->where('category', $row->category)->count(),
            ];
        });

        // Featured categories: show configured ones first, then the rest.
        $featured = $settings->featured_categories ?? [];
        if (! empty($featured)) {
            $categoryData = $categoryData->sortBy(function ($cat) use ($featured) {
                $pos = array_search($cat->name, $featured);
                return $pos === false ? 999 : $pos;
            })->values();
        }

        // Recent items (newest 12 with images preferred).
        $recentItems = Item::where('status', 'in_stock')
            ->orderByRaw("CASE WHEN image IS NOT NULL AND image != '' THEN 0 ELSE 1 END")
            ->latest()
            ->limit(12)
            ->get();

        $recentImageUrls = [];
        foreach ($recentItems as $item) {
            $recentImageUrls[$item->id] = $this->catalog->resolveImageUrl($request, $item);
        }

        return view('public.catalog.home', compact(
            'categoryData', 'recentItems', 'recentImageUrls'
        ));
    }

    /**
     * All products listing — paginated, filterable, searchable.
     */
    public function products(Request $request, string $slug)
    {
        $query = Item::where('status', 'in_stock');

        if ($search = $request->query('search')) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(barcode) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(design) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(category) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(sub_category) LIKE ?', [$term]);
            });
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($subCategory = $request->query('sub_category')) {
            $query->where('sub_category', $subCategory);
        }

        $sort = $request->query('sort', 'newest');
        $query = match ($sort) {
            'price_asc'  => $query->orderBy('selling_price', 'asc'),
            'price_desc' => $query->orderBy('selling_price', 'desc'),
            'name'       => $query->orderBy('design', 'asc'),
            default      => $query->latest(),
        };

        $items = $query->paginate(24)->withQueryString();

        $imageUrls = [];
        foreach ($items as $item) {
            $imageUrls[$item->id] = $this->catalog->resolveImageUrl($request, $item);
        }

        // Subcategories for active category filter.
        $subCategories = collect();
        if ($category) {
            $subCategories = Item::where('status', 'in_stock')
                ->where('category', $category)
                ->whereNotNull('sub_category')
                ->where('sub_category', '!=', '')
                ->distinct()
                ->orderBy('sub_category')
                ->pluck('sub_category');
        }

        return view('public.catalog.products', compact(
            'items', 'imageUrls', 'subCategories', 'search', 'category', 'sort'
        ));
    }

    /**
     * Products filtered by category.
     */
    public function category(Request $request, string $slug, string $category)
    {
        $query = Item::where('status', 'in_stock')
            ->where('category', $category);

        if ($sub = $request->query('sub_category')) {
            $query->where('sub_category', $sub);
        }

        $sort = $request->query('sort', 'newest');
        $query = match ($sort) {
            'price_asc'  => $query->orderBy('selling_price', 'asc'),
            'price_desc' => $query->orderBy('selling_price', 'desc'),
            default      => $query->latest(),
        };

        $items = $query->paginate(24)->withQueryString();

        $imageUrls = [];
        foreach ($items as $item) {
            $imageUrls[$item->id] = $this->catalog->resolveImageUrl($request, $item);
        }

        $subCategories = Item::where('status', 'in_stock')
            ->where('category', $category)
            ->whereNotNull('sub_category')
            ->where('sub_category', '!=', '')
            ->distinct()
            ->orderBy('sub_category')
            ->pluck('sub_category');

        $itemCount = Item::where('status', 'in_stock')
            ->where('category', $category)
            ->count();

        return view('public.catalog.category', compact(
            'category', 'items', 'imageUrls', 'subCategories', 'itemCount', 'sort'
        ));
    }

    /**
     * Single product detail page.
     */
    public function product(Request $request, string $slug, string $token)
    {
        $item = Item::where('share_token', $token)
            ->where('status', 'in_stock')
            ->firstOrFail();

        $imageUrl = $this->catalog->resolveImageUrl($request, $item);

        // Related items from same category.
        $relatedItems = Item::where('status', 'in_stock')
            ->where('category', $item->category)
            ->where('id', '!=', $item->id)
            ->limit(4)
            ->get();

        $relatedImageUrls = [];
        foreach ($relatedItems as $related) {
            $relatedImageUrls[$related->id] = $this->catalog->resolveImageUrl($request, $related);
        }

        return view('public.catalog.product', compact(
            'item', 'imageUrl', 'relatedItems', 'relatedImageUrls'
        ));
    }

    /**
     * Shared collection landing page.
     */
    public function collection(Request $request, string $slug, string $token)
    {
        $collection = PublicCatalogCollection::where('token', $token)->firstOrFail();

        abort_if(
            $collection->expires_at !== null && $collection->expires_at->isPast(),
            410,
            'This catalog link has expired.'
        );

        $pivotItems = $collection->collectionItems()->with('item')->get();
        $items = $pivotItems->map(fn ($ci) => $ci->item)->filter()->values();

        abort_if($items->isEmpty(), 404);

        $imageUrls = [];
        foreach ($items as $item) {
            $imageUrls[$item->id] = $this->catalog->resolveImageUrl($request, $item);
        }

        return view('public.catalog.collection', compact(
            'collection', 'items', 'imageUrls'
        ));
    }

    /**
     * CMS page (about, terms, contact, custom).
     */
    public function page(Request $request, string $slug, string $pageSlug)
    {
        $page = CatalogPage::where('slug', $pageSlug)
            ->published()
            ->firstOrFail();

        return view('public.catalog.page', compact('page'));
    }
}
