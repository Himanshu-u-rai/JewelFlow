@php
    $complianceStatus = $customer->complianceStatus();
    $kycDocs = $customer->kycDocuments()->where('is_active', \Illuminate\Support\Facades\DB::raw('true'))->latest('created_at')->get();
    $complianceShouldOpen = $customer->isComplianceVerified()
        ? false
        : ($errors->has('pan') || $errors->has('aadhaar') || $errors->has('consent') || old('pan') || old('aadhaar') || old('consent'));
@endphp
<section
    class="customers-show-compliance bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6"
    x-data="{ kycOpen: {{ $complianceShouldOpen ? 'true' : 'false' }} }"
    @keydown.escape.window="kycOpen = false"
>
    <div class="customers-show-compliance-head">
        <div class="customers-show-compliance-title">
            <h2>KYC / Compliance</h2>
        </div>
        <span class="customers-show-compliance-status {{ $complianceStatus === 'compliant' ? 'is-verified' : ($complianceStatus === 'pending_verification' ? 'is-pending' : 'is-missing') }}">
            {{ $complianceStatus === 'compliant' ? 'Verified'
               : ($complianceStatus === 'pending_verification' ? 'PAN on file' : 'PAN not provided') }}
        </span>
    </div>

    <button type="button" class="customers-show-compliance-open" @click="kycOpen = true">
        {{ $customer->isComplianceVerified() ? 'Review KYC' : 'Verify KYC' }}
    </button>

    <div
        x-show="kycOpen"
        x-cloak
        class="customers-show-kyc-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="customers-show-kyc-title-{{ $customer->id }}"
    >
        <div class="customers-show-kyc-backdrop" @click="kycOpen = false"></div>
        <div class="customers-show-kyc-panel">
            <div class="customers-show-kyc-head">
                <div>
                    <h2 id="customers-show-kyc-title-{{ $customer->id }}">KYC / Compliance</h2>
                    <p>Needed for any sale above ₹2,00,000.</p>
                </div>
                <button type="button" class="customers-show-kyc-close" @click="kycOpen = false" aria-label="Close KYC compliance">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            <div class="customers-show-kyc-body">
                <div class="customers-show-kyc-status-row">
                    <span>Status</span>
                    <strong>{{ $complianceStatus === 'compliant' ? 'Verified' : ($complianceStatus === 'pending_verification' ? 'PAN on file, not verified' : 'PAN not provided') }}</strong>
                </div>

                @if($customer->isComplianceVerified())
                    <dl class="customers-show-kyc-facts">
                        <div><dt>PAN</dt><dd>{{ $customer->pan ?: '—' }}</dd></div>
                        <div><dt>Aadhaar</dt><dd>{{ $customer->id_number ?: '—' }}</dd></div>
                        <div><dt>Verified On</dt><dd>{{ optional($customer->compliance_verified_at)->format('d M Y') }}</dd></div>
                    </dl>
                @endif

                <form method="POST" action="{{ route('customers.verify-compliance', $customer) }}" data-turbo-frame="_top" class="customers-show-kyc-form">
                    @csrf
                    <div class="customers-show-kyc-form-grid">
                        <div>
                            <label>PAN</label>
                            <input type="text" name="pan" value="{{ old('pan', $customer->pan) }}" maxlength="10"
                                   class="uppercase" placeholder="ABCDE1234F">
                            @error('pan')<p class="customers-show-kyc-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label>Aadhaar Number <span>(12 digits, optional)</span></label>
                            <input type="text" name="aadhaar" value="{{ old('aadhaar', $customer->id_number) }}" maxlength="12" inputmode="numeric"
                                   placeholder="123412341234">
                            @error('aadhaar')<p class="customers-show-kyc-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <label class="customers-show-kyc-consent">
                        <input type="checkbox" name="consent" value="1" @checked(old('consent'))>
                        <span>Customer consents to recording their PAN / Aadhaar for tax-compliance purposes.</span>
                    </label>
                    @error('consent')<p class="customers-show-kyc-error">{{ $message }}</p>@enderror
                    <button type="submit" class="customers-show-kyc-primary">Verify Customer</button>
                </form>

                <div class="customers-show-compliance-docs">
                    <h3>ID Documents</h3>
                    @if($kycDocs->isNotEmpty())
                        <ul>
                            @foreach($kycDocs as $doc)
                                <li>
                                    <span>{{ \App\Models\KycDocument::ALLOWED_TYPES[$doc->document_type] ?? ucfirst($doc->document_type) }}
                                        <small>{{ optional($doc->created_at)->format('d M Y') }}</small></span>
                                    <span>
                                        <a href="{{ route('kyc-documents.show', $doc) }}" target="_blank">View</a>
                                        <form method="POST" action="{{ route('kyc-documents.destroy', $doc) }}" data-turbo-frame="_top"
                                              onsubmit="return confirm('Remove this document?')">
                                            @csrf @method('DELETE')
                                            <button type="submit">Remove</button>
                                        </form>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p>No ID documents uploaded.</p>
                    @endif

                    @can('customers.create')
                    <form id="kyc-upload-form-{{ $customer->id }}" enctype="multipart/form-data" class="customers-show-kyc-upload">
                        @csrf
                        <input type="hidden" name="customer_id" value="{{ $customer->id }}">
                        <div>
                            <label>Document type</label>
                            <select name="document_type">
                                @foreach(\App\Models\KycDocument::ALLOWED_TYPES as $val => $label)
                                    <option value="{{ $val }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label>File (JPG/PNG/PDF, ≤10MB)</label>
                            <input type="file" name="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                                   required>
                        </div>
                        <button type="submit">Upload ID</button>
                        <span class="kyc-upload-msg"></span>
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
                            .catch(function () { msg.textContent = 'Upload failed. Use JPG/PNG/PDF up to 10MB.'; });
                        });
                    })();
                    </script>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</section>
