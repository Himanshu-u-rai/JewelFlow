<?php

namespace App\Support\Historical;

/**
 * The canonical target fields a source column can be mapped onto, and the header
 * aliases that SUGGEST a mapping.
 *
 * Suggestion is not acceptance. Nothing in this module maps a column because a
 * table said so; the mapping screen prefills from here and the operator confirms
 * every field before a single row is normalized. The alias table exists to stop
 * a jeweller re-typing 40 mappings, not to make decisions for them.
 */
final class HistoricalFields
{
    /** Without these two a historical bill is not a record of anything. */
    public const REQUIRED = ['document_date', 'grand_total'];

    /**
     * Header-level canonical fields. `label => group` drives the mapping screen's
     * grouping and nothing else.
     */
    public const HEADER = [
        'original_document_number' => 'Document identity',
        'document_series'          => 'Document identity',
        'document_date'            => 'Document identity',
        'source_reference'         => 'Document identity',

        'customer_name'            => 'Customer snapshot',
        'customer_mobile'          => 'Customer snapshot',
        'customer_gstin'           => 'Customer snapshot',
        'customer_address'         => 'Customer snapshot',
        'place_of_supply'          => 'Customer snapshot',

        'taxable_amount'           => 'Amounts',
        'tax_total'                => 'Amounts',
        'cgst'                     => 'Amounts',
        'sgst'                     => 'Amounts',
        'igst'                     => 'Amounts',
        'cess'                     => 'Amounts',
        'discount'                 => 'Amounts',
        'rounding'                 => 'Amounts',
        'metal_value'              => 'Amounts',
        'stone_value'              => 'Amounts',
        'grand_total'              => 'Amounts',
        'paid_amount'              => 'Amounts',
        'outstanding_amount'       => 'Amounts',

        'making_label'             => 'Making / labour',
        'making_value'             => 'Making / labour',
    ];

    /** Line-level canonical fields. Unused by Layout A. */
    public const LINE = [
        'line_item_name'     => 'Line',
        'line_sku'           => 'Line',
        'line_hsn'           => 'Line',
        'line_quantity'      => 'Line',
        'line_purity'        => 'Line',
        'line_gross_weight'  => 'Line',
        'line_net_weight'    => 'Line',
        'line_stone_weight'  => 'Line',
        'line_metal_value'   => 'Line',
        'line_stone_value'   => 'Line',
        'line_making_label'  => 'Line',
        'line_making_value'  => 'Line',
        'line_rate'          => 'Line',
        'line_total'         => 'Line',
    ];

    /**
     * Layout C only: the column on BOTH sheets that ties a detail row to its
     * header row. Explicitly mapped — never inferred from row order, because a
     * detail sheet sorted differently from its header sheet silently attaches
     * every line to the wrong bill.
     */
    public const JOIN_KEY        = 'join_key';
    public const DETAIL_JOIN_KEY = 'detail_join_key';

    /**
     * Fields that hold money. Used to decide when an UNMAPPED source column that
     * looks monetary has to be explicitly ignored rather than quietly dropped.
     */
    public const MONETARY = [
        'taxable_amount', 'tax_total', 'cgst', 'sgst', 'igst', 'cess',
        'discount', 'rounding', 'metal_value', 'stone_value', 'grand_total',
        'paid_amount', 'outstanding_amount', 'making_value',
        'line_metal_value', 'line_stone_value', 'line_making_value',
        'line_rate', 'line_total',
    ];

