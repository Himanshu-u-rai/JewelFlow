<?php

namespace App\Services\Reporting\Filters;

use Carbon\CarbonImmutable;

/**
 * A resolved, explicit date range with the human label stamped on the document
 * (frozen §17). "This FY" always prints as "1 Apr 2025 – 31 Mar 2026" so two
 * exports are reconcilable (frozen §15).
 */
final class ResolvedPeriod
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
        public readonly ?string $fyName = null,
    ) {
    }

    /** @return array{from: string, to: string} ISO 8601 — JSON-safe for the audit row (§16). */
    public function toIso(): array
    {
        return ['from' => $this->from->toIso8601String(), 'to' => $this->to->toIso8601String()];
    }
}
