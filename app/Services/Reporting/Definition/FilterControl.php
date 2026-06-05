<?php

namespace App\Services\Reporting\Definition;

/**
 * A filter a report opts into (frozen §6.2). `required` marks date range as
 * mandatory for transactional reports; `fyPresets` exposes the FY-first preset
 * set (frozen §17). A reserved-hook filter (Branch) is declared but never
 * rendered (frozen §3.2).
 */
final class FilterControl
{
    public function __construct(
        public readonly FilterKey $key,
        public readonly bool $required = false,
        public readonly bool $fyPresets = false,
    ) {
    }

    public static function for(FilterKey $key, bool $required = false): self
    {
        return new self($key, $required, $key->supportsFyPresets());
    }

    public function isRendered(): bool
    {
        return ! $this->key->isReservedHook();
    }
}
