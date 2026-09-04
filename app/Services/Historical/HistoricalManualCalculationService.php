<?php

namespace App\Services\Historical;

use App\Models\Historical\HistoricalSalesLine;

/**
 * Wires raw manual-form values into the existing Historical formula contract.
 * Formulae stay in HistoricalCalculationSuggester; this class only applies the
 * shared auto/manual state machine and prepares values for normalization.
 */
class HistoricalManualCalculationService
{
    public function __construct(
        private readonly HistoricalCalculationSuggester $suggester = new HistoricalCalculationSuggester,
        private readonly HistoricalCalculationStateService $states = new HistoricalCalculationStateService,
    ) {}

    /** @return array{header: array, lines: array, line_attributes: array, document_attributes: array, errors: array} */
    public function prepare(array $header, array $lines): array
    {
        $prepared = [];
        $lineAttributes = [];
        $errors = [];
        $totals = [
            'taxable_amount' => 0.0,
            'tax_total' => 0.0,
            'discount' => 0.0,
            'metal_value' => 0.0,
            'stone_value' => 0.0,
            'making_amount' => 0.0,
            'grand_total' => 0.0,
        ];
        $hasCalculatedLine = false;

        foreach ($lines as $index => $line) {
            $line = (array) $line;
            $result = $this->prepareLine($line, $index + 1);
            $prepared[$index] = $result['line'];
            $lineAttributes[$index] = $result['attributes'];
            array_push($errors, ...$result['errors']);

            if (! $result['calculation_enabled']) {
                continue;
            }

            $hasCalculatedLine = true;
            foreach ($totals as $field => $value) {
                $totals[$field] += (float) ($result['totals'][$field] ?? 0);
            }
        }

        $documentState = [];
        if ($hasCalculatedLine) {
            foreach (['taxable_amount', 'tax_total', 'discount', 'metal_value', 'stone_value', 'grand_total'] as $field) {
                $suggestion = round($totals[$field], 2);
                $state = $this->resolveState(
                    $header,
                    $field,
                    $suggestion,
                    ['line_count' => count($prepared)],
                    true,
                );
                $documentState[$field] = $state;
                $header[$field] = $state['value'];
            }
        }

        return [
            'header' => $header,
            'lines' => $prepared,
            'line_attributes' => $lineAttributes,
            'document_attributes' => $hasCalculatedLine ? [
                'making_amount' => round($totals['making_amount'], 2),
                'calculation_state' => $documentState,
            ] : [],
            'errors' => $errors,
        ];
    }

