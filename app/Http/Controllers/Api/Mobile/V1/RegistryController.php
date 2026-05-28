<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Data\Mobile\V1\MaterialRegistrySnapshot;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Mobile v1 — Material Registry endpoint (M1 of Mobile Contract
 * Stabilization).
 *
 * GET /api/mobile/v1/registry/materials
 *
 * Single-producer authority for the materials capability snapshot.
 * Mobile clients consult this instead of hardcoding metal business
 * rules (purity selector mode, fine-weight support, reference-price
 * support, identity class, pricing class).
 *
 * Read-only: this controller never writes. There is no write
 * counterpart — capability data flows one-way from MetalRegistry
 * to the client.
 *
 * Envelope wrapping (canonical {data, meta, errors} shape) is added
 * by the mobile.envelope middleware — this action returns the raw
 * Spatie Data object.
 */
class RegistryController extends Controller
{
    public function materials(Request $request): MaterialRegistrySnapshot
    {
        $shopId = (int) $request->user()->shop_id;

        return MaterialRegistrySnapshot::forShop($shopId);
    }
}
