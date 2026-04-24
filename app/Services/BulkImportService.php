<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Item;
use App\Models\MetalLot;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SubCategory;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

class BulkImportService
{
    public function __construct(
        private ItemManufacturingService $manufacturingService,
        private ShopPricingService $pricing
    )
    {
    }

    public function createPreview(int $shopId, int $userId, string $type, UploadedFile $file): Import
    {
        $this->assertShopWritable($shopId);
        SubscriptionGateService::assertShopWritable($shopId);
        if (!in_array($type, [Import::TYPE_CATALOG, Import::TYPE_MANUFACTURE, Import::TYPE_STOCK], true)) {
            throw new LogicException('Invalid import type.');
        }
        $this->assertImportTypeAllowed($shopId, $type);
        $this->assertFinancialLock($shopId);
        if ($type === Import::TYPE_STOCK) {
            $this->assertRetailerStockPricingReady($shopId);
        }

        $storedPath = $file->store('imports');
        $rows = $this->readCsvRows($storedPath);

        if (empty($rows)) {
            throw new LogicException('CSV is empty.');
        }

        [$headers, $dataRows] = $rows;
        $required = match ($type) {
            Import::TYPE_CATALOG => ['design_code', 'name', 'category', 'sub_category', 'default_purity', 'approx_weight', 'default_making', 'stone_type', 'notes'],
            Import::TYPE_MANUFACTURE => ['barcode', 'design_code', 'lot_number', 'gross_weight', 'stone_weight', 'purity', 'wastage_percent', 'making_charge', 'stone_charge'],
            Import::TYPE_STOCK => ['barcode', 'category', 'sub_category', 'metal_type', 'gross_weight', 'stone_weight', 'purity', 'making_charge', 'stone_charge', 'huid'],
            default => throw new LogicException('Unknown import type.'),
        };

        $missing = array_values(array_diff($required, $headers));
        if (!empty($missing)) {
            throw new LogicException('Missing required columns: ' . implode(', ', $missing));
        }

        $import = Import::create([
            'shop_id' => $shopId,
            'type' => $type,
            'status' => Import::STATUS_PREVIEW,
            'created_by' => $userId,
            'file_path' => $storedPath,
        ]);

        $validRows = 0;
        $invalidRows = 0;
        $lotDeductions = [];
        $projectedLotBalance = [];
        $seenBarcodes = [];
        $seenDesignCodes = [];

        foreach ($dataRows as $line => $payload) {
            $rowNumber = $line + 2;
            $validation = match ($type) {
                Import::TYPE_CATALOG => $this->validateCatalogRow($shopId, $payload, $seenDesignCodes),
                Import::TYPE_MANUFACTURE => $this->validateManufactureRow($shopId, $payload, $seenBarcodes),
                Import::TYPE_STOCK => $this->validateStockRow($shopId, $payload, $seenBarcodes),
            };

            $status = $validation['valid'] ? 'valid' : 'invalid';
            if ($validation['valid']) {
                if ($type === Import::TYPE_MANUFACTURE) {
                    $lotId = (int) $validation['normalized']['lot_id'];
                    $requiredFine = (float) $validation['computed']['total_fine_needed'];

                    if (!array_key_exists($lotId, $projectedLotBalance)) {
                        $projectedLotBalance[$lotId] = (float) MetalLot::where('shop_id', $shopId)
                            ->where('id', $lotId)
                            ->value('fine_weight_remaining');
                    }

                    if ($projectedLotBalance[$lotId] < $requiredFine) {
                        $validation = [
                            'valid' => false,
                            'normalized' => $validation['normalized'],
                            'computed' => $validation['computed'],
                            'error' => 'Not enough gold in this lot after counting earlier rows in this file.',
                        ];
                        $status = 'invalid';
                    } else {
                        $projectedLotBalance[$lotId] = round($projectedLotBalance[$lotId] - $requiredFine, 6);
                    }
                }
            }

            if ($validation['valid']) {
                $validRows++;
                if ($type === Import::TYPE_MANUFACTURE) {
                    $lotId = (int) $validation['normalized']['lot_id'];
                    $lotDeductions[$lotId] = ($lotDeductions[$lotId] ?? 0) + (float) $validation['computed']['total_fine_needed'];
                }
            } else {
                $invalidRows++;
            }

            ImportRow::create([
                'import_id' => $import->id,
                'shop_id' => $shopId,
                'row_number' => $rowNumber,
                'status' => $status,
                'error_message' => $validation['error'] ?? null,
                'payload' => $validation['normalized'] ?? $payload,
                'computed' => $validation['computed'] ?? null,
            ]);
        }

        $previewSummary = [
            'required_columns' => $required,
            'lot_summary' => $type === Import::TYPE_MANUFACTURE ? $this->buildLotSummary($shopId, $lotDeductions) : [],
            'vendor_summary' => $type === Import::TYPE_STOCK ? $this->buildVendorSummary($shopId, $dataRows) : [],
        ];

        $import->update([
            'total_rows' => count($dataRows),
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'preview_summary' => $previewSummary,
        ]);

        AccountingAuditService::log([
            'shop_id' => $shopId,
            'user_id' => $userId,
            'action' => 'import.previewed',
            'model_type' => 'import',
            'model_id' => $import->id,
            'description' => strtoupper($type) . ' import preview created',
            'data' => [
                'type' => $type,
                'total_rows' => count($dataRows),
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
            ],
            'target' => ['type' => 'import', 'id' => $import->id],
        ]);

        return $import->fresh();
    }

