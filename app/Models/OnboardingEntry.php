<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staged opening input under an onboarding batch. Editable/deletable while
 * the batch is draft/review; posted into a canonical ledger at lock (see
 * OnboardingPostingService). `payload` shape depends on `kind`.
 */
class OnboardingEntry extends Model
{
    use BelongsToShop;

    // Cash / bank / UPI / card / wallet → cash_transactions
    public const KIND_CASH = 'cash';
    // Loose vault bullion → metal_lots(source=opening) + metal_movements(type=opening)
    public const KIND_VAULT_METAL = 'vault_metal';
    // Metal physically with a karigar → metal_lots(source=karigar_held)
    public const KIND_KARIGAR_GOLD = 'karigar_gold';
    // Karigar money outstanding → karigars.opening_balance
    public const KIND_KARIGAR_MONEY = 'karigar_money';
    // Customer gold balance → customer_gold_transactions(type=adjust)
    public const KIND_CUSTOMER_GOLD = 'customer_gold';
    // Customer advance / store credit → store_credit_movements(opening_advance)
    public const KIND_CUSTOMER_ADVANCE = 'customer_advance';
    // Customer money receivable/payable → customer_opening_balances
    public const KIND_CUSTOMER_RECEIVABLE = 'customer_receivable';
    public const KIND_CUSTOMER_PAYABLE = 'customer_payable';
    // Supplier balance → supplier_opening_balances
    public const KIND_SUPPLIER_PAYABLE = 'supplier_payable';
    public const KIND_SUPPLIER_RECEIVABLE = 'supplier_receivable';
    // Finished jewellery stock → items(source=opening_stock), separate metal pool
    public const KIND_STOCK_ITEM = 'stock_item';

    public const KINDS = [
        self::KIND_CASH,
        self::KIND_VAULT_METAL,
        self::KIND_KARIGAR_GOLD,
        self::KIND_KARIGAR_MONEY,
        self::KIND_CUSTOMER_GOLD,
        self::KIND_CUSTOMER_ADVANCE,
        self::KIND_CUSTOMER_RECEIVABLE,
        self::KIND_CUSTOMER_PAYABLE,
        self::KIND_SUPPLIER_PAYABLE,
        self::KIND_SUPPLIER_RECEIVABLE,
        self::KIND_STOCK_ITEM,
    ];

    protected $fillable = [
        'shop_id',
        'onboarding_batch_id',
        'kind',
        'payload',
        'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OnboardingBatch::class, 'onboarding_batch_id');
    }
}
