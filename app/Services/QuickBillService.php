<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\QuickBill;
use App\Models\ShopPaymentMethod;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuickBillService
{
    public function create(Shop $shop, User $user, array $payload): QuickBill
    {
        return DB::transaction(function () use ($shop, $user, $payload): QuickBill {
            $identity = BusinessIdentifierService::nextQuickBillIdentifier((int) $shop->id);
            $quickBill = new QuickBill();
            $quickBill->forceFill([
                'shop_id' => $shop->id,
                'bill_sequence' => $identity['sequence'],
                'bill_number' => $identity['number'],
                'created_by' => $user->id,
            ]);

            $result = $this->persist($quickBill, $shop, $user, $payload);

            AuditLog::create([
                'shop_id' => (int) $shop->id,
                'user_id' => (int) $user->id,
                'action' => 'quick_bill.created',
                'model_type' => 'quick_bill',
                'model_id' => (int) $result->id,
                'description' => 'Quick bill created.',
                'data' => [
                    'bill_number' => $result->bill_number,
                    'status' => $result->status,
                    'total_amount' => (float) $result->total_amount,
                ],
            ]);

            return $result;
        });
    }

    public function update(QuickBill $quickBill, Shop $shop, User $user, array $payload): QuickBill
    {
        return DB::transaction(function () use ($quickBill, $shop, $user, $payload): QuickBill {
            $result = $this->persist($quickBill, $shop, $user, $payload);

            AuditLog::create([
                'shop_id' => (int) $shop->id,
                'user_id' => (int) $user->id,
                'action' => 'quick_bill.updated',
                'model_type' => 'quick_bill',
                'model_id' => (int) $result->id,
                'description' => 'Quick bill updated.',
                'data' => [
                    'bill_number' => $result->bill_number,
                    'status' => $result->status,
                    'total_amount' => (float) $result->total_amount,
                    'paid_amount' => (float) $result->paid_amount,
                ],
            ]);

            return $result;
        });
    }

    public function void(QuickBill $quickBill, User $user, ?string $reason = null): QuickBill
    {
        return DB::transaction(function () use ($quickBill, $user, $reason): QuickBill {
            $quickBill->lockForUpdate();

            if ($quickBill->status === QuickBill::STATUS_VOID) {
                throw ValidationException::withMessages([
                    'bill' => 'This quick bill is already voided.',
                ]);
            }

            $previousStatus = $quickBill->status;
            $previousPaid = (float) $quickBill->paid_amount;

            // Delete payments — they are no longer valid for a voided bill
            $quickBill->payments()->delete();

            $quickBill->forceFill([
                'status' => QuickBill::STATUS_VOID,
                'void_reason' => trim((string) $reason) ?: 'Voided by user',
                'voided_at' => now(),
                'paid_amount' => 0,
                'due_amount' => 0,
                'updated_by' => $user->id,
            ])->save();

            AuditLog::create([
                'shop_id' => (int) $quickBill->shop_id,
                'user_id' => (int) $user->id,
                'action' => 'quick_bill.voided',
                'model_type' => 'quick_bill',
                'model_id' => (int) $quickBill->id,
                'description' => 'Quick bill voided.',
                'data' => [
                    'bill_number' => $quickBill->bill_number,
                    'previous_status' => $previousStatus,
                    'previous_paid_amount' => $previousPaid,
                    'void_reason' => $quickBill->void_reason,
                    'source' => 'quick_bill_service',
                ],
            ]);

            return $quickBill->fresh(['customer', 'items', 'payments.paymentMethod']);
        });
    }

    private function persist(QuickBill $quickBill, Shop $shop, User $user, array $payload): QuickBill
    {
        if ($quickBill->status === QuickBill::STATUS_VOID) {
            throw ValidationException::withMessages([
                'bill' => 'Voided quick bills cannot be edited.',
            ]);
        }

        $items = $this->normalizeItems($payload['items'] ?? []);
        if (count($items) === 0) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one bill item.',
            ]);
        }

        $customer = null;
        if (!empty($payload['customer_id'])) {
            $customer = Customer::query()->find((int) $payload['customer_id']);
        }

        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $customerMobile = trim((string) ($payload['customer_mobile'] ?? ''));
        $customerAddress = trim((string) ($payload['customer_address'] ?? ''));

        if ($customer) {
            $customerName = $customerName !== '' ? $customerName : $customer->name;
            $customerMobile = $customerMobile !== '' ? $customerMobile : (string) $customer->mobile;
            $customerAddress = $customerAddress !== '' ? $customerAddress : (string) $customer->address;
        }

        $pricingMode = in_array(($payload['pricing_mode'] ?? ''), ['no_gst', 'gst_exclusive', 'gst_inclusive'], true)
            ? $payload['pricing_mode']
            : 'gst_exclusive';
        $gstRate = round(max(0, (float) ($payload['gst_rate'] ?? 0)), 2);
        $discountType = in_array(($payload['discount_type'] ?? ''), ['fixed', 'percent'], true)
            ? $payload['discount_type']
            : null;
        $discountValue = round(max(0, (float) ($payload['discount_value'] ?? 0)), 2);
        $roundOff = round((float) ($payload['round_off'] ?? 0), 2);

        $subtotal = round(array_sum(array_column($items, 'line_total')), 2);
        $discountAmount = $this->discountAmount($subtotal, $discountType, $discountValue);
        $afterDiscount = round(max(0, $subtotal - $discountAmount), 2);

        if ($pricingMode === 'no_gst') {
            $taxable = $afterDiscount;
            $gst = 0.0;
            $total = round($afterDiscount + $roundOff, 2);
        } elseif ($pricingMode === 'gst_inclusive') {
            $divisor = 1 + ($gstRate / 100);
            $taxable = $divisor > 0 ? round($afterDiscount / $divisor, 2) : $afterDiscount;
            $gst = round($afterDiscount - $taxable, 2);
            $total = round($afterDiscount + $roundOff, 2);
        } else {
            $taxable = $afterDiscount;
            $gst = round($taxable * ($gstRate / 100), 2);
            $total = round($taxable + $gst + $roundOff, 2);
        }

        $payments = $this->normalizePayments($payload['payments'] ?? [], (int) $shop->id);
        $paidAmount = round(array_sum(array_column($payments, 'amount')), 2);
        if ($paidAmount - $total > 0.01) {
            throw ValidationException::withMessages([
                'payments' => 'Paid amount cannot exceed the quick bill total.',
            ]);
        }

        $halfTax = round($gst / 2, 2);
        $saveAction = ($payload['save_action'] ?? 'draft') === 'issue' ? QuickBill::STATUS_ISSUED : QuickBill::STATUS_DRAFT;
        $status = $quickBill->exists && $quickBill->status === QuickBill::STATUS_ISSUED
            ? QuickBill::STATUS_ISSUED
            : $saveAction;

        $issuedAt = $quickBill->issued_at;
        if ($status === QuickBill::STATUS_ISSUED && !$issuedAt) {
            $issuedAt = now();
        }

        $quickBill->forceFill([
            'shop_id' => $shop->id,
            'customer_id' => $customer?->id,
            'status' => $status,
            'bill_date' => Carbon::parse($payload['bill_date'] ?? now()->toDateString())->toDateString(),
            'customer_name' => $customerName !== '' ? $customerName : null,
            'customer_mobile' => $customerMobile !== '' ? $customerMobile : null,
            'customer_address' => $customerAddress !== '' ? $customerAddress : null,
            'pricing_mode' => $pricingMode,
            'gst_rate' => $gstRate,
            'subtotal' => $subtotal,
            'discount_type' => $discountType,
            'discount_value' => $discountType ? $discountValue : null,
            'discount_amount' => $discountAmount,
            'round_off' => $roundOff,
            'taxable_amount' => $taxable,
            'cgst_amount' => $pricingMode === 'no_gst' ? 0 : $halfTax,
            'sgst_amount' => $pricingMode === 'no_gst' ? 0 : round($gst - $halfTax, 2),
            'igst_amount' => 0,
            'total_amount' => $total,
            'paid_amount' => $paidAmount,
            'due_amount' => round(max(0, $total - $paidAmount), 2),
            'notes' => $this->nullableText($payload['notes'] ?? null),
            'terms' => $this->nullableText($payload['terms'] ?? $shop->billingSettings?->terms_and_conditions),
            'shop_snapshot' => $this->shopSnapshot($shop),
            'updated_by' => $user->id,
            'issued_at' => $issuedAt,
            'void_reason' => null,
            'voided_at' => null,
        ]);

        $quickBill->save();

        $quickBill->items()->delete();
        foreach ($items as $index => $item) {
            $line = new \App\Models\QuickBillItem();
            $line->forceFill([
                'shop_id' => $shop->id,
                'quick_bill_id' => $quickBill->id,
                'sort_order' => $index + 1,
                ...$item,
            ]);
            $line->save();
        }

        $quickBill->payments()->delete();
        foreach ($payments as $payment) {
            $entry = new \App\Models\QuickBillPayment();
            $entry->forceFill([
                'shop_id' => $shop->id,
                'quick_bill_id' => $quickBill->id,
                'payment_mode' => $payment['payment_mode'],
                'payment_method_id' => $payment['payment_method_id'],
                'reference_no' => $payment['reference_no'],
                'amount' => $payment['amount'],
                'paid_at' => $payment['paid_at'],
                'notes' => $payment['notes'],
            ]);
            $entry->save();
        }

        return $quickBill->fresh(['customer', 'items', 'payments.paymentMethod']);
    }

    private function normalizeItems(array $rawItems): array
    {
        $normalized = [];

        foreach ($rawItems as $item) {
            $description = trim((string) Arr::get($item, 'description', ''));
            if ($description === '') {
                continue;
            }

            $grossWeight = round(max(0, (float) Arr::get($item, 'gross_weight', 0)), 3);
            $stoneWeight = round(max(0, (float) Arr::get($item, 'stone_weight', 0)), 3);
            $netWeightInput = (float) Arr::get($item, 'net_weight', 0);
            $netWeight = round($netWeightInput > 0 ? $netWeightInput : max(0, $grossWeight - $stoneWeight), 3);
            $rate = round(max(0, (float) Arr::get($item, 'rate', 0)), 2);
            $making = round(max(0, (float) Arr::get($item, 'making_charge', 0)), 2);
            $stoneCharge = round(max(0, (float) Arr::get($item, 'stone_charge', 0)), 2);
            $wastagePercent = round(max(0, (float) Arr::get($item, 'wastage_percent', 0)), 2);
            $lineDiscount = round(max(0, (float) Arr::get($item, 'line_discount', 0)), 2);
            $metalValue = round($netWeight * $rate, 2);
            $wastageAmount = round($metalValue * ($wastagePercent / 100), 2);
            $lineTotal = round(max(0, $metalValue + $making + $stoneCharge + $wastageAmount - $lineDiscount), 2);

            $normalized[] = [
                'description' => $description,
                'hsn_code' => $this->nullableText(Arr::get($item, 'hsn_code')),
                'metal_type' => $this->nullableText(Arr::get($item, 'metal_type')),
                'purity' => $this->nullableText(Arr::get($item, 'purity')),
                'pcs' => max(1, (int) Arr::get($item, 'pcs', 1)),
                'gross_weight' => $grossWeight,
                'stone_weight' => $stoneWeight,
                'net_weight' => $netWeight,
                'rate' => $rate,
                'making_charge' => $making,
                'stone_charge' => $stoneCharge,
                'wastage_percent' => $wastagePercent,
                'line_discount' => $lineDiscount,
                'line_total' => $lineTotal,
            ];
        }

        return $normalized;
    }

    private function normalizePayments(array $rawPayments, int $shopId): array
    {
        $normalized = [];
        $methodIds = collect($rawPayments)
            ->pluck('payment_method_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $methodsById = $methodIds->isEmpty()
            ? collect()
            : ShopPaymentMethod::query()
                ->where('shop_id', $shopId)
                ->whereIn('id', $methodIds)
                ->get(['id', 'type'])
                ->keyBy('id');

        $modeTypeMap = [
            ShopPaymentMethod::TYPE_UPI => ShopPaymentMethod::TYPE_UPI,
            ShopPaymentMethod::TYPE_BANK => ShopPaymentMethod::TYPE_BANK,
            ShopPaymentMethod::TYPE_WALLET => ShopPaymentMethod::TYPE_WALLET,
        ];

        foreach ($rawPayments as $index => $payment) {
            $amount = round(max(0, (float) Arr::get($payment, 'amount', 0)), 2);
            if ($amount <= 0) {
                continue;
            }

            $paymentMethodId = Arr::get($payment, 'payment_method_id');
            $paymentMethodId = $paymentMethodId !== null && $paymentMethodId !== '' ? (int) $paymentMethodId : null;
            $paymentMode = strtolower(trim((string) Arr::get($payment, 'payment_mode', 'cash')));
            if ($paymentMode === '') {
                $paymentMode = 'cash';
            }

            if ($paymentMethodId !== null) {
                $method = $methodsById->get($paymentMethodId);
                if (!$method) {
                    throw ValidationException::withMessages([
                        "payments.{$index}.payment_method_id" => 'Selected payment method is invalid for this shop.',
                    ]);
                }

                $expectedType = $modeTypeMap[$paymentMode] ?? null;
                if ($expectedType !== null && $method->type !== $expectedType) {
                    throw ValidationException::withMessages([
                        "payments.{$index}.payment_method_id" => "Payment method type must match mode \"{$paymentMode}\".",
                    ]);
                }
            }

            $normalized[] = [
                'payment_mode' => $paymentMode,
                'payment_method_id' => $paymentMethodId,
                'reference_no' => $this->nullableText(Arr::get($payment, 'reference_no')),
                'amount' => $amount,
                'paid_at' => $payment['paid_at'] ?? now(),
                'notes' => $this->nullableText(Arr::get($payment, 'notes')),
            ];
        }

        return $normalized;
    }

    private function discountAmount(float $subtotal, ?string $discountType, float $discountValue): float
    {
        if ($subtotal <= 0 || !$discountType || $discountValue <= 0) {
            return 0.0;
        }

        $discount = $discountType === 'percent'
            ? round($subtotal * ($discountValue / 100), 2)
            : round($discountValue, 2);

        return round(min($subtotal, max(0, $discount)), 2);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? $text : null;
    }

    private function shopSnapshot(Shop $shop): array
    {
        $billing = $shop->billingSettings;

        return [
            // Shop identity
            'name'                      => $shop->name,
            'phone'                     => $shop->phone,
            'shop_whatsapp'             => $shop->shop_whatsapp,
            'shop_email'                => $shop->shop_email,
            'established_year'          => $shop->established_year,
            'shop_registration_number'  => $shop->shop_registration_number,
            'address_line1'             => $shop->address_line1,
            'address_line2'             => $shop->address_line2,
            'city'                      => $shop->city,
            'state'                     => $shop->state,
            'state_code'                => $shop->state_code,
            'pincode'                   => $shop->pincode,
            'gst_number'                => $shop->gst_number,
            'owner_name'                => trim(($shop->owner_first_name ?? '') . ' ' . ($shop->owner_last_name ?? '')),
            // Billing / payment details
            'terms_and_conditions'      => $billing?->terms_and_conditions,
            'bank_details'              => $billing?->bank_details,
            'upi_id'                    => $billing?->upi_id,
        ];
    }
}
