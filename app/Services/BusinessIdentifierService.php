<?php

namespace App\Services;

use App\Models\ShopCounter;
use App\Support\FinancialYear;
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
    public const KEY_PURCHASE = 'purchase';
    public const KEY_JOB_ORDER = 'job_order';
    public const KEY_CHALLAN = 'challan';
    public const KEY_JOB_RECEIPT = 'job_receipt';
    public const KEY_PLATFORM_INVOICE = 'platform_invoice';
    public const KEY_SHOP_CODE = 'shop_code';
    public const KEY_RETURN = 'return';
    public const KEY_EXCHANGE = 'exchange';

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
                // The counter's first use. Two first uses at the same instant both
                // get here. ON CONFLICT DO NOTHING lets the second wait for the
                // first to commit and then insert nothing, without an error.
                // (Catching the unique violation instead, as this did, does not
                // work on PostgreSQL: the failed INSERT aborts the transaction and
                // every later statement in it, the caller's included, is refused.)
                // Only that conflict is absorbed: any other failure still raises.
                ShopCounter::query()->insertOrIgnore([
                    'shop_id'       => $shopId,
                    'counter_key'   => $counterKey,
                    'current_value' => self::initialCounterSeed($shopId, $counterKey),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

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
    /**
     * The next invoice identity in the shop's series for a financial year (default: the current one). The database
     * function next_invoice_identity is the single implementation (continuous vs yearly series, {FY} token,
     * starting number); the invoice trigger uses the same function. The old April reset is gone: it reused last
     * year's numbers and every sale on 1 April failed.
     *
     * @return array{sequence:int, number:string, series:string, fiscal_year:string}
     */
    public static function nextInvoiceIdentifier(int $shopId, ?FinancialYear $year = null): array
    {
        if ($shopId <= 0) {
            throw new LogicException('Valid shop_id is required to generate business identifiers.');
        }
        $fy = ($year ?? FinancialYear::current())->label();
        $row = DB::selectOne('SELECT sequence_value, invoice_number, series FROM next_invoice_identity(?, ?)', [$shopId, $fy]);

        return [
            'sequence'    => (int) $row->sequence_value,
            'number'      => (string) $row->invoice_number,
            'series'      => (string) $row->series,
            'fiscal_year' => $fy,
        ];
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
            // See nextCounter(): the conflict is absorbed by the statement, not caught.
            DB::table('platform_counters')->insertOrIgnore([
                'counter_key'   => self::KEY_SHOP_CODE,
                'current_value' => 0,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

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
            // See nextCounter(): the conflict is absorbed by the statement, not caught.
            DB::table('platform_counters')->insertOrIgnore([
                'counter_key'   => self::KEY_PLATFORM_INVOICE,
                'current_value' => 0,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

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
