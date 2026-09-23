<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceRenderSnapshot;
use App\Models\QuickBill;
use App\Models\Shop;
use App\Models\ShopBillingSettings;

/**
 * Audit finding S3-05 — decide what a bill SAYS about itself from what it was
 * issued with rather than from today's shop settings.
 *
 * THE LINE THIS CLASS DRAWS, AND WHY IT IS NOT "PIN EVERYTHING"
 * -------------------------------------------------------------
 * A reprint has two kinds of field on it and they want opposite treatment:
 *
 *   ASSERTED   what the document CLAIMS about the transaction. The GSTIN the
 *              supply was made under, whether that GSTIN was shown at all, the
 *              terms the customer was handed, and the account the customer was
 *              told to pay into. A reprint that changes one of these is making
 *              a different claim about the same transaction, under the same
 *              bill number. These come from the bill's own snapshot.
 *
 *   RENDERING  how the document is physically produced TODAY. Theme colour,
 *              font tier, paper size, subtitle, tagline, copy label and count,
 *              and the show_* column toggles other than show_gstin. These
 *              describe the printer in front of you, not the sale. They stay
 *              LIVE, deliberately — freezing paper size to a 2024 setting would
 *              be a defect, not a repair. This class does not touch them and
 *              must not grow to. D-12 pins that bound.
 *
 * show_gstin sits on the ASSERTED side even though its ten neighbours are
 * rendering toggles, and that is not an inconsistency. The other show_* flags
 * choose which COLUMNS appear; show_gstin chooses whether a statutory
 * particular of a tax invoice is on the page. Hiding it on reprint changes what
 * the document attests, which is the test this whole class applies.
 *
 * WHAT IS PINNED
 * --------------
 *   igst_mode              one IGST row vs two CGST/SGST rows (first half of
 *                          this finding, commit b216b80)
 *   hsn_map                the HSN printed against each line (same commit)
 *   show_gstin, gst_number the shop's tax identity as printed
 *   terms_and_conditions   the terms the customer was actually given
 *   upi_id, bank_*         every payment instruction on the bill
 *   seller                 the supplier particulars: name, address lines,
 *                          city, state and state code (which also drive the
 *                          place-of-supply line), pincode, registration no.
 *                          (XR-06)
 *   recipient              name, mobile, address, ID and PAN (XR-06)
 *
 * NOT PINNED, AND SAID SO. The seller's phone, WhatsApp and email stay live,
 * pending a product decision about what a reprint's contact line is for (D-17).
 * The snapshot's capture of the recipient's address, ID and PAN prefers the
 * compliance record and falls back to the customer profile with `?:` — a
 * compliance record with no address prints the profile's. Whether a compliance
 * sale may do that is also a product decision. It is recorded, not changed.
 *
 * WHAT THIS DOES NOT CLAIM. Money was never affected and this repairs no
 * figure. The amounts come off the bill's own row; CGST and SGST are half the
 * stored GST each. Total tax is identical under either presentation. This fixes
 * the CHARACTERIZATION, not the arithmetic — see D-03.
 *
 * RESOLUTION ORDER — snapshot first, live settings only as fallback
 * Same shape as InvoiceSignatureRenderer, deliberately: a bill that recorded
 * what it printed is honoured, and a bill that recorded nothing falls back to
 * the only information that exists. The fallback is a STATED LIMITATION, not a
 * repair. An invoice finalized before invoice_render_snapshots carried these
 * keys has no record of its presentation and nothing can reconstruct one.
 *
 * LEGACY SNAPSHOT COMPATIBILITY, PER KEY FOR THE ASSERTED FIELDS
 * The tax pair falls back at SECTION granularity: a payload with no 'billing'
 * section is unreadable, and within a section a missing 'hsn_map' reads as an
 * empty map, which hsnFromMap turns into HSN_DEFAULTS — what those bills
 * printed wherever the shop had not overridden a code.
 *
 * The asserted-identity fields fall back per KEY instead, and the difference
 * matters in both directions. A snapshot written before a key existed has no
 * record of it, so live settings are the only answer; but a snapshot that
 * recorded the key as NULL is recording that the bill printed NOTHING there,
 * and must keep printing nothing. array_key_exists() separates those two; `??`
 * would collapse them and start printing today's bank account on a bill that
 * was issued without one. Snapshots are NEVER rewritten: all of this defaulting
 * happens at read time, so a mixed estate of old and new rows resolves
 * correctly at every intermediate state. D-13 pins the missing-key half and
 * D-13c the recorded-NULL half — and until D-13c was added, after a mutation
 * run contradicted the coverage claimed for D-13, this paragraph described
 * behaviour that no test bound. Replacing either branch with `??` passes
 * everything except D-13c.
 *
 * QUICK BILLS CARRY LESS, AND THE GAP IS STATED RATHER THAN PAPERED OVER
 * QuickBillService::shopSnapshot writes a FLAT payload holding gst_number,
 * terms_and_conditions, bank_details, upi_id and igst_mode. It has never
 * carried show_gstin or the itemised bank_name/ifsc/branch group. Those keys
 * are not invented here and nothing new is captured by this change: the
 * quick-bill template keeps reading the itemised group exactly as it already
 * did. Quick bills do not print a GSTIN at all, so show_gstin has nothing to
 * gate there.
 *
 * NO AUTHORIZATION GATE HERE, unlike InvoiceSignatureRenderer, and the
 * difference is deliberate rather than an omission. That class returns image
 * BYTES off disk, so it re-checks the caller before touching storage. This
 * class returns booleans and short strings already derived from the bill the
 * caller was handed. Adding a gate that guards nothing the route has not
 * already guarded would be ceremony, and ceremony is how a real gate gets
 * mistaken for one more of these.
 */
