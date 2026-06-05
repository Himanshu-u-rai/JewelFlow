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
 *                 in addition to the above.
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
        );
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
