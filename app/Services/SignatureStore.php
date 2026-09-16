<?php

namespace App\Services;

use App\Models\ShopBillingSettings;
use Illuminate\Http\UploadedFile;

/**
 * Audit finding S3-04 — the single write path for a shop's digital signature.
 *
 * Two jobs, both narrow:
 *   1. New uploads go to the PRIVATE disk and the row records which disk, so a
 *      signature is never again served by nginx off the public/storage symlink.
 *   2. Previous signature files are NEVER deleted.
 *
 * WHY "NEVER DELETE" IS THE WHOLE IMMUTABILITY MECHANISM
 * ImageOptimizer names every stored file Str::ulid() (ImageOptimizer::putWebp),
 * so each upload already lands at a brand-new path — the old bytes are only lost
 * because SettingsController went out of its way to delete them
 * (:524 on replace, :529 on remove, both at baseline 018b3d8). Removing those two
 * deletes turns "one mutable current signature" into "an append-only series of
 * immutable versions" at zero structural cost: no versions table, no content
 * addressing, no reference counting. A finalized invoice's snapshot pins the
 * version it was signed with, and that path stays readable forever.
 *
 * ponytail: append-only, no GC. Ceiling — one orphaned file per signature
 * replacement, a few KB each, accumulating for the life of the shop. If that ever
 * matters, the upgrade path is a reaper that deletes only paths referenced by NO
 * invoice_render_snapshots row AND not the current settings row. It must not be
 * written speculatively: a reaper with a wrong query destroys exactly the evidence
 * this finding exists to protect.
 */
class SignatureStore
{
    /** New signatures live here. Private disk, rooted outside the webroot. */
    public const DISK = 'local';

    /**
     * Store a newly uploaded signature as a new immutable version.
     * The caller is responsible for validating the upload and for saving $billing.
     *
     * @return array{path:string, disk:string} to merge into the settings write
     */
    public function store(ShopBillingSettings $billing, UploadedFile $file): array
    {
        // Namespaced per shop so a stray path from one tenant cannot collide with
        // (or be confused for) another's, and so relocation can reconcile per shop.
        $path = app(ImageOptimizer::class)
            ->optimizeAndStore($file, 'signatures/'.(int) $billing->shop_id, self::DISK);

        return ['path' => $path, 'disk' => self::DISK];
    }

    /**
     * Drop the shop's CURRENT signature selection.
     *
     * Deliberately only clears the reference. The bytes stay on disk because a
     * finalized invoice may still reference this exact version, and "the operator
     * removed the signature from settings today" must not retroactively change
     * what a bill issued last year looks like.
     *
     * @return array{path:null, disk:null}
     */
    public function clear(ShopBillingSettings $billing): array
    {
        return ['path' => null, 'disk' => null];
    }
}
