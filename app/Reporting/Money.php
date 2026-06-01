<?php

namespace App\Reporting;

/**
 * Paisa-integer money value object for report aggregation.
 *
 * Mirrors the discipline already used by PricingEngine: hold money as an
 * integer number of paisa internally so that summing many lines can never
 * accumulate floating-point drift, and only convert back to rupees at the
 * display/storage boundary (audit §1.5).
 *
 * Note: reports that read already-persisted decimal totals (e.g. the GST
 * report sums invoices.gst) do not strictly need this — the database does the
 * summation. Money is for in-PHP aggregation across rows where float drift is
 * a real risk.
 */
final class Money
{
    private function __construct(private readonly int $paisa) {}

    public static function fromPaisa(int $paisa): self
    {
        return new self($paisa);
    }

    public static function fromRupees(int|float|string $rupees): self
    {
        // Round half-up at the paisa boundary, then store as integer paisa.
        return new self((int) round(((float) $rupees) * 100));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->paisa + $other->paisa);
    }

    public function subtract(self $other): self
    {
        return new self($this->paisa - $other->paisa);
    }

    public function paisa(): int
    {
        return $this->paisa;
    }

    public function rupees(): float
    {
        return $this->paisa / 100;
    }

    public function isNegative(): bool
    {
        return $this->paisa < 0;
    }
}
