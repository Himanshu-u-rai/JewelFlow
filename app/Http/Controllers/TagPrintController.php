<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        // All matching IDs for "Select All" — capped at 500 to prevent
        // bloating the page payload and localStorage for large catalogues.
        $allMatchingIds = (clone $query)->limit(500)->pluck('id')->map(fn ($id) => (string) $id)->values()->all();

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

    /** Folded jewellery (dumbbell) tags: total length × height, and the length
     *  of each printable end (the tail between them stays blank). */
    public const FOLDED_SIZES = [
        '95x12' => ['length' => 95, 'height' => 12, 'end' => 25],
        '95x15' => ['length' => 95, 'height' => 15, 'end' => 25],
    ];

    /** Flat labels: width × height. */
    public const FLAT_SIZES = [
        '25x15' => ['length' => 25, 'height' => 15],
        '38x25' => ['length' => 38, 'height' => 25],
        '50x25' => ['length' => 50, 'height' => 25],
        '70x40' => ['length' => 70, 'height' => 40],
        '90x50' => ['length' => 90, 'height' => 50],
    ];

    /** Sizes before tag sizes became selectable, still accepted from old forms. */
    private const LEGACY_LABEL_SIZES = ['small' => '50x25', 'medium' => '70x40', 'large' => '90x50'];

    public function print(Request $request)
    {
        // Opened with no tags chosen (old bookmark, stray reload): back to the list.
        if (! $request->filled('item_ids')) {
            return redirect()->route('tags.index');
        }

        $shopId = auth()->user()->shop_id;
        $sizeKeys = implode(',', array_merge(array_keys(self::FOLDED_SIZES), array_keys(self::FLAT_SIZES), ['custom']));

        $data = $request->validate([
            'item_ids'             => 'required|array|min:1',
            'item_ids.*'           => [
                'integer',
                Rule::exists('items', 'id')->where(fn ($q) => $q
                    ->where('shop_id', $shopId)
                    ->where('status', 'in_stock')),
            ],
            'print_format'         => 'nullable|in:standard,folded',
            'tag_size'             => 'nullable|in:' . $sizeKeys,
            'custom_length'        => 'required_if:tag_size,custom|nullable|numeric|between:15,200',
            'custom_height'        => 'required_if:tag_size,custom|nullable|numeric|between:6,100',
            'custom_end'           => 'nullable|numeric|between:8,100',
            'codes'                => 'nullable|in:both,qr,barcode,none',
            // Old form fields.
            'label_size'           => 'nullable|in:small,medium,large',
            'include_barcode_image' => 'nullable|boolean',
            'folded_size'          => 'nullable|in:95x12,95x15',
        ]);

        $folded = ($data['print_format'] ?? 'standard') === 'folded';
        $key = $data['tag_size']
            ?? ($folded ? ($data['folded_size'] ?? '95x12') : self::LEGACY_LABEL_SIZES[$data['label_size'] ?? 'medium']);

        if ($key === 'custom') {
            $size = ['length' => (float) $data['custom_length'], 'height' => (float) $data['custom_height']];
            if ($folded) {
                $end = (float) ($data['custom_end'] ?? 0);
                if ($end <= 0 || $end * 2 > $size['length']) {
                    throw ValidationException::withMessages([
                        'custom_end' => 'Each printable end must fit twice on the tag (at most half its length).',
                    ]);
                }
                $size['end'] = $end;
            }
        } else {
            $size = ($folded ? self::FOLDED_SIZES : self::FLAT_SIZES)[$key] ?? null;
            if ($size === null) {
                throw ValidationException::withMessages(['tag_size' => 'Choose a size for this kind of tag.']);
            }
        }

        $codes = $data['codes']
            ?? (array_key_exists('include_barcode_image', $data) && ! $data['include_barcode_image'] ? 'none' : 'both');

        $tag = [
            'folded' => $folded,
            'length' => $size['length'],
            'height' => $size['height'],
            'end'    => $size['end'] ?? null,
            'qr'     => in_array($codes, ['both', 'qr'], true),
            'barcode' => in_array($codes, ['both', 'barcode'], true),
        ];

        $items = Item::where('shop_id', $shopId)
            ->whereIn('id', $data['item_ids'])
            ->get();

        $shop = auth()->user()->shop;

        return view('tags.print', compact('items', 'shop', 'tag'));
    }
}
