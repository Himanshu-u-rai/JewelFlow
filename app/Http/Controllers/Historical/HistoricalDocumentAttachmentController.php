<?php

namespace App\Http\Controllers\Historical;

use App\Http\Controllers\Controller;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesDocumentAttachment;
use App\Services\Historical\HistoricalSalesDocumentAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Evidence (scanned bills/proof) attached to a historical document (Batch 5).
 * Storage/streaming posture mirrors `KycDocumentController` exactly: the file
 * never has a public URL, and is only ever served through this authenticated,
 * shop-scoped stream route.
 *
 * Both `{document}` and `{attachment}` are resolved via the module's existing
 * tenant-scoped `Route::bind()` convention (routes/web.php) — a cross-shop id
 * 404s before this controller ever runs, matching every other Historical route.
 */
class HistoricalDocumentAttachmentController extends Controller
{
    public function __construct(private readonly HistoricalSalesDocumentAttachmentService $attachments) {}

    public function store(Request $request, HistoricalSalesDocument $document): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:10240'],
        ]);

        $this->attachments->store($document, $validated['file'], (int) $request->user()->id);

        return back()->with('success', 'Attachment uploaded.');
    }

    /** Stream a private evidence file to an authenticated, same-shop user. */
    public function show(HistoricalSalesDocumentAttachment $attachment)
    {
        abort_unless($attachment->is_active, 404);

        $disk = $attachment->file_disk ?? 'local';
        abort_unless(Storage::disk($disk)->exists($attachment->file_path), 404);

        return Storage::disk($disk)->response(
            $attachment->file_path,
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream']
        );
    }

    public function destroy(Request $request, HistoricalSalesDocumentAttachment $attachment): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->attachments->remove($attachment, (int) $request->user()->id, $data['reason']);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Attachment removed.');
    }
}