    /** @return array{line: array, attributes: array, totals: array, errors: array, calculation_enabled: bool} */
    private function prepareLine(array $line, int $lineNumber): array
    {
        $enabled = self::bool($line['line_calculation_enabled'] ?? false);
        $metal = self::text($line['line_metal_type'] ?? null);
        $basis = self::text($line['line_billable_weight_basis'] ?? null);
        $gross = self::number($line['line_gross_weight'] ?? null);
        $net = self::number($line['line_net_weight'] ?? null);
        $manualWeight = self::number($line['line_billable_weight'] ?? $line['line_billable_weight_manual'] ?? null);

        if (! $enabled && $metal === null && $basis === null) {
            return [
                'line' => $line,
                'attributes' => [],
                'totals' => [],
                'errors' => [],
                'calculation_enabled' => false,
            ];
        }

        $weightSuggestion = match ($basis) {
            HistoricalSalesLine::BILLABLE_WEIGHT_GROSS,
            HistoricalSalesLine::BILLABLE_WEIGHT_NET => $this->suggester->suggestBillableWeight((string) $basis, $gross, $net),
            default => null,
        };
        $weightState = $basis === HistoricalSalesLine::BILLABLE_WEIGHT_MANUAL
            ? [
                'mode' => HistoricalCalculationStateService::MANUAL,
                'value' => $manualWeight,
                'suggestion' => null,
                'inputs' => ['basis' => $basis],
            ]
            : $this->resolveState($line, 'line_billable_weight', $weightSuggestion, [
                'basis' => $basis,
                'gross_weight' => $gross,
                'net_weight' => $net,
            ]);
        $billableWeight = $this->states->currentValue($weightState);

        $purity = self::number($line['line_purity_value'] ?? null);
        $rate = self::number($line['line_rate'] ?? null);
        $metalSuggestion = $this->suggester->suggestMetalValue($metal, $purity, $billableWeight, $rate);
        $metalState = $this->resolveState($line, 'line_metal_value', $metalSuggestion, [
            'metal' => $metal,
            'purity' => $purity,
            'billable_weight' => $billableWeight,
            'rate_per_gram' => $rate,
        ]);

        $errors = [];
        if ($basis !== null && $billableWeight === null) {
            $errors[] = [
                'code' => 'billable_weight_undetermined',
                'text' => sprintf('Line %d: choose gross, net, or a manual weight before a metal value can be suggested.', $lineNumber),
            ];
        } elseif (($metal !== null || $basis !== null) && $metalSuggestion === null) {
            $errors[] = [
                'code' => 'metal_value_undetermined',
                'text' => sprintf('Line %d: metal value could not be determined — check purity and rate.', $lineNumber),
            ];
        }

        $calculationState = [
            'billable_weight' => $weightState,
            'metal_value' => $metalState,
        ];

        if (! $enabled) {
            return [
                'line' => $line,
                'attributes' => [
                    'line_metal_type' => $metal,
                    'metal_snapshot' => $metal,
                    'billable_weight_basis' => $basis,
                    'billable_weight' => $billableWeight,
                    'calculation_state' => ['metal_value' => $metalState],
                ],
                'totals' => [],
                'errors' => $errors,
                'calculation_enabled' => false,
            ];
        }

        $stoneSuggestion = $this->suggester->suggestStoneValue(
            self::number($line['line_stone_weight'] ?? null),
            self::number($line['line_stone_rate'] ?? null),
            null,
        );
        $stoneState = $this->resolveState($line, 'line_stone_value', $stoneSuggestion, [
            'stone_weight' => self::number($line['line_stone_weight'] ?? null),
            'stone_rate' => self::number($line['line_stone_rate'] ?? null),
        ]);

        $makingBasis = self::text($line['line_making_basis'] ?? null);
        $makingSuggestion = $this->suggester->suggestMakingAmount(
            $makingBasis ?? '',
            self::number($line['line_making_value'] ?? null),
            $this->states->currentValue($metalState),
            $net,
            self::number($line['line_quantity'] ?? null),
        );
        $makingState = $this->resolveState($line, 'line_making_amount', $makingSuggestion, [
            'basis' => $makingBasis,
            'value' => self::number($line['line_making_value'] ?? null),
        ]);

        $wastageBasis = self::text($line['line_wastage_basis'] ?? null);
        $wastageSuggestion = $this->suggester->suggestWastageAmount(
            $wastageBasis,
            self::number($line['line_wastage_value'] ?? null),
            $this->states->currentValue($metalState),
        );
        $wastageState = $this->resolveState($line, 'line_wastage_amount', $wastageSuggestion, [
            'basis' => $wastageBasis,
            'value' => self::number($line['line_wastage_value'] ?? null),
        ]);

        $charges = $this->suggester->suggestLineCharges(
            self::number($line['line_hallmark_charge'] ?? null),
            self::number($line['line_rhodium_charge'] ?? null),
            self::number($line['line_other_charge'] ?? null),
        );
        $subtotal = $this->suggester->suggestLineSubtotal(
            $this->states->currentValue($metalState),
            $this->states->currentValue($makingState),
            $this->states->currentValue($wastageState),
            $this->states->currentValue($stoneState),
            $charges,
        );
        $discountSuggestion = $this->suggester->discountAmount(
            $subtotal,
            self::text($line['line_discount_type'] ?? null),
            self::number($line['line_discount_value'] ?? null),
        );
        $discountState = $this->resolveState($line, 'line_discount_amount', $discountSuggestion, ['subtotal' => $subtotal]);
        $afterDiscount = round(max(0, $subtotal - (float) $this->states->currentValue($discountState)), 2);

        $split = $this->suggester->taxableValueSplit(
            $afterDiscount,
            self::text($line['line_tax_mode'] ?? null) ?? 'no_gst',
            self::number($line['line_gst_rate'] ?? null) ?? 0.0,
        );
        $taxableState = $this->resolveState($line, 'line_taxable', $split['taxable'], ['after_discount' => $afterDiscount]);
        $totalState = $this->resolveState($line, 'line_total', $split['total'], [
            'tax_mode' => self::text($line['line_tax_mode'] ?? null) ?? 'no_gst',
            'gst_rate' => self::number($line['line_gst_rate'] ?? null) ?? 0.0,
        ], true);

        $calculationState += [
            'stone_value' => $stoneState,
            'making_amount' => $makingState,
            'wastage_amount' => $wastageState,
            'line_discount_amount' => $discountState,
            'line_taxable' => $taxableState,
            'line_total' => $totalState,
        ];

        $line['line_billable_weight'] = $billableWeight;
        $line['line_metal_value'] = $this->states->currentValue($metalState);
        $line['line_stone_value'] = $this->states->currentValue($stoneState);
        $line['line_making_amount'] = $this->states->currentValue($makingState);
        $line['line_wastage_amount'] = $this->states->currentValue($wastageState);
        $line['line_discount_amount'] = $this->states->currentValue($discountState);
        $line['line_taxable'] = $this->states->currentValue($taxableState);
        $line['line_total'] = $this->states->currentValue($totalState);

        return [
            'line' => $line,
            'attributes' => [
                'line_metal_type' => $metal,
                'metal_snapshot' => $metal,
                'purity_snapshot' => self::text($line['line_purity'] ?? null) ?? ($purity === null ? null : (string) $purity),
                'billable_weight_basis' => $basis,
                'billable_weight' => $billableWeight,
                'making_basis' => $makingBasis,
                'making_amount' => $this->states->currentValue($makingState),
                'hallmark_charge' => self::number($line['line_hallmark_charge'] ?? null),
                'rhodium_charge' => self::number($line['line_rhodium_charge'] ?? null),
                'other_charge' => self::number($line['line_other_charge'] ?? null),
                'wastage_basis' => $wastageBasis,
                'wastage_value' => self::number($line['line_wastage_value'] ?? null),
                'line_discount_type' => self::text($line['line_discount_type'] ?? null),
                'line_discount_value' => self::number($line['line_discount_value'] ?? null),
                'line_total' => $this->states->currentValue($totalState),
                'calculation_state' => $calculationState,
            ],
            'totals' => [
                'taxable_amount' => $this->states->currentValue($taxableState),
                'tax_total' => round($split['total'] - $split['taxable'], 2),
                'discount' => $this->states->currentValue($discountState),
                'metal_value' => $this->states->currentValue($metalState),
                'stone_value' => $this->states->currentValue($stoneState),
                'making_amount' => $this->states->currentValue($makingState),
                'grand_total' => $this->states->currentValue($totalState),
            ],
            'errors' => $errors,
            'calculation_enabled' => true,
        ];
    }

    private function resolveState(array $source, string $field, ?float $suggestion, array $inputs = [], bool $strictAuto = false): array
    {
        $submitted = self::number($source[$field] ?? null);
        $mode = self::text($source[$field.'_mode'] ?? null);
        $recalculate = self::bool($source[$field.'_recalculate'] ?? false);

        if ($recalculate || ($strictAuto && $mode === HistoricalCalculationStateService::AUTO)) {
            return $this->states->recalculate($suggestion, $inputs);
        }

        if ($submitted !== null) {
            return $this->states->applyClientSubmission(
                $mode ?? HistoricalCalculationStateService::MANUAL,
                $submitted,
                $suggestion,
                $inputs,
            );
        }

        return $this->states->autoState($suggestion, $inputs);
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function bool(mixed $value): bool
    {
        return in_array($value, ['1', 1, true, 'true', 'on'], true);
    }
}
