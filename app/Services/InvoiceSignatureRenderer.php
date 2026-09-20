<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceRenderSnapshot;
use App\Models\QuickBill;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Audit finding S3-04 — resolve the signature a bill should print, and return it
 * as inline bytes rather than a URL.
 *
 * WHY INLINE (option B)
 * A protected <img src="/signature/..."> works in a browser, where the session
 * cookie rides along with the sub-request. It does NOT work on mobile: the app
 * fetches the invoice HTML as a JSON string with a bearer token, then hands that
 * HTML to a WebView / Expo Print, and the resulting <img> sub-request carries no
 * token. Embedding the bytes in the document that was already authorized is the
 * only representation both consumers can render.
 *
 * BASE64 IS NOT ACCESS CONTROL. It is an encoding. The protection is that this
 * method refuses to produce bytes at all unless the caller has already passed the
 * same checks the print routes enforce, and that the containing document is not
 * retained by a cache after it is displayed. The second half is the 'nocache'
 * middleware (App\Http\Middleware\NoCache) on invoices.print, quick-bills.print,
 * quick-bills.print-original and both mobile /template routes — pinned by G-21
 * and G-22. An earlier revision of this docblock named a middleware
 * ("EnsurePrintResponsesArePrivate") that was never written; that gap was real
 * until those tests were added.
 *
 * TWO CORRECTIONS TO THE ORIGINAL RATIONALE, both measured by G-23:
 * 1. The framework default already sends an unqualified `private`, which forbids
 *    SHARED-cache storage (RFC 9111 §5.2.2.7). No shared-cache exposure was ever
 *    demonstrated. What no-store adds is the PRIVATE caches `private` permits:
 *    the till browser's disk cache and the mobile WebView cache.
 * 2. These are application response headers. No CDN has been observed honouring,
 *    stripping or overriding them. Do not read G-21/G-22 as edge verification.
 *
 * RESOLUTION ORDER — snapshot first, live settings only as fallback
 * A finalized invoice must print what it was signed with. invoice_render_snapshots
 * already captures the selection at finalize time; at baseline 018b3d8 nothing
 * ever read those rows, which is why reprinting silently followed today's
 * settings. This is the reader. When no snapshot exists (every invoice finalized
 * before the snapshot service shipped) the live row is the only information that
 * exists, and is used — that is a stated limitation, not a repair. Bytes already
 * deleted by the old SettingsController delete calls cannot be reconstructed.
 *
 * LEGACY SNAPSHOT COMPATIBILITY
 * Snapshots written before digital_signature_disk existed carry a path and no
 * disk. Those bytes are physically on the public tree, so a missing disk key reads
 * as 'public'. Snapshots are NEVER rewritten: the fallback is applied at read
 * time, so a mixed estate (old public rows, new private rows) resolves correctly
 * at every intermediate state of the relocation.
 */
class InvoiceSignatureRenderer
{
    /** Matches the upload rule (max:512 KB) with no headroom to smuggle a payload. */
    public const MAX_BYTES = 524288;

    /** A signature is a small graphic. Anything larger is not one. */
    public const MAX_DIMENSION = 2000;

    /** Only disks this application controls. No s3, no remote, no request input. */
    public const ALLOWED_DISKS = ['public', 'local'];

    private const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /**
     * Per-instance memoization. The renderer is resolved once per request, and a
     * bill may print copy_count copies from one template render, so without this
     * the same file is read and base64-encoded once per copy.
     *
     * @var array<string, array{show:bool, available:bool, dataUri:?string, reason:?string}>
     */
    private array $memo = [];

    /**
     * @return array{show:bool, available:bool, dataUri:?string, reason:?string}
     */
    public function forInvoice(Invoice $invoice): array
    {
        $this->authorize((int) $invoice->shop_id);

        $snapshot = InvoiceRenderSnapshot::withoutTenant()
            ->where('invoice_id', $invoice->id)
            ->value('snapshot');

        // The stored payload nests the settings under 'billing' (schema_version 1,
        // InvoiceRenderSnapshotService::buildInvoiceSnapshot). A row whose payload
        // lacks that section is not something this can read, so it falls back to
        // live settings rather than guessing at a shape.
        $billing = is_array($snapshot) && is_array($snapshot['billing'] ?? null)
            ? $snapshot['billing']
            : null;

        return $this->resolve('invoice:'.$invoice->id, (int) $invoice->shop_id, $billing);
    }

