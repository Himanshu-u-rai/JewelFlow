<?php

namespace App\Services\Reporting\Definition;

/**
 * Report family — orthogonal to classification (Addendum B §23).
 * `Dhiran` carries family-level defaults (KYC masking, `dhiran.reports` baseline
 * gate, CONFIDENTIAL watermark posture, bespoke document templates) without
 * being a separate export system. Everything else is `Standard`.
 */
enum ReportFamily: string
{
    case Standard = 'standard';
    case Dhiran = 'dhiran';
}
