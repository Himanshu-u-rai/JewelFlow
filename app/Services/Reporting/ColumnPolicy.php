<?php

namespace App\Services\Reporting;

use App\Models\User;
use App\Services\Reporting\Definition\ColumnDefinition;
use App\Services\Reporting\Definition\ColumnTier;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportProfile;

/**
 * The sensitive-column gate (frozen §7, §9, §18, §28). THE security boundary:
 * it decides the final column set server-side, so a crafted request, a saved
 * preset, or a signed link can never smuggle a sensitive column past the
 * permission check (frozen §13). Enforced at resolve time, before the dataset
 * service runs and before any renderer sees data.
 *
 * Rules:
 *  - Mandatory columns are always present.
 *  - Compliance (rigid): the full fixed catalogue, no toggles (§9).
 *  - Optional columns: profile default, overridable by the user's toggles only
 *    for NON-locked profiles (Summary/Detailed/CA/Raw). Locked profiles
 *    (CA Standard, Fixed) ignore toggles — canonical, identical everywhere (§18).
 *  - Sensitive columns: included ONLY when the user opts in, holds
 *    `permissions->sensitive`, AND the profile is not CA Standard or Fixed
 *    (CA Standard never includes sensitive; CA allows opt-in) (§7.1, §18).
 *  - Masked columns (Dhiran KYC) may be revealed in full only with the sensitive
 *    permission and an explicit reveal; the dataset service applies the mask
 *    (Addendum B §23).
 */
class ColumnPolicy
{
    /**
     * @param string[]|null $selectedOptional optional column keys the user toggled on
     * @param string[]|null $selectedSensitive sensitive column keys the user opted into
     */
    public function resolve(
        ReportDefinition $definition,
        ReportProfile $profile,
        User $user,
        bool $includeSensitive = false,
        ?array $selectedOptional = null,
        ?array $selectedSensitive = null,
        bool $requestRevealMasked = false,
    ): ColumnResolution {
        // Compliance is rigid: the whole fixed catalogue, in order, nothing else.
        if ($definition->classification->isRigid()) {
            return new ColumnResolution(
                columnKeys: array_map(static fn (ColumnDefinition $c) => $c->key, $definition->columns),
                sensitiveIncluded: false,
                revealMasked: false,
            );
        }

        $locked = $profile->isLocked(); // CA Standard / Fixed
        $sensitiveForbidden = $this->profileForbidsSensitive($profile);

        $chosenOptional = $this->resolveOptional($definition, $profile, $locked, $selectedOptional);

        $hasSensitivePermission = $user->hasPermission($definition->permissions->sensitive);
        $sensitiveAllowed = $includeSensitive && $hasSensitivePermission && ! $sensitiveForbidden;
        $chosenSensitive = $sensitiveAllowed
            ? $this->resolveSensitive($definition, $selectedSensitive)
            : [];

        // Preserve catalogue order.
        $allow = array_flip([...$chosenOptional, ...$chosenSensitive]);
        $finalKeys = [];
        $sensitiveIncluded = false;
        foreach ($definition->columns as $column) {
            $include = $column->tier === ColumnTier::Mandatory || isset($allow[$column->key]);
            if (! $include) {
                continue;
            }
            $finalKeys[] = $column->key;
            if ($column->tier === ColumnTier::Sensitive) {
                $sensitiveIncluded = true;
            }
        }

        // Full reveal of masked values requires the sensitive gate to have opened.
        $revealMasked = $requestRevealMasked && $hasSensitivePermission && ! $sensitiveForbidden;

        return new ColumnResolution($finalKeys, $sensitiveIncluded, $revealMasked);
    }

    /** CA Standard and Fixed never include sensitive data (frozen §18). CA allows opt-in. */
    private function profileForbidsSensitive(ReportProfile $profile): bool
    {
        return $profile === ReportProfile::CaStandard || $profile === ReportProfile::Fixed;
    }

    /**
     * @param string[]|null $selectedOptional
     * @return string[]
     */
    private function resolveOptional(
        ReportDefinition $definition,
        ReportProfile $profile,
        bool $locked,
        ?array $selectedOptional,
    ): array {
        $catalogue = array_map(
            static fn (ColumnDefinition $c) => $c->key,
            $definition->columnsByTier(ColumnTier::Optional),
        );

        // Locked profiles ignore user toggles; they use the canonical default set.
        if ($locked || $selectedOptional === null) {
            return $this->defaultOptional($profile, $catalogue);
        }

        // Flexible profile: honour the user's selection, but only within the catalogue.
        return array_values(array_intersect($catalogue, $selectedOptional));
    }

    /**
     * Convention for the default optional set per profile (frozen §8). A report
     * may tune this later via profile-specific metadata without changing callers.
     *
     * @param string[] $catalogue
     * @return string[]
     */
    private function defaultOptional(ReportProfile $profile, array $catalogue): array
    {
        return match ($profile) {
            ReportProfile::Summary => [],                 // mandatory only
            default => $catalogue,                        // Detailed/CA/CA Standard/Raw: full optional set
        };
    }

    /**
     * @param string[]|null $selectedSensitive
     * @return string[]
     */
    private function resolveSensitive(ReportDefinition $definition, ?array $selectedSensitive): array
    {
        $catalogue = array_map(
            static fn (ColumnDefinition $c) => $c->key,
            $definition->columnsByTier(ColumnTier::Sensitive),
        );

        if ($selectedSensitive === null) {
            return $catalogue; // opt-in with no explicit picks → all sensitive columns
        }

        return array_values(array_intersect($catalogue, $selectedSensitive));
    }
}
