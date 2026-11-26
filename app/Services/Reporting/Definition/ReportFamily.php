<?php

namespace App\Services\Reporting\Definition;

/**
 * Report family — orthogonal to classification (Addendum B §23).
 * Everything is `Standard`.
 */
enum ReportFamily: string
{
    case Standard = 'standard';
}
