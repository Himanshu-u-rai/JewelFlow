<?php

namespace App\Services;

use App\Models\ShopCounter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

class BusinessIdentifierService
{
    public const KEY_LOT = 'lot';
    public const KEY_REPAIR = 'repair';
    public const KEY_CUSTOMER = 'customer';
    public const KEY_IMPORT = 'import';
    public const KEY_INVOICE = 'invoice';
    public const KEY_QUICK_BILL = 'quick_bill';
    public const KEY_DHIRAN = 'dhiran';
    public const KEY_PURCHASE = 'purchase';
    public const KEY_JOB_ORDER = 'job_order';
    public const KEY_CHALLAN = 'challan';
    public const KEY_JOB_RECEIPT = 'job_receipt';

    public static function nextCounter(int $shopId, string $counterKey): int
    {
        if ($shopId <= 0) {
            throw new LogicException('Valid shop_id is required to generate business identifiers.');
        }

        return DB::transaction(function () use ($shopId, $counterKey): int {
            $counter = ShopCounter::query()
                ->where('shop_id', $shopId)
                ->where('counter_key', $counterKey)
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                $initialValue = self::initialCounterSeed($shopId, $counterKey);
                try {
                    ShopCounter::query()->create([
                        'shop_id'       => $shopId,
                        'counter_key'   => $counterKey,
                        'current_value' => $initialValue,
                    ]);
                } catch (QueryException $e) {
                    if (($e->getCode() ?? '') !== '23505') {
                        throw $e;
                    }
                }

                $counter = ShopCounter::query()
                    ->where('shop_id', $shopId)
                    ->where('counter_key', $counterKey)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $next = (int) $counter->current_value + 1;
            $counter->current_value = $next;
            $counter->save();

            return $next;
        });
    }

    private static function initialCounterSeed(int $shopId, string $counterKey): int
    {
        if ($counterKey !== self::KEY_INVOICE) {
            return 0;
        }

        $startNumber = (int) DB::table('shop_billing_settings')
            ->where('shop_id', $shopId)
            ->value('invoice_start_number');

        if ($startNumber < 1) {
            $startNumber = 1001;
        }

        return max(0, $startNumber - 1);
    }

    /**
     * @return array{sequence:int, number:string}
     */
    public static function nextInvoiceIdentifier(int $shopId): array
    {
        self::maybeResetForFiscalYear($shopId);

        $sequence = self::nextCounter($shopId, self::KEY_INVOICE);
        $prefix   = self::invoicePrefixForShop($shopId);
        $suffix   = self::invoiceSuffixForShop($shopId);

        return [
            'sequence' => $sequence,
            'number'   => $prefix . $sequence . $suffix,
        ];
    }

    private static function invoicePrefixForShop(int $shopId): string
    {
        $prefix = DB::table('shop_billing_settings')
            ->where('shop_id', $shopId)
            ->value('invoice_prefix');

        $prefix = trim((string) ($prefix ?? 'INV-'));
        if ($prefix === '') {
            $prefix = 'INV-';
        }

        $normalized = preg_replace('/[^A-Za-z0-9\\-\\/]/', '', $prefix) ?? 'INV-';
        if ($normalized === '') {
            $normalized = 'INV-';
        }

        return $normalized;
    }

    private static function invoiceSuffixForShop(int $shopId): string
    {
        $suffix = DB::table('shop_billing_settings')
            ->where('shop_id', $shopId)
            ->value('invoice_suffix');

        $suffix = trim((string) ($suffix ?? ''));
        if ($suffix === '') {
            return '';
        }

        // Allow alphanumeric, hyphens, slashes, dots
        return preg_replace('/[^A-Za-z0-9\\-\\/\\.]/', '', $suffix) ?? '';
    }

    /**
     * If year_reset is enabled and the fiscal year has changed, reset the invoice counter.
     * India fiscal year: April 1 – March 31.
     */
    private static function maybeResetForFiscalYear(int $shopId): void
    {
        $settings = DB::table('shop_billing_settings')
            ->where('shop_id', $shopId)
            ->first(['year_reset', 'current_fiscal_year', 'invoice_start_number']);

        if (!$settings || !$settings->year_reset) {
            return;
        }

        $now  = now();
        $year = $now->month >= 4 ? $now->year : $now->year - 1;
        $currentFY = $year . '-' . substr((string) ($year + 1), 2, 2); // e.g. "2025-26"

        if ($settings->current_fiscal_year === $currentFY) {
            return;
        }

        DB::transaction(function () use ($shopId, $currentFY, $settings): void {
            $startNumber = max(1, (int) ($settings->invoice_start_number ?? 1001));

            ShopCounter::query()
                ->where('shop_id', $shopId)
                ->where('counter_key', self::KEY_INVOICE)
                ->update(['current_value' => $startNumber - 1]);

            DB::table('shop_billing_settings')
                ->where('shop_id', $shopId)
                ->update(['current_fiscal_year' => $currentFY]);
        });
    }

    public static function formatImportReference(int $sequence): string
    {
        return 'IMP-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{sequence:int, number:string}
     */
    public static function nextQuickBillIdentifier(int $shopId): array
    {
        $sequence = self::nextCounter($shopId, self::KEY_QUICK_BILL);

        return [
            'sequence' => $sequence,
            'number'   => 'QB-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * @return array{sequence:int, number:string}
     */
    public static function nextPurchaseIdentifier(int $shopId): array
    {
        $sequence = self::nextCounter($shopId, self::KEY_PURCHASE);

        return [
            'sequence' => $sequence,
            'number'   => 'PUR-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * @return array{sequence:int, number:string}
     */
    public static function nextJobOrderIdentifier(int $shopId): array
    {
        $sequence = self::nextCounter($shopId, self::KEY_JOB_ORDER);

        return [
            'sequence' => $sequence,
            'number'   => 'JO-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * @return array{sequence:int, number:string}
     */
    public static function nextChallanIdentifier(int $shopId): array
    {
        $sequence = self::nextCounter($shopId, self::KEY_CHALLAN);

        return [
            'sequence' => $sequence,
            'number'   => 'DC-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * @return array{sequence:int, number:string}
     */
    public static function nextJobReceiptIdentifier(int $shopId): array
    {
        $sequence = self::nextCounter($shopId, self::KEY_JOB_RECEIPT);

        return [
            'sequence' => $sequence,
            'number'   => 'JR-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
        ];
    }
}
