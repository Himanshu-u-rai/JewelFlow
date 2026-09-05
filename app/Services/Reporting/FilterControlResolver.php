<?php

namespace App\Services\Reporting;

use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Reporting\Definition\FilterKey;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Reports\HistoricalSalesRegisterDataset;
use Illuminate\Http\Request;

/**
 * Builds the renderable (non-date) filter controls a report declared — shared
 * by the screen (`ReportScreenController`) and the export panel
 * (`ExportController::panel()`), so a report's filters render, and behave,
 * identically in both places (Batch 4 requirements §A: "consistent
 * screen/export filtering"). Extracted unchanged from `ReportScreenController`
 * — pure move, no behavior change for the screen.
 *
 * Date (Period/AsOf) and reserved hooks are excluded — date has its own
 * preset control. Options are read-only lookups.
 */
class FilterControlResolver
{
    /**
     * @return array<int, array{key:string,label:string,type:string,options:array<int,array{value:string,label:string}>,current:string}>
     */
    public function forReport(ReportDefinition $definition, int $shopId, Request $request): array
    {
        $out = [];
        foreach ($definition->filters as $filter) {
            $key = $filter->key;
            if (! $filter->isRendered() || $key->supportsFyPresets()) {
                continue; // skip date-style + reserved-hook filters
            }

            // Reference is free-text search (original invoice/document number),
            // not an enumerable option list — render a text box instead of a <select>.
            if ($key === FilterKey::Reference) {
                $out[] = [
                    'key' => $key->value,
                    'label' => 'Reference',
                    'type' => 'text',
                    'options' => [],
                    'current' => (string) $request->input($key->value, ''),
                ];

                continue;
            }

            // Customer is free-text name/mobile search on the Historical
            // Register (matches BOTH linked and snapshot-only customers, per
            // §3 of the Batch 4 requirements). Scoped to this one report
            // because Sales Register's Customer filter still expects an exact
            // customer_id (pre-existing, unrelated gap) — a text box there
            // would silently never match.
            if ($key === FilterKey::Customer && $definition->key === HistoricalSalesRegisterDataset::KEY) {
                $out[] = [
                    'key' => $key->value,
                    'label' => 'Customer',
                    'type' => 'text',
                    'options' => [],
                    'current' => (string) $request->input($key->value, ''),
                ];

                continue;
            }

            $options = $this->filterOptions($key, $shopId, $definition);
            if ($options === null) {
                continue; // no option provider for this key yet — don't render a broken control
            }

            $out[] = [
                'key' => $key->value,
                'label' => \Illuminate\Support\Str::headline(str_replace(['cash_', '_'], ['', ' '], $key->value)),
                'type' => 'select',
                'options' => $options,
                'current' => (string) $request->input($key->value, ''),
            ];
        }

        return $out;
    }

    /**
     * Option list for a renderable filter key, or null if unsupported.
     *
     * @return array<int, array{value:string,label:string}>|null
     */
    private function filterOptions(FilterKey $key, int $shopId, ReportDefinition $definition): ?array
    {
        // Status is meaningful per-report (document status here vs invoice/payment
        // status elsewhere) — the Historical Register is the only report so far
        // that needs it surfaced as a discoverable dropdown (§3 audit trail: void/
        // draft/superseded are otherwise only reachable via a hand-typed query param).
        if ($key === FilterKey::Status && $definition->key === HistoricalSalesRegisterDataset::KEY) {
            return $this->staticOptions([
                HistoricalSalesDocument::STATUS_PUBLISHED => 'Published',
                HistoricalSalesDocument::STATUS_DRAFT => 'Draft',
                HistoricalSalesDocument::STATUS_VOID => 'Void',
                HistoricalSalesDocument::STATUS_SUPERSEDED => 'Superseded',
            ]);
        }

        return match ($key) {
            FilterKey::PaymentMode => $this->staticOptions([
                'cash' => 'Cash', 'upi' => 'UPI', 'bank' => 'Bank', 'card' => 'Card', 'wallet' => 'Wallet', 'other' => 'Other',
            ]),
            FilterKey::CashType => $this->staticOptions([
                'in' => 'Money in', 'out' => 'Money out',
            ]),
            FilterKey::CashSource => $this->distinctCashSources($shopId),
            FilterKey::Operator => $this->shopOperators($shopId),
            // All three modes offered (owner-agreed reporting semantics — see
            // DuesAgingDataset's docblock). §7.5/§7.6 numeric reconciliation
            // remains deferred but no longer blocks the Combined presentation.
            // An unrecognised/hand-typed value is rejected by ExportRequest /
            // ReportScreenController validation, never silently relabelled.
            FilterKey::SalesSource => $this->staticOptions([
                'live' => 'Live', 'historical' => 'Historical', 'combined' => 'Combined',
            ]),
            default => null,
        };
    }

    /** @param array<string,string> $map */
    private function staticOptions(array $map): array
    {
        $out = [];
        foreach ($map as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    /** Distinct source_type values present in this shop's cash ledger. */
    private function distinctCashSources(int $shopId): array
    {
        return \Illuminate\Support\Facades\DB::table('cash_transactions')
            ->where('shop_id', $shopId)
            ->whereNotNull('source_type')
            ->distinct()
            ->orderBy('source_type')
            ->pluck('source_type')
            ->map(fn ($s) => ['value' => (string) $s, 'label' => \Illuminate\Support\Str::headline(str_replace('_', ' ', (string) $s))])
            ->values()
            ->all();
    }

    /** Active users in this shop (operator filter). */
    private function shopOperators(int $shopId): array
    {
        return \App\Models\User::where('shop_id', $shopId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['value' => (string) $u->id, 'label' => (string) $u->name])
            ->values()
            ->all();
    }
}
