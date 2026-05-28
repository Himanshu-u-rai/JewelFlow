<?php

namespace App\Services\Returns;

/**
 * Immutable result of RefundPolicyResolver::resolve().
 *
 * Carries the per-line refund amounts that will be written to
 * return_line_items, plus a breakdown array that is stored as
 * policy_breakdown jsonb for auditing.
 */
final class RefundBreakdown
{
    public function __construct(
        public readonly float $refundSubtotal,
        public readonly float $refundGst,
        public readonly float $refundDiscount,
        public readonly float $refundRoundOff,
        public readonly int   $refundLoyaltyPts,
        public readonly float $refundTotal,
        public readonly array $breakdown,
    ) {}

    /**
     * Convenience: the breakdown array ready to be JSON-encoded into
     * return_line_items.policy_breakdown.
     */
    public function toArray(): array
    {
        return $this->breakdown;
    }
}