    public function execute(Import $import, string $mode): Import
    {
        if (!in_array($mode, [Import::MODE_STRICT, Import::MODE_ROW], true)) {
            throw new LogicException('Invalid import mode.');
        }

        $import = Import::withoutTenant()->findOrFail($import->id);
        if (in_array($import->status, [Import::STATUS_RUNNING, Import::STATUS_COMPLETED, Import::STATUS_FAILED], true)) {
            AccountingAuditService::log([
                'shop_id' => $import->shop_id,
                'user_id' => $import->created_by,
                'action' => 'bulk_import_execution_skipped',
                'model_type' => 'import',
                'model_id' => $import->id,
                'description' => 'Import execution skipped due to terminal/in-progress status',
                'data' => [
                    'status' => $import->status,
                    'requested_mode' => $mode,
                ],
                'target' => ['type' => 'import', 'id' => $import->id],
            ]);

            return $import;
        }

        $this->assertImportTypeAllowed((int) $import->shop_id, (string) $import->type);
        $this->assertShopWritable((int) $import->shop_id);
        SubscriptionGateService::assertShopWritable((int) $import->shop_id);
        $this->assertFinancialLock((int) $import->shop_id);

        // Atomic claim: only one worker can transition preview/queued -> running.
        $claimed = Import::withoutTenant()
            ->where('id', $import->id)
            ->whereIn('status', [Import::STATUS_PREVIEW, Import::STATUS_QUEUED])
            ->update([
                'status' => Import::STATUS_RUNNING,
                'mode' => $mode,
                'started_at' => now(),
                'processed_rows' => 0,
            ]);

        if ($claimed === 0) {
            $fresh = Import::withoutTenant()->find($import->id);
            AccountingAuditService::log([
                'shop_id' => $import->shop_id,
                'user_id' => $import->created_by,
                'action' => 'import.claim_rejected',
                'model_type' => 'import',
                'model_id' => $import->id,
                'description' => 'Import execution claim rejected (already claimed or already processed)',
                'data' => [
                    'requested_mode' => $mode,
                    'current_status' => $fresh?->status,
                ],
                'target' => ['type' => 'import', 'id' => $import->id],
            ]);

            return $fresh ?? $import;
        }

        $import = Import::withoutTenant()->findOrFail($import->id);

        $rows = ImportRow::where('import_id', $import->id)
            ->where('status', 'valid')
            ->orderBy('row_number')
            ->get();

        $processed = 0;
        $created = 0;
        $failed = 0;
        $deductedFine = 0.0;
        $affectedLots = [];
        $errors = [];

        try {
            if ($mode === Import::MODE_STRICT) {
                DB::transaction(function () use ($import, $rows, &$processed, &$created, &$deductedFine, &$affectedLots) {
                    foreach ($rows as $row) {
                        $result = $this->processValidRow($import, $row);
                        $processed++;
                        $created += $result['created'];
                        $deductedFine += $result['deducted_fine'];
                        $affectedLots = array_merge($affectedLots, $result['affected_lots']);
                    }
                });
            } else {
                foreach (array_chunk($rows->all(), 500) as $chunk) {
                    foreach ($chunk as $row) {
                        try {
                            DB::transaction(function () use ($import, $row, &$processed, &$created, &$deductedFine, &$affectedLots) {
                                $result = $this->processValidRow($import, $row);
                                $processed++;
                                $created += $result['created'];
                                $deductedFine += $result['deducted_fine'];
                                $affectedLots = array_merge($affectedLots, $result['affected_lots']);
                            });
                        } catch (\Throwable $e) {
                            $failed++;
                            $friendlyError = $this->humanizeError($e->getMessage());
                            $errors[] = ['row_number' => $row->row_number, 'error' => $friendlyError];
                            $row->update([
                                'status' => 'failed',
                                'error_message' => $friendlyError,
                            ]);
                        }
                    }
                    $import->update(['processed_rows' => $processed + $failed]);
                }
            }

            $executionSummary = [
                'mode' => $mode,
                'processed_rows' => $processed + $failed,
                'created_rows' => $created,
                'failed_rows' => $failed,
                'total_fine_deducted' => round($deductedFine, 6),
                'affected_lots' => array_values(array_unique($affectedLots)),
            ];

            $errorPath = null;
            if (!empty($errors)) {
                $errorPath = $this->writeErrorCsv($import, $errors);
            }

            $import->update([
                'status' => Import::STATUS_COMPLETED,
                'processed_rows' => $processed + $failed,
                'execution_summary' => $executionSummary,
                'error_file_path' => $errorPath,
                'finished_at' => now(),
            ]);

            AccountingAuditService::log([
                'shop_id' => $import->shop_id,
                'user_id' => $import->created_by,
                'action' => 'import.executed',
                'model_type' => 'import',
                'model_id' => $import->id,
                'description' => strtoupper($import->type) . ' import completed',
                'data' => $executionSummary,
                'target' => ['type' => 'import', 'id' => $import->id],
            ]);
        } catch (\Throwable $e) {
            $friendlyError = $this->humanizeError($e->getMessage());
            if ($mode === Import::MODE_STRICT) {
                ImportRow::where('import_id', $import->id)
                    ->where('status', 'valid')
                    ->update([
                        'status' => 'failed',
                        'error_message' => $friendlyError,
                    ]);
            }

            $import->update([
                'status' => Import::STATUS_FAILED,
                'finished_at' => now(),
                'execution_summary' => [
                    'mode' => $mode,
                    'error' => $friendlyError,
                    'processed_rows' => $processed,
                ],
                'error_file_path' => $this->writeErrorCsv($import, [
                    ['row_number' => 0, 'error' => $friendlyError],
                ]),
            ]);

            AccountingAuditService::log([
                'shop_id' => $import->shop_id,
                'user_id' => $import->created_by,
                'action' => 'import.failed',
                'model_type' => 'import',
                'model_id' => $import->id,
                'description' => strtoupper($import->type) . ' import failed',
                'data' => ['error' => $friendlyError],
                'target' => ['type' => 'import', 'id' => $import->id],
            ]);

            throw $e;
        }

        return $import->fresh();
    }

