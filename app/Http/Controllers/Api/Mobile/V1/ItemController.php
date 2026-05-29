<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Concerns\EmitsEntityTag;
use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile v1 — Item show/update with ETag concurrency control (M5).
 *
 * GET    /api/mobile/v1/items/{item}   — returns item + ETag header
 * PATCH  /api/mobile/v1/items/{item}   — updates item, requires If-Match
 *
 * Shop scoping is enforced explicitly (abort 404 on cross-shop access)
 * rather than via a global scope so the route can be evaluated before
 * any shop-scoped query layer is fully active. 404 (not 403) is used to
 * avoid leaking the existence of other shops' rows.
 */
class ItemController extends Controller
{
    use EmitsEntityTag;

    public function show(Request $request, Item $item): JsonResponse
    {
        abort_if($item->shop_id !== (int) $request->user()->shop_id, 404);

        return response()
            ->json($this->present($item))
            ->header('ETag', $this->entityTagFor($item))
            ->header('X-Has-Entity-Tag', 'yes');
    }

    public function update(Request $request, Item $item): JsonResponse
    {
        abort_if($item->shop_id !== (int) $request->user()->shop_id, 404);

        $this->assertIfMatchOrFail($request, $item);

        $data = $request->validate([
            'selling_price'           => ['sometimes', 'numeric', 'min:0'],
            'design'                  => ['sometimes', 'string', 'max:255'],
            'category'                => ['sometimes', 'string', 'max:255'],
            'sub_category'            => ['sometimes', 'nullable', 'string', 'max:255'],
            'pricing_review_notes'    => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $item->fill($data)->save();
        $item->refresh();

        return response()
            ->json($this->present($item))
            ->header('ETag', $this->entityTagFor($item))
            ->header('X-Has-Entity-Tag', 'yes');
    }

    private function present(Item $item): array
    {
        return [
            'id'                  => $item->id,
            'barcode'             => $item->barcode,
            'design'              => $item->design,
            'category'            => $item->category,
            'sub_category'        => $item->sub_category,
            'metal_type'          => $item->metal_type,
            'gross_weight'        => $item->gross_weight,
            'stone_weight'        => $item->stone_weight,
            'net_metal_weight'    => $item->net_metal_weight,
            'purity'              => $item->purity,
            'selling_price'       => $item->selling_price,
            'status'              => $item->status,
            'updated_at'          => optional($item->updated_at)->toIso8601String(),
        ];
    }
}
