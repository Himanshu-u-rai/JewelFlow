<?php

namespace App\Http\Controllers;

use App\Models\GstCategory;
use App\Services\MetalRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GstCategoryController extends Controller
{
    public function store(Request $request)
    {
        $shopId = (int) auth()->user()->shop_id;

        $validated = $request->validate([
            'name'       => 'required|string|max:80',
            'rate_pct'   => 'required|numeric|min:0|max:99.99',
            'metal_type' => ['nullable', 'string', Rule::in(MetalRegistry::enabledMetalsForShop($shopId))],
            'is_default' => 'boolean',
        ]);

        $validated['shop_id'] = $shopId;
        $validated['is_default'] = $request->boolean('is_default');
        $validated['metal_type'] = $validated['metal_type'] ?: null;

        if ($validated['is_default']) {
            GstCategory::where('shop_id', $shopId)->update(['is_default' => DB::raw('false')]);
        }

        GstCategory::create($validated);

        return redirect(route('settings.edit', ['tab' => 'gst']) . '#gst-categories')
            ->with('success', 'GST category added.');
    }

    public function update(Request $request, GstCategory $gstCategory)
    {
        abort_unless($gstCategory->shop_id === auth()->user()->shop_id, 403);

        $shopId = (int) $gstCategory->shop_id;

        $validated = $request->validate([
            'name'       => 'required|string|max:80',
            'rate_pct'   => 'required|numeric|min:0|max:99.99',
            'metal_type' => ['nullable', 'string', Rule::in(MetalRegistry::enabledMetalsForShop($shopId))],
            'is_default' => 'boolean',
        ]);

        $validated['is_default'] = $request->boolean('is_default');
        $validated['metal_type'] = $validated['metal_type'] ?: null;

        if ($validated['is_default']) {
            GstCategory::where('shop_id', $gstCategory->shop_id)
                ->where('id', '!=', $gstCategory->id)
                ->update(['is_default' => DB::raw('false')]);
        }

        $gstCategory->update($validated);

        return redirect(route('settings.edit', ['tab' => 'gst']) . '#gst-categories')
            ->with('success', 'GST category updated.');
    }

    public function destroy(GstCategory $gstCategory)
    {
        abort_unless($gstCategory->shop_id === auth()->user()->shop_id, 403);

        // No minimum-count rule: per-metal categories are optional overrides on
        // top of the shop's flat "Default GST Rate", which is always the
        // fallback. Deleting the last one simply reverts that metal to default.
        $gstCategory->delete();

        return redirect(route('settings.edit', ['tab' => 'gst']) . '#gst-categories')
            ->with('success', 'GST category deleted.');
    }
}