    /**
     * Folded header alias => canonical field. Keys are the output of
     * HistoricalDocumentIdentity::comparableLabel(), so punctuation, spacing,
     * case and full-width forms are already handled and do not need variants.
     */
    private const ALIASES = [
        // --- invoice identity
        'INVOICENO'      => 'original_document_number',
        'INVOICENUMBER'  => 'original_document_number',
        'INVNO'          => 'original_document_number',
        'BILLNO'         => 'original_document_number',
        'BILLNUMBER'     => 'original_document_number',
        'VOUCHERNO'      => 'original_document_number',
        'VOUCHERNUMBER'  => 'original_document_number',
        'DOCUMENTNO'     => 'original_document_number',
        'DOCNO'          => 'original_document_number',
        'SERIES'         => 'document_series',
        'PREFIX'         => 'document_series',
        'INVOICEDATE'    => 'document_date',
        'BILLDATE'       => 'document_date',
        'DATE'           => 'document_date',
        'VOUCHERDATE'    => 'document_date',

        // --- customer
        'CUSTOMER'       => 'customer_name',
        'CUSTOMERNAME'   => 'customer_name',
        'PARTY'          => 'customer_name',
        'PARTYNAME'      => 'customer_name',
        'BUYER'          => 'customer_name',
        'BUYERNAME'      => 'customer_name',
        'MOBILE'         => 'customer_mobile',
        'MOBILENO'       => 'customer_mobile',
        'PHONE'          => 'customer_mobile',
        'CONTACT'        => 'customer_mobile',
        'GSTIN'          => 'customer_gstin',
        'GSTNO'          => 'customer_gstin',
        'ADDRESS'        => 'customer_address',
        'PLACEOFSUPPLY'  => 'place_of_supply',
        'STATE'          => 'place_of_supply',

        // --- tax
        'GST'            => 'tax_total',
        'TAX'            => 'tax_total',
        'TAXAMOUNT'      => 'tax_total',
        'GSTAMOUNT'      => 'tax_total',
        'TOTALTAX'       => 'tax_total',
        'CGST'           => 'cgst',
        'CGSTAMOUNT'     => 'cgst',
        'SGST'           => 'sgst',
        'SGSTAMOUNT'     => 'sgst',
        'IGST'           => 'igst',
        'IGSTAMOUNT'     => 'igst',
        'CESS'           => 'cess',

        // --- amounts
        'TAXABLE'        => 'taxable_amount',
        'TAXABLEVALUE'   => 'taxable_amount',
        'TAXABLEAMOUNT'  => 'taxable_amount',
        'DISCOUNT'       => 'discount',
        'DISC'           => 'discount',
        'ROUNDOFF'       => 'rounding',
        'ROUNDING'       => 'rounding',
        'GRANDTOTAL'     => 'grand_total',
        'NETAMOUNT'      => 'grand_total',
        'INVOICETOTAL'   => 'grand_total',
        'BILLAMOUNT'     => 'grand_total',
        'TOTALAMOUNT'    => 'grand_total',
        'PAID'           => 'paid_amount',
        'PAIDAMOUNT'     => 'paid_amount',
        'RECEIVED'       => 'paid_amount',
        'BALANCE'        => 'outstanding_amount',
        'OUTSTANDING'    => 'outstanding_amount',
        'DUE'            => 'outstanding_amount',

        // --- making / labour. Suggests the COLUMN, never the meaning: the
        //     category and basis are separate confirmations (Phase 11).
        'MAKINGCHARGES'  => 'making_value',
        'MAKINGCHARGE'   => 'making_value',
        'MAKING'         => 'making_value',
        'LABOUR'         => 'making_value',
        'LABOURCHARGES'  => 'making_value',
        'LABOR'          => 'making_value',
        'MC'             => 'making_value',
        'VA'             => 'making_value',
        'JOBWORK'        => 'making_value',
        'WASTAGE'        => 'making_value',

        // --- line
        'ITEM'           => 'line_item_name',
        'ITEMNAME'       => 'line_item_name',
        'DESCRIPTION'    => 'line_item_name',
        'PARTICULARS'    => 'line_item_name',
        'SKU'            => 'line_sku',
        'ITEMCODE'       => 'line_sku',
        'CODE'           => 'line_sku',
        'HSN'            => 'line_hsn',
        'HSNCODE'        => 'line_hsn',
        'QTY'            => 'line_quantity',
        'QUANTITY'       => 'line_quantity',
        'PCS'            => 'line_quantity',
        'PURITY'         => 'line_purity',
        'KARAT'          => 'line_purity',
        'CARAT'          => 'line_purity',
        'GROSSWT'        => 'line_gross_weight',
        'GROSSWEIGHT'    => 'line_gross_weight',
        'GW'             => 'line_gross_weight',
        'NETWT'          => 'line_net_weight',
        'NETWEIGHT'      => 'line_net_weight',
        'NW'             => 'line_net_weight',
        'WEIGHT'         => 'line_net_weight',
        'WT'             => 'line_net_weight',
        'STONEWT'        => 'line_stone_weight',
        'STONEWEIGHT'    => 'line_stone_weight',
        'RATE'           => 'line_rate',
        'LINETOTAL'      => 'line_total',
        'AMOUNT'         => 'line_total',
    ];

    public static function all(): array
    {
        return self::HEADER + self::LINE;
    }

    public static function isKnown(string $field): bool
    {
        return array_key_exists($field, self::all())
            || in_array($field, [self::JOIN_KEY, self::DETAIL_JOIN_KEY], true);
    }

    public static function isMonetary(string $field): bool
    {
        return in_array($field, self::MONETARY, true);
    }

    /** The suggested canonical field for a raw source header, or null. */
    public static function suggest(string $header): ?string
    {
        return self::ALIASES[HistoricalDocumentIdentity::comparableLabel($header)] ?? null;
    }

    /**
     * Suggestions for a whole header row, first-wins.
     *
     * First-wins matters: a sheet with both `Amount` and `Line Total` must not
     * have the second silently overwrite the first's claim on `line_total`. The
     * loser stays unmapped and reaches the operator as a decision, which is the
     * entire point of the "no silently discarded column" rule.
     *
     * @param  array<int, string>  $headers
     * @return array{mapping: array<string, string>, unmapped: array<int, string>}
     */
    public static function suggestAll(array $headers): array
    {
        $mapping  = [];
        $unmapped = [];

        foreach ($headers as $header) {
            $header = (string) $header;

            if (trim($header) === '') {
                continue;
            }

            $field = self::suggest($header);

            if ($field === null || isset($mapping[$field])) {
                $unmapped[] = $header;

                continue;
            }

            $mapping[$field] = $header;
        }

        return ['mapping' => $mapping, 'unmapped' => $unmapped];
    }
}
