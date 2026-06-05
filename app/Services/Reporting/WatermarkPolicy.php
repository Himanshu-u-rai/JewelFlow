<?php

namespace App\Services\Reporting;

use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportProfile;

/**
 * Derives the document watermark from classification + state + sensitive opt-in
 * + the report's baseline (frozen §19). Automatic, never user-chosen.
 *
 *  - Compliance reports and the CA Standard profile carry NO watermark (they are
 *    meant to be filed/shared formally — a watermark would look invalid).
 *  - Period not finalized → DRAFT.
 *  - Audit class → INTERNAL USE ONLY (+ CONFIDENTIAL).
 *  - Sensitive columns opted in, or a CONFIDENTIAL baseline (cost/margin, Dhiran)
 *    → CONFIDENTIAL.
 */
class WatermarkPolicy
{
    public const DRAFT = 'DRAFT';
    public const INTERNAL = 'INTERNAL USE ONLY';
    public const CONFIDENTIAL = 'CONFIDENTIAL';

    public function for(
        ReportDefinition $definition,
        ReportProfile $profile,
        bool $sensitiveIncluded = false,
        bool $isDraft = false,
    ): ?string {
        // Formal, shareable outputs are never watermarked (frozen §19).
        if ($definition->classification->isRigid() || $profile === ReportProfile::CaStandard) {
            return null;
        }

        $labels = [];

        if ($isDraft) {
            $labels[] = self::DRAFT;
        }

        if ($definition->classification->isAudit()) {
            $labels[] = self::INTERNAL;
        }

        if ($sensitiveIncluded
            || $definition->classification->isAudit()
            || strcasecmp((string) $definition->watermarkBaseline, self::CONFIDENTIAL) === 0) {
            $labels[] = self::CONFIDENTIAL;
        }

        if ($definition->watermarkBaseline !== null
            && strcasecmp($definition->watermarkBaseline, self::INTERNAL) === 0) {
            $labels[] = self::INTERNAL;
        }

        $labels = array_values(array_unique($labels));

        return $labels === [] ? null : implode(' · ', $labels);
    }
}
