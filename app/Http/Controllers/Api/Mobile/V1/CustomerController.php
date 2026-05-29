<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Concerns\EmitsEntityTag;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile v1 — Customer show/update with ETag concurrency control (M5).
 *
 * GET    /api/mobile/v1/customers/{customer}  — returns customer + ETag
 * PATCH  /api/mobile/v1/customers/{customer}  — updates, requires If-Match
 *
 * Shop scoping is enforced explicitly via abort_if(... !== shop_id, 404)
 * so cross-shop access is indistinguishable from a missing row. 404 is
 * intentional: returning 403 would confirm the row exists in another
 * shop.
 */
class CustomerController extends Controller
{
    use EmitsEntityTag;

    public function show(Request $request, Customer $customer): JsonResponse
    {
        abort_if($customer->shop_id !== (int) $request->user()->shop_id, 404);

        return response()
            ->json($this->present($customer))
            ->header('ETag', $this->entityTagFor($customer))
            ->header('X-Has-Entity-Tag', 'yes');
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        abort_if($customer->shop_id !== (int) $request->user()->shop_id, 404);

        $this->assertIfMatchOrFail($request, $customer);

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'mobile'     => ['sometimes', 'nullable', 'string', 'max:32'],
            'email'      => ['sometimes', 'nullable', 'email', 'max:255'],
            'address'    => ['sometimes', 'nullable', 'string', 'max:1000'],
            'notes'      => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $customer->fill($data)->save();
        $customer->refresh();

        return response()
            ->json($this->present($customer))
            ->header('ETag', $this->entityTagFor($customer))
            ->header('X-Has-Entity-Tag', 'yes');
    }

    private function present(Customer $customer): array
    {
        return [
            'id'         => $customer->id,
            'first_name' => $customer->first_name,
            'last_name'  => $customer->last_name,
            'mobile'     => $customer->mobile,
            'email'      => $customer->email,
            'address'    => $customer->address,
            'notes'      => $customer->notes,
            'updated_at' => optional($customer->updated_at)->toIso8601String(),
        ];
    }
}
