<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\InvoicePayment;
use App\Models\Repair;
use App\Services\InvoiceAccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RepairController extends Controller
{
    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $query = Repair::where('shop_id', $shopId)
            ->whereNotIn('status', ['delivered'])
            ->with(['customer', 'invoice']);

        if ($request->filled('search')) {
            $s = trim($request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('item_description', 'ilike', "%{$s}%")
                  ->orWhere('description', 'ilike', "%{$s}%")
                  ->orWhere('repair_number', 'like', "%{$s}%")
                  ->orWhereHas('customer', function ($c) use ($s) {
                      $c->where('first_name', 'ilike', "%{$s}%")
                        ->orWhere('last_name', 'ilike', "%{$s}%")
                        ->orWhere('mobile', 'like', "%{$s}%");
                  });
            });
        }

        $repairs = $query->orderBy('created_at', 'desc')->paginate(25)->withQueryString();

        if ($request->expectsJson()) {
            $repairs->setCollection(
                $repairs->getCollection()->map(fn (Repair $repair) => $this->serializeRepair($repair))
            );

            return response()->json($repairs);
        }

        $statusCounts = Repair::where('shop_id', $shopId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Select only the columns needed for the dropdown — avoids loading all customer fields.
        $customers = Customer::where('shop_id', $shopId)
            ->select('id', 'first_name', 'last_name', 'mobile')
            ->orderBy('first_name')
            ->get();

        return view('repairs', compact('repairs', 'customers', 'statusCounts'));
    }

    public function store(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $validated = $request->validate([
            'customer_id'     => ['required', Rule::exists('customers', 'id')->where('shop_id', $shopId)],
            'item_description' => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'gross_weight'    => 'required|numeric|min:0',
            'purity'          => 'nullable|numeric|min:0|max:24',
            'estimated_cost'  => 'nullable|numeric|min:0',
            'image'           => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'image_base64'    => 'nullable|string',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $this->storeUploadedRepairImage($request, $shopId);
        } elseif (!empty($validated['image_base64'])) {
            $imagePath = $this->storeRepairImageFromBase64($validated['image_base64'], $shopId);
        }

        $repair = Repair::create([
            'shop_id'          => $shopId,
            'customer_id'      => $validated['customer_id'],
            'item_description' => $validated['item_description'],
            'description'      => $validated['description'] ?? null,
            'image_path'       => $imagePath,
            'image'            => $imagePath,
            'gross_weight'     => $validated['gross_weight'],
            'purity'           => $validated['purity'] ?? null,
            'estimated_cost'   => $validated['estimated_cost'],
            'status'           => 'received',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Repair item received successfully!',
                'repair' => $this->serializeRepair($repair),
            ], 201);
        }

        return redirect()->route('repairs.index')->with('success', 'Repair item received successfully!');
    }

    public function show(Request $request, Repair $repair)
    {
        $this->authorize('update', $repair);
        $repair->loadMissing('customer', 'invoice');

        if ($request->expectsJson()) {
            return response()->json($this->serializeRepair($repair));
        }

        return view('repairs.show', compact('repair'));
    }

    public function edit(Repair $repair)
    {
        $this->authorize('update', $repair);

        if ($repair->status === 'delivered') {
            return redirect()->route('repairs.index')->with('error', 'Delivered repairs cannot be edited.');
        }

        $customers = Customer::where('shop_id', auth()->user()->shop_id)
            ->select('id', 'first_name', 'last_name', 'mobile')
            ->orderBy('first_name')
            ->get();

        return view('repairs.edit', compact('repair', 'customers'));
    }

    public function update(Request $request, Repair $repair)
    {
        $this->authorize('update', $repair);

        if ($repair->status === 'delivered') {
            return redirect()->route('repairs.index')->with('error', 'Delivered repairs cannot be edited.');
        }

        $shopId = auth()->user()->shop_id;

        $validated = $request->validate([
            'customer_id'      => ['required', Rule::exists('customers', 'id')->where('shop_id', $shopId)],
            'item_description' => 'required|string|max:255',
            'description'      => 'nullable|string|max:1000',
            'gross_weight'     => 'required|numeric|min:0',
            'purity'           => 'nullable|numeric|min:0|max:24',
            'estimated_cost'   => 'nullable|numeric|min:0',
            'status'           => ['required', Rule::in(['received', 'in_repair', 'ready'])],
            'image'            => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'image_base64'     => 'nullable|string',
            'remove_image'     => 'nullable|boolean',
        ]);

        $existingImagePath = $repair->resolveImagePath();
        if ($request->hasFile('image')) {
            if ($existingImagePath) {
                Storage::disk($this->repairImageDisk())->delete($existingImagePath);
            }
            $newPath = $this->storeUploadedRepairImage($request, $shopId);
            $validated['image_path'] = $newPath;
            $validated['image'] = $newPath;
        } elseif (!empty($validated['image_base64'])) {
            if ($existingImagePath) {
                Storage::disk($this->repairImageDisk())->delete($existingImagePath);
            }
            $newPath = $this->storeRepairImageFromBase64($validated['image_base64'], $shopId);
            $validated['image_path'] = $newPath;
            $validated['image'] = $newPath;
        } elseif ($request->boolean('remove_image') && $existingImagePath) {
            Storage::disk($this->repairImageDisk())->delete($existingImagePath);
            $validated['image_path'] = null;
            $validated['image'] = null;
        }

        unset($validated['image_base64'], $validated['remove_image']);

        $repair->update($validated);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Repair updated successfully!',
                'repair' => $this->serializeRepair($repair->fresh(['customer', 'invoice'])),
            ]);
        }

        return redirect()->route('repairs.index')->with('success', 'Repair updated successfully!');
    }

    public function deliver(Request $request, Repair $repair)
    {
        $this->authorize('update', $repair);

        $shopId = auth()->user()->shop_id;

        if ($repair->status === 'delivered') {
            return back()->with('error', 'Repair is already delivered.');
        }

        $validated = $request->validate([
            'amount'      => 'required|numeric|min:0',
            'include_gst' => 'nullable|boolean',
            'gst_rate'    => 'nullable|numeric|min:0|max:100',
        ]);

        $includeGst  = $request->boolean('include_gst');
        $subtotal    = (float) $validated['amount'];
        $gstRate     = $includeGst
            ? (float) ($validated['gst_rate'] ?? (auth()->user()->shop->gst_rate ?? config('business.gst_rate_default')))
            : 0.0;
        $gstAmount   = $includeGst ? round(($subtotal * $gstRate) / 100, 2) : 0.0;
        $totalAmount = round($subtotal + $gstAmount, 2);


        $invoice = DB::transaction(function () use ($shopId, $repair, $subtotal, $gstRate, $gstAmount, $totalAmount) {
            $invoice = InvoiceAccountingService::createFinalized([
                'shop_id'        => $shopId,
                'customer_id'    => $repair->customer_id,
                'gold_rate'      => 0,
                'subtotal'       => $subtotal,
                'gst'            => $gstAmount,
                'gst_rate'       => $gstRate,
                'wastage_charge' => 0,
                'total'          => $totalAmount,
            ]);

            // Record the payment — repair service is always collected as cash.
            InvoicePayment::record([
                'invoice_id' => $invoice->id,
                'shop_id'    => $shopId,
                'mode'       => InvoicePayment::MODE_CASH,
                'amount'     => $totalAmount,
            ]);

            \App\Models\CashTransaction::record([
                'shop_id'          => $shopId,
                'user_id'          => auth()->id(),
                'type'             => 'in',
                'amount'           => $totalAmount,
                'source_type'      => 'invoice',
                'source_id'        => $invoice->id,
                'invoice_id'       => $invoice->id,
                'description'      => 'Repair delivery - ' . $repair->item_description,
                'reference_type'   => 'invoice',
                'reference_id'     => $invoice->id,
            ]);

            $repair->final_cost = $totalAmount;
            $repair->invoice_id = $invoice->id;
            $repair->status     = 'delivered';
            $repair->save();

            \App\Models\AuditLog::create([
                'shop_id'     => $shopId,
                'user_id'     => auth()->id(),
                'action'      => 'repair_deliver',
                'model_type'  => 'repair',
                'model_id'    => $repair->id,
                'data'        => [
                    'subtotal'   => $subtotal,
                    'gst_rate'   => $gstRate,
                    'gst'        => $gstAmount,
                    'amount'     => $totalAmount,
                    'invoice_id' => $invoice->id,
                ],
            ]);

            return $invoice;
        });

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Repair delivered and billed successfully (Invoice ' . $invoice->invoice_number . ').');
    }

    public function destroy(Repair $repair)
    {
        $this->authorize('delete', $repair);

        if ($repair->status === 'delivered') {
            return redirect()->route('repairs.index')->with('error', 'Delivered repairs cannot be deleted.');
        }

        $imagePath = $repair->resolveImagePath();
        if ($imagePath) {
            Storage::disk($this->repairImageDisk())->delete($imagePath);
        }

        $repair->delete();

        return redirect()->route('repairs.index')->with('success', 'Repair deleted successfully.');
    }

    private function repairImageDisk(): string
    {
        $defaultDisk = (string) config('filesystems.default', 'public');

        return in_array($defaultDisk, ['public', 's3'], true) ? $defaultDisk : 'public';
    }

    private function storeUploadedRepairImage(Request $request, int $shopId): ?string
    {
        if (!$request->hasFile('image')) {
            return null;
        }

        return $request->file('image')->store("repairs/{$shopId}", $this->repairImageDisk());
    }

    private function storeRepairImageFromBase64(string $imageBase64, int $shopId): string
    {
        $imageData = base64_decode($imageBase64, true);
        if ($imageData === false) {
            throw ValidationException::withMessages([
                'image_base64' => 'Invalid image data.',
            ]);
        }

        $maxBytes = 5 * 1024 * 1024;
        if (strlen($imageData) > $maxBytes) {
            throw ValidationException::withMessages([
                'image_base64' => 'Image size must not exceed 5 MB.',
            ]);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($imageData);
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedMimes[$mime])) {
            throw ValidationException::withMessages([
                'image_base64' => 'Invalid image format. Allowed: JPEG, PNG, WebP.',
            ]);
        }

        $path = sprintf('repairs/%d/%s.%s', $shopId, (string) Str::ulid(), $allowedMimes[$mime]);
        Storage::disk($this->repairImageDisk())->put($path, $imageData);

        return $path;
    }

    private function serializeRepair(Repair $repair): array
    {
        $payload = $repair->toArray();
        $payload['image'] = $repair->resolveImagePath();
        $payload['image_path'] = $repair->resolveImagePath();
        $payload['image_url'] = $repair->resolveImageUrl($this->repairImageDisk());

        return $payload;
    }
}
