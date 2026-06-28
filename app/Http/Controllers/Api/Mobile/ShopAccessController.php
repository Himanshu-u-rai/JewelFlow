<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Data\Mobile\ShopAccessData;
use App\Http\Controllers\Controller;
use App\Models\ShopPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile owner Close/Reopen Shop. Lives outside the shopaccess.open gate (its
 * route names are exempted in EnsureShopAccessOpen) so the owner can reopen and
 * any role can read status even while the shop is closed.
 */
class ShopAccessController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->statusPayload($request));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isOwner()) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Only the shop owner can change shop access.',
            ], 403);
        }

        $validated = $request->validate([
            'shop_access_enabled' => 'required|boolean',
        ]);

        // Flip ONLY this flag — never via updatePreferences(), which rewrites
        // every preference column and would clobber quick_bill_enabled etc.
        $shop = $user->shop;
        $preferences = $shop->preferences ?? new ShopPreferences(['shop_id' => $shop->id]);
        $preferences->shop_access_enabled = $validated['shop_access_enabled'];
        $preferences->save();

        return response()->json($this->statusPayload($request));
    }

    /**
     * Endpoint shape (status string), derived from the shared ShopAccessData so
     * the open/closed/owner logic stays in one place.
     */
    private function statusPayload(Request $request): array
    {
        $user = $request->user();
        $access = ShopAccessData::forShopUser($user->shop, $user);

        return [
            'shop_access_enabled'     => $access->shop_access_enabled,
            'status'                  => $access->is_open ? 'open' : 'closed',
            'message'                 => $access->message,
            'can_current_user_access' => $access->can_current_user_access,
        ];
    }
}
