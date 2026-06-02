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
        // Identity documents are PII — store on the PRIVATE 'local' disk, never
        // the public disk. Served only via the authenticated stream route.
        $path = $file->storeAs(
            "kyc/{$shopId}",
            Str::ulid() . '.' . $ext,
            'local'
        );

        $doc = KycDocument::create([
            'shop_id'           => $shopId,
            'customer_id'       => $customer->id,
            'uploaded_by'       => auth()->id(),
            'document_type'     => $validated['document_type'],
            'file_path'         => $path,
            'file_disk'         => 'local',
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

        // Actually remove the PII file from disk — a soft deactivate alone left
        // the identity document on disk forever.
        $disk = $kycDocument->file_disk ?? 'public';
        if ($kycDocument->file_path && Storage::disk($disk)->exists($kycDocument->file_path)) {
            Storage::disk($disk)->delete($kycDocument->file_path);
        }

        $kycDocument->deactivate();

        return response()->json(['success' => true]);
    }
}