class BillPresentation
{
    /**
     * The asserted-identity fields that live on shop_billing_settings and are
     * plain text. show_gstin is resolved separately because it is a boolean
     * defaulting to TRUE, and gst_number separately because it lives on the
     * shop row rather than on the billing row.
     */
    private const ASSERTED_BILLING_TEXT = [
        'terms_and_conditions',
        'upi_id',
        'bank_name',
        'bank_account_holder',
        'bank_account_number',
        'bank_ifsc',
        'bank_account_type',
        'bank_branch',
        'bank_details',
    ];

    /**
     * XR-06. The supplier particulars a tax invoice states: who sold, from
     * where. Captured in the snapshot's 'shop' section since schema_version 1
     * and, until XR-06, rendered from the live shop row. The seller's CONTACT
     * details — phone, WhatsApp, email — are deliberately absent: whether a
     * reprint should show how to reach the shop today or what the bill said
     * is an open product decision, and they stay live until it is made (D-17).
     */
    private const ASSERTED_SELLER = [
        'name', 'address', 'address_line1', 'address_line2', 'city',
        'state', 'state_code', 'pincode', 'shop_registration_number',
    ];

    /** XR-06. The recipient particulars, from the snapshot's 'customer' section. */
    private const ASSERTED_RECIPIENT = ['name', 'mobile', 'address', 'id_number', 'pan'];

    /**
     * Per-instance memoization. Resolved once per request; the template calls
     * hsnFor() once per LINE and reads the rest once per printed COPY, so
     * without this a two-copy ten-line bill re-decodes the snapshot dozens of
     * times and re-queries live settings alongside it.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

    /**
     * @return array<string, mixed>
     */
    public function forInvoice(Invoice $invoice): array
    {
        return $this->memo['invoice:'.$invoice->id] ??= (function () use ($invoice): array {
            $snapshot = InvoiceRenderSnapshot::withoutTenant()
                ->where('invoice_id', $invoice->id)
                ->value('snapshot');

            $billing = $this->section($snapshot, 'billing');
            $shop = $this->section($snapshot, 'shop');

            return $this->resolve(
                (int) $invoice->shop_id,
                $billing,
                $billing,
                $shop,
            ) + [
                'seller' => $this->seller((int) $invoice->shop_id, $shop),
                'recipient' => $this->recipient($invoice, $this->section($snapshot, 'customer')),
            ];
        })();
    }

    /**
     * @return array<string, mixed>
     */
    public function forQuickBill(QuickBill $quickBill): array
    {
        $snapshot = is_array($quickBill->shop_snapshot) ? $quickBill->shop_snapshot : null;

        // Keyed by object identity, not by id: QuickBillController::printOriginal
        // renders a second, non-persisted QuickBill carrying the SAME id as the
        // live bill but the as-issued snapshot, so an id key would be ambiguous
        // between the two.
        //
        // DEFENSIVE, AND CURRENTLY UNREACHABLE — measured, not assumed. Swapping
        // this for $quickBill->id changes no test result, because this class has
        // no container binding (app() hands back a fresh instance per resolve)
        // and each template resolves it once and renders one bill, so the memo
        // never holds two quick bills at once. Kept as object identity because it
        // stays correct if this is ever bound as a singleton or a view ever
        // renders both; recorded as unbound rather than implied to be covered.
        // See QuickBillOriginalReprintTest.
        return $this->memo['quickbill:'.spl_object_id($quickBill)] ??= $this->resolve(
            (int) $quickBill->shop_id,
            // Bills issued before this finding have no igst_mode key. Treating a
            // missing key as false would silently claim they were intra-state;
            // treating the whole snapshot as unusable sends them to live
            // settings, which is where they already resolved.
            ($snapshot !== null && array_key_exists('igst_mode', $snapshot)) ? $snapshot : null,
            // The asserted fields are deliberately NOT gated on igst_mode. A
            // quick bill predating that key can still have recorded its own
            // terms and payment instructions, and those are honoured on their
            // own. The payload is flat, so one array serves as both sections.
            $snapshot,
            $snapshot,
        );
    }

    /**
     * The HSN for one line, resolved against the map this bill was issued under.
     *
     * @param  array<string, mixed>  $presentation
     */
    public function hsnFor(array $presentation, ?string $metalType, ?string $category = null): string
    {
        return ShopBillingSettings::hsnFromMap($presentation['hsn_map'], $metalType, $category);
    }

