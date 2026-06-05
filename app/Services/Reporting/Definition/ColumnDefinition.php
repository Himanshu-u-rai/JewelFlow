<?php

namespace App\Services\Reporting\Definition;

/**
 * One column in a report's fixed catalogue (frozen §7). Immutable, declarative.
 * `tier` drives default selection + the sensitive gate; `masking` covers
 * Dhiran KYC (Addendum B §23). There is no free-form/SQL/expression column —
 * the hard guard against becoming a builder (frozen §13).
 */
final class ColumnDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ColumnType $type,
        public readonly ColumnTier $tier = ColumnTier::Mandatory,
        public readonly MaskingStrategy $masking = MaskingStrategy::None,
    ) {
    }

    public static function mandatory(string $key, string $label, ColumnType $type): self
    {
        return new self($key, $label, $type, ColumnTier::Mandatory);
    }

    public static function optional(string $key, string $label, ColumnType $type): self
    {
        return new self($key, $label, $type, ColumnTier::Optional);
    }

    public static function sensitive(
        string $key,
        string $label,
        ColumnType $type,
        MaskingStrategy $masking = MaskingStrategy::None,
    ): self {
        return new self($key, $label, $type, ColumnTier::Sensitive, $masking);
    }

    public function isSensitive(): bool
    {
        return $this->tier === ColumnTier::Sensitive;
    }

    public function isMandatory(): bool
    {
        return $this->tier === ColumnTier::Mandatory;
    }
}
