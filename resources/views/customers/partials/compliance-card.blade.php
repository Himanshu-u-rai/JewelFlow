@php
    $complianceStatus = $customer->complianceStatus();
    $kycDocs = $customer->kycDocuments()->where('is_active', \Illuminate\Support\Facades\DB::raw('true'))->latest('created_at')->get();
@endphp
<section class="customers-show-compliance bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
    <div class="flex items-center justify-between gap-3 mb-2">
        <h2 class="text-lg font-semibold text-gray-900">KYC / Compliance</h2>
        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold
            {{ $complianceStatus === 'compliant' ? 'bg-green-100 text-green-800'
               : ($complianceStatus === 'pending_verification' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800') }}">
            {{ $complianceStatus === 'compliant' ? 'Verified'
               : ($complianceStatus === 'pending_verification' ? 'PAN on file — not verified' : 'PAN not provided') }}
        </span>
    </div>
    <p class="text-sm text-gray-500 mb-3">Needed for any sale above ₹2,00,000. Record the customer's PAN / Aadhaar and consent, and attach a copy of their ID.</p>

    @if($customer->isComplianceVerified())
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm mb-1">
            <div><dt class="text-gray-500">PAN</dt><dd class="font-medium text-gray-900">{{ $customer->pan ?: '—' }}</dd></div>
            <div><dt class="text-gray-500">Aadhaar</dt><dd class="font-medium text-gray-900">{{ $customer->id_number ?: '—' }}</dd></div>
            <div><dt class="text-gray-500">Verified On</dt><dd class="font-medium text-gray-900">{{ optional($customer->compliance_verified_at)->format('d M Y') }}</dd></div>
        </dl>
    @endif

    <details class="mt-2" {{ $customer->isComplianceVerified() ? '' : 'open' }}>
        <summary class="text-sm text-blue-600 cursor-pointer">{{ $customer->isComplianceVerified() ? 'Update / re-verify' : 'Verify now' }}</summary>
        <form method="POST" action="{{ route('customers.verify-compliance', $customer) }}" data-turbo-frame="_top" class="mt-3 space-y-3">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">PAN</label>
                    <input type="text" name="pan" value="{{ old('pan', $customer->pan) }}" maxlength="10"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm uppercase" placeholder="ABCDE1234F">
                    @error('pan')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Aadhaar Number <span class="text-gray-400 text-xs">(12 digits, optional)</span></label>
                    <input type="text" name="aadhaar" value="{{ old('aadhaar', $customer->id_number) }}" maxlength="12" inputmode="numeric"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="123412341234">
                    @error('aadhaar')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
            <label class="flex items-start gap-2 text-sm text-gray-700">
                <input type="checkbox" name="consent" value="1" class="mt-0.5" @checked(old('consent'))>
                <span>Customer consents to recording their PAN / Aadhaar for tax-compliance purposes.</span>
            </label>
            @error('consent')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            <button type="submit" class="btn btn-primary btn-sm">Verify Customer</button>
        </form>
    </details>

    {{-- ID document images --}}
    <div class="mt-4 pt-4 border-t border-gray-100">
        <h3 class="text-sm font-semibold text-gray-800 mb-2">ID Documents</h3>
        @if($kycDocs->isNotEmpty())
            <ul class="divide-y divide-gray-100 mb-3">
                @foreach($kycDocs as $doc)
                    <li class="flex items-center justify-between py-2 text-sm">
                        <span class="text-gray-700">{{ \App\Models\KycDocument::ALLOWED_TYPES[$doc->document_type] ?? ucfirst($doc->document_type) }}
                            <span class="text-gray-400 text-xs">· {{ optional($doc->created_at)->format('d M Y') }}</span></span>
                        <span class="flex items-center gap-3">
                            <a href="{{ route('kyc-documents.show', $doc) }}" target="_blank" class="text-blue-600">View</a>
                            <form method="POST" action="{{ route('kyc-documents.destroy', $doc) }}" data-turbo-frame="_top"
                                  onsubmit="return confirm('Remove this document?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-600">Remove</button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-sm text-gray-400 mb-3">No ID documents uploaded.</p>
        @endif

        @can('customers.create')
        <form id="kyc-upload-form-{{ $customer->id }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
            @csrf
            <input type="hidden" name="customer_id" value="{{ $customer->id }}">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Document type</label>
                <select name="document_type" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    @foreach(\App\Models\KycDocument::ALLOWED_TYPES as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">File (JPG/PNG/PDF, ≤10MB)</label>
                <input type="file" name="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                       class="text-sm" required>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm">Upload ID</button>
            <span class="kyc-upload-msg text-xs text-gray-500"></span>
        </form>
        <script>
        (function () {
            var f = document.getElementById('kyc-upload-form-{{ $customer->id }}');
            if (!f || f.dataset.bound) return;
            f.dataset.bound = '1';
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                var msg = f.querySelector('.kyc-upload-msg');
                msg.textContent = 'Uploading…';
                fetch('{{ route('kyc-documents.store') }}', {
                    method: 'POST',
                    body: new FormData(f),
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                })
                .then(function (r) { if (!r.ok) { throw r; } return r.json(); })
                .then(function () { window.location.reload(); })
                .catch(function () { msg.textContent = 'Upload failed — use JPG/PNG/PDF up to 10MB.'; });
            });
        })();
        </script>
        @endcan
    </div>
</section>
