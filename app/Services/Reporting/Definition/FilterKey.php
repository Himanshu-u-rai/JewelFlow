<?php

namespace App\Services\Reporting\Definition;

/**
 * The filter catalogue a report may opt into (frozen §6.2, matrix §22).
 * `Branch` is a reserved-but-unrendered hook (frozen §3.2, §6.2) — present so the
 * dimension exists, never shown until a multi-location model arrives.
 */
enum FilterKey: string
{
    case Period = 'period';            // date range, FY-first presets (frozen §17)
    case AsOf = 'as_of';               // point-in-time (valuations, balances)
    case Operator = 'operator';
    case Customer = 'customer';
    case MetalType = 'metal_type';
    case Status = 'status';
    case PaymentMode = 'payment_mode';
    case AgeBand = 'age_band';         // dead stock / aging
    case DaysOverdue = 'days_overdue'; // overdue loans / dues
    case Karigar = 'karigar';
    case MovementType = 'movement_type'; // metal movement ledger
    case Reference = 'reference';      // item/invoice/loan reference
    case Lot = 'lot';
    case CashType = 'cash_type';       // cash ledger: money in / money out
    case CashSource = 'cash_source';   // cash ledger: source_type (invoice, expense, …)
    case Branch = 'branch';            // RESERVED — never rendered (frozen §3.2)

    /** Date-style filters carry the FY-first preset set (frozen §17). */
    public function supportsFyPresets(): bool
    {
        return $this === self::Period || $this === self::AsOf;
    }

    public function isReservedHook(): bool
    {
        return $this === self::Branch;
    }
}
