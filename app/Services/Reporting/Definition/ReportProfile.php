<?php

namespace App\Services\Reporting\Definition;

/**
 * Export profile — the "how it's shaped" control (frozen §8, §18, §22 legend).
 * Ca is the flexible CA-leaning profile; CaStandard is the system-locked,
 * canonical, identical-everywhere profile (frozen §18). Fixed is the single
 * locked shape for compliance reports (frozen §9).
 */
enum ReportProfile: string
{
    case Summary = 'summary';
    case Detailed = 'detailed';
    case Ca = 'ca';
    case CaStandard = 'ca_standard';
    case Raw = 'raw';
    case Fixed = 'fixed';

    /** Locked profiles expose no column toggles (frozen §9, §18). */
    public function isLocked(): bool
    {
        return $this === self::CaStandard || $this === self::Fixed;
    }

    /** CA-facing profiles never include sensitive columns by default (frozen §18). */
    public function excludesSensitiveByDefault(): bool
    {
        return $this === self::Ca || $this === self::CaStandard;
    }
}
