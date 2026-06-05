<?php

namespace App\Services\Reporting\Definition;

use InvalidArgumentException;

/**
 * Declarative metadata for one report (frozen §3.1, §15). Immutable and
 * code-defined (version lives here, not in the DB — frozen §15). The constructor
 * enforces the frozen invariants so an out-of-architecture report cannot be
 * declared (e.g. a compliance report with toggleable columns).
 *
 * @property-read ColumnDefinition[]  $columns
 * @property-read ReportProfile[]     $profiles
 * @property-read FilterControl[]     $filters
 * @property-read ExportFormat[]      $formats
 */
final class ReportDefinition
{
    /**
     * @param ColumnDefinition[] $columns
     * @param ReportProfile[]    $profiles
     * @param FilterControl[]    $filters
     * @param ExportFormat[]     $formats
     */
    public function __construct(
        public readonly string $key,
        public readonly string $version,
        public readonly string $title,
        public readonly ReportClassification $classification,
        public readonly array $columns,
        public readonly array $profiles,
        public readonly array $filters,
        public readonly array $formats,
        public readonly ReportPermissions $permissions,
        public readonly ReportFamily $family = ReportFamily::Standard,
        public readonly ?string $pdfTemplate = null,
        public readonly ?string $watermarkBaseline = null,
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->key === '' || $this->version === '') {
            throw new InvalidArgumentException('ReportDefinition requires a non-empty key and version.');
        }
        if ($this->columns === []) {
            throw new InvalidArgumentException("Report [{$this->key}] declares no columns.");
        }
        if ($this->formats === []) {
            throw new InvalidArgumentException("Report [{$this->key}] declares no formats.");
        }
        if ($this->profiles === []) {
            throw new InvalidArgumentException("Report [{$this->key}] declares no profiles.");
        }

        // Compliance is rigid: single Fixed profile, no optional/sensitive columns (frozen §9).
        if ($this->classification->isRigid()) {
            if ($this->profiles !== [ReportProfile::Fixed]) {
                throw new InvalidArgumentException(
                    "Compliance report [{$this->key}] must expose exactly the Fixed profile (frozen §9)."
                );
            }
            foreach ($this->columns as $column) {
                if (! $column->isMandatory()) {
                    throw new InvalidArgumentException(
                        "Compliance report [{$this->key}] may not declare optional/sensitive columns (frozen §9)."
                    );
                }
            }
        }

        // Accounting + Compliance must offer the formal PDF (frozen §11).
        if ($this->classification->requiresFormalPdf() && ! $this->supportsFormat(ExportFormat::Pdf)) {
            throw new InvalidArgumentException(
                "Report [{$this->key}] is {$this->classification->value} and must offer the formal PDF (frozen §11)."
            );
        }

        // CA Standard is scoped to CA-facing classes — not Operational/Audit (frozen §18).
        if ($this->supportsProfile(ReportProfile::CaStandard)
            && in_array($this->classification, [ReportClassification::Operational, ReportClassification::Audit], true)) {
            throw new InvalidArgumentException(
                "Report [{$this->key}] ({$this->classification->value}) may not offer CA Standard (frozen §18 scope)."
            );
        }

        // The Dhiran family carries a baseline gate (Addendum B §23).
        if ($this->family === ReportFamily::Dhiran && $this->permissions->familyGate === null) {
            throw new InvalidArgumentException(
                "Dhiran report [{$this->key}] must declare a family gate (e.g. dhiran.reports) (Addendum B §23)."
            );
        }
    }

    public function supportsFormat(ExportFormat $format): bool
    {
        return in_array($format, $this->formats, true);
    }

    public function supportsProfile(ReportProfile $profile): bool
    {
        return in_array($profile, $this->profiles, true);
    }

    /** @return ColumnDefinition[] */
    public function columnsByTier(ColumnTier $tier): array
    {
        return array_values(array_filter($this->columns, static fn (ColumnDefinition $c) => $c->tier === $tier));
    }

    public function hasSensitiveColumns(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->isSensitive()) {
                return true;
            }
        }
        return false;
    }

    public function hasMaskedColumns(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->masking === MaskingStrategy::Mask) {
                return true;
            }
        }
        return false;
    }

    public function column(string $key): ?ColumnDefinition
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }
        return null;
    }

    /** @return FilterControl[] filters that are actually rendered (excludes reserved hooks). */
    public function renderedFilters(): array
    {
        return array_values(array_filter($this->filters, static fn (FilterControl $f) => $f->isRendered()));
    }
}
