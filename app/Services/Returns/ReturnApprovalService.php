<?php

namespace App\Services\Returns;

use App\Models\Invoice;
use App\Models\ShopPreferences;

class ReturnApprovalService
{
    /**
     * Returns null when no approval is needed.
     * Returns a human-readable reason string when approval IS required.
     *
     * "Approval required" means the current user must have `returns.approve`
     * permission before the return can proceed.
     *
     * @param array $selections       [ invoice_item_id => ['condition', 'disposition', 'reason'] ]
     * @param array $lineRefundTotals [ invoice_item_id => float refund_total ] — pre-computed by resolver
     */
    public function checkRequired(
        Invoice $invoice,
        array $selections,
        array $lineRefundTotals,
        ?ShopPreferences $policy,
    ): ?string {
        // 1. Amount threshold
        if ($policy?->approval_threshold_amount !== null) {
            $sumRefund = array_sum($lineRefundTotals);
            if ($sumRefund > (float) $policy->approval_threshold_amount) {
                return "Return total ₹" . number_format($sumRefund, 2)
                    . " exceeds the approval threshold of ₹"
                    . number_format((float) $policy->approval_threshold_amount, 2) . ".";
            }
        }

        // 2. Sensitive dispositions
        if ($policy?->approval_required_for_sensitive_dispositions) {
            foreach ($selections as $sel) {
                $disp = $sel['disposition'] ?? '';
                if (in_array($disp, ['sent_to_melt', 'written_off'], true)) {
                    return "Dispositions 'sent_to_melt' or 'written_off' require manager approval per this shop's policy.";
                }
            }
        }

        return null;
    }
}
