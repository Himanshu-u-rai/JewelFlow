<?php

namespace App\Support;

use App\Models\ShopPaymentMethod;

class PaymentMethodLabel
{
    public static function resolve(
        ?ShopPaymentMethod $method,
        ?string $mode,
        ?string $snapshotLabel = null
    ): string {
        $snapshot = trim((string) ($snapshotLabel ?? ''));
        if ($snapshot !== '') {
            return $snapshot;
        }

        if ($method) {
            return (string) $method->account_label;
        }

        return self::modeLabel($mode);
    }

    public static function modeLabel(?string $mode): string
    {
        $key = strtolower(trim((string) $mode));

        return match ($key) {
            'cash' => 'Cash',
            'upi' => 'UPI',
            'bank' => 'Bank Transfer',
            'wallet' => 'Wallet',
            'old_gold' => 'Old Gold',
            'old_silver' => 'Old Silver',
            'emi' => 'EMI',
            'scheme', 'scheme_redemption' => 'Scheme Redemption',
            'other' => 'Other',
            default => $key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'Other',
        };
    }
}