    /**
     * @return array{show:bool, available:bool, dataUri:?string, reason:?string}
     */
    public function forQuickBill(QuickBill $quickBill): array
    {
        $this->authorize((int) $quickBill->shop_id);

        // Quick bills carry their own shop_snapshot. Bills issued before this
        // finding carry no signature keys there, so they fall back to live
        // settings — same stated limitation as pre-snapshot invoices.
        $snapshot = is_array($quickBill->shop_snapshot) ? $quickBill->shop_snapshot : null;

        // Keyed by object identity, NOT by id. QuickBillController::printOriginal
        // renders a second, non-persisted QuickBill hydrated from
        // original_snapshot that carries the SAME id as the live bill but a
        // different shop_snapshot. Keying on id would serve the live bill's
        // signature for the as-issued original.
        return $this->resolve(
            'quickbill:'.spl_object_id($quickBill),
            (int) $quickBill->shop_id,
            (is_array($snapshot) && array_key_exists('show_digital_signature', $snapshot)) ? $snapshot : null,
        );
    }

    /**
     * The bill belongs to the active tenant, and the caller may view sales.
     *
     * Checked BEFORE any storage read, so an authorization failure never reaches
     * the filesystem. This duplicates the route middleware on purpose: the print
     * views are rendered from four places (web invoice, web quick bill, and both
     * mobile HTML endpoints) and a future fifth caller must not be able to inline
     * signature bytes by forgetting a gate.
     */
    private function authorize(int $shopId): void
    {
        $activeShopId = TenantContext::get() ?? Auth::user()?->shop_id;

        abort_if($activeShopId === null, 403, 'No active shop context.');
        abort_if((int) $activeShopId !== $shopId, 404);

        if (Auth::check()) {
            abort_if(Gate::denies('sales.view'), 403);
        }
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{show:bool, available:bool, dataUri:?string, reason:?string}
     */
    private function resolve(string $memoKey, int $shopId, ?array $snapshot): array
    {
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        if ($snapshot !== null) {
            $show = (bool) ($snapshot['show_digital_signature'] ?? false);
            $path = $snapshot['digital_signature_path'] ?? null;
            // Legacy snapshots predate the disk key. Their bytes are on the
            // public tree, so that is the honest default — applied here at read
            // time, never written back into the stored snapshot.
            $disk = $snapshot['digital_signature_disk'] ?? 'public';
        } else {
            $billing = \App\Models\ShopBillingSettings::withoutTenant()
                ->where('shop_id', $shopId)
                ->first();

            $show = (bool) $billing?->show_digital_signature;
            $path = $billing?->digital_signature_path;
            $disk = $billing?->digital_signature_disk ?? 'public';
        }

        return $this->memo[$memoKey] = $this->build($show, is_string($path) ? $path : null, is_string($disk) ? $disk : null);
    }

    /**
     * @return array{show:bool, available:bool, dataUri:?string, reason:?string}
     */
    private function build(bool $show, ?string $path, ?string $disk): array
    {
        // Deliberately switched off, or never configured. Not a fault — the
        // operator gets no warning, because nothing is wrong.
        if (! $show || $path === null || $path === '') {
            return ['show' => false, 'available' => false, 'dataUri' => null, 'reason' => null];
        }

        if ($disk === null || ! in_array($disk, self::ALLOWED_DISKS, true)) {
            return $this->unavailable('bad_disk', $path, $disk);
        }

        // Storage paths are relative and application-generated. A traversal
        // sequence or absolute path means the value did not come from where it
        // should have, so it is refused rather than normalized.
        if (str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return $this->unavailable('bad_path', $path, $disk);
        }

        // The recorded disk is a location HINT, not the identity of the file.
        // A finalized snapshot is immutable, so once relocation moves bytes from
        // the public tree to the private one, that snapshot's disk is stale and
        // cannot be corrected — rewriting it is precisely what this finding
        // forbids. Every signature path is a Str::ulid() under signatures/{shop},
        // so a path names one and only one set of bytes on whichever
        // app-controlled disk currently holds it.
        //
        // This widens WHERE the same path is looked for. It does not widen WHICH
        // paths or WHICH disks are acceptable: the ALLOWED_DISKS and traversal
        // checks above have already run, and the content validation below still
        // runs on whatever is found.
        $storage = null;
        $found   = $disk;

        foreach (array_unique([$disk, ...self::ALLOWED_DISKS]) as $candidate) {
            if (Storage::disk($candidate)->exists($path)) {
                $storage = Storage::disk($candidate);
                $found   = $candidate;
                break;
            }
        }

        if ($storage === null) {
            return $this->unavailable('missing', $path, $disk);
        }

        if ($found !== $disk) {
            // Not an error — this is the expected state mid-relocation. Logged
            // so a stale recorded disk is visible to reconciliation rather than
            // silently absorbed forever.
            Log::info('Invoice signature resolved from a disk other than the one recorded', [
                'recorded_disk' => $disk,
                'found_on'      => $found,
                'path_hash'     => substr(hash('sha256', $path), 0, 12),
            ]);
        }

        $disk = $found;

        // Size is checked from metadata BEFORE the bytes are pulled into memory,
        // so an oversized file cannot be used to exhaust the render process.
        $size = $storage->size($path);
        if ($size === false || $size <= 0) {
            return $this->unavailable('unreadable', $path, $disk);
        }
        if ($size > self::MAX_BYTES) {
            return $this->unavailable('too_large', $path, $disk);
        }

        try {
            $bytes = $storage->get($path);
        } catch (\Throwable $e) {
            // Narrow on purpose: only the storage read is wrapped. An exception
            // from anywhere else in the render must keep propagating — swallowing
            // it would turn a real bug into a silently unsigned invoice.
            Log::warning('InvoiceSignatureRenderer: signature read failed', [
                'disk' => $disk, 'reason' => $e->getMessage(),
            ]);

            return $this->unavailable('unreadable', $path, $disk);
        }

        if (! is_string($bytes) || $bytes === '') {
            return $this->unavailable('unreadable', $path, $disk);
        }

        // Content validation, not extension trust: the recorded path could name
        // a .png that holds anything at all.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || empty($info['mime']) || ! in_array($info['mime'], self::ALLOWED_MIMES, true)) {
            return $this->unavailable('invalid', $path, $disk);
        }

        if ((int) $info[0] > self::MAX_DIMENSION || (int) $info[1] > self::MAX_DIMENSION) {
            return $this->unavailable('too_large', $path, $disk);
        }

        return [
            'show'      => true,
            'available' => true,
            'dataUri'   => 'data:'.$info['mime'].';base64,'.base64_encode($bytes),
            'reason'    => null,
        ];
    }

    /**
     * A signature was expected but cannot be produced.
     *
     * show stays TRUE so the template still renders the signature block with a
     * visible "Signature unavailable" marker: the operator must be able to see
     * that the bill they are about to hand over is missing its signature.
     * dataUri stays NULL — no other shop's signature, and no placeholder image
     * that could be mistaken for one, is ever substituted.
     *
     * The path is deliberately NOT logged. It names a file under a shop's private
     * storage, and diagnostics are a lower-trust surface than the render itself.
     *
     * @return array{show:true, available:false, dataUri:null, reason:string}
     */
    private function unavailable(string $reason, string $path, ?string $disk): array
    {
        Log::warning('Invoice signature unavailable', [
            'reason' => $reason,
            'disk'   => $disk,
            'path_hash' => substr(hash('sha256', $path), 0, 12),
        ]);

        return ['show' => true, 'available' => false, 'dataUri' => null, 'reason' => $reason];
    }
}
