<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
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
        $categories = \App\Models\Category::where('shop_id', $shopId)->get();
        return view('products.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $shopId = auth()->user()->shop_id;
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
            'default_purity' => 'nullable|integer|min:1|max:24',
            'approx_weight' => 'nullable|numeric|min:0',
            'default_making' => 'nullable|numeric|min:0',
            'default_stone' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'image' => 'nullable|file|mimetypes:image/jpeg,image/png,image/gif,image/webp|max:5120',
        ]);
        
        // Auto-generate design_code if not provided
        if (empty($validated['design_code'])) {
            $validated['design_code'] = 'PRD-' . strtoupper(substr(md5(uniqid()), 0, 8));
        }
        
        // Handle image upload
        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('products', 'public');
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
        $product = \App\Models\Product::where('shop_id', $shopId)->findOrFail($id);
        $categories = \App\Models\Category::where('shop_id', $shopId)->with('subCategories')->get();
        return view('products.edit', compact('product', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $shopId = auth()->user()->shop_id;
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
            'default_purity' => 'nullable|integer|min:1|max:24',
            'approx_weight' => 'nullable|numeric|min:0',
            'default_making' => 'nullable|numeric|min:0',
            'default_stone' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'image' => 'nullable|file|mimetypes:image/jpeg,image/png,image/gif,image/webp|max:5120',
        ]);
        
        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($product->image && \Storage::disk('public')->exists($product->image)) {
                \Storage::disk('public')->delete($product->image);
            }
            $validated['image'] = $request->file('image')->store('products', 'public');
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
        $product = \App\Models\Product::where('shop_id', $shopId)->findOrFail($id);
        $product->delete();
        return redirect()->route('products.index')->with('success', 'Product deleted successfully.');
    }
}
