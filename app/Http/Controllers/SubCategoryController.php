<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RespondsDynamically;
use App\Models\SubCategory;
use App\Rules\UniqueNameIgnoringCase;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubCategoryController extends Controller
{
    use RespondsDynamically;
    public function store(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $validated = $request->validate([
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where('shop_id', $shopId),
            ],
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNameIgnoringCase('sub_categories', [
                    'shop_id'     => $shopId,
                    'category_id' => $request->category_id,
                ], null, 'sub-category'),
            ],
        ]);

        SubCategory::create([
            'category_id' => $validated['category_id'],
            'name'        => $validated['name'],
        ]);

        return redirect()->route('categories.index')
            ->with('success', 'Sub-category created successfully!');
    }

    public function update(Request $request, SubCategory $sub_category)
    {
        $this->authorize('update', $sub_category);

        $shopId = auth()->user()->shop_id;

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNameIgnoringCase('sub_categories', [
                    'shop_id'     => $shopId,
                    'category_id' => $sub_category->category_id,
                ], $sub_category->id, 'sub-category'),
            ],
        ]);

        $sub_category->update(['name' => $validated['name']]);

        return redirect()->route('categories.index')
            ->with('success', 'Sub-category renamed successfully!');
    }

    public function destroy(SubCategory $sub_category)
    {
        $this->authorize('delete', $sub_category);

        $sub_category->delete();

        return $this->dynamicRedirect('categories.index', [], 'Sub-category deleted successfully!');
    }
}
