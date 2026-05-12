<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\KycDocument;
use App\Services\AccountingAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        $file = $request->file('file');
        // Derive extension from validated MIME type, not the client-supplied filename
        $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
        $ext = $mimeToExt[$file->getMimeType()] ?? 'bin';
        $path = $file->storeAs(
            "kyc/{$shopId}",
            Str::ulid() . '.' . $ext,
            'public'
        );

        $doc = KycDocument::create([
            'shop_id'           => $shopId,
            'customer_id'       => $customer->id,
            'uploaded_by'       => auth()->id(),
            'document_type'     => $validated['document_type'],
            'file_path'         => $path,
            'file_disk'         => 'public',
            'original_filename' => $file->getClientOriginalName(),
            'mime_type'         => $file->getMimeType(),
            'file_size_bytes'   => $file->getSize(),
            'notes'             => $validated['notes'] ?? null,
        ]);

        AccountingAuditService::log([
            'shop_id'     => $shopId,
            'action'      => 'kyc_document_uploaded',
            'model_type'  => 'customer',
            'model_id'    => $customer->id,
            'description' => "KYC document ({$doc->document_type}) uploaded for customer #{$customer->id}",
            'data'        => ['document_id' => $doc->id, 'document_type' => $doc->document_type],
        ]);

        return response()->json([
            'id'            => $doc->id,
            'document_type' => $doc->document_type,
            'url'           => $doc->url(),
            'created_at'    => $doc->created_at->toDateTimeString(),
        ]);
    }

    public function destroy(KycDocument $kycDocument)
    {
        $this->authorize('update', $kycDocument->customer);

        $kycDocument->deactivate();

        return response()->json(['success' => true]);
    }
}