    /**
     * The invoice payload nests settings under 'billing' and shop identity
     * under 'shop' (schema_version 1, InvoiceRenderSnapshotService). A payload
     * lacking the requested section is not something this can read, so it falls
     * back rather than guessing at a shape.
     *
     * @return array<string, mixed>|null
     */
    private function section(mixed $snapshot, string $name): ?array
    {
        return is_array($snapshot) && is_array($snapshot[$name] ?? null)
            ? $snapshot[$name]
            : null;
    }

    /**
     * @param  array<string, mixed>|null  $tax       igst_mode/hsn_map source, section-granular
     * @param  array<string, mixed>|null  $asserted  billing-row asserted fields, key-granular
     * @param  array<string, mixed>|null  $shop      gst_number source, key-granular
     * @return array<string, mixed>
     */
    private function resolve(int $shopId, ?array $tax, ?array $asserted, ?array $shop): array
    {
        // Loaded at most once, and only if some key actually falls back. A
        // fully-snapshotted bill never touches shop_billing_settings at all.
        $loaded = false;
        $settings = null;
        $live = function () use ($shopId, &$loaded, &$settings): ?ShopBillingSettings {
            if (! $loaded) {
                $settings = ShopBillingSettings::withoutTenant()->where('shop_id', $shopId)->first();
                $loaded = true;
            }

            return $settings;
        };

        $resolved = $tax !== null
            ? [
                'igst_mode' => (bool) ($tax['igst_mode'] ?? false),
                // Snapshots predating the key read as an empty map, which
                // hsnFromMap turns into HSN_DEFAULTS. Never written back.
                'hsn_map' => is_array($tax['hsn_map'] ?? null) ? $tax['hsn_map'] : [],
            ]
            : [
                'igst_mode' => (bool) $live()?->igst_mode,
                'hsn_map' => $live()?->hsnMap() ?? [],
            ];

        // Defaults TRUE when nothing recorded it, matching the template's
        // historical `$billing?->show_gstin ?? true` — a shop that never touched
        // the toggle showed its GSTIN.
        $resolved['show_gstin'] = $asserted !== null && array_key_exists('show_gstin', $asserted)
            ? (bool) $asserted['show_gstin']
            : (bool) ($live()?->show_gstin ?? true);

        foreach (self::ASSERTED_BILLING_TEXT as $key) {
            $resolved[$key] = $asserted !== null && array_key_exists($key, $asserted)
                ? $this->text($asserted[$key])
                : $this->text($live()?->{$key});
        }

        // Resolved against the BILL's shop rather than auth()->user()->shop,
        // which is what the template used to read. Tenant scoping makes the two
        // coincide, so this is not a cross-tenant repair; it is simply the
        // correct source now that one is available.
        $resolved['gst_number'] = $shop !== null && array_key_exists('gst_number', $shop)
            ? $this->text($shop['gst_number'])
            : $this->text(Shop::query()->whereKey($shopId)->value('gst_number'));

        return $resolved;
    }

    /**
     * XR-06. Per key, like the billing fields: a recorded NULL stays empty (the
     * bill printed nothing there), and only a key the snapshot never recorded
     * falls back to the live shop row.
     *
     * @param  array<string, mixed>|null  $section
     * @return array<string, string|null>
     */
    private function seller(int $shopId, ?array $section): array
    {
        $live = null;
        $seller = [];

        foreach (self::ASSERTED_SELLER as $key) {
            if ($section !== null && array_key_exists($key, $section)) {
                $seller[$key] = $this->text($section[$key]);

                continue;
            }

            $live ??= Shop::query()->whereKey($shopId)->first();
            $seller[$key] = $this->text($live?->{$key});
        }

        return $seller;
    }

    /**
     * XR-06. Per key as above. The fallback for a key the snapshot never
     * recorded is exactly what the template rendered before XR-06, so a legacy
     * bill prints as it always has: the live customer, and for ID/PAN the
     * compliance record first.
     *
     * @param  array<string, mixed>|null  $section
     * @return array<string, string|null>
     */
    private function recipient(Invoice $invoice, ?array $section): array
    {
        $recipient = [];

        foreach (self::ASSERTED_RECIPIENT as $key) {
            if ($section !== null && array_key_exists($key, $section)) {
                $recipient[$key] = $this->text($section[$key]);

                continue;
            }

            $customer = $invoice->customer;
            $recipient[$key] = $this->text(match ($key) {
                'id_number' => $invoice->complianceSnapshot?->snapshot_id_number ?: $customer?->id_number,
                'pan' => $invoice->complianceSnapshot?->snapshot_pan ?: $customer?->pan,
                default => $customer?->{$key},
            });
        }

        return $recipient;
    }

    /**
     * Snapshots are JSON, so a value can come back as a number where the column
     * held text. Normalised to string-or-null so the templates' !empty() guards
     * behave identically for the snapshot and live-settings sources. NULL is
     * preserved rather than coerced to '' — the two are the same to !empty(),
     * but only NULL says "this bill recorded nothing here".
     */
    private function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
