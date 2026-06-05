<?php

namespace App\Services\Reporting\Dataset;

/**
 * The canonical RowSet — the single source of truth every renderer consumes
 * (frozen §3.1). Screen / PDF / Excel / CSV all read THIS; none re-queries, so
 * they can never disagree. Presentation-agnostic: native typed values only.
 */
final class ReportDataset
{
    /**
     * @param ReportSection[] $sections
     */
    public function __construct(
        public readonly array $sections,
        public readonly ReportMeta $meta,
    ) {
    }

    public function section(string $key): ?ReportSection
    {
        foreach ($this->sections as $section) {
            if ($section->key === $key) {
                return $section;
            }
        }
        return null;
    }

    public function isEmpty(): bool
    {
        foreach ($this->sections as $section) {
            if ($section->rowCount() > 0) {
                return false;
            }
        }
        return true;
    }

    public function totalRowCount(): int
    {
        $count = 0;
        foreach ($this->sections as $section) {
            $count += $section->rowCount();
        }
        return $count;
    }

    public function isMultiSection(): bool
    {
        return count($this->sections) > 1;
    }
}
