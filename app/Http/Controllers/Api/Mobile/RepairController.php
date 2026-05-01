<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\InvoicePayment;
use App\Models\Repair;
use App\Services\InvoiceAccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class RepairController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;
        $query = Repair::with('customer:id,first_name,last_name,mobile')
            ->where('shop_id', $shopId);

        $allowedStatuses = ['received', 'in_repair', 'ready', 'delivered'];
        if ($request->filled('status') && in_array($request->status, $allowedStatuses, true)) {
            $query->where('status', $request->status);
        }

        if ($request->boolean('overdue')) {
            $query->whereNot('status', 'delivered')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString());
        }

        $repairs = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));
        $repairs->setCollection(
            $repairs->getCollection()->map(fn (Repair $repair) => $this->serializeRepair($repair))
        );

        return response()->json($repairs);
    }

    public function show(int $repair, Request $request): JsonResponse
    {
        $repairModel = Repair::with('customer:id,first_name,last_name,mobile')
            ->where('shop_id', (int) $request->user()->shop_id)
            ->findOrFail($repair);

        return response()->json($this->serializeRepair($repairModel));
    }

    public function store(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $validated = $request->validate([
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->where('shop_id', $shopId),
            ],
            'item_description' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'due_date' => 'nullable|date',
            'gross_weight' => 'required|numeric|min:0.001',
            'purity' => 'required|numeric|min:1|max:24',
            'estimated_cost' => 'required|numeric|min:0',
            'image_base64' => 'nullable|string',
        ]);

        $imagePath = null;
        if (!empty($validated['image_base64'])) {
            $imagePath = $this->storeRepairImageFromBase64($validated['image_base64'], $shopId);
        }

        $repair = Repair::create([
            'shop_id' => $shopId,
            'customer_id' => $validated['customer_id'],
            'item_description' => $validated['item_description'],
            'description' => $validated['description'] ?? null,
            'image_path' => $imagePath,
            'image' => $imagePath,
            'due_date' => $validated['due_date'] ?? null,
            'gross_weight' => $validated['gross_weight'],
            'purity' => $validated['purity'],
            'estimated_cost' => $validated['estimated_cost'],
            'status' => 'received',
        ]);

        AuditLog::create([
            'shop_id' => $shopId,
            'user_id' => (int) $request->user()->id,
            'action' => 'repair_created',
            'model_type' => 'repair',
            'model_id' => (int) $repair->id,
            'description' => 'Repair created from mobile app.',
            'data' => [
                'source' => 'mobile_app',
                'repair_number' => $repair->repair_number,
                'status' => $repair->status,
                'due_date' => optional($repair->due_date)?->toDateString(),
            ],
        ]);

        $resolvedImagePath = $repair->resolveImagePath();

        return response()->json([
            'id' => $repair->id,
            'repair_number' => $repair->repair_number,
            'status' => $repair->status,
            'image' => $resolvedImagePath,
            'image_path' => $resolvedImagePath,
            'image_url' => $this->repairImageUrl($resolvedImagePath),
            'message' => 'Repair created successfully.',
        ], 201);
    }

    public function updateStatus(Repair $repair, Request $request): JsonResponse
    {
        if ((int) $repair->shop_id !== (int) $request->user()->shop_id) {
            return response()->json(['message' => 'Repair not found.'], 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['received', 'in_repair', 'ready', 'delivered'])],
            'final_cost' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date',
        ]);

        $newStatus = $validated['status'];
        if ($newStatus === 'delivered') {
            if (!$request->has('amount') && $request->has('final_cost')) {
                $request->merge(['amount' => $request->input('final_cost')]);
            }
            if (!$request->has('include_gst')) {
                $request->merge(['include_gst' => false]);
            }

            return $this->deliver($repair, $request);
        }

        $repair = DB::transaction(function () use ($repair, $newStatus, $validated, $request) {
            // Lock and refresh BEFORE reading old status to prevent race conditions
            $repair = Repair::query()->where('id', $repair->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $repair->status;

            if ($oldStatus === 'delivered' && $newStatus !== 'delivered') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => 'Delivered repairs cannot be moved back to earlier stages.',
                ]);
            }

            $repair->status = $newStatus;

            if ($request->has('due_date')) {
                $repair->due_date = $validated['due_date'] ?? null;
            }

            $repair->save();

            AuditLog::create([
                'shop_id' => (int) $request->user()->shop_id,
                'user_id' => (int) $request->user()->id,
                'action' => 'repair_status_updated',
                'model_type' => 'repair',
                'model_id' => (int) $repair->id,
                'description' => 'Repair status updated from mobile app.',
                'data' => [
                    'source' => 'mobile_app',
                    'repair_number' => $repair->repair_number,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'final_cost' => $repair->final_cost,
                    'due_date' => optional($repair->due_date)?->toDateString(),
                ],
            ]);

            return $repair;
        });

        return response()->json([
            'id' => $repair->id,
            'repair_number' => $repair->repair_number,
            'status' => $repair->status,
            'final_cost' => $repair->final_cost,
            'due_date' => optional($repair->due_date)?->toDateString(),
            'message' => 'Repair status updated.',
        ]);
    }

    public function deliver(Repair $repair, Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        if ((int) $repair->shop_id !== $shopId) {
            return response()->json(['message' => 'Repair not found.'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'include_gst' => 'nullable|boolean',
            'gst_rate' => 'nullable|numeric|min:0|max:100',
            'payment_mode' => ['nullable', Rule::in(['cash', 'upi', 'bank', 'wallet', 'other'])],
        ]);

        $includeGst = $request->boolean('include_gst');
        $subtotal = (float) $validated['amount'];
        $defaultGstRate = (float) ($request->user()->shop->gst_rate ?? config('business.gst_rate_default'));
        $gstRate = $includeGst ? (float) ($validated['gst_rate'] ?? $defaultGstRate) : 0.0;
        $gstAmount = $includeGst ? round(($subtotal * $gstRate) / 100, 2) : 0.0;
        $totalAmount = round($subtotal + $gstAmount, 2);

        $paymentMode = $validated['payment_mode'] ?? InvoicePayment::MODE_CASH;

        [$updatedRepair, $invoice] = DB::transaction(function () use (
            $repair,
            $request,
            $shopId,
            $subtotal,
            $gstRate,
            $gstAmount,
            $totalAmount,
            $paymentMode
        ) {
            $lockedRepair = Repair::query()->where('id', $repair->id)->lockForUpdate()->firstOrFail();

            if ($lockedRepair->status === 'delivered') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => 'Repair is already delivered.',
                ]);
            }

            $invoice = InvoiceAccountingService::createFinalized([
                'shop_id' => $shopId,
                'customer_id' => $lockedRepair->customer_id,
                'gold_rate' => 0,
                'subtotal' => $subtotal,
                'gst' => $gstAmount,
                'gst_rate' => $gstRate,
                'wastage_charge' => 0,
                'total' => $totalAmount,
            ]);

            InvoicePayment::record([
                'invoice_id' => $invoice->id,
                'shop_id' => $shopId,
                'mode' => $paymentMode,
                'amount' => $totalAmount,
            ]);

            if ($paymentMode === InvoicePayment::MODE_CASH) {
                CashTransaction::record([
                    'shop_id' => $shopId,
                    'user_id' => (int) $request->user()->id,
                    'type' => 'in',
                    'amount' => $totalAmount,
                    'source_type' => 'invoice',
                    'source_id' => $invoice->id,
                    'invoice_id' => $invoice->id,
                    'description' => 'Repair delivery - ' . $lockedRepair->item_description,
                    'reference_type' => 'invoice',
                    'reference_id' => $invoice->id,
                ]);
            }

            $lockedRepair->final_cost = $totalAmount;
            $lockedRepair->invoice_id = $invoice->id;
            $lockedRepair->status = 'delivered';
            $lockedRepair->save();

            AuditLog::create([
                'shop_id' => $shopId,
                'user_id' => (int) $request->user()->id,
                'action' => 'repair_deliver',
                'model_type' => 'repair',
                'model_id' => (int) $lockedRepair->id,
                'description' => 'Repair delivered and billed from mobile app.',
                'data' => [
                    'source' => 'mobile_app',
                    'repair_number' => $lockedRepair->repair_number,
                    'subtotal' => $subtotal,
                    'gst_rate' => $gstRate,
                    'gst' => $gstAmount,
                    'amount' => $totalAmount,
                    'payment_mode' => $paymentMode,
                    'invoice_id' => $invoice->id,
                ],
            ]);

            return [$lockedRepair, $invoice];
        });

        return response()->json([
            'id' => $updatedRepair->id,
            'repair_number' => $updatedRepair->repair_number,
            'status' => $updatedRepair->status,
            'final_cost' => $updatedRepair->final_cost,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'payment_mode' => $paymentMode,
            'gst_rate' => $gstRate,
            'gst' => $gstAmount,
            'total' => $totalAmount,
            'message' => 'Repair delivered and billed successfully.',
        ]);
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

    private function repairImageUrl(?string $imagePath): ?string
    {
        if (empty($imagePath)) {
            return null;
        }

        $url = Storage::disk($this->repairImageDisk())->url(ltrim($imagePath, '/'));

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : url($url);
    }

    private function serializeRepair(Repair $repair): array
    {
        $resolvedImagePath = $repair->resolveImagePath();
        $payload = $repair->toArray();
        $payload['image'] = $resolvedImagePath;
        $payload['image_path'] = $resolvedImagePath;
        $payload['image_url'] = $this->repairImageUrl($resolvedImagePath);

        return $payload;
    }

    private function repairImageDisk(): string
    {
        $defaultDisk = (string) config('filesystems.default', 'public');

        return in_array($defaultDisk, ['public', 's3'], true) ? $defaultDisk : 'public';
    }
}
