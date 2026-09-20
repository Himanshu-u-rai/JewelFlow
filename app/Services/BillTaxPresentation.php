<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceRenderSnapshot;
use App\Models\QuickBill;
use App\Models\ShopBillingSettings;

/**
 * Audit finding S3-05 — decide how a bill DESCRIBES its own tax, from what it
 * was issued with rather than from today's shop settings.
 *
 * WHAT WAS WRONG
 * At baseline 018b3d8 invoice_print.blade.php resolved two statutory fields off
 * the live shop_billing_settings row every time it rendered:
 *
 *   igst_mode    one IGST row vs two CGST/SGST rows. A shop that switched the
 *                setting saw every historical invoice re-characterize itself as
 *                a different kind of supply.
 *   hsnForMetal  the HSN code printed against each line, read from the shop's
 *                CURRENT map, so editing the map rewrote finalized documents.
 *
 * Two prints of one invoice number could therefore disagree about what kind of
 * supply occurred, and about the HSN it occurred under.
 *
 * WHAT THIS DOES NOT CLAIM. Money was never affected and this does not repair
 * any figure. The amounts come off the bill's own row; CGST and SGST are half
 * the stored GST each. Total tax is identical under either presentation. This
 * fixes the CHARACTERIZATION, not the arithmetic — see D-03.
 *
 * NOR DOES IT COVER THE OTHER 43 LIVE READS. Theme colour, font tier, paper
 * size, subtitle and tagline still re-resolve at reprint. That drift is untidy,
 * not statutory, and pinning it is a separate change with its own review. S3-05
 * stays open for them.
 *
 * RESOLUTION ORDER — snapshot first, live settings only as fallback
 * Same shape as InvoiceSignatureRenderer, deliberately: a bill that recorded
 * what it printed is honoured, and a bill that recorded nothing falls back to
 * the only information that exists. The fallback is a STATED LIMITATION, not a
 * repair. An invoice finalized before invoice_render_snapshots carried these
 * keys has no record of its presentation and nothing can reconstruct one.
 *
 * LEGACY SNAPSHOT COMPATIBILITY
 * A snapshot written before 'hsn_map' existed has a 'billing' section without
 * it. That reads as an empty map, which resolves to HSN_DEFAULTS — which is
 * what those bills printed whenever the shop had not overridden a code, and is
 * the closest honest answer where it had. Snapshots are NEVER rewritten: the
 * defaulting happens at read time, so a mixed estate of old and new rows
 * resolves correctly at every intermediate state.
 *
 * NO AUTHORIZATION GATE HERE, unlike InvoiceSignatureRenderer, and the
 * difference is deliberate rather than an omission. That class returns image
 * BYTES off disk, so it re-checks the caller before touching storage. This
 * class returns a boolean and five short codes already derived from the bill
 * the caller was handed. Adding a gate that guards nothing the route has not
 * already guarded would be ceremony, and ceremony is how a real gate gets
 * mistaken for one more of these.
 */
class BillTaxPresentation
{
    /**
     * Per-instance memoization. Resolved once per request; the template calls
     * hsnFor() once per LINE and igstMode() once per printed copy, so without
     * this a two-copy ten-line bill re-decodes the snapshot twenty-two times.
     *
     * @var array<string, array{igst_mode:bool, hsn_map:array<string, ?string>}>
     */
    private array $memo = [];

    /**
     * @return array{igst_mode:bool, hsn_map:array<string, ?string>}
     */
    public function forInvoice(Invoice $invoice): array
    {
        return $this->memo['invoice:'.$invoice->id] ??= $this->resolve(
            (int) $invoice->shop_id,
            $this->billingSection(
                InvoiceRenderSnapshot::withoutTenant()
                    ->where('invoice_id', $invoice->id)
                    ->value('snapshot')
            )
        );
    }

    /**
     * @return array{igst_mode:bool, hsn_map:array<string, ?string>}
     */
    public function forQuickBill(QuickBill $quickBill): array
    {
        $snapshot = is_array($quickBill->shop_snapshot) ? $quickBill->shop_snapshot : null;

        // Keyed by object identity, not by id: QuickBillController::printOriginal
        // renders a second, non-persisted QuickBill carrying the SAME id as the
        // live bill but the as-issued snapshot. Keying on id would serve the
        // edited bill's presentation for the original.
        return $this->memo['quickbill:'.spl_object_id($quickBill)] ??= $this->resolve(
            (int) $quickBill->shop_id,
            // Bills issued before this finding have no igst_mode key. Treating a
            // missing key as false would silently claim they were intra-state;
            // treating the whole snapshot as unusable sends them to live
            // settings, which is where they already resolved.
            (is_array($snapshot) && array_key_exists('igst_mode', $snapshot)) ? $snapshot : null
        );
    }

    /**
     * The HSN for one line, resolved against the map this bill was issued under.
     *
     * @param  array{igst_mode:bool, hsn_map:array<string, ?string>}  $presentation
     */
    public function hsnFor(array $presentation, ?string $metalType, ?string $category = null): string
    {
        return ShopBillingSettings::hsnFromMap($presentation['hsn_map'], $metalType, $category);
    }

    /**
     * The stored payload nests settings under 'billing' (schema_version 1,
     * InvoiceRenderSnapshotService::buildInvoiceSnapshot). A row whose payload
     * lacks that section is not something this can read, so it falls back rather
     * than guessing at a shape.
     *
     * @return array<string, mixed>|null
     */
    private function billingSection(mixed $snapshot): ?array
    {
        return is_array($snapshot) && is_array($snapshot['billing'] ?? null)
            ? $snapshot['billing']
            : null;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{igst_mode:bool, hsn_map:array<string, ?string>}
     */
    private function resolve(int $shopId, ?array $snapshot): array
    {
        if ($snapshot !== null) {
            return [
                'igst_mode' => (bool) ($snapshot['igst_mode'] ?? false),
                // Snapshots predating the key read as an empty map, which
                // hsnFromMap turns into HSN_DEFAULTS. Never written back.
                'hsn_map' => is_array($snapshot['hsn_map'] ?? null) ? $snapshot['hsn_map'] : [],
            ];
        }

        $billing = ShopBillingSettings::withoutTenant()->where('shop_id', $shopId)->first();

        return [
            'igst_mode' => (bool) $billing?->igst_mode,
            'hsn_map' => $billing?->hsnMap() ?? [],
        ];
    }
}