    private function processValidRow(Import $import, ImportRow $row): array
    {
        if ($import->type === Import::TYPE_CATALOG) {
            $this->upsertCatalogProduct((int) $import->shop_id, $row->payload);
            $row->update(['status' => 'imported', 'error_message' => null]);

            return ['created' => 1, 'deducted_fine' => 0.0, 'affected_lots' => []];
        }

        if ($import->type === Import::TYPE_STOCK) {
            $this->createStockItem((int) $import->shop_id, $row->payload);
            $row->update(['status' => 'imported', 'error_message' => null]);

            return ['created' => 1, 'deducted_fine' => 0.0, 'affected_lots' => []];
        }

        $payload = $row->payload;
        [$category, $subCategory] = $this->resolveMetadataForExecution(
            (int) $import->shop_id,
            (string) ($payload['category'] ?? ''),
            (string) ($payload['sub_category'] ?? 'General')
        );
        $productId = null;
        if (!empty($payload['design_code'])) {
            $productId = Product::where('shop_id', $import->shop_id)
                ->where('design_code', $payload['design_code'])
                ->value('id');
        }

        $item = $this->manufacturingService->manufacture((int) $import->shop_id, (int) $import->created_by, [
            'barcode' => $payload['barcode'],
            'product_id' => $productId,
            'design' => $payload['design'] ?? ($payload['design_code'] ?? null),
            'category' => $category->name,
            'sub_category' => $subCategory->name,
            'gross_weight' => (float) $payload['gross_weight'],
            'stone_weight' => (float) $payload['stone_weight'],
            'purity' => (float) $payload['purity'],
            'wastage_percent' => (float) $payload['wastage_percent'],
            'making_charges' => (float) $payload['making_charge'],
            'stone_charges' => (float) $payload['stone_charge'],
            'metal_lot_id' => (int) $payload['lot_id'],
            'image' => null,
        ]);

        $row->update(['status' => 'imported', 'error_message' => null]);

        return [
            'created' => $item ? 1 : 0,
            'deducted_fine' => (float) data_get($row->computed, 'total_fine_needed', 0),
            'affected_lots' => [(int) $payload['lot_id']],
        ];
    }

