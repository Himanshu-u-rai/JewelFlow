<?php

namespace App\Http\Requests\Historical;

use App\Models\Historical\HistoricalImportProfile;
use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalDateParser;
use App\Support\Historical\HistoricalMakingCharge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The confirmed mapping for one source file. Everything the operator decided on
 * the mapping screen, persisted onto the profile so next year's file from the
 * same accounting package is a one-click reuse.
 *
 * The negative rule (every unmapped column must be a decision, not an absence)
 * is enforced by HistoricalColumnMapper::validate() during normalization — this
 * request only checks the confirmed values are well-formed.
 */
class SaveHistoricalMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:120'],
            'source_system'      => ['nullable', 'string', 'max:80'],
            'layout_type'        => ['required', Rule::in(HistoricalImportProfile::LAYOUTS)],
            'header_row'         => ['required', 'integer', 'min:1', 'max:5000'],

            'date_format'        => ['required', Rule::in(array_keys(HistoricalDateParser::FORMATS))],
            'decimal_separator'  => ['required', Rule::in(HistoricalImportProfile::SEPARATORS)],
            'thousands_separator' => ['nullable', Rule::in(HistoricalImportProfile::SEPARATORS), 'different:decimal_separator'],

            'tax_mode'           => ['required', Rule::in([
                HistoricalSalesDocument::TAX_MODE_INCLUSIVE,
                HistoricalSalesDocument::TAX_MODE_EXCLUSIVE,
                HistoricalSalesDocument::TAX_MODE_UNKNOWN,
                HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            ])],

            // canonical field => source column header.
            'mapping'            => ['required', 'array'],
            'mapping.*'          => ['nullable', 'string', 'max:255'],

            // source column header => ignored|informational.
            'column_decisions'   => ['nullable', 'array'],
            'column_decisions.*' => [Rule::in([
                HistoricalImportProfile::DECISION_IGNORED,
                HistoricalImportProfile::DECISION_INFORMATIONAL,
            ])],

            // role => sheet name. Layout C needs header + detail; A/B need data.
            'sheets'             => ['nullable', 'array'],
            'sheets.*'           => ['nullable', 'string', 'max:255'],

            'making_defaults'          => ['nullable', 'array'],
            'making_defaults.category' => ['nullable', Rule::in(HistoricalMakingCharge::CATEGORIES)],
            'making_defaults.basis'    => ['nullable', Rule::in(HistoricalMakingCharge::BASES)],

            'tax_defaults'                => ['nullable', 'array'],
            'tax_defaults.zero_confirmed' => ['nullable', 'boolean'],

            'is_active'          => ['nullable', 'boolean'],
        ];
    }

    /** Profile attributes, mass-assignment safe (model $fillable is explicit). */
    public function profileAttributes(): array
    {
        return [
            'name'                => $this->input('name'),
            'source_system'       => $this->input('source_system'),
            'layout_type'         => $this->input('layout_type'),
            'header_row'          => (int) $this->input('header_row', 1),
            'date_format'         => $this->input('date_format'),
            'decimal_separator'   => $this->input('decimal_separator', '.'),
            'thousands_separator' => $this->input('thousands_separator'),
            'tax_mode'            => $this->input('tax_mode'),
            'mapping'             => array_filter((array) $this->input('mapping', []), static fn ($v) => $v !== null && $v !== ''),
            'column_decisions'    => (array) $this->input('column_decisions', []),
            'sheets'              => array_filter((array) $this->input('sheets', []), static fn ($v) => $v !== null && $v !== ''),
            'making_defaults'     => (array) $this->input('making_defaults', []),
            'tax_defaults'        => (array) $this->input('tax_defaults', []),
            'is_active'           => $this->boolean('is_active', true),
        ];
    }
}
