<?php

namespace App\Services\Reporting\Definition;

/**
 * Per-column masking (Addendum B §23). Dhiran KYC (Aadhaar/PAN) is `Mask` by
 * default — full value is an audited, owner/manager-gated reveal and is never
 * emitted in a shareable CSV/Excel. Standard columns are `None`.
 */
enum MaskingStrategy: string
{
    case None = 'none';
    case Mask = 'mask';
}
