<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RespondsDynamically;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    use RespondsDynamically;
    public function index()
    {
        $categories = Category::where('shop_id', auth()->user()->shop_id)
            ->with('subCategories')
            ->orderBy('name')
            ->get();

        return view('categories.index', compact('categories'));
    }

    public function store(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->where('shop_id', $shopId),
            ],
        ]);

        $category = Category::create([
            'shop_id' => $shopId,
            'name'    => $validated['name'],
        ]);

        $category->load('subCategories');

        return $this->turboStreamAppend(
            'categories-list',
            'categories._category-card',
            ['category' => $category],
            'Category created successfully!',
            'categories.index',
        );
    }

    public function update(Request $request, Category $category)
    {
        $this->authorize('update', $category);

        $shopId = auth()->user()->shop_id;

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->where('shop_id', $shopId)->ignore($category->id),
            ],
        ]);

        $category->update(['name' => $validated['name']]);

        return redirect()->route('categories.index')
            ->with('success', 'Category renamed successfully!');
    }

    public function destroy(Category $category)
    {
        $this->authorize('delete', $category);

        DB::transaction(function () use ($category) {
            $category->subCategories()->delete();
            $category->delete();
        });

        return $this->dynamicRedirect('categories.index', [], 'Category deleted successfully!');
    }
}
