<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Fills in the real purchase cost of a piece whose cost was never recorded (CONSTITUTION §5: acquisition_cost NULL is
 * an intentional historical value until the owner enters it). The cost is set ONCE with source 'entered'; the database
 * trigger items_acquisition_cost_guard_trigger refuses any later change, so a mistaken entry can only be corrected by
 * a compensating process, never by an edit. cost_price (today's replacement cost) is never touched.
 */
final class AcquisitionCostService
{
    public const AUDIT_ACTION = 'item.acquisition_cost_filled';

    /** Largest value the decimal(18,2) column holds is far above any piece; this keeps typos out. */
    public const MAX_AMOUNT = 100000000.0;

    /** In-stock pieces of the current shop (tenant scope) whose purchase cost is still unknown. */
    public static function missingCount(): int
    {
        return Item::query()->whereNull('acquisition_cost')->whereIn('status', StockAsAt::STOCK_STATUSES)->count();
    }

    /** Accepts "41234.5", "41,234.50", "₹ 41,234". Returns null when it is not a plain positive money amount. */
    public static function parseAmount(mixed $raw): ?string
    {
        $s = trim(ltrim(trim((string) $raw), '₹'));
        // Plain digits, or digits grouped with commas the Indian (12,34,567) or western (1,234,567) way.
        if (preg_match('/^\d{1,3}(,\d{2,3})+(\.\d{1,2})?$/', $s)) {
            $s = str_replace(',', '', $s);
        }
        if (! preg_match('/^\d{1,9}(\.\d{1,2})?$/', $s)) {
            return null;
        }
        $v = (float) $s;

        return ($v > 0 && $v <= self::MAX_AMOUNT) ? number_format($v, 2, '.', '') : null;
    }

    /** @throws DomainException with a message safe to show the user */
    public function fill(Item $item, mixed $amount, User $by, ?string $note = null): Item
    {
        $value = self::parseAmount($amount);
        if ($value === null) {
            throw new DomainException(__('Enter the purchase cost as an amount greater than zero.'));
        }
        if ((int) $item->shop_id !== (int) $by->shop_id) {
            throw new DomainException(__('This piece was not found.'));
        }

        try {
            return DB::transaction(function () use ($item, $value, $by, $note) {
                $fresh = Item::withoutTenant()->whereKey($item->id)->where('shop_id', $by->shop_id)->lockForUpdate()->first();
                if (! $fresh) {
                    throw new DomainException(__('This piece was not found.'));
                }
                if ($fresh->acquisition_cost !== null) {
                    throw new DomainException(__('This piece already has a purchase cost. It is set once and cannot be changed.'));
                }
                if (! StockAsAt::isStockStatus($fresh->status)) {
                    throw new DomainException(__('Only pieces in stock can be given a purchase cost here.'));
                }

                $fresh->acquisition_cost = $value;
                $fresh->acquisition_cost_source = 'entered';
                $fresh->save();

                $log = new AuditLog();
                $log->shop_id = (int) $by->shop_id;
                $log->fill([
                    'user_id' => (int) $by->id,
                    'action' => self::AUDIT_ACTION,
                    'model_type' => 'items',
                    'model_id' => (int) $fresh->id,
                    'description' => "Purchase cost of piece {$fresh->barcode} entered.",
                    'before' => ['acquisition_cost' => null, 'acquisition_cost_source' => $item->acquisition_cost_source],
                    'after' => ['acquisition_cost' => $value, 'acquisition_cost_source' => 'entered'],
                    'data' => ['item_id' => (int) $fresh->id, 'amount' => $value, 'note' => $note !== null ? trim($note) : null, 'source' => 'entered'],
                    'ip' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);
                $log->save();

                return $fresh;
            });
        } catch (QueryException $e) {
            // The immutability trigger (or the source CHECK) said no; never a 500.
            if (str_contains($e->getMessage(), 'acquisition_cost')) {
                throw new DomainException(__('This piece already has a purchase cost. It is set once and cannot be changed.'), 0, $e);
            }
            throw $e;
        }
    }
}
