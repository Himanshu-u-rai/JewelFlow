<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerProfilePhoto;
use App\Services\CustomerProfilePhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CustomerProfilePhotoController extends Controller
{
    public function show(Request $request, Customer $customer)
    {
        $this->tenant($request, $customer);
        $photo = $customer->profilePhoto;
        if ($request->expectsJson()) {
            return response()->json(['photo' => $this->present($request, $photo)])->header('Cache-Control', 'private, no-store');
        }
        abort_unless($photo && (! $request->has('version') || $request->query('version') === $photo->version), 404);
        abort_unless(Storage::disk('local')->exists($photo->file_path), 404);

        return Storage::disk('local')->response($photo->file_path, null, [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline',
        ]);
    }

    public function store(Request $request, Customer $customer, CustomerProfilePhotoService $photos)
    {
        $this->tenant($request, $customer);
        $request->validate([
            'customer_photo' => ['required', 'file', 'max:5120'],
            'customer_photo_version' => ['present', 'nullable', 'uuid'],
        ], ['customer_photo.max' => 'Choose an image no larger than 5 MB.']);
        $photo = $photos->save($customer, $request->file('customer_photo'), (string) $request->input('customer_photo_version'));

        return response()->json(['photo' => $this->present($request, $photo), 'message' => 'Customer photo saved.'])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, Customer $customer, CustomerProfilePhotoService $photos)
    {
        $this->tenant($request, $customer);
        $request->validate(['customer_photo_version' => ['present', 'nullable', 'uuid']]);
        $photos->save($customer, null, (string) $request->input('customer_photo_version'));

        return response()->json(['photo' => null, 'message' => 'Photo removed. Initials are shown instead.'])->header('Cache-Control', 'private, no-store');
    }

    private function tenant(Request $request, Customer $customer): void
    {
        abort_unless((int) $customer->shop_id === (int) $request->user()->shop_id, 404);
    }

    private function present(Request $request, ?CustomerProfilePhoto $photo): ?array
    {
        return $photo ? ['url' => $photo->url($request->routeIs('dhiran.*') ? 'dhiran' : 'erp'), 'version' => $photo->version] : null;
    }
}
