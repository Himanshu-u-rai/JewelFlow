<?php

namespace App\Services;

use App\Models\ComplianceAlert;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceComplianceSnapshot;
use App\Models\ShopPreferences;
use App\Rules\PanFormatRule;
use App\Services\AccountingAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ComplianceService
{
    /**
     * Check whether the given invoice total requires compliance for this shop.
     *
     * Returns null  → compliance not enabled or threshold not crossed, no action needed.
     * Returns []    → compliance required and all fields are already present, proceed.
     * Returns [...]  → compliance required, these fields are missing, show modal.
     */
    public function checkRequired(int $shopId, int $customerId, float $invoiceTotal): ?array
    {
        $prefs = ShopPreferences::where('shop_id', $shopId)->first();

        if (!$prefs || !$prefs->compliance_enabled) {
            return null;
        }

        $threshold = (float) ($prefs->compliance_threshold ?? 200000);

        if ($invoiceTotal < $threshold) {
            return null;
        }

        $customer = Customer::find($customerId);
        if (!$customer) {
            return null;
        }

        $missing = [];

        if ($prefs->compliance_pan_mandatory && empty($customer->pan)) {
            $missing[] = 'pan';
        }
        if ($prefs->compliance_mobile_mandatory && empty($customer->mobile)) {
            $missing[] = 'mobile';
        }
        if ($prefs->compliance_address_mandatory && empty($customer->address)) {
            $missing[] = 'address';
        }

        return $missing;
    }

    /**
     * Validate and save compliance data to the customer profile.
     * Returns array of validation errors (empty = success).
     */
    public function saveComplianceData(Customer $customer, array $data, int $staffUserId): array
    {
        $shopId = $customer->shop_id;
        $prefs  = ShopPreferences::where('shop_id', $shopId)->first();

        // A mandatory field is only required from this form when the customer does
        // not already have it on their profile. The POS modal only collects the
        // fields it knows are missing, so re-validating fields the customer already
        // has would reject the submission with errors the modal never shows.
        $needsPan     = (bool) $prefs?->compliance_pan_mandatory && empty($customer->pan);
        $needsMobile  = (bool) $prefs?->compliance_mobile_mandatory && empty($customer->mobile);
        $needsAddress = (bool) $prefs?->compliance_address_mandatory && empty($customer->address);

        // Only validate fields that were actually submitted (non-empty) or that we
        // still need. Drop empty submitted values for fields the customer already has.
        $input = [
            'consent'   => $data['consent'] ?? null,
            'id_number' => $data['id_number'] ?? null,
        ];
        if ($needsPan || !empty($data['pan'])) {
            $input['pan'] = $data['pan'] ?? null;
        }
        if ($needsMobile || !empty($data['mobile'])) {
            $input['mobile'] = $data['mobile'] ?? null;
        }
        if ($needsAddress || !empty($data['address'])) {
            $input['address'] = $data['address'] ?? null;
        }

        $mobileFormat = function (string $attribute, mixed $value, \Closure $fail) {
            if (!preg_match('/^[6-9][0-9]{9}$/', (string) $value)) {
                $fail('Mobile number must be 10 digits starting with 6, 7, 8, or 9.');
            }
        };

        $rules = [
            'consent'   => 'required|accepted',
            'id_number' => ['nullable', 'string', 'max:20'],
        ];
        if (array_key_exists('pan', $input)) {
            $rules['pan'] = $needsPan
                ? ['required', 'string', 'max:10', new PanFormatRule()]
                : ['nullable', 'string', 'max:10', new PanFormatRule()];
        }
        if (array_key_exists('mobile', $input)) {
            $rules['mobile'] = $needsMobile
                ? ['required', 'digits:10', $mobileFormat]
                : ['nullable', 'digits:10', $mobileFormat];
        }
        if (array_key_exists('address', $input)) {
            $rules['address'] = $needsAddress
                ? ['required', 'string', 'max:1000']
                : ['nullable', 'string', 'max:1000'];
        }

        $validator = Validator::make($input, $rules, [
            'pan.required'     => 'PAN number is required for this transaction.',
            'pan.max'          => 'PAN number cannot exceed 10 characters.',
            'mobile.required'  => 'Mobile number is required for this transaction.',
            'mobile.digits'    => 'Mobile number must be exactly 10 digits.',
            'address.required' => 'Address is required for this transaction.',
            'consent.required' => 'Customer consent is required.',
            'consent.accepted' => 'Customer consent must be confirmed.',
        ]);

        if ($validator->fails()) {
            return $validator->errors()->toArray();
        }

        $update = [
            'consent_given_at' => now(),
            'consent_given_by' => $staffUserId,
        ];

        if (!empty($data['pan'])) {
            $update['pan'] = strtoupper(trim($data['pan']));
        }
        if (!empty($data['mobile'])) {
            $update['mobile'] = $data['mobile'];
        }
        if (!empty($data['address'])) {
            $update['address'] = $data['address'];
        }
        if (!empty($data['id_number'])) {
            $update['id_number'] = $data['id_number'];
        }

        // Mark compliance verified
        $update['compliance_verified_at'] = now();
        $update['compliance_verified_by'] = $staffUserId;

        $customer->forceFill($update)->save();

        AccountingAuditService::log([
            'shop_id'    => $customer->shop_id,
            'action'     => 'compliance_data_saved',
            'model_type' => 'customer',
            'model_id'   => $customer->id,
            'description' => "Compliance data saved for customer #{$customer->id} by user #{$staffUserId}",
            'data' => [
                'fields_updated' => array_keys(array_filter([
                    'pan'       => !empty($data['pan']),
                    'mobile'    => !empty($data['mobile']),
                    'address'   => !empty($data['address']),
                    'id_number' => !empty($data['id_number']),
                ])),
            ],
        ]);

        return [];
    }

    /**
     * Create the immutable compliance snapshot on the invoice.
     * Called inside InvoiceAccountingService::finalizeDraft() within the same transaction.
     */
    public function createSnapshot(Invoice $invoice, Customer $customer): InvoiceComplianceSnapshot
    {
        $shopId = (int) $invoice->shop_id;
        $prefs  = ShopPreferences::where('shop_id', $shopId)->first();

        $threshold        = (float) ($prefs?->compliance_threshold ?? 200000);
        $invoiceTotal     = (float) $invoice->total;
        $complianceNeeded = (bool) ($prefs?->compliance_enabled) && $invoiceTotal >= $threshold;

        $hasPan     = !empty($customer->pan);
        $hasMobile  = !empty($customer->mobile);
        $hasAddress = !empty($customer->address);

        $complianceMet = !$complianceNeeded || ($hasPan && $hasMobile && $hasAddress);

        // Count today's invoices for this customer, excluding the current invoice
        $sameDayBase = Invoice::where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->where('status', Invoice::STATUS_FINALIZED)
            ->whereDate('finalized_at', today())
            ->where('id', '!=', $invoice->id);

        $sameDayCount = $sameDayBase->count();
        $sameDayTotal = (float) $sameDayBase->sum('total');

        return InvoiceComplianceSnapshot::create([
            'shop_id'                => $shopId,
            'invoice_id'             => $invoice->id,
            'customer_id'            => $customer->id,
            'snapshot_customer_name' => $customer->name,
            'snapshot_pan'           => $customer->pan,
            'snapshot_id_number'     => $customer->id_number,
            'snapshot_mobile'        => $customer->mobile,
            'snapshot_address'       => $customer->address,
            'invoice_total'          => $invoiceTotal,
            'threshold_at_sale'      => $threshold,
            'compliance_required'    => $complianceNeeded,
            'compliance_met'         => $complianceMet,
            'consent_given'          => !empty($customer->consent_given_at),
            'completed_by_user_id'   => auth()->id(),
            'completed_at'           => $complianceNeeded ? now() : null,
            'same_day_invoice_count' => $sameDayCount + 1,
            'same_day_total'         => $sameDayTotal + $invoiceTotal,
            'split_alert_raised'     => false,
        ]);
    }

    /**
     * Detect potential split transactions. Never blocks the sale — logs alert only.
     * Shows warning on the next POS load for this customer.
     */
    public function detectSplitTransaction(Invoice $invoice, Customer $customer): void
    {
        $shopId    = (int) $invoice->shop_id;
        $prefs     = ShopPreferences::where('shop_id', $shopId)->first();

        if (!$prefs?->compliance_enabled) {
            return;
        }

        $threshold = (float) ($prefs->compliance_threshold ?? 200000);

        // Get today's total INCLUDING this invoice
        $sameDayTotal = (float) Invoice::where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->where('status', Invoice::STATUS_FINALIZED)
            ->whereDate('finalized_at', today())
            ->sum('total');

        $sameDayCount = Invoice::where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->where('status', Invoice::STATUS_FINALIZED)
            ->whereDate('finalized_at', today())
            ->count();

        // Raise alert if combined total crosses threshold with more than one invoice
        if ($sameDayCount >= 2 && $sameDayTotal >= $threshold) {
            // Only raise once per day per customer (check for existing unresolved alert)
            $exists = ComplianceAlert::where('shop_id', $shopId)
                ->where('customer_id', $customer->id)
                ->where('alert_type', ComplianceAlert::TYPE_SPLIT_TRANSACTION)
                ->whereRaw('resolved IS FALSE')
                ->whereDate('created_at', today())
                ->exists();

            if (!$exists) {
                ComplianceAlert::create([
                    'shop_id'     => $shopId,
                    'customer_id' => $customer->id,
                    'invoice_id'  => $invoice->id,
                    'alert_type'  => ComplianceAlert::TYPE_SPLIT_TRANSACTION,
                    'alert_data'  => [
                        'same_day_invoice_count' => $sameDayCount,
                        'same_day_total'         => $sameDayTotal,
                        'threshold'              => $threshold,
                        'trigger_invoice_id'     => $invoice->id,
                    ],
                ]);

                // Mark snapshot as having raised a split alert.
                // Use DB::raw for the boolean — native pgsql prepared statements
                // reject a bound PHP bool against a boolean column.
                DB::table('invoice_compliance_snapshots')
                    ->where('invoice_id', $invoice->id)
                    ->update(['split_alert_raised' => DB::raw('true'), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Get open split transaction alert total for a customer today (for POS warning banner).
     * Returns null if no alert, or the combined same-day total if alert exists.
     */
    public function getSplitAlertTotal(int $shopId, int $customerId): ?float
    {
        $alert = ComplianceAlert::where('shop_id', $shopId)
            ->where('customer_id', $customerId)
            ->where('alert_type', ComplianceAlert::TYPE_SPLIT_TRANSACTION)
            ->whereRaw('resolved IS FALSE')
            ->whereDate('created_at', today())
            ->latest()
            ->first();

        if (!$alert) {
            return null;
        }

        return (float) ($alert->alert_data['same_day_total'] ?? 0);
    }

    public static function validatePan(string $pan): bool
    {
        return (bool) preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', strtoupper(trim($pan)));
    }

    public static function validateMobile(string $mobile): bool
    {
        return (bool) preg_match('/^[6-9][0-9]{9}$/', $mobile);
    }
}
