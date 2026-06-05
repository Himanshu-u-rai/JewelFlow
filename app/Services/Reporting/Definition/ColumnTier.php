<?php

namespace App\Services\Reporting\Definition;

/**
 * Three-tier column model (frozen §7.1).
 * Mandatory: always present. Optional: user-toggleable within the catalogue.
 * Sensitive: off by default in external/CA profiles, opt-in, permission-gated
 * (`reports.export_sensitive`).
 */
enum ColumnTier: string
{
    case Mandatory = 'mandatory';
    case Optional = 'optional';
    case Sensitive = 'sensitive';
}
