<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\KycDocument;
use App\Services\KycDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KycDocumentController extends Controller
{
    public function store(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $validated = $request->validate([
            'customer_id'   => ['required', 'integer', \Illuminate\Validation\Rule::exists('customers', 'id')->where('shop_id', $shopId)],
            'document_type' => ['required', 'in:pan_card,aadhaar,passport,other'],
            'file'          => ['required', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:10240'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);

        $customer = Customer::findOrFail($validated['customer_id']);
        $this->authorize('update', $customer);

        // Storage + audit live in KycDocumentService so web and mobile stay identical.
        $doc = app(KycDocumentService::class)->store(
            $customer,
            $request->file('file'),
            $validated['document_type'],
            $validated['notes'] ?? null,
            (int) auth()->id(),
        );

        return response()->json([
            'id'            => $doc->id,
            'document_type' => $doc->document_type,
            'url'           => $doc->url(),
            'created_at'    => $doc->created_at->toDateTimeString(),
        ]);
    }

    /**
     * Stream a private KYC document to an authenticated, same-shop user.
     * The file never has a public URL.
     */
    public function show(KycDocument $kycDocument)
    {
        abort_unless($kycDocument->shop_id === auth()->user()->shop_id, 403);

        $disk = $kycDocument->file_disk ?? 'public';
        abort_unless(Storage::disk($disk)->exists($kycDocument->file_path), 404);

        return Storage::disk($disk)->response(
            $kycDocument->file_path,
            $kycDocument->original_filename,
            ['Content-Type' => $kycDocument->mime_type ?? 'application/octet-stream']
        );
    }

    public function destroy(KycDocument $kycDocument)
    {
        $this->authorize('update', $kycDocument->customer);

        // Remove the PII file from disk + deactivate (shared with the mobile path).
        app(KycDocumentService::class)->delete($kycDocument);

        return response()->json(['success' => true]);
    }
}
