<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Services\MetalRegistry;
use App\Services\ReferencePriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile v1 — Class-B reference price reads (M10).
 *
 * GET /api/mobile/v1/reference-prices
 *
 * Returns the latest noted reference price and a short history for each
 * Tier-2 (Class B) metal that is enabled for this shop.
 *
 * Stabilized guarantees maintained:
 *   - Reference price is NEVER an accounting rate.
 *   - Class A metals (gold, silver) are absent from this response.
 *   - Class C materials (stones) are absent.
 *   - The payload carries `pricing_class: "B"` and `is_memo_only: true`
 *     so mobile clients cannot misinterpret the value.
 *   - No fine-weight, vault, or GST computation is performed.
 */
class ReferencePriceController extends Controller
{
    private const HISTORY_LIMIT = 20;

    public function __construct(private ReferencePriceService $references) {}

    public function index(Request $request): JsonResponse
    {
        $shopId  = (int) $request->user()->shop_id;
        $tier2   = MetalRegistry::tier2Metals();
        $enabled = MetalRegistry::enabledMetalsForShop($shopId);

        $metals = [];

        foreach ($tier2 as $metal) {
            if (! in_array($metal, $enabled, true)) {
                continue;
            }

            $latest  = $this->references->latestReference($shopId, $metal);

            // History: last N records for this metal, newest first.
            $history = \App\Models\ShopMetalReferencePrice::withoutTenant()
                ->where('shop_id', $shopId)
                ->where('metal_type', $metal)
                ->with('notedBy:id,name')
                ->orderByDesc('noted_at')
                ->orderByDesc('id')
                ->limit(self::HISTORY_LIMIT)
                ->get()
                ->map(fn ($row) => $this->presentRow($row))
                ->values()
                ->all();

            $metals[$metal] = [
                'metal'       => $metal,
                'pricing_class' => 'B',
                'is_memo_only'  => true,
                'latest'      => $latest ? $this->presentRow($latest) : null,
                'history'     => $history,
            ];
        }

        return response()->json([
            'metals'   => $metals,
            'disclaimer' => 'Reference prices are operator-noted memos. They are never used in accounting, vault math, GST, or repricing.',
        ]);
    }

    private function presentRow(\App\Models\ShopMetalReferencePrice $row): array
    {
        return [
            'id'              => $row->id,
            'metal_type'      => $row->metal_type,
            'reference_price' => (float) $row->reference_price,
            'noted_at'        => optional($row->noted_at)->toIso8601String(),
            'noted_by'        => $row->notedBy ? ['id' => $row->notedBy->id, 'name' => $row->notedBy->name] : null,
            'note'            => $row->note,
        ];
    }
}
