<?php

namespace App\Services\Reporting\Definition;

/**
 * The permission ladder for one report (frozen §10, Addendum C §28).
 *
 *  - view:        gate to see/run the report on screen (default `reports.view`;
 *                 daily closing uses `reports.daily_closing`).
 *  - export:      gate to export any format (default `reports.export`).
 *  - sensitive:   column/export-layer gate for sensitive data
 *                 (default `reports.export_sensitive`).
 *  - surfaceGate: optional WHOLE-surface owner/manager gate — the §28 exception
 *                 set (Audit reports, Dhiran forfeiture/profitability) use
 *                 `reports.audit`. When set, it replaces `view` at the route.
 *  - familyGate:  optional family baseline (Dhiran = `dhiran.reports`), required
 *                 in addition to the above, UNCONDITIONALLY (every request).
 *  - historicalModeGate: optional MODE-DEPENDENT baseline — required only when
 *                 the request's `sales_source` is `historical`/`combined`
 *                 (Dues Aging: `historical.view`, since LIVE must keep its
 *                 existing reporting permissions unchanged — see
 *                 `gateForSalesSource()`).
 *  - edition:     optional edition constraint ('retailer' | 'manufacturer').
 */
final class ReportPermissions
{
    public function __construct(
        public readonly string $view = 'reports.view',
        public readonly string $export = 'reports.export',
        public readonly string $sensitive = 'reports.export_sensitive',
        public readonly ?string $surfaceGate = null,
        public readonly ?string $familyGate = null,
        public readonly ?string $edition = null,
        public readonly ?string $historicalModeGate = null,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    /** Whole-surface owner/manager-only report (frozen §28 exception). */
    public function withSurfaceGate(string $permission): self
    {
        return new self(
            $this->view,
            $this->export,
            $this->sensitive,
            $permission,
            $this->familyGate,
            $this->edition,
            $this->historicalModeGate,
        );
    }

    public function withFamilyGate(string $permission): self
    {
        return new self(
            $this->view,
            $this->export,
            $this->sensitive,
            $this->surfaceGate,
            $permission,
            $this->edition,
            $this->historicalModeGate,
        );
    }

    public function withView(string $permission): self
    {
        return new self(
            $permission,
            $this->export,
            $this->sensitive,
            $this->surfaceGate,
            $this->familyGate,
            $this->edition,
            $this->historicalModeGate,
        );
    }

    public function withEdition(?string $edition): self
    {
        return new self(
            $this->view,
            $this->export,
            $this->sensitive,
            $this->surfaceGate,
            $this->familyGate,
            $edition,
            $this->historicalModeGate,
        );
    }

    /** Requires $permission in addition to view/export, but ONLY when the request's `sales_source` is `historical`/`combined` — LIVE (default/explicit) keeps whatever view/export/familyGate already grant it, unchanged. */
    public function withHistoricalModeGate(string $permission): self
    {
        return new self(
            $this->view,
            $this->export,
            $this->sensitive,
            $this->surfaceGate,
            $this->familyGate,
            $this->edition,
            $permission,
        );
    }

    /**
     * The permission needed for the given `sales_source` value, or null if
     * this mode needs nothing beyond the report's normal gates. Same closed
     * vocabulary as the dataset's own mode resolution (`historical`/
     * `combined` trigger it; `live`, absent, or anything else does not — an
     * unrecognised value is rejected by upstream validation regardless, never
     * silently granted or denied here).
     */
    public function gateForSalesSource(?string $salesSource): ?string
    {
        if ($this->historicalModeGate === null) {
            return null;
        }

        return in_array($salesSource, ['historical', 'combined'], true) ? $this->historicalModeGate : null;
    }

    /**
     * The permission a user must hold to open the surface at all: the surface
     * gate when present (whole-surface owner/manager), else the view gate.
     */
    public function effectiveViewGate(): string
    {
        return $this->surfaceGate ?? $this->view;
    }
}
