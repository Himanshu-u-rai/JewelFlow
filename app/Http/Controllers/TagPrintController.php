<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TagPrintController extends Controller
{
    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $query = Item::where('shop_id', $shopId)
            ->where('status', 'in_stock');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('barcode', 'ilike', "%{$search}%")
                  ->orWhere('design', 'ilike', "%{$search}%")
                  ->orWhere('category', 'ilike', "%{$search}%")
                  ->orWhere('huid', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // All matching IDs (no pagination) — used by the "Select All" button
        // to let the user select every item across all pages at once.
        $allMatchingIds = (clone $query)->pluck('id')->map(fn ($id) => (string) $id)->values()->all();

        $items = $query->latest()->paginate(20)->withQueryString();

        $categories = Item::where('shop_id', $shopId)
            ->where('status', 'in_stock')
            ->distinct()
            ->pluck('category')
            ->filter()
            ->sort()
            ->values();

        // Unique localStorage key scoped to shop + current filter combo.
        // Changing the filter clears the old selection automatically.
        $filterKey = md5(collect($request->only(['search', 'category']))->filter()->toJson());
        $storeKey  = 'tag_sel_' . $shopId . '_' . $filterKey;

        return view('tags.index', compact('items', 'categories', 'allMatchingIds', 'storeKey'));
    }

    public function print(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $data = $request->validate([
            'item_ids'             => 'required|array|min:1',
            'item_ids.*'           => [
                'integer',
                Rule::exists('items', 'id')->where(fn ($q) => $q
                    ->where('shop_id', $shopId)
                    ->where('status', 'in_stock')),
            ],
            'label_size'           => 'nullable|in:small,medium,large',
            'include_barcode_image' => 'nullable|boolean',
            'print_format'         => 'nullable|in:standard,folded',
            'folded_size'          => 'nullable|required_if:print_format,folded|in:95x12,95x15',
        ]);

        $items = Item::where('shop_id', $shopId)
            ->whereIn('id', $data['item_ids'])
            ->get();

        $labelSize          = $data['label_size'] ?? 'medium';
        $includeBarcodeImage = (bool) ($data['include_barcode_image'] ?? true);
        $printFormat        = $data['print_format'] ?? 'standard';
        $foldedSize         = $data['folded_size'] ?? '95x12';
        $shop               = auth()->user()->shop;

        return view('tags.print', compact(
            'items',
            'labelSize',
            'shop',
            'includeBarcodeImage',
            'printFormat',
            'foldedSize'
        ));
    }
}
