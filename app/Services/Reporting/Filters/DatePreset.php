<?php

namespace App\Services\Reporting\Filters;

/**
 * FY-first date presets (frozen §17). Indian jewellery accounting is FY-centric
 * (Apr 1 – Mar 31), so the panel leads with these, not a raw calendar.
 * Quarters are FY-aligned (Apr-Jun, Jul-Sep, Oct-Dec, Jan-Mar) per GST cadence.
 */
enum DatePreset: string
{
    case Today = 'today';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';
    case ThisQuarter = 'this_quarter';
    case LastQuarter = 'last_quarter';
    case ThisFy = 'this_fy';
    case LastFy = 'last_fy';
    case FyToDate = 'fy_to_date';
    case NamedFy = 'named_fy';
    case Custom = 'custom';
}
