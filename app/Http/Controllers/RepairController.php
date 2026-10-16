<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\InvoicePayment;
use App\Models\Repair;
use App\Services\InvoiceAccountingService;
use App\Services\PricingEngine;
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
            ->with(['customer', 'invoice']);

        // Default view is active work; an explicit ?status= reveals any state —
        // including 'delivered', which was previously hidden forever (H4).
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->whereNotIn('status', ['delivered']);
        }

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

        $metalType = $request->input('metal_type', Repair::DEFAULT_METAL_TYPE);

        $validated = $request->validate([
            'customer_id'     => ['required', Customer::activeExistsRule((int) $shopId)],
            'item_description' => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'metal_type'      => ['nullable', Rule::in(Repair::METAL_TYPES)],
            'gross_weight'    => 'required|numeric|min:0',
            'purity'          => 'nullable|numeric|min:0|max:' . Repair::maxPurityFor($metalType),
            'estimated_cost'  => 'nullable|numeric|min:0',
            'item_quantity'   => 'nullable|integer|min:1|max:999',
            'intake_condition' => 'nullable|string|max:500',
            'due_date'        => 'nullable|date',
            'image'           => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'image_base64'    => 'nullable|string',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $this->storeUploadedRepairImage($request, $shopId);
        } elseif (!empty($validated['image_base64'])) {
            $imagePath = $this->storeRepairImageFromBase64($validated['image_base64'], $shopId);
        }

        // MASTERS PART 3: a repair intake is a new commitment — authoritative
        // archive check and the write share one transaction.
        $repair = DB::transaction(function () use ($shopId, $validated, $imagePath) {
            Customer::lockActiveOrFail((int) $shopId, (int) $validated['customer_id'], 'customer_id');

            return Repair::create([
                'shop_id'          => $shopId,
                'customer_id'      => $validated['customer_id'],
                'item_description' => $validated['item_description'],
                'description'      => $validated['description'] ?? null,
                'image_path'       => $imagePath,
                'image'            => $imagePath,
                'metal_type'       => $validated['metal_type'] ?? Repair::DEFAULT_METAL_TYPE,
                'gross_weight'     => $validated['gross_weight'],
                'purity'           => $validated['purity'] ?? null,
                'estimated_cost'   => $validated['estimated_cost'] ?? null,
                'item_quantity'    => $validated['item_quantity'] ?? null,
                'intake_condition' => $validated['intake_condition'] ?? null,
                'due_date'         => $validated['due_date'] ?? null,
                'status'           => 'received',
            ]);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Repair item received successfully!',
                'repair' => $this->serializeRepair($repair),
            ], 201);
        }

        return redirect()->route('repairs.show', $repair)->with('success', 'Repair item received. Print, download or share the challan for the customer below.');
    }

    /**
     * Intake challan: what was recorded for this repair, for the customer to keep
     * and bring back at collection. Read-only: printing or downloading it again
     * never creates a repair, uses a number, records a payment or moves the status.
     */
    public function challan(Repair $repair)
    {
        return view('repairs.challan', $this->challanData($repair));
    }

    public function challanPdf(Repair $repair)
    {
        $data = $this->challanData($repair);
        $pdf = new \App\Services\Reporting\Render\ChromiumPdfService(
            (string) config('reporting.chromium_path'),
            (int) config('reporting.chromium_timeout_seconds', 60),
        );
        abort_unless($pdf->isAvailable(), 503, 'PDF download is unavailable right now. Use Print challan instead.');

        $bytes = $pdf->convert(view('repairs.challan', $data + ['forPdf' => true])->render());

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            // The reference only: no customer name in a file that gets forwarded.
            'Content-Disposition' => 'attachment; filename="' . $data['reference'] . '-challan.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function challanData(Repair $repair): array
    {
        $this->authorize('update', $repair); // same shop check as the repair page
        $repair->loadMissing('customer');
        $shop = \App\Models\Shop::query()->findOrFail($repair->shop_id);
        $current = $repair->intakeDetails();
        $intake = $repair->intake_snapshot; // null: received before intake copies were kept

        return [
            'repair' => $repair,
            'shop' => $shop,
            'reference' => 'REP-' . str_pad((string) $repair->repair_number, 3, '0', STR_PAD_LEFT),
            'terms' => trim((string) $shop->billingSettings()->value('terms_and_conditions')),
            'hasIntakeCopy' => $intake !== null,
            'details' => $intake ?? $current,
            'changes' => $intake === null ? [] : array_filter(
                $current,
                fn (?string $value, string $key) => ($intake[$key] ?? null) !== $value,
                ARRAY_FILTER_USE_BOTH,
            ),
            'forPdf' => false,
        ];
    }

    public function show(Request $request, Repair $repair)
    {
        $this->authorize('update', $repair);
        $repair->loadMissing('customer', 'invoice');

        if ($request->expectsJson()) {
            return response()->json($this->serializeRepair($repair));
        }

        return view('repairs.show', compact('repair') + ['customerWhatsApp' => $this->customerWhatsApp($repair)]);
    }

    /**
     * WhatsApp's official click-to-chat link to the customer's saved number,
     * opened in the shop's own WhatsApp. Only a number Mobile::normalize accepts
     * (an Indian mobile) gets a link; anything else is reported, never guessed.
     *
     * @return array{link: ?string, problem: ?string}
     */
    private function customerWhatsApp(Repair $repair): array
    {
        $saved = trim((string) $repair->customer?->mobile);
        if ($saved === '') {
            return ['link' => null, 'problem' => 'No mobile number is saved for this customer.'];
        }
        $national = \App\Support\Mobile::normalize($saved);
        if ($national === null) {
            return ['link' => null, 'problem' => "The saved number ({$saved}) is not a valid Indian mobile number, so WhatsApp cannot be opened from here. Check the customer's record."];
        }

        $shopName = \App\Models\Shop::query()->whereKey($repair->shop_id)->value('name');
        $reference = 'REP-' . str_pad((string) $repair->repair_number, 3, '0', STR_PAD_LEFT);
        // Says nothing about a PDF: staff attach it themselves, or not.
        $message = "Hello from {$shopName}. We have received your item for repair, reference {$reference}. Please keep this reference for collection.";

        return ['link' => 'https://wa.me/' . \App\Support\Mobile::COUNTRY_CODE . $national . '?text=' . rawurlencode($message), 'problem' => null];
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

        $metalType = $request->input('metal_type', $repair->metal_type ?? Repair::DEFAULT_METAL_TYPE);

        $validated = $request->validate([
            'customer_id'      => ['required', Customer::activeOrCurrentExistsRule((int) $shopId, (int) $repair->customer_id)],
            'item_description' => 'required|string|max:255',
            'description'      => 'nullable|string|max:1000',
            'metal_type'       => ['nullable', Rule::in(Repair::METAL_TYPES)],
            'gross_weight'     => 'required|numeric|min:0',
            'purity'           => 'nullable|numeric|min:0|max:' . Repair::maxPurityFor($metalType),
            'estimated_cost'   => 'nullable|numeric|min:0',
            'status'           => ['required', Rule::in(['received', 'in_repair', 'ready'])],
            'image'            => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'image_base64'     => 'nullable|string',
            'remove_image'     => 'nullable|boolean',
        ]);

        $existingImagePath = $repair->resolveImagePath();
        if ($request->hasFile('image')) {
            if ($existingImagePath) {
                $repair->deleteImageFile();
            }
            $newPath = $this->storeUploadedRepairImage($request, $shopId);
            $validated['image_path'] = $newPath;
            $validated['image'] = $newPath;
        } elseif (!empty($validated['image_base64'])) {
            if ($existingImagePath) {
                $repair->deleteImageFile();
            }
            $newPath = $this->storeRepairImageFromBase64($validated['image_base64'], $shopId);
            $validated['image_path'] = $newPath;
            $validated['image'] = $newPath;
        } elseif ($request->boolean('remove_image') && $existingImagePath) {
            $repair->deleteImageFile();
            $validated['image_path'] = null;
            $validated['image'] = null;
        }

        unset($validated['image_base64'], $validated['remove_image']);

        // MASTERS PART 3: authoritative check shares the write's transaction, so a
        // race that archives the newly picked customer cannot slip past layer 1.
        // The customer already on this repair stays allowed; re-tagging it to a
        // different archived customer is rejected (see activeOrCurrentExistsRule).
        DB::transaction(function () use ($repair, $validated, $shopId) {
            Customer::lockActiveOrFail(
                (int) $shopId,
                (int) $validated['customer_id'],
                'customer_id',
                (int) $repair->customer_id,
            );

            $repair->update($validated);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Repair updated successfully!',
                'repair' => $this->serializeRepair($repair->fresh(['customer', 'invoice'])),
            ]);
        }

        return redirect()->route('repairs.index')->with('success', 'Repair updated successfully!');
    }

    public function updateStatus(Request $request, Repair $repair)
    {
        $this->authorize('update', $repair);

        if ($repair->status === 'delivered') {
            return back()->with('error', 'Delivered repairs cannot be changed.');
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['received', 'in_repair', 'ready'])],
        ]);

        $repair->update(['status' => $validated['status']]);

        return back()->with('success', 'Status updated to "' . ucwords(str_replace('_', ' ', $validated['status'])) . '".');
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

        $includeGst = $request->boolean('include_gst');
        $amount     = (float) $validated['amount'];
        $gstRate    = $includeGst
            ? (float) ($validated['gst_rate'] ?? (auth()->user()->shop->gst_rate ?? config('business.gst_rate_default')))
            : 0.0;

        $engine    = app(PricingEngine::class);
        $breakdown = $engine->computeRepair($shopId, $amount, $includeGst, $gstRate);

        $subtotal    = $breakdown->subtotal;
        $gstAmount   = $breakdown->gst;
        $gstRate     = $breakdown->gstRate;
        $totalAmount = $breakdown->finalTotal;
        $roundOff    = $breakdown->roundingAdjustment;

        $invoice = DB::transaction(function () use ($shopId, $repair, $subtotal, $gstRate, $gstAmount, $totalAmount, $roundOff) {
            $invoice = InvoiceAccountingService::createFinalized([
                'shop_id'        => $shopId,
                'customer_id'    => $repair->customer_id,
                'gold_rate'      => 0,
                'subtotal'       => $subtotal,
                'gst'            => $gstAmount,
                'gst_rate'       => $gstRate,
                'wastage_charge' => 0,
                'total'          => $totalAmount,
                'round_off'      => $roundOff,
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
                'payment_mode'     => 'cash',
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
                    'round_off'  => $roundOff,
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

        $repair->deleteImageFile();

        $repair->delete();

        return redirect()->route('repairs.index')->with('success', 'Repair deleted successfully.');
    }

    /** The photo of one repair, for a member of its shop with repairs.view (route middleware; {repair} is tenant-scoped). */
    public function image(Repair $repair)
    {
        return $repair->imageResponse();
    }

    private function repairImageDisk(): string
    {
        return Repair::imageWriteDisk();
    }

    private function storeUploadedRepairImage(Request $request, int $shopId): ?string
    {
        if (!$request->hasFile('image')) {
            return null;
        }

        return app(\App\Services\ImageOptimizer::class)->optimizeAndStore($request->file('image'), "repairs/{$shopId}", $this->repairImageDisk());
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
        $payload['image_url'] = $repair->resolveImageUrl();

        return $payload;
    }
}
