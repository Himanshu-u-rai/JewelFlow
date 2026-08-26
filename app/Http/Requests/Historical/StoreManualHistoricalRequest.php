<?php

namespace App\Http\Requests\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Rules\IndianMobileRule;
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

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $money = ['nullable', 'numeric'];

        return [
            // which button was pressed. Absent/unknown means the safe one.
            'intent'                   => ['nullable', Rule::in([self::INTENT_DRAFT, self::INTENT_PUBLISH])],
            // warning acknowledgement for a direct publish. The checkbox alone is not
            // enough: the digest pins the acknowledgement to the exact warning set the
            // operator was shown, so an edit that changes the warnings invalidates it.
            'acknowledge_warnings'         => ['nullable', 'boolean'],
            'acknowledged_warning_digest'  => ['nullable', 'string', 'max:64'],
            // identity — number optional (cash bills exist), date + total required.
            'original_document_number' => ['nullable', 'string', 'max:120'],
            'document_series'          => ['nullable', 'string', 'max:60'],
            'document_date'            => ['required', 'date'],
            'source_reference'         => ['nullable', 'string', 'max:120'],
            'source_system'            => ['nullable', 'string', 'max:80'],

            // customer snapshot — all optional, never linked in Batch 2.
            'customer_name'            => ['nullable', 'string', 'max:180'],
            'customer_mobile'          => ['nullable', 'string', new IndianMobileRule()],
            'customer_gstin'           => ['nullable', 'string', 'max:20'],
            'customer_address'         => ['nullable', 'string', 'max:500'],
            'place_of_supply'          => ['nullable', 'string', 'max:120'],

            // amounts — taxable optional (legacy data is thin); grand_total required.
            'taxable_amount'           => $money,
            'tax_total'                => $money,
            'cgst'                     => $money,
            'sgst'                     => $money,
            'igst'                     => $money,
            'cess'                     => $money,
            'discount'                 => $money,
            'rounding'                 => ['nullable', 'numeric'],
            'metal_value'              => $money,
            'stone_value'              => $money,
            'grand_total'              => ['required', 'numeric'],
            'paid_amount'              => $money,
            'outstanding_amount'       => $money,

            // making / labour — value free text ("12%", "450/gm"); meaning confirmed.
            'making_label'             => ['nullable', 'string', 'max:120'],
            'making_value'             => ['nullable', 'string', 'max:60'],
            'making_category'          => ['nullable', Rule::in(HistoricalMakingCharge::CATEGORIES)],
            'making_basis'             => ['nullable', Rule::in(HistoricalMakingCharge::BASES)],

            // tax posture and the two explicit acknowledgements Phase 4 / 10 need.
            'tax_mode'                 => ['nullable', Rule::in([
                HistoricalSalesDocument::TAX_MODE_INCLUSIVE,
                HistoricalSalesDocument::TAX_MODE_EXCLUSIVE,
                HistoricalSalesDocument::TAX_MODE_UNKNOWN,
                HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            ])],
            'zero_tax_confirmed'       => ['nullable', 'boolean'],
            'cutover_date'             => ['nullable', 'date'],
            'cutover_acknowledged'     => ['nullable', 'boolean'],
            'cutover_reason'           => ['nullable', 'string', 'max:500'],

            // optional item lines — keyed by canonical LINE fields.
            'lines'                       => ['nullable', 'array'],
            'lines.*.line_item_name'      => ['nullable', 'string', 'max:180'],
            'lines.*.line_sku'            => ['nullable', 'string', 'max:120'],
            'lines.*.line_hsn'            => ['nullable', 'string', 'max:20'],
            'lines.*.line_quantity'       => ['nullable', 'numeric'],
            'lines.*.line_purity'         => ['nullable', 'string', 'max:40'],
            'lines.*.line_gross_weight'   => ['nullable', 'numeric'],
            'lines.*.line_net_weight'     => ['nullable', 'numeric'],
            'lines.*.line_stone_weight'   => ['nullable', 'numeric'],
            'lines.*.line_metal_value'    => ['nullable', 'numeric'],
            'lines.*.line_stone_value'    => ['nullable', 'numeric'],
            'lines.*.line_making_label'   => ['nullable', 'string', 'max:120'],
            'lines.*.line_making_value'   => ['nullable', 'string', 'max:60'],
            'lines.*.line_rate'           => ['nullable', 'numeric'],
            'lines.*.line_total'          => ['nullable', 'numeric'],
        ];
    }

    /**
     * The canonical header the normalizer expects: only the HEADER-catalog keys,
     * so nothing extra (acknowledgements, options) leaks into the record.
     *
     * @return array<string, mixed>
     */
    public function headerFields(): array
    {
        return array_intersect_key($this->validated(), HistoricalFields::HEADER);
    }

    /** Defaults to the non-destructive intent for any absent or unexpected value. */
    public function intent(): string
    {
        return $this->input('intent') === self::INTENT_PUBLISH
            ? self::INTENT_PUBLISH
            : self::INTENT_DRAFT;
    }

    public function wantsDirectPublish(): bool
    {
        return $this->intent() === self::INTENT_PUBLISH;
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

        foreach ((array) $this->input('lines', []) as $line) {
            $line = array_intersect_key((array) $line, HistoricalFields::LINE);

            // Drop a wholly blank line row — a form always POSTs its empty template.
            if (array_filter($line, static fn ($v) => $v !== null && trim((string) $v) !== '') !== []) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return [
            'source_system'        => $this->input('source_system'),
            'tax_mode'             => $this->input('tax_mode'),
            'making_category'      => $this->input('making_category'),
            'making_basis'         => $this->input('making_basis'),
            'zero_tax_confirmed'   => $this->boolean('zero_tax_confirmed'),
            'cutover_date'         => $this->input('cutover_date'),
            'cutover_acknowledged' => $this->boolean('cutover_acknowledged'),
            'cutover_reason'       => $this->input('cutover_reason'),
        ];
    }
}
