<?php

namespace App\Services\Historical;

/**
 * The auto/manual field state machine from HISTORICAL-BATCH-3-UX-CONTRACT-V2
 * §9, operating purely on the `calculation_state` jsonb array already carried
 * by `HistoricalSalesLine`/`HistoricalSalesDocument`.
 *
 * STATELESS, no DB access — every method here takes the current
 * `calculation_state` array in and returns a new one out. The caller (a
 * not-yet-written draft controller/service) is responsible for persisting the
 * result and for actually invoking `HistoricalCalculationSuggester` to obtain
 * a fresh suggestion when one is needed.
 *
 * FIELD SHAPE, one entry per tracked field name:
 *   [
 *     'mode'       => self::AUTO | self::MANUAL,
 *     'value'      => float|null,   // the value currently in effect
 *     'suggestion' => float|null,   // the last computed suggestion, kept even
 *                                   // in `manual` state so "Use automatic
 *                                   // value" has something to restore without
 *                                   // recomputing first
 *     'inputs'     => array,        // the reproducible scalar inputs that
 *                                   // produced `suggestion` — what a preview
 *                                   // renders for an `auto` field per §9
 *   ]
 *
 * TRACKED FIELDS (§9): metal_value, making_amount, wastage_amount, line_total,
 * bill totals, tax amounts, round_off, paid_total, outstanding,
 * advance_credit. This service does not enumerate them — it operates on
 * whatever field name string the caller passes, so the same machine serves
 * every tracked field uniformly without a hardcoded list going stale.
 *
 * SERVER-AUTHORITATIVE (§10): `applyClientSubmission()` is the ONLY entry
 * point that accepts a client-claimed mode, and it NEVER trusts it blindly —
 * it always compares the client's submitted value against a freshly computed
 * suggestion and only accepts `auto` when the two actually match. A client
 * claiming `auto` while submitting a value that disagrees with the server's
 * own suggestion is corrected to `manual`, because trusting an unverified
 * client-submitted mode is exactly how a forged "auto" label could smuggle a
 * silently-wrong number past the state machine.
 */
class HistoricalCalculationStateService
{
    public const AUTO   = 'auto';
    public const MANUAL = 'manual';

    /**
     * Initial state for a freshly (re)computed suggestion — always `auto`.
     * Used the first time a field is calculated, or whenever
     * `recalculate()` restores a field to automatic.
     */
    public function autoState(?float $suggestion, array $inputs = []): array
    {
        return [
            'mode'       => self::AUTO,
            'value'      => $suggestion,
            'suggestion' => $suggestion,
            'inputs'     => $inputs,
        ];
    }

    /**
     * A direct operator edit. Freezes the field at the given value; the
     * `suggestion`/`inputs` already on record are preserved (untouched) so
     * "Use automatic value" still has something to offer without forcing an
     * immediate recompute.
     */
    public function markManual(array $currentState, float $value): array
    {
        return [
            'mode'       => self::MANUAL,
            'value'      => $value,
            'suggestion' => $currentState['suggestion'] ?? null,
            'inputs'     => $currentState['inputs'] ?? [],
        ];
    }

    /**
     * A source-field change (e.g. the rate or weight this value derives from
     * moved). `auto` fields follow along to the fresh suggestion; `manual`
     * fields are left completely alone — this is the "manual survives source
     * changes" rule (§9/red-first-matrix). The fresh suggestion/inputs are
     * still recorded on a manual field (for later "Use automatic value"), but
     * its `value`/`mode` never move.
     */
    public function reactToSourceChange(array $currentState, ?float $freshSuggestion, array $freshInputs = []): array
    {
        if (($currentState['mode'] ?? self::AUTO) === self::MANUAL) {
            return [
                'mode'       => self::MANUAL,
                'value'      => $currentState['value'] ?? null,
                'suggestion' => $freshSuggestion,
                'inputs'     => $freshInputs,
            ];
        }

        return $this->autoState($freshSuggestion, $freshInputs);
    }

    /**
     * "Use automatic value" — explicit recalculation, always restores `auto`
     * regardless of the field's prior mode.
     */
    public function recalculate(?float $freshSuggestion, array $freshInputs = []): array
    {
        return $this->autoState($freshSuggestion, $freshInputs);
    }

    /**
     * The only entry point that accepts a client-claimed mode. Never trusts it
     * blindly (§10): a claimed `auto` is only honoured when the submitted
     * value actually equals the server's own fresh suggestion (within a cent,
     * matching the module's existing ±0.01 reconciliation tolerance
     * elsewhere) — otherwise the state is corrected to `manual` so a forged
     * "this is automatic" label can never smuggle a wrong number through as
     * if the server had derived it.
     */
    public function applyClientSubmission(
        string $claimedMode,
        float $submittedValue,
        ?float $freshSuggestion,
        array $freshInputs = [],
    ): array {
        if ($claimedMode === self::AUTO
            && $freshSuggestion !== null
            && abs($submittedValue - $freshSuggestion) <= 0.01
        ) {
            return $this->autoState($freshSuggestion, $freshInputs);
        }

        return [
            'mode'       => self::MANUAL,
            'value'      => $submittedValue,
            'suggestion' => $freshSuggestion,
            'inputs'     => $freshInputs,
        ];
    }

    public function isManual(array $state): bool
    {
        return ($state['mode'] ?? self::AUTO) === self::MANUAL;
    }

    public function currentValue(array $state): ?float
    {
        return $state['value'] ?? null;
    }
}
