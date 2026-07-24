<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RespondsDynamically;
use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
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

        return DB::transaction(function () use ($category) {
            // ponytail: lockForUpdate on the parent row is enough on Postgres — a
            // concurrent INSERT into products/sub_categories referencing this
            // category_id takes a FK "FOR KEY SHARE" lock on this same row, so it
            // blocks until our transaction commits/rolls back. That makes the
            // count-then-delete below race-safe without touching the child tables.
            $locked = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();

            // ponytail: withoutTenant() here is deliberate, not a leak. $locked is
            // already tenant-authorized above (BelongsToShop's scope + lockForUpdate
            // only bound it if it's this shop's row). These two counts must find
            // every physical FK reference by category_id, including a legacy or
            // malformed cross-shop row that app-level validation would normally
            // block — otherwise a stray Product row would trip the products.category_id
            // RESTRICT FK into a raw 500 below, and a stray SubCategory row would get
            // silently wiped by sub_categories.category_id's CASCADE FK instead of
            // being counted and blocked.
            $productCount = Product::withoutTenant()->where('category_id', $locked->id)->count();
            $subCategoryCount = SubCategory::withoutTenant()->where('category_id', $locked->id)->count();

            if ($productCount > 0 || $subCategoryCount > 0) {
                $parts = [];
                if ($productCount > 0) {
                    $parts[] = $productCount . ' product' . ($productCount === 1 ? '' : 's');
                }
                if ($subCategoryCount > 0) {
                    $parts[] = $subCategoryCount . ' subcategor' . ($subCategoryCount === 1 ? 'y' : 'ies');
                }

                $message = 'Cannot delete this category: ' . implode(' and ', $parts) . ' still reference it.';

                return $this->dynamicRedirect('categories.index', [], $message, 'error');
            }

            $locked->delete();

            return $this->dynamicRedirect('categories.index', [], 'Category deleted successfully!');
        });
    }
}
