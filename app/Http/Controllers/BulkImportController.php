<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBulkImportJob;
use App\Models\Import;
use App\Services\BulkImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use LogicException;

class BulkImportController extends Controller
{
    public function __construct(private BulkImportService $service)
    {
    }

    public function index()
    {
        $imports = Import::with('creator')
            ->orderByDesc('id')
            ->paginate(20);

        return view('imports.index', compact('imports'));
    }

    public function show(Import $import)
    {
        abort_if($import->shop_id !== auth()->user()->shop_id, 403);

        $rows = $import->rows()->orderBy('row_number')->paginate(100);
        return view('imports.show', compact('import', 'rows'));
    }

    public function previewCatalog(Request $request)
    {
        $this->validateCsvUpload($request, 'Catalog Import');

        try {
            $import = $this->service->createPreview(
                (int) auth()->user()->shop_id,
                (int) auth()->id(),
                Import::TYPE_CATALOG,
                $request->file('file')
            );
        } catch (LogicException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        return redirect()->route('imports.show', $import)->with('success', 'Catalog import preview created.');
    }

    public function previewManufacture(Request $request)
    {
        $this->validateCsvUpload($request, 'Manufacture Import');

        try {
            $import = $this->service->createPreview(
                (int) auth()->user()->shop_id,
                (int) auth()->id(),
                Import::TYPE_MANUFACTURE,
                $request->file('file')
            );
        } catch (LogicException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        return redirect()->route('imports.show', $import)->with('success', 'Manufacture import preview created.');
    }

    public function previewStock(Request $request)
    {
        $this->validateCsvUpload($request, 'Stock Import');

        try {
            $import = $this->service->createPreview(
                (int) auth()->user()->shop_id,
                (int) auth()->id(),
                Import::TYPE_STOCK,
                $request->file('file')
            );
        } catch (LogicException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        return redirect()->route('imports.show', $import)->with('success', 'Stock import preview created.');
    }

    public function execute(Request $request, Import $import)
    {
        abort_if($import->shop_id !== auth()->user()->shop_id, 403);

        $request->validate([
            'mode' => ['required', Rule::in([Import::MODE_STRICT, Import::MODE_ROW])],
        ]);

        if ($import->status !== Import::STATUS_PREVIEW) {
            return back()->withErrors(['import' => 'Only preview imports can be executed.']);
        }

        if ((int) $import->valid_rows === 0) {
            return back()->withErrors(['import' => 'No valid rows to import.']);
        }

        $import->update([
            'mode' => $request->input('mode'),
            'status' => Import::STATUS_QUEUED,
        ]);

        try {
            ProcessBulkImportJob::dispatchSync($import->id, $request->input('mode'));
        } catch (LogicException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        return redirect()->route('imports.show', $import)->with('success', 'Import completed successfully.');
    }

    public function downloadErrors(Import $import)
    {
        abort_if($import->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($import->error_file_path && Storage::exists($import->error_file_path), 404);

        return Storage::download($import->error_file_path, 'import-errors-' . $import->id . '.csv');
    }

    public function cancel(Import $import)
    {
        abort_if($import->shop_id !== auth()->user()->shop_id, 403);

        if (!in_array($import->status, [Import::STATUS_PREVIEW, Import::STATUS_QUEUED], true)) {
            return back()->withErrors(['import' => 'Only preview or queued imports can be cancelled.']);
        }

        $import->update(['status' => Import::STATUS_CANCELLED]);

        if ($import->file_path && Storage::exists($import->file_path)) {
            Storage::delete($import->file_path);
        }

        return redirect()->route('imports.index')->with('success', 'Import cancelled successfully.');
    }

    public function downloadTemplate(string $type)
    {
        $templates = [
            Import::TYPE_CATALOG => [
                'filename' => 'catalog-import-template.csv',
                'headers' => [
                    'design_code',
                    'name',
                    'category',
                    'sub_category',
                    'default_purity',
                    'approx_weight',
                    'default_making',
                    'stone_type',
                    'notes',
                ],
                'sample' => [
                    'D-1001',
                    'Floral Ring',
                    'Gold Jewellery',
                    'Rings',
                    '22',
                    '8.500',
                    '1200',
                    'None',
                    'Sample row. Replace with your own data.',
                ],
            ],
            Import::TYPE_MANUFACTURE => [
                'filename' => 'manufacture-import-template.csv',
                'headers' => [
                    'barcode',
                    'design_code',
                    'lot_number',
                    'gross_weight',
                    'stone_weight',
                    'purity',
                    'wastage_percent',
                    'making_charge',
                    'stone_charge',
                ],
                'sample' => [
                    'RJ-001-001',
                    'D-1001',
                    '1',
                    '12.000',
                    '0.500',
                    '22',
                    '2',
                    '1500',
                    '0',
                ],
            ],
            Import::TYPE_STOCK => [
                'filename' => 'stock-import-template.csv',
                'headers' => [
                    'barcode',
                    'category',
                    'sub_category',
                    'gross_weight',
                    'stone_weight',
                    'purity',
                    'making_charge',
                    'stone_charge',
                    'huid',
                    'vendor_name',
                    'design',
                    'cost_price',
                    'selling_price',
                ],
                'sample' => [
                    'BRC-0001',
                    'Gold Jewellery',
                    'Rings',
                    '8.500',
                    '0.200',
                    '22',
                    '1200',
                    '500',
                    'AB1234CD5678',
                    'Kumar Jewellers',
                    'Floral Ring',
                    '35000',
                    '42000',
                ],
            ],
        ];

        abort_unless(array_key_exists($type, $templates), 404);

        $template = $templates[$type];
        $content = implode(',', $template['headers']) . "\n" . implode(',', $template['sample']) . "\n";

        return Response::make($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $template['filename'] . '"',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    private function validateCsvUpload(Request $request, string $importName): void
    {
        $request->validate(
            [
                'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            ],
            [
                'file.required' => "Please select a CSV file for {$importName}.",
                'file.file' => "The uploaded file for {$importName} is invalid.",
                'file.mimes' => "Only CSV files (.csv) are allowed for {$importName}.",
                'file.max' => "The {$importName} CSV must be 10 MB or smaller.",
            ],
            [
                'file' => "{$importName} CSV file",
            ]
        );
    }
}
