<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\ShopPricingService;
use App\Services\MetalRegistry;

class ProductController extends Controller
{
    public function __construct(private ShopPricingService $pricing) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $query = \App\Models\Product::where('shop_id', $shopId)
            ->with(['category', 'subCategory']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('name', 'ilike', "%$search%")
                  ->orWhere('design_code', 'ilike', "%$search%")
                  ->orWhereHas('category', fn($c) => $c->where('name', 'ilike', "%$search%"))
                  ->orWhereHas('subCategory', fn($sc) => $sc->where('name', 'ilike', "%$search%"));
            });
        }

        $products = $query->orderBy('id', 'desc')->paginate(15);
        return view('products.index', compact('products'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $shopId = auth()->user()->shop_id;
        $shop = auth()->user()->shop;
        $categories = \App\Models\Category::where('shop_id', $shopId)->with('subCategories')->get();
        $purityProfiles = $this->pricing->activePurityProfiles($shop)->groupBy('metal_type');

        return view('products.create', compact('categories', 'purityProfiles'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $shop = auth()->user()->shop;
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'design_code' => ['nullable', 'string', 'max:100', Rule::unique('products', 'design_code')->where('shop_id', $shopId)],
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where('shop_id', $shopId),
            ],
            'sub_category_id' => [
                'required',
                Rule::exists('sub_categories', 'id')->where('shop_id', $shopId),
            ],
            'metal_type' => ['required', Rule::in(MetalRegistry::accountingTruthMetals())],
            'default_purity' => 'nullable|numeric|min:0.001|max:1000',
            'approx_weight' => 'nullable|numeric|min:0',
            'default_making' => 'nullable|numeric|min:0',
            'default_stone' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'image' => 'nullable|file|mimetypes:image/jpeg,image/png,image/gif,image/webp|max:5120',
        ]);

        if (! empty($validated['default_purity']) && ! $this->pricing->profileForPurity($shop, $validated['metal_type'], (float) $validated['default_purity'])) {
            return back()->withErrors([
                'default_purity' => 'Select an active purity profile for the chosen metal type.',
            ])->withInput();
        }
        
        // Auto-generate design_code if not provided
        if (empty($validated['design_code'])) {
            $validated['design_code'] = 'PRD-' . strtoupper(substr(md5(uniqid()), 0, 8));
        }
        
        // Handle image upload
        if ($request->hasFile('image')) {
            $validated['image'] = app(\App\Services\ImageOptimizer::class)->optimizeAndStore($request->file('image'), 'products', 'public');
        }
        
        $product = \App\Models\Product::create($validated);
        return redirect()->route('products.show', $product)->with('success', 'Product created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $shopId = auth()->user()->shop_id;
        $product = \App\Models\Product::where('shop_id', $shopId)
            ->with(['category', 'subCategory', 'items'])
            ->findOrFail($id);
        return view('products.show', compact('product'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $shopId = auth()->user()->shop_id;
        $shop = auth()->user()->shop;
        $product = \App\Models\Product::where('shop_id', $shopId)->findOrFail($id);
        $categories = \App\Models\Category::where('shop_id', $shopId)->with('subCategories')->get();
        $purityProfiles = $this->pricing->activePurityProfiles($shop)->groupBy('metal_type');

        return view('products.edit', compact('product', 'categories', 'purityProfiles'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $shopId = auth()->user()->shop_id;
        $shop = auth()->user()->shop;
        $product = \App\Models\Product::where('shop_id', $shopId)->findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'design_code' => ['nullable', 'string', 'max:100', Rule::unique('products', 'design_code')->where('shop_id', $shopId)->ignore($product->id)],
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where('shop_id', $shopId),
            ],
            'sub_category_id' => [
                'required',
                Rule::exists('sub_categories', 'id')->where('shop_id', $shopId),
            ],
            'metal_type' => ['required', Rule::in(MetalRegistry::accountingTruthMetals())],
            'default_purity' => 'nullable|numeric|min:0.001|max:1000',
            'approx_weight' => 'nullable|numeric|min:0',
            'default_making' => 'nullable|numeric|min:0',
            'default_stone' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'image' => 'nullable|file|mimetypes:image/jpeg,image/png,image/gif,image/webp|max:5120',
        ]);

        if (! empty($validated['default_purity']) && ! $this->pricing->profileForPurity($shop, $validated['metal_type'], (float) $validated['default_purity'])) {
            return back()->withErrors([
                'default_purity' => 'Select an active purity profile for the chosen metal type.',
            ])->withInput();
        }
        
        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($product->image && \Storage::disk('public')->exists($product->image)) {
                \Storage::disk('public')->delete($product->image);
            }
            $validated['image'] = app(\App\Services\ImageOptimizer::class)->optimizeAndStore($request->file('image'), 'products', 'public');
        }
        
        // Handle image removal
        if ($request->has('remove_image') && $request->remove_image) {
            if ($product->image && \Storage::disk('public')->exists($product->image)) {
                \Storage::disk('public')->delete($product->image);
            }
            $validated['image'] = null;
        }
        
        $product->update($validated);
        return redirect()->route('products.show', $product)->with('success', 'Product updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $shopId = auth()->user()->shop_id;

        return DB::transaction(function () use ($shopId, $id) {
            // ponytail: same lockForUpdate-on-parent trick as CategoryController —
            // a concurrent Item insert referencing this product_id takes a
            // Postgres FK "FOR KEY SHARE" lock on this row, so it blocks until we
            // commit/rollback, making the count-then-delete below race-safe.
            $product = \App\Models\Product::where('shop_id', $shopId)->lockForUpdate()->findOrFail($id);

            // ponytail: withoutTenant() here is deliberate, not a leak. $product is
            // already tenant-authorized above. This count must find every physical
            // items.product_id reference, including a legacy/malformed cross-shop
            // Item row that app-level validation would normally block — otherwise
            // that stray row would get silently detached by items.product_id's
            // SET NULL FK instead of being counted and blocked.
            $itemCount = \App\Models\Item::withoutTenant()->where('product_id', $product->id)->count();

            if ($itemCount > 0) {
                $message = 'Cannot delete this design master: ' . $itemCount . ' item' . ($itemCount === 1 ? '' : 's') . ' still reference it.';

                return redirect()->route('products.index')->with('error', $message);
            }

            $product->delete();

            return redirect()->route('products.index')->with('success', 'Product deleted successfully.');
        });
    }
}