    private function validateCatalogRow(int $shopId, array $payload, array &$seenDesignCodes): array
    {
        $normalized = [
            'design_code' => trim((string) ($payload['design_code'] ?? '')),
            'name' => trim((string) ($payload['name'] ?? '')),
            'category' => trim((string) ($payload['category'] ?? '')),
            'sub_category' => trim((string) ($payload['sub_category'] ?? '')),
            'default_purity' => $payload['default_purity'] ?? null,
            'approx_weight' => $payload['approx_weight'] ?? null,
            'default_making' => $payload['default_making'] ?? null,
            'stone_type' => trim((string) ($payload['stone_type'] ?? '')),
            'notes' => trim((string) ($payload['notes'] ?? '')),
        ];

        if ($normalized['design_code'] === '' || $normalized['name'] === '') {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Design code and product name are required. Please fill both.'];
        }

        if ($normalized['category'] === '' || $normalized['sub_category'] === '') {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Category and sub-category are required.'];
        }

        if (isset($seenDesignCodes[$normalized['design_code']])) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Same design code appears more than once in this file.'];
        }
        $seenDesignCodes[$normalized['design_code']] = true;

        $metadata = $this->resolveMetadataForPreview($shopId, $normalized['category'], $normalized['sub_category']);

        return [
            'valid' => true,
            'normalized' => $normalized,
            'computed' => [
                'will_create_category' => $metadata['will_create_category'],
                'will_create_sub_category' => $metadata['will_create_sub_category'],
            ],
        ];
    }

    private function validateManufactureRow(int $shopId, array $payload, array &$seenBarcodes): array
    {
        $normalized = [
            'barcode' => trim((string) ($payload['barcode'] ?? '')),
            'design_code' => trim((string) ($payload['design_code'] ?? '')),
            'lot_number' => (int) ($payload['lot_number'] ?? $payload['lot_id'] ?? 0),
            'gross_weight' => (float) ($payload['gross_weight'] ?? 0),
            'stone_weight' => (float) ($payload['stone_weight'] ?? 0),
            'purity' => (float) ($payload['purity'] ?? 0),
            'wastage_percent' => (float) ($payload['wastage_percent'] ?? 0),
            'making_charge' => (float) ($payload['making_charge'] ?? 0),
            'stone_charge' => (float) ($payload['stone_charge'] ?? 0),
        ];

        if ($normalized['barcode'] === '' || $normalized['lot_number'] <= 0) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Barcode and Lot Number are required.'];
        }

        if (isset($seenBarcodes[$normalized['barcode']])) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Same barcode appears more than once in this file.'];
        }
        $seenBarcodes[$normalized['barcode']] = true;

        if ($normalized['design_code'] !== '' && Product::where('shop_id', $shopId)->where('design_code', $normalized['design_code'])->doesntExist()) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Design code not found in Product Catalog.'];
        }

        if (Item::where('shop_id', $shopId)->where('barcode', $normalized['barcode'])->exists()) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'This barcode is already used in your stock.'];
        }

        $lot = MetalLot::where('shop_id', $shopId)->where('lot_number', $normalized['lot_number'])->first();
        if (!$lot) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Lot Number not found in your shop.'];
        }
        $normalized['lot_id'] = (int) $lot->id;
        $normalized['lot_number'] = (int) $lot->lot_number;

        if ($normalized['gross_weight'] <= 0 || $normalized['purity'] <= 0 || $normalized['purity'] > 24) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Gross weight or purity is invalid. Purity must be between 0 and 24.'];
        }

        if ($normalized['stone_weight'] < 0 || $normalized['stone_weight'] >= $normalized['gross_weight']) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Stone weight is invalid. It must be less than gross weight.'];
        }

        $net = round($normalized['gross_weight'] - $normalized['stone_weight'], 6);
        $fineRequired = round($net * ($normalized['purity'] / 24), 6);
        $wastageFine = round($fineRequired * ($normalized['wastage_percent'] / 100), 6);
        $totalFine = round($fineRequired + $wastageFine, 6);

        if ((float) $lot->fine_weight_remaining < $totalFine) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Not enough gold in selected lot for this item.'];
        }

        $categoryName = $lot->purity >= 22 ? 'Gold Jewellery' : 'Jewellery';
        $subCategory = 'General';
        $willCreateCategory = false;
        $willCreateSubCategory = false;

        $linkedProduct = null;
        if ($normalized['design_code'] !== '') {
            $linkedProduct = Product::where('shop_id', $shopId)
                ->where('design_code', $normalized['design_code'])
                ->with(['category', 'subCategory'])
                ->first();
        }
        if ($linkedProduct) {
            $categoryName = $linkedProduct->category?->name ?? $categoryName;
            $subCategory = $linkedProduct->subCategory?->name ?? $subCategory;
            $normalized['design'] = $linkedProduct->name;
            $normalized['category'] = $categoryName;
            $normalized['sub_category'] = $subCategory;
        }

        $metadata = $this->resolveMetadataForPreview($shopId, $categoryName, $subCategory);
        $willCreateCategory = $metadata['will_create_category'];
        $willCreateSubCategory = $metadata['will_create_sub_category'];

        $normalized['category'] = $categoryName;
        $normalized['sub_category'] = $subCategory;

        return [
            'valid' => true,
            'normalized' => $normalized,
            'computed' => [
                'net_metal_weight' => $net,
                'fine_required' => $fineRequired,
                'wastage_fine' => $wastageFine,
                'total_fine_needed' => $totalFine,
                'will_create_category' => $willCreateCategory,
                'will_create_sub_category' => $willCreateSubCategory,
            ],
        ];
    }

    private function upsertCatalogProduct(int $shopId, array $payload): void
    {
        [$category, $sub] = $this->resolveMetadataForExecution($shopId, (string) $payload['category'], (string) $payload['sub_category']);

        Product::updateOrCreate(
            [
                'shop_id' => $shopId,
                'design_code' => $payload['design_code'],
            ],
            [
                'name' => $payload['name'],
                'category_id' => $category->id,
                'sub_category_id' => $sub->id,
                'default_purity' => $payload['default_purity'] !== '' ? (float) $payload['default_purity'] : null,
                'approx_weight' => $payload['approx_weight'] !== '' ? (float) $payload['approx_weight'] : null,
                'default_making' => $payload['default_making'] !== '' ? (float) $payload['default_making'] : null,
                'default_stone' => null,
                'notes' => trim(($payload['notes'] ?? '') . ($payload['stone_type'] ? ' | Stone: ' . $payload['stone_type'] : '')),
            ]
        );
    }

    private function validateStockRow(int $shopId, array $payload, array &$seenBarcodes): array
    {
        $normalized = [
            'barcode' => trim((string) ($payload['barcode'] ?? '')),
            'category' => trim((string) ($payload['category'] ?? '')),
            'sub_category' => trim((string) ($payload['sub_category'] ?? '')),
            'metal_type' => trim((string) ($payload['metal_type'] ?? '')),
            'gross_weight' => (float) ($payload['gross_weight'] ?? 0),
            'stone_weight' => (float) ($payload['stone_weight'] ?? 0),
            'purity' => (float) ($payload['purity'] ?? 0),
            'making_charge' => (float) ($payload['making_charge'] ?? 0),
            'stone_charge' => (float) ($payload['stone_charge'] ?? 0),
            'huid' => trim((string) ($payload['huid'] ?? '')),
            'vendor_name' => trim((string) ($payload['vendor_name'] ?? '')),
            'design' => trim((string) ($payload['design'] ?? '')),
            'cost_price' => (float) ($payload['cost_price'] ?? 0),
            'selling_price' => (float) ($payload['selling_price'] ?? 0),
        ];

        if ($normalized['barcode'] === '') {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Barcode is required.'];
        }

        if (isset($seenBarcodes[$normalized['barcode']])) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Same barcode appears more than once in this file.'];
        }
        $seenBarcodes[$normalized['barcode']] = true;

        if (Item::where('shop_id', $shopId)->where('barcode', $normalized['barcode'])->exists()) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'This barcode is already used in your stock.'];
        }

        if ($normalized['category'] === '' || $normalized['sub_category'] === '') {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Category and sub-category are required.'];
        }

        if ($normalized['gross_weight'] <= 0) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Gross weight must be greater than zero.'];
        }

        try {
            $normalized['metal_type'] = $this->pricing->normalizeMetalType($normalized['metal_type']);
        } catch (LogicException $e) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => $e->getMessage()];
        }

        if ($normalized['stone_weight'] < 0 || $normalized['stone_weight'] >= $normalized['gross_weight']) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Stone weight must be less than gross weight.'];
        }

        $net = round($normalized['gross_weight'] - $normalized['stone_weight'], 6);

        // Resolve vendor if provided; unknown vendors will be auto-created during execution
        if ($normalized['vendor_name'] !== '') {
            $vendor = Vendor::where('shop_id', $shopId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($normalized['vendor_name'])])
                ->first();
            if ($vendor) {
                $normalized['vendor_id'] = (int) $vendor->id;
            }
        }

        $metadata = $this->resolveMetadataForPreview($shopId, $normalized['category'], $normalized['sub_category']);
        $shop = Shop::find($shopId);

        if (! $shop) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Shop not found.'];
        }

        try {
            $pricingPayload = $this->pricing->computeRetailerCostPayload($shop, [
                'metal_type' => $normalized['metal_type'],
                'purity' => $normalized['purity'],
                'gross_weight' => $normalized['gross_weight'],
                'stone_weight' => $normalized['stone_weight'],
                'making_charges' => $normalized['making_charge'],
                'stone_charges' => $normalized['stone_charge'],
            ]);
        } catch (\Throwable $e) {
            return ['valid' => false, 'normalized' => $normalized, 'error' => $e->getMessage()];
        }

        return [
            'valid' => true,
            'normalized' => $normalized,
            'computed' => [
                'net_metal_weight' => $net,
                'resolved_rate_per_gram' => $pricingPayload['resolved_rate_per_gram'],
                'cost_price' => $pricingPayload['cost_price'],
                'will_create_category' => $metadata['will_create_category'],
                'will_create_sub_category' => $metadata['will_create_sub_category'],
            ],
        ];
    }

    private function createStockItem(int $shopId, array $payload): void
    {
        [$category, $subCategory] = $this->resolveMetadataForExecution(
            $shopId,
            (string) ($payload['category'] ?? ''),
            (string) ($payload['sub_category'] ?? 'General')
        );

        $grossWeight = (float) $payload['gross_weight'];
        $stoneWeight = (float) $payload['stone_weight'];
        $makingCharge = (float) $payload['making_charge'];
        $stoneCharge = (float) $payload['stone_charge'];
        $shop = Shop::findOrFail($shopId);
        $pricingPayload = $this->pricing->computeRetailerCostPayload($shop, [
            'metal_type' => (string) ($payload['metal_type'] ?? ''),
            'purity' => (float) $payload['purity'],
            'gross_weight' => $grossWeight,
            'stone_weight' => $stoneWeight,
            'making_charges' => $makingCharge,
            'stone_charges' => $stoneCharge,
        ]);

        // Resolve or auto-create vendor
        $vendorId = $payload['vendor_id'] ?? null;
        $vendorName = trim((string) ($payload['vendor_name'] ?? ''));
        if (!$vendorId && $vendorName !== '') {
            $vendor = Vendor::where('shop_id', $shopId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($vendorName)])
                ->first();
            if (!$vendor) {
                $vendor = new Vendor();
                $vendor->shop_id = $shopId;
                $vendor->name = $vendorName;
                $vendor->is_active = true;
                $vendor->save();
            }
            $vendorId = $vendor->id;
        }

        Item::create([
            'shop_id' => $shopId,
            'barcode' => $payload['barcode'],
            'design' => $payload['design'] ?: null,
            'category' => $category->name,
            'sub_category' => $subCategory->name,
            'metal_type' => $pricingPayload['metal_type'],
            'gross_weight' => $grossWeight,
            'stone_weight' => $stoneWeight,
            'net_metal_weight' => $pricingPayload['net_metal_weight'],
            'purity' => $pricingPayload['purity'],
            'making_charges' => $makingCharge,
            'stone_charges' => $stoneCharge,
            'cost_price' => $pricingPayload['cost_price'],
            'selling_price' => ((float) ($payload['selling_price'] ?? 0)) ?: null,
            'huid' => $payload['huid'] ?: null,
            'vendor_id' => $vendorId,
            'source' => 'import',
            'status' => 'in_stock',
            'pricing_review_required' => false,
            'pricing_review_notes' => null,
        ]);
    }

    private function buildVendorSummary(int $shopId, array $dataRows): array
    {
        $vendors = [];
        foreach ($dataRows as $row) {
            $name = trim((string) ($row['vendor_name'] ?? ''));
            if ($name !== '') {
                $vendors[$name] = ($vendors[$name] ?? 0) + 1;
            }
        }

        $summary = [];
        foreach ($vendors as $name => $count) {
            $exists = Vendor::where('shop_id', $shopId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();
            $summary[] = ['vendor' => $name, 'row_count' => $count, 'exists' => $exists];
        }

        return $summary;
    }

    private function resolveMetadataForPreview(int $shopId, string $categoryName, string $subCategoryName): array
    {
        $categoryNormalized = $this->normalizeName($categoryName);
        $subCategoryNormalized = $this->normalizeName($subCategoryName);

        $category = Category::where('shop_id', $shopId)
            ->where('normalized_name', $categoryNormalized)
            ->first();

        $willCreateCategory = !$category;
        $willCreateSubCategory = false;

        if ($category) {
            $sub = SubCategory::where('shop_id', $shopId)
                ->where('category_id', $category->id)
                ->where('normalized_name', $subCategoryNormalized)
                ->first();

            $willCreateSubCategory = !$sub;
        } else {
            $willCreateSubCategory = true;
        }

        return [
            'will_create_category' => $willCreateCategory,
            'will_create_sub_category' => $willCreateSubCategory,
        ];
    }

    private function resolveMetadataForExecution(int $shopId, string $categoryName, string $subCategoryName): array
    {
        $categoryName = trim($categoryName);
        $subCategoryName = trim($subCategoryName);

        if ($categoryName === '' || $subCategoryName === '') {
            throw new LogicException('Category and sub-category are required.');
        }

        $categoryNormalized = $this->normalizeName($categoryName);
        $subCategoryNormalized = $this->normalizeName($subCategoryName);

        $category = Category::where('shop_id', $shopId)
            ->where('normalized_name', $categoryNormalized)
            ->first();

        if (!$category) {
            $category = $this->createCategorySafely($shopId, $categoryName, $categoryNormalized);
        }

        $subCategory = SubCategory::where('shop_id', $shopId)
            ->where('category_id', $category->id)
            ->where('normalized_name', $subCategoryNormalized)
            ->first();

        if (!$subCategory) {
            $subCategory = $this->createSubCategorySafely(
                $shopId,
                (int) $category->id,
                $subCategoryName,
                $subCategoryNormalized
            );
        }

        return [$category, $subCategory];
    }

    private function createCategorySafely(int $shopId, string $name, string $normalizedName): Category
    {
        try {
            return Category::create([
                'shop_id' => $shopId,
                'name' => $name,
                'normalized_name' => $normalizedName,
            ]);
        } catch (QueryException $e) {
            if (($e->getCode() ?? '') !== '23505') {
                throw $e;
            }

            $existing = Category::where('shop_id', $shopId)
                ->where('normalized_name', $normalizedName)
                ->first();

            if (!$existing) {
                throw $e;
            }

            return $existing;
        }
    }

    private function createSubCategorySafely(int $shopId, int $categoryId, string $name, string $normalizedName): SubCategory
    {
        try {
            return SubCategory::create([
                'shop_id' => $shopId,
                'category_id' => $categoryId,
                'name' => $name,
                'normalized_name' => $normalizedName,
            ]);
        } catch (QueryException $e) {
            if (($e->getCode() ?? '') !== '23505') {
                throw $e;
            }

            $existing = SubCategory::where('shop_id', $shopId)
                ->where('category_id', $categoryId)
                ->where('normalized_name', $normalizedName)
                ->first();

            if (!$existing) {
                throw $e;
            }

            return $existing;
        }
    }

    private function readCsvRows(string $path): array
    {
        $content = Storage::get($path);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $headers = null;
        $rows = [];
        while (($raw = fgetcsv($stream)) !== false) {
            if ($headers === null) {
                $headers = array_map(fn ($h) => $this->normalizeHeader($h), $raw);
                continue;
            }

            if (count(array_filter($raw, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $rows[] = array_combine($headers, array_pad($raw, count($headers), null));
        }
        fclose($stream);

        return [$headers ?? [], $rows];
    }

    private function normalizeHeader(?string $header): string
    {
        $header = strtolower(trim((string) $header));
        $header = str_replace([' ', '-'], '_', $header);

        return $header;
    }

    private function normalizeName(string $value): string
    {
        $value = trim($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value);

        return $value ?? '';
    }

    private function buildLotSummary(int $shopId, array $lotDeductions): array
    {
        $summary = [];
        foreach ($lotDeductions as $lotId => $requiredFine) {
            $lot = MetalLot::where('shop_id', $shopId)->where('id', $lotId)->first();
            if (!$lot) {
                continue;
            }

            $available = (float) $lot->fine_weight_remaining;
            $summary[] = [
                'lot_id' => $lotId,
                'lot_number' => (int) $lot->lot_number,
                'required_fine' => round($requiredFine, 6),
                'available_fine' => round($available, 6),
                'after_import_fine' => round($available - $requiredFine, 6),
                'sufficient' => $available >= $requiredFine,
            ];
        }

        return $summary;
    }

    private function writeErrorCsv(Import $import, array $errors): string
    {
        $lines = ["row_number,error_message"];
        foreach ($errors as $error) {
            $lines[] = (int) $error['row_number'] . ',\"' . str_replace('"', '""', (string) $error['error']) . '\"';
        }

        $path = 'imports/errors/import-' . $import->id . '-errors.csv';
        Storage::put($path, implode("\n", $lines));

        return $path;
    }

    private function assertShopWritable(int $shopId): void
    {
        $shop = Shop::find($shopId);
        if (!$shop) {
            throw new LogicException('Shop not found.');
        }

        $mode = $shop->access_mode ?: ($shop->is_active ? 'active' : 'suspended');
        if ($mode !== 'active') {
            throw new LogicException("Shop is in {$mode} mode. Imports are blocked.");
        }
    }

    private function assertFinancialLock(int $shopId): void
    {
        $lockDate = DB::table('shop_rules')->where('shop_id', $shopId)->value('financial_lock_date');
        if ($lockDate && now()->toDateString() <= $lockDate) {
            throw new LogicException("Financial lock is active through {$lockDate}.");
        }
    }

    private function assertImportTypeAllowed(int $shopId, string $type): void
    {
        $shopType = Shop::whereKey($shopId)->value('shop_type') ?? 'manufacturer';

        if ($shopType === 'retailer' && in_array($type, [Import::TYPE_CATALOG, Import::TYPE_MANUFACTURE], true)) {
            throw new LogicException('This import type is available only in manufacturer edition.');
        }

        if ($shopType === 'manufacturer' && $type === Import::TYPE_STOCK) {
            throw new LogicException('Stock import is available only in retailer edition.');
        }
    }

    private function assertRetailerStockPricingReady(int $shopId): void
    {
        $shop = Shop::find($shopId);

        if (! $shop) {
            throw new LogicException('Shop not found.');
        }

        $this->pricing->assertRetailerPricingReady($shop);
    }

    private function humanizeError(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return 'Row failed due to invalid data. Please check this row and try again.';
        }

        if (str_contains($message, 'Insufficient fine gold in lot')) {
            return 'Not enough gold in selected lot. Use another lot or reduce weight in this row.';
        }

        if (str_contains($message, 'Invalid gold lot selected')) {
            return 'Selected lot number is not valid for this shop.';
        }

        if (str_contains($message, 'Barcode already exists')) {
            return 'This barcode is already used in your stock.';
        }

        if (str_contains($message, 'design_code not found')) {
            return 'Design code was not found in Product Catalog.';
        }

        if (str_contains($message, 'Insufficient lot balance')) {
            return 'Not enough gold in selected lot for this item.';
        }

        if (str_contains($message, 'Financial lock is active')) {
            return 'Changes are locked for old dates. Financial lock is active.';
        }

        if (str_contains($message, 'Shop is in')) {
            return 'This shop is not allowed to import right now (read-only or suspended).';
        }

        return $message;
    }
}
