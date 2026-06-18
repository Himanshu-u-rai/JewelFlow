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
    public const KEY_CREDIT_NOTE = 'credit_note';
    public const KEY_QUICK_BILL = 'quick_bill';
    public const KEY_DHIRAN = 'dhiran';
    public const KEY_PURCHASE = 'purchase';
    public const KEY_JOB_ORDER = 'job_order';
    public const KEY_CHALLAN = 'challan';
    public const KEY_JOB_RECEIPT = 'job_receipt';
    public const KEY_PLATFORM_INVOICE = 'platform_invoice';
    public const KEY_SHOP_CODE = 'shop_code';

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
        return 'IMP-' . $sequence;
    }

    /**
     * @return array{sequence:int, number:string}
     */
    public static function nextQuickBillIdentifier(int $shopId): array
    {
        $sequence = self::nextCounter($shopId, self::KEY_QUICK_BILL);

        return [
            'sequence' => $sequence,
            'number'   => 'QB-' . $sequence,
        ];
    }

    /**
     * Credit-note identifier. Indian shops keep a distinct CN sequence space
     * separate from invoices (GSTR-1 credit-note section). Mirrors the other
     * next*Identifier methods; race-safe via the shared nextCounter lock.
     *
     * @return array{sequence:int, number:string}
     */
    public static function nextCreditNoteIdentifier(int $shopId): array
    {
        $sequence = self::nextCounter($shopId, self::KEY_CREDIT_NOTE);

        return [
            'sequence' => $sequence,
            'number'   => 'CN-' . $sequence,
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
            'number'   => 'PUR-' . $sequence,
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
            'number'   => 'JO-' . $sequence,
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
            'number'   => 'DC-' . $sequence,
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
            'number'   => 'JR-' . $sequence,
        ];
    }

    /**
     * Global (platform-wide) sequential shop code. Format: JF-0001
     * Must be called inside a DB::transaction().
     */
    public static function nextShopCode(): string
    {
        $row = DB::table('platform_counters')
            ->where('counter_key', self::KEY_SHOP_CODE)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            try {
                DB::table('platform_counters')->insert([
                    'counter_key'   => self::KEY_SHOP_CODE,
                    'current_value' => 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            } catch (QueryException $e) {
                if (($e->getCode() ?? '') !== '23505') {
                    throw $e;
                }
            }

            $row = DB::table('platform_counters')
                ->where('counter_key', self::KEY_SHOP_CODE)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $next = (int) $row->current_value + 1;

        DB::table('platform_counters')
            ->where('counter_key', self::KEY_SHOP_CODE)
            ->update(['current_value' => $next, 'updated_at' => now()]);

        return 'JF-' . $next;
    }

    /**
     * Global (platform-wide) sequential invoice number for platform billing.
     * Uses the platform_counters table — not shop-scoped.
     *
     * @return array{sequence:int, number:string}
     */
    /**
     * Must always be called from within a DB::transaction() so the
     * lockForUpdate() is held for the full outer transaction duration,
     * preventing concurrent requests from obtaining the same sequence number.
     */
    public static function nextPlatformInvoiceNumber(): array
    {
        $row = DB::table('platform_counters')
            ->where('counter_key', self::KEY_PLATFORM_INVOICE)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            try {
                DB::table('platform_counters')->insert([
                    'counter_key'   => self::KEY_PLATFORM_INVOICE,
                    'current_value' => 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            } catch (QueryException $e) {
                if (($e->getCode() ?? '') !== '23505') {
                    throw $e;
                }
            }

            $row = DB::table('platform_counters')
                ->where('counter_key', self::KEY_PLATFORM_INVOICE)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $next = (int) $row->current_value + 1;

        DB::table('platform_counters')
            ->where('counter_key', self::KEY_PLATFORM_INVOICE)
            ->update(['current_value' => $next, 'updated_at' => now()]);

        $prefix = config('business.platform_invoice_prefix', 'JFINV-');

        return [
            'sequence' => $next,
            'number'   => $prefix . $next,
        ];
    }
}
