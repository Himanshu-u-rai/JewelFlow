<?php

namespace App\Http\Requests\Historical;

use App\Models\Customer;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Models\Historical\HistoricalSalesPayment;
use App\Rules\IndianMobileRule;
use App\Services\Historical\HistoricalCalculationStateService;
use App\Support\Historical\HistoricalFields;
use App\Support\Historical\HistoricalMakingCharge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A typed historical bill. Structural only: the normalizer owns date parsing,
 * money parsing, tax-conflict detection and reconciliation — this request just
 * proves the shape is sane so those services are never handed garbage.
 *
 * The field names ARE the canonical HistoricalFields::HEADER / LINE keys, so the
 * controller can hand the validated payload straight to the same normalizer a
 * file import uses. One pipeline, not two.
 */
class StoreManualHistoricalRequest extends FormRequest
{
    /** Save the bill as a historical draft and stop. */
    public const INTENT_DRAFT = 'draft';

    /** Save and immediately publish it, without ever showing the batch page. */
    public const INTENT_PUBLISH = 'publish';

    /** Batch 3 fast-entry: save as draft, then open a fresh form for the next bill. */
    public const INTENT_DRAFT_AND_NEW = 'draft_and_new';

    /** Batch 3 fast-entry: publish, then open a fresh form for the next bill. */
    public const INTENT_PUBLISH_AND_NEW = 'publish_and_new';

    /**
     * A validation failure here is reached from the real browser flow with the
     * preview URL (a POST-only route) as the referer, so FormRequest's default
     * redirectTo() — UrlGenerator::previous(), which reads that referer — would
     * otherwise land on previewExpired(), a plain GET redirect with no
     * withInput()/withErrors(), discarding the operator's typed bill. Naming
     * the create route directly makes getRedirectUrl() use it before ever
     * falling back to previous(); Handler::invalid() still applies withInput()
     * and withErrors() identically regardless of which target this resolves
     * to, so normal error/old-input flashing is unaffected.
     */
    protected $redirectRoute = 'historical.manual.create';

    /**
     * Batch 3 §4/§9/§10 calculation fields — deliberately NOT part of the
     * shared HistoricalFields::LINE catalog (that catalog also drives the
     * bulk-import mapping screen; adding these there would let a spreadsheet
     * column accidentally map onto them). Manual entry alone needs them, so
     * they are merged in locally by lines() instead.
     *
     * `line_metal_value` itself is NOT listed here — it is already a
     * HistoricalFields::LINE catalog field (the legacy making-charge
     * base-amount input) and survives request filtering on its own; Batch 3
     * reuses that same field name for the operator's submitted/overridden
     * metal value, so no second entry is needed.
     */
    private const MANUAL_CALCULATION_LINE_FIELDS = [
        'line_calculation_enabled',
        'line_metal_type',
        'line_purity_value',
        'line_billable_weight_basis',
        'line_billable_weight',
        'line_billable_weight_manual',
        'line_billable_weight_mode',
        'line_billable_weight_recalculate',
        'line_metal_value_mode',
        'line_metal_value_recalculate',
        'line_stone_rate',
        'line_stone_value_mode',
        'line_stone_value_recalculate',
        'line_making_basis',
        'line_making_amount',
        'line_making_amount_mode',
        'line_making_amount_recalculate',
        'line_wastage_basis',
        'line_wastage_value',
        'line_wastage_amount',
        'line_wastage_amount_mode',
        'line_wastage_amount_recalculate',
        'line_hallmark_charge',
        'line_rhodium_charge',
        'line_other_charge',
        'line_discount_type',
        'line_discount_value',
        'line_discount_amount',
        'line_discount_amount_mode',
        'line_discount_amount_recalculate',
        'line_tax_mode',
        'line_gst_rate',
        'line_taxable',
        'line_taxable_mode',
        'line_taxable_recalculate',
        'line_total_mode',
        'line_total_recalculate',
        'line_notes',
    ];

