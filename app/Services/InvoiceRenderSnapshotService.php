<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSnapshot;
use App\Models\InvoiceRenderSnapshot;
use App\Models\Repair;

class InvoiceRenderSnapshotService
{
    public function captureForInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing([
            'shop.billingSettings',
            'customer',
            'complianceSnapshot',
            'items.item',
        ]);

        InvoiceRenderSnapshot::query()->firstOrCreate(
            ['invoice_id' => (int) $invoice->id],
            [
                'shop_id' => (int) $invoice->shop_id,
                'snapshot' => $this->buildInvoiceSnapshot($invoice),
            ]
        );

        foreach ($invoice->items as $line) {
            $this->captureForLine($invoice, $line);
        }
    }

    public function captureForLine(Invoice $invoice, InvoiceItem $line): void
    {
        $line->loadMissing('item');

        InvoiceItemSnapshot::query()->firstOrCreate(
            ['invoice_item_id' => (int) $line->id],
            [
                'shop_id' => (int) $invoice->shop_id,
                'invoice_id' => (int) $invoice->id,
                'snapshot' => $this->buildLineSnapshot($line),
            ]
        );
    }

    public function buildInvoiceSnapshot(Invoice $invoice): array
    {
        $shop = $invoice->shop;
        $billing = $shop?->billingSettings;
        $customer = $invoice->customer;
        $compliance = $invoice->complianceSnapshot;
        $repair = Repair::query()
            ->where('shop_id', (int) $invoice->shop_id)
            ->where('invoice_id', (int) $invoice->id)
            ->first();

        return [
            'schema_version' => 1,
            'captured_at' => now()->toIso8601String(),
            'invoice' => [
                'invoice_number' => (string) $invoice->invoice_number,
                'status' => (string) $invoice->status,
                'created_at' => optional($invoice->created_at)?->toIso8601String(),
                'finalized_at' => optional($invoice->finalized_at)?->toIso8601String(),
                'subtotal' => (float) ($invoice->subtotal ?? 0),
                'gst' => (float) ($invoice->gst ?? 0),
                'gst_rate' => (float) ($invoice->gst_rate ?? 0),
                'wastage_charge' => (float) ($invoice->wastage_charge ?? 0),
                'discount' => (float) ($invoice->discount ?? 0),
                'round_off' => (float) ($invoice->round_off ?? 0),
                'total' => (float) ($invoice->total ?? 0),
                'cgst_amount' => (float) ($invoice->cgst_amount ?? 0),
                'sgst_amount' => (float) ($invoice->sgst_amount ?? 0),
                'igst_amount' => (float) ($invoice->igst_amount ?? 0),
            ],
            'shop' => [
                'name' => $shop?->name,
                'phone' => $shop?->phone,
                'shop_whatsapp' => $shop?->shop_whatsapp,
                'shop_email' => $shop?->shop_email,
                'address' => $shop?->address,
                'address_line1' => $shop?->address_line1,
                'address_line2' => $shop?->address_line2,
                'city' => $shop?->city,
                'state' => $shop?->state,
                'state_code' => $shop?->state_code,
                'pincode' => $shop?->pincode,
                'gst_number' => $shop?->gst_number,
                'shop_registration_number' => $shop?->shop_registration_number,
            ],
            'billing' => [
                'theme_color' => $billing?->theme_color,
                'font_size' => $billing?->font_size,
                'paper_size' => $billing?->paper_size,
                'shop_subtitle' => $billing?->shop_subtitle,
                'custom_tagline' => $billing?->custom_tagline,
                'invoice_copy_label' => $billing?->invoice_copy_label,
                'copy_count' => (int) ($billing?->copy_count ?? 1),
                'show_huid' => (bool) ($billing?->show_huid ?? true),
                'show_stone_columns' => (bool) ($billing?->show_stone_columns ?? true),
                'show_purity' => (bool) ($billing?->show_purity ?? true),
                'show_gstin' => (bool) ($billing?->show_gstin ?? true),
                'show_customer_address' => (bool) ($billing?->show_customer_address ?? true),
                'show_customer_id_pan' => (bool) ($billing?->show_customer_id_pan ?? true),
                'show_bis_logo' => (bool) ($billing?->show_bis_logo ?? false),
                'show_digital_signature' => (bool) ($billing?->show_digital_signature ?? false),
                'digital_signature_path' => $billing?->digital_signature_path,
                'second_signature_label' => $billing?->second_signature_label,
                'igst_mode' => (bool) ($billing?->igst_mode ?? false),
                'terms_and_conditions' => $billing?->terms_and_conditions,
                'upi_id' => $billing?->upi_id,
                'bank_name' => $billing?->bank_name,
                'bank_account_holder' => $billing?->bank_account_holder,
                'bank_account_number' => $billing?->bank_account_number,
                'bank_ifsc' => $billing?->bank_ifsc,
                'bank_account_type' => $billing?->bank_account_type,
                'bank_branch' => $billing?->bank_branch,
                'bank_details' => $billing?->bank_details,
            ],
            'customer' => [
                'name' => $customer?->name,
                'mobile' => $customer?->mobile,
                'address' => $compliance?->snapshot_address ?: $customer?->address,
                'id_number' => $compliance?->snapshot_id_number ?: $customer?->id_number,
                'pan' => $compliance?->snapshot_pan ?: $customer?->pan,
            ],
            'tax_display' => [
                'mode' => ((float) ($invoice->igst_amount ?? 0) > 0.0001) ? 'igst' : 'cgst_sgst',
                'gst_rate' => (float) ($invoice->gst_rate ?? 0),
                'cgst_amount' => (float) ($invoice->cgst_amount ?? 0),
                'sgst_amount' => (float) ($invoice->sgst_amount ?? 0),
                'igst_amount' => (float) ($invoice->igst_amount ?? 0),
            ],
            'repair' => $repair ? [
                'item_description' => $repair->item_description,
                'gross_weight' => (float) ($repair->gross_weight ?? 0),
                'purity' => (float) ($repair->purity ?? 0),
            ] : null,
        ];
    }

    public function buildLineSnapshot(InvoiceItem $line): array
    {
        $item = $line->item;
        $weight = (float) ($line->weight ?? 0);
        $grossWeight = (float) ($item?->gross_weight ?? $weight);
        $stoneWeight = (float) ($item?->stone_weight ?? 0);
        $netWeight = (float) ($item?->net_metal_weight ?? $weight);

        return [
            'schema_version' => 1,
            'captured_at' => now()->toIso8601String(),
            'invoice_item_id' => (int) $line->id,
            'item_id' => $line->item_id ? (int) $line->item_id : null,
            'barcode' => $item?->barcode,
            'design' => $item?->design,
            'category' => $item?->category,
            'huid' => $item?->huid,
            'metal_type' => $item?->metal_type,
            'purity' => $item?->purity !== null ? (float) $item->purity : null,
            'gross_weight' => $grossWeight,
            'stone_weight' => $stoneWeight,
            'net_weight' => $netWeight,
            'hsn_code' => $line->hsn_code,
        ];
    }
}