    /**
     * Batch 3 §B/§E — same reasoning as MANUAL_CALCULATION_LINE_FIELDS above,
     * applied to the header instead of a line: `customer_pan` deliberately
     * is NOT in the shared HistoricalFields::HEADER catalog, so a bulk-import
     * spreadsheet column can never map onto it. Manual entry alone needs it.
     */
    private const MANUAL_HEADER_FIELDS = [
        'customer_pan',
        'customer_type',
        'taxable_amount_mode',
        'taxable_amount_recalculate',
        'tax_total_mode',
        'tax_total_recalculate',
        'discount_mode',
        'discount_recalculate',
        'metal_value_mode',
        'metal_value_recalculate',
        'stone_value_mode',
        'stone_value_recalculate',
        'grand_total_mode',
        'grand_total_recalculate',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $money = ['nullable', 'numeric'];

        return [
            // which button was pressed. Absent/unknown means the safe one.
            'intent' => ['nullable', Rule::in([
                self::INTENT_DRAFT,
                self::INTENT_PUBLISH,
                self::INTENT_DRAFT_AND_NEW,
                self::INTENT_PUBLISH_AND_NEW,
            ])],
            // warning acknowledgement for a direct publish. The checkbox alone is not
            // enough: the digest pins the acknowledgement to the exact warning set the
            // operator was shown, so an edit that changes the warnings invalidates it.
            'acknowledge_warnings' => ['nullable', 'boolean'],
            'acknowledged_warning_digest' => ['nullable', 'string', 'max:64'],
            // identity — number optional (cash bills exist), date + total required.
            'original_document_number' => ['nullable', 'string', 'max:120'],
            'document_series' => ['nullable', 'string', 'max:60'],
            'document_date' => ['required', 'date'],
            'source_reference' => ['nullable', 'string', 'max:120'],
            'source_system' => ['nullable', 'string', 'max:80'],

            // customer snapshot — all optional, never linked just by being typed.
            'customer_name' => ['nullable', 'string', 'max:180'],
            'customer_mobile' => ['nullable', 'string', new IndianMobileRule],
            'customer_gstin' => ['nullable', 'string', 'max:20'],
            // Snapshot-only, like customer_gstin above — historical data is thin,
            // so no PanFormatRule here. A malformed PAN just never gets copied to
            // a newly created live customer (HistoricalImportService::validPan()).
            'customer_pan' => ['nullable', 'string', 'max:20'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'place_of_supply' => ['nullable', 'string', 'max:120'],
            'customer_type' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'customer_id' => [
                'nullable',
                'integer',
                Customer::activeExistsRule((int) $this->user()?->shop_id),
            ],

            // Batch 3 §B — explicit, operator-submitted choice for THIS publish
            // only. Never defaulted server-side; the UI may default the checkbox
            // to checked later, but the server persists whatever was submitted.
            'add_customer_on_publish' => ['nullable', 'boolean'],

            // amounts — taxable optional (legacy data is thin); grand_total required.
            'taxable_amount' => $money,
            'tax_total' => $money,
            'cgst' => $money,
            'sgst' => $money,
            'igst' => $money,
            'cess' => $money,
            'discount' => $money,
            'rounding' => ['nullable', 'numeric'],
            'metal_value' => $money,
            'stone_value' => $money,
            'grand_total' => ['required', 'numeric'],
            'paid_amount' => $money,
            'outstanding_amount' => $money,
            'taxable_amount_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'taxable_amount_recalculate' => ['nullable', 'boolean'],
            'tax_total_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'tax_total_recalculate' => ['nullable', 'boolean'],
            'discount_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'discount_recalculate' => ['nullable', 'boolean'],
            'metal_value_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'metal_value_recalculate' => ['nullable', 'boolean'],
            'stone_value_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'stone_value_recalculate' => ['nullable', 'boolean'],
            'grand_total_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'grand_total_recalculate' => ['nullable', 'boolean'],
            // Batch 3 §7 — which figure wins when payment rows and a typed
            // aggregate disagree. Absent/unknown means "trust the rows" (the
            // normalizer's existing header-driven paid_amount stays untouched
            // unless this is explicitly 'manual').
            'paid_amount_mode' => ['nullable', Rule::in(['auto', 'manual'])],

            // making / labour — value free text ("12%", "450/gm"); meaning confirmed.
            'making_label' => ['nullable', 'string', 'max:120'],
            'making_value' => ['nullable', 'string', 'max:60'],
            'making_category' => ['nullable', Rule::in(HistoricalMakingCharge::CATEGORIES)],
            'making_basis' => ['nullable', Rule::in(HistoricalMakingCharge::BASES)],

            // tax posture and the two explicit acknowledgements Phase 4 / 10 need.
            'tax_mode' => ['nullable', Rule::in([
                HistoricalSalesDocument::TAX_MODE_INCLUSIVE,
                HistoricalSalesDocument::TAX_MODE_EXCLUSIVE,
                HistoricalSalesDocument::TAX_MODE_UNKNOWN,
                HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            ])],
            'zero_tax_confirmed' => ['nullable', 'boolean'],
            'cutover_date' => ['nullable', 'date'],
            'cutover_acknowledged' => ['nullable', 'boolean'],
            'cutover_reason' => ['nullable', 'string', 'max:500'],

            // optional item lines — keyed by canonical LINE fields.
            'lines' => ['nullable', 'array'],
            'lines.*.line_item_name' => ['nullable', 'string', 'max:180'],
            'lines.*.line_sku' => ['nullable', 'string', 'max:120'],
            'lines.*.line_hsn' => ['nullable', 'string', 'max:20'],
            'lines.*.line_quantity' => ['nullable', 'numeric'],
            'lines.*.line_purity' => ['nullable', 'string', 'max:40'],
            'lines.*.line_gross_weight' => ['nullable', 'numeric'],
            'lines.*.line_net_weight' => ['nullable', 'numeric'],
            'lines.*.line_stone_weight' => ['nullable', 'numeric'],
            'lines.*.line_metal_value' => ['nullable', 'numeric'],
            'lines.*.line_stone_value' => ['nullable', 'numeric'],
            'lines.*.line_making_label' => ['nullable', 'string', 'max:120'],
            'lines.*.line_making_value' => ['nullable', 'string', 'max:60'],
            'lines.*.line_rate' => ['nullable', 'numeric'],
            'lines.*.line_total' => ['nullable', 'numeric'],

            // Batch 3 §4/§9/§10 — manual-entry-only calculation fields (see
            // MANUAL_CALCULATION_LINE_FIELDS docblock above).
            'lines.*.line_metal_type' => ['nullable', 'string', 'max:40'],
            'lines.*.line_calculation_enabled' => ['nullable', 'boolean'],
            'lines.*.line_purity_value' => ['nullable', 'numeric'],
            'lines.*.line_billable_weight_basis' => ['nullable', Rule::in(HistoricalSalesLine::BILLABLE_WEIGHT_BASES)],
            'lines.*.line_billable_weight' => ['nullable', 'numeric'],
            'lines.*.line_billable_weight_manual' => ['nullable', 'numeric'],
            'lines.*.line_billable_weight_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'lines.*.line_billable_weight_recalculate' => ['nullable', 'boolean'],
            'lines.*.line_metal_value_mode' => ['nullable', Rule::in([
                HistoricalCalculationStateService::AUTO,
                HistoricalCalculationStateService::MANUAL,
            ])],
            'lines.*.line_metal_value_recalculate' => ['nullable', 'boolean'],
            'lines.*.line_stone_rate' => ['nullable', 'numeric'],
            'lines.*.line_stone_value_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'lines.*.line_stone_value_recalculate' => ['nullable', 'boolean'],
            'lines.*.line_making_basis' => ['nullable', Rule::in(HistoricalMakingCharge::BASES)],
            'lines.*.line_making_amount' => ['nullable', 'numeric'],
            'lines.*.line_making_amount_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'lines.*.line_making_amount_recalculate' => ['nullable', 'boolean'],
            'lines.*.line_wastage_basis' => ['nullable', Rule::in(HistoricalSalesLine::WASTAGE_BASES)],
            'lines.*.line_wastage_value' => ['nullable', 'numeric'],
            'lines.*.line_wastage_amount' => ['nullable', 'numeric'],
            'lines.*.line_wastage_amount_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'lines.*.line_wastage_amount_recalculate' => ['nullable', 'boolean'],
            'lines.*.line_hallmark_charge' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_rhodium_charge' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_other_charge' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_discount_type' => ['nullable', Rule::in(HistoricalSalesLine::DISCOUNT_TYPES)],
            'lines.*.line_discount_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_discount_amount_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'lines.*.line_discount_amount_recalculate' => ['nullable', 'boolean'],
            'lines.*.line_tax_mode' => ['nullable', Rule::in(['no_gst', 'gst_inclusive', 'gst_exclusive'])],
            'lines.*.line_gst_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_taxable' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_taxable_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'lines.*.line_taxable_recalculate' => ['nullable', 'boolean'],
            'lines.*.line_total_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'lines.*.line_total_recalculate' => ['nullable', 'boolean'],
            'lines.*.line_notes' => ['nullable', 'string', 'max:1000'],

            // Batch 3 §7/§8 — payment rows. shop_payment_method_id is checked
            // against THIS shop only, so a cross-tenant reference is a clean
            // 422/302 validation error, never a 500 from the model-layer guard.
            'payments' => ['nullable', 'array'],
            'payments.*.mode' => ['required', Rule::in(HistoricalSalesPayment::VALID_MODES)],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.reference' => ['nullable', 'string', 'max:120'],
            'payments.*.payment_date' => ['nullable', 'date'],
            'payments.*.note' => ['nullable', 'string', 'max:1000'],
            // Only meaningful for a custom/free-text account (no shop_payment_method_id);
            // see HistoricalSalesPayment::normalizeAccountLabelSnapshot() for why a linked
            // row's label is never sourced from here.
            'payments.*.account_label_snapshot' => ['nullable', 'string', 'max:255'],
            'payments.*.shop_payment_method_id' => [
                'nullable',
                'integer',
                Rule::exists('shop_payment_methods', 'id')
                    ->where(fn ($query) => $query->where('shop_id', $this->user()?->shop?->id)),
            ],
        ];
    }

    /**
     * Drops a wholly blank payment row before validation ever sees it.
     *
     * Unlike `lines()` (whose fields are all nullable, so a blank row simply
     * passes validation trivially and is filtered out later), `mode` and
     * `amount` above are `required` — a form always POSTs its empty payment
     * template, and without this the untouched default row would fail
     * validation on every submit that has no real payment breakdown, which
     * `StoreManualHistoricalRequest::payments()`'s own docblock says is "the
     * normal case".
     */
    protected function prepareForValidation(): void
    {
        $payments = [];

        foreach ((array) $this->input('payments', []) as $payment) {
            $payment = (array) $payment;
            $meaningful = array_filter(
                $payment,
                static fn ($value): bool => $value !== null && trim((string) $value) !== '',
            );

            if ($meaningful !== []) {
                $payments[] = $payment;
            }
        }

        $this->merge(['payments' => $payments]);
    }

    /**
     * The canonical header the normalizer expects: the HEADER-catalog keys
     * plus MANUAL_HEADER_FIELDS (manual-entry-only, see docblock above), so
     * nothing extra (acknowledgements, options) leaks into the record.
     *
     * @return array<string, mixed>
     */
    public function headerFields(): array
    {
        $allowed = HistoricalFields::HEADER + array_fill_keys(self::MANUAL_HEADER_FIELDS, true);

        return array_intersect_key($this->validated(), $allowed);
    }

    /** Defaults to the non-destructive intent for any absent or unexpected value. */
    public function intent(): string
    {
        return match ($this->input('intent')) {
            self::INTENT_PUBLISH, self::INTENT_PUBLISH_AND_NEW, self::INTENT_DRAFT_AND_NEW => $this->input('intent'),
            default => self::INTENT_DRAFT,
        };
    }

    public function wantsDirectPublish(): bool
    {
        return in_array($this->intent(), [self::INTENT_PUBLISH, self::INTENT_PUBLISH_AND_NEW], true);
    }

    /**
     * Batch 3 fast-entry: "Save Draft & New" / "Publish & New". A true result
     * means store()/storeAndPublish() must redirect to a fresh create() form
     * (carrying forward carryForwardFields()) instead of to the saved
     * document — but only on success; a validation or publish failure always
     * returns to backToForm() regardless of intent, so the operator's typed
     * bill is never silently discarded.
     */
    public function wantsFreshFormAfterSuccess(): bool
    {
        return in_array($this->intent(), [self::INTENT_DRAFT_AND_NEW, self::INTENT_PUBLISH_AND_NEW], true);
    }

    /**
     * The only state a high-volume operator re-types identically bill after
     * bill. Everything else (customer, lines, payments, totals, notes,
     * cutover) is bill-specific and must start blank on the next one.
     *
     * @return array<string, mixed>
     */
    public function carryForwardFields(): array
    {
        return [
            'document_date' => $this->input('document_date'),
            'document_series' => $this->input('document_series'),
            'source_system' => $this->input('source_system'),
            'tax_mode' => $this->input('tax_mode'),
        ];
    }

    /**
     * The digest is only meaningful when the operator actually ticked the box —
     * a stray hidden field must not acknowledge anything on its own.
     */
    public function acknowledgedWarningDigest(): ?string
    {
        if (! $this->boolean('acknowledge_warnings')) {
            return null;
        }

        $digest = trim((string) $this->input('acknowledged_warning_digest', ''));

        return $digest === '' ? null : $digest;
    }

    /** @return array<int, array<string, mixed>> */
    public function lines(): array
    {
        $lines = [];

        $allowed = HistoricalFields::LINE + array_fill_keys(self::MANUAL_CALCULATION_LINE_FIELDS, true);

        foreach ((array) $this->input('lines', []) as $line) {
            $line = array_intersect_key((array) $line, $allowed);
            $meaningful = array_filter(
                $line,
                static fn ($value, $key): bool => $key !== 'line_calculation_enabled'
                    && $key !== 'line_tax_mode'
                    && ! str_ends_with($key, '_mode')
                    && ! str_ends_with($key, '_recalculate')
                    && $value !== null
                    && trim((string) $value) !== '',
                ARRAY_FILTER_USE_BOTH,
            );

            // Drop a wholly blank line row — a form always POSTs its empty template.
            if ($meaningful !== []) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return [
            'source_system' => $this->input('source_system'),
            'tax_mode' => $this->input('tax_mode'),
            'making_category' => $this->input('making_category'),
            'making_basis' => $this->input('making_basis'),
            'zero_tax_confirmed' => $this->boolean('zero_tax_confirmed'),
            'cutover_date' => $this->input('cutover_date'),
            'cutover_acknowledged' => $this->boolean('cutover_acknowledged'),
            'cutover_reason' => $this->input('cutover_reason'),
            'paid_amount_mode' => $this->input('paid_amount_mode'),
            // Batch 3 §B — see the `add_customer_on_publish` rule above.
            'add_customer_on_publish' => $this->boolean('add_customer_on_publish'),
            'customer_id' => $this->filled('customer_id') ? (int) $this->input('customer_id') : null,
        ];
    }

    /**
     * Validated payment rows only — never raw input. Absent/empty when the
     * bill has no typed payment breakdown, which is the normal case for a
     * bill entered before Batch 3 shipped.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payments(): array
    {
        return (array) $this->validated('payments', []);
    }
}
