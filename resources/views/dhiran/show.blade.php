<x-dhiran-layout title="Loan Detail">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Loan {{ $loan->loan_number }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ $loan->customer->name ?? '---' }}
                @php
                    $statusColors = [
                        'pending_evidence' => 'bg-amber-100 text-amber-800',
                        'active' => 'bg-emerald-100 text-emerald-800',
                        'overdue' => 'bg-rose-100 text-rose-800',
                        'closed' => 'bg-slate-100 text-slate-600',
                        'renewed' => 'bg-sky-100 text-sky-800',
                        'forfeited' => 'bg-red-100 text-red-800',
                    ];
                @endphp
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold ml-2 {{ $statusColors[$loan->status] ?? 'bg-slate-100 text-slate-600' }}">
                    {{ $loan->status === 'pending_evidence' ? 'Awaiting Evidence' : ucfirst($loan->status) }}
                </span>
            </p>
        </div>
        <div class="page-actions flex flex-wrap gap-2">
            @if($loan->status === 'active')
                @can('dhiran.pay')
                <button type="button" class="btn btn-dark btn-sm" onclick="document.getElementById('payInterestModal').showModal()">
                    <x-icon name="dollar-sign" size="14" class="mr-1" />
                    Pay Interest
                </button>
                <button type="button" class="btn btn-dark btn-sm" onclick="document.getElementById('repayModal').showModal()">
                    <x-icon name="square-check-big" size="14" class="mr-1" />
                    Repay
                </button>
                @endcan
                @can('dhiran.release')
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('releaseItemModal').showModal()">
                    <x-icon name="package-open" size="14" class="mr-1" />
                    Release Item
                </button>
                @endcan
                @can('dhiran.pay')
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('precloseModal').showModal()">
                    <x-icon name="circle-x" size="14" class="mr-1" />
                    Pre-Close
                </button>
                @endcan
                @can('dhiran.renew')
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('renewModal').showModal()">
                    <x-icon name="rotate-cw" size="14" class="mr-1" />
                    Renew
                </button>
                @endcan
                @can('dhiran.forfeit')
                @if(! $loan->forfeitureNoticeSent())
                <form method="POST" action="{{ route('dhiran.send-notice', $loan) }}" class="inline" data-turbo-frame="_top">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <x-icon name="mail" size="14" class="mr-1" />
                        Send Notice
                    </button>
                </form>
                @endif

                {{-- Execute Forfeit — only after the notice is sent AND the notice
                     period has elapsed (mirrors the service guard). Serious terminal
                     action → explicit confirm. --}}
                @if($loan->canExecuteForfeit())
                <form method="POST" action="{{ route('dhiran.forfeit', $loan) }}" class="inline" data-turbo-frame="_top"
                      onsubmit="return confirm('Execute forfeiture for {{ $loan->loan_number }}? This permanently forfeits all pledged items and writes off the outstanding balance. This cannot be undone.');">
                    @csrf
                    <button type="submit" class="btn btn-danger btn-sm">
                        <x-icon name="trash-2" size="14" class="mr-1" />
                        Execute Forfeit
                    </button>
                </form>
                @endif
                @endcan
            @endif

            {{-- Print Forfeiture Notice — visible once the notice is sent (and stays
                 available after forfeiture, where it is legally useful). --}}
            @can('dhiran.view')
            @if($loan->forfeitureNoticeSent())
            <a href="{{ route('dhiran.forfeiture-notice', $loan) }}" target="_blank" class="btn btn-secondary btn-sm">
                <x-icon name="file" size="14" class="mr-1" />
                Print Forfeiture Notice
            </a>
            @endif

            {{-- Print Closure Certificate — only for closed loans. --}}
            @if($loan->status === 'closed')
            <a href="{{ route('dhiran.closure-certificate', $loan) }}" target="_blank" class="btn btn-secondary btn-sm">
                <x-icon name="circle-check" size="14" class="mr-1" />
                Print Closure Certificate
            </a>
            @endif
            @endcan

            <a href="{{ route('dhiran.receipt', $loan) }}" target="_blank" class="btn btn-secondary btn-sm">
                <x-icon name="printer" size="14" class="mr-1" />
                Print Receipt
            </a>
            <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
                <x-icon name="arrow-left" size="14" class="mr-1" />
                All Loans
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner dhiran-show-root">

        {{-- Evidence gate: a pending-evidence loan must have a pledged-item photo
             AND a borrower ID proof before it can be activated. --}}
        @if($loan->status === 'pending_evidence')
        <div class="rounded-2xl border border-amber-200 bg-amber-50 shadow-sm p-6 mb-6">
            <h2 class="text-base font-semibold text-amber-900 mb-1">Evidence required before activation</h2>
            <p class="text-xs text-amber-800/80 mb-4">This loan is not active yet. Upload the required evidence below, then activate it.</p>
            <ul class="space-y-2 mb-4">
                <li class="flex items-center gap-2 text-sm {{ ($evidence['item_photo'] ?? false) ? 'text-emerald-700' : 'text-amber-900' }}">
                    @if($evidence['item_photo'] ?? false)
                        <x-icon name="check" size="16" stroke-width="2.5" />
                    @else
                        <x-icon name="circle" size="16" />
                    @endif
                    Pledged item photo
                </li>
                <li class="flex items-center gap-2 text-sm {{ ($evidence['id_proof'] ?? false) ? 'text-emerald-700' : 'text-amber-900' }}">
                    @if($evidence['id_proof'] ?? false)
                        <x-icon name="check" size="16" stroke-width="2.5" />
                    @else
                        <x-icon name="circle" size="16" />
                    @endif
                    Borrower ID proof
                </li>
            </ul>
            @can('dhiran.create')
            @if($evidence['ok'] ?? false)
                <form method="POST" action="{{ route('dhiran.activate-loan', $loan) }}" data-turbo-frame="_top"
                      onsubmit="return confirm('Activate this loan? Interest will start accruing.');">
                    @csrf
                    <button type="submit" class="btn btn-success btn-sm">
                        Activate Loan
                    </button>
                </form>
            @else
                <button type="button" class="btn btn-sm" disabled>Activate Loan</button>
                <p class="text-xs text-amber-800/80 mt-2">Upload both required documents in the Evidence &amp; Documents section below to enable activation.</p>
            @endif
            @endcan
        </div>
        @endif

        {{-- Loan Info Grid --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 mb-6">
            <div class="ds-info-grid">
                <div>
                    <div class="ds-info-item-label">Loan Date</div>
                    <div class="ds-info-item-value">{{ $loan->loan_date->format('d M Y') }}</div>
                </div>
                <div>
                    <div class="ds-info-item-label">Maturity Date</div>
                    <div class="ds-info-item-value">{{ $loan->maturity_date ? $loan->maturity_date->format('d M Y') : '---' }}</div>
                </div>
                <div>
                    <div class="ds-info-item-label">Tenure</div>
                    <div class="ds-info-item-value">{{ $loan->tenure_months }} months</div>
                </div>
                <div>
                    <div class="ds-info-item-label">Interest Rate</div>
                    <div class="ds-info-item-value">{{ $loan->interest_rate_monthly }}% / month</div>
                </div>
                <div>
                    <div class="ds-info-item-label">Interest Type</div>
                    <div class="ds-info-item-value">{{ ucfirst($loan->interest_type) }}</div>
                </div>
                <div>
                    <div class="ds-info-item-label">LTV %</div>
                    <div class="ds-info-item-value">{{ number_format($loan->ltv_percent ?? 0, 1) }}%</div>
                </div>
                @if((float) $loan->gold_rate_on_date > 0)
                <div>
                    <div class="ds-info-item-label">Gold rate (at pledge)</div>
                    <div class="ds-info-item-value">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->gold_rate_on_date, 2) }}</div>
                </div>
                @endif
                @if((float) ($loan->silver_rate_on_date ?? 0) > 0)
                <div>
                    <div class="ds-info-item-label">Silver rate (at pledge)</div>
                    <div class="ds-info-item-value">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->silver_rate_on_date, 2) }}</div>
                </div>
                @endif
                <div>
                    <div class="ds-info-item-label">Customer</div>
                    <div class="ds-info-item-value">
                        @if($loan->customer)
                            <a href="{{ route('dhiran.customer-loans', $loan->customer) }}" class="text-amber-700 hover:underline">{{ $loan->customer->name }}</a>
                        @else
                            ---
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Financial Summary --}}
        <div class="ds-fin-grid">
            <div class="ds-fin-card">
                <div class="ds-fin-label">Principal</div>
                <div class="ds-fin-value">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->principal_amount, 2) }}</div>
            </div>
            <div class="ds-fin-card">
                <div class="ds-fin-label">Outstanding Principal</div>
                <div class="ds-fin-value">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->outstanding_principal ?? $loan->principal_amount, 2) }}</div>
            </div>
            <div class="ds-fin-card">
                <div class="ds-fin-label">Outstanding Interest</div>
                <div class="ds-fin-value text-amber-700">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->outstanding_interest ?? 0, 2) }}</div>
            </div>
            <div class="ds-fin-card">
                <div class="ds-fin-label">Outstanding Penalty</div>
                <div class="ds-fin-value text-rose-600">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->outstanding_penalty ?? 0, 2) }}</div>
            </div>
            <div class="ds-fin-card ds-fin-card-primary">
                <div class="ds-fin-label">Total Outstanding</div>
                <div class="ds-fin-value">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->total_outstanding ?? 0, 2) }}</div>
            </div>
        </div>

        {{-- Pledged Items --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
            <div class="p-4 border-b border-slate-200">
                <h2 class="text-lg font-semibold text-slate-900">Pledged Items</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Description</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Metal</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Gross Wt</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Net Wt</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Purity</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Fine Wt</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Market Val</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Loan Val</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">HUID</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($loan->items ?? [] as $item)
                            <tr class="hover:bg-slate-50/70">
                                <td class="pl-6 pr-4 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="{{ route('dhiran.items.show', $item) }}" class="text-slate-700 hover:text-amber-700">{{ $item->description }}</a>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-slate-600 capitalize">{{ $item->metal_type ?? 'gold' }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ number_format($item->gross_weight, 3) }}g</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ number_format($item->net_metal_weight, 3) }}g</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-center text-slate-600">@if(($item->metal_type ?? "gold") === "other")—@else{{ rtrim(rtrim((string) $item->purity, "0"), ".") }}{{ ($item->metal_type ?? "gold") === "silver" ? "" : "K" }}@endif</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ number_format($item->fine_weight, 3) }}g</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-700">{{ $currencySymbol ?? '₹' }}{{ number_format($item->market_value, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right font-medium text-slate-800">{{ $currencySymbol ?? '₹' }}{{ number_format($item->loan_value, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    @php
                                        $itemStatus = $item->status ?? 'pledged';
                                        $itemStatusColors = [
                                            'pledged' => 'bg-amber-100 text-amber-800',
                                            'released' => 'bg-emerald-100 text-emerald-800',
                                            'forfeited' => 'bg-red-100 text-red-800',
                                        ];
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $itemStatusColors[$itemStatus] ?? 'bg-slate-100 text-slate-600' }}">
                                        {{ ucfirst($itemStatus) }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-slate-500">{{ $item->huid ?? '---' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-6 py-8 text-center text-slate-500 text-sm">No items recorded</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Payment History --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
            <div class="p-4 border-b border-slate-200">
                <h2 class="text-lg font-semibold text-slate-900">Payment History</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Type</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Amount</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Principal</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Interest</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Penalty</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Method</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Receipt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($loan->payments ?? [] as $payment)
                            <tr class="hover:bg-slate-50/70">
                                <td class="pl-6 pr-4 py-4 whitespace-nowrap text-sm text-slate-600">{{ $payment->paid_at ? $payment->paid_at->format('d M Y') : '---' }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-slate-700">{{ ucfirst($payment->type ?? '---') }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right font-medium text-slate-800">{{ $currencySymbol ?? '₹' }}{{ number_format($payment->amount, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ $currencySymbol ?? '₹' }}{{ number_format($payment->principal_component ?? 0, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ $currencySymbol ?? '₹' }}{{ number_format($payment->interest_component ?? 0, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ $currencySymbol ?? '₹' }}{{ number_format($payment->penalty_component ?? 0, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-700">
                                        {{ ucfirst($payment->method ?? 'cash') }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    <a href="{{ route('dhiran.payment-receipt', [$loan, $payment]) }}" target="_blank" class="text-amber-700 hover:underline text-xs font-medium">Print Receipt</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-slate-500 text-sm">No payments recorded yet</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- KYC Section --}}
        @if($loan->aadhaar || $loan->pan)
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 mb-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">KYC Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @if($loan->aadhaar)
                <div>
                    <div class="ds-info-item-label">Aadhaar Number</div>
                    <div class="ds-info-item-value">{{ $loan->aadhaar }}</div>
                </div>
                @endif
                @if($loan->pan)
                <div>
                    <div class="ds-info-item-label">PAN</div>
                    <div class="ds-info-item-value">{{ $loan->pan }}</div>
                </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Evidence & Documents (private, shop-scoped attachments) --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 mb-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-1">Evidence &amp; Documents</h2>
            <p class="text-xs text-slate-500 mb-4">Pledged-item photos, borrower ID proof, and loan documents. Files are private to your shop.</p>

            @if(($attachments ?? collect())->isNotEmpty())
                <ul class="divide-y divide-slate-100 mb-4">
                    @foreach($attachments as $att)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0">
                                <span class="text-sm font-medium text-slate-800">{{ ucwords(str_replace('_', ' ', $att->document_type)) }}</span>
                                <span class="text-xs text-slate-400 block truncate">{{ $att->original_name }} · {{ number_format(($att->size_bytes ?? 0) / 1024) }} KB</span>
                            </div>
                            <a href="{{ route('dhiran.attachments.show', $att) }}" target="_blank" rel="noopener" class="text-amber-700 hover:underline text-xs font-semibold shrink-0">View</a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-slate-400 mb-4">No documents uploaded yet.</p>
            @endif

            @can('dhiran.create')
            @if($errors->has('file'))
                <p class="text-sm text-red-600 mb-2">{{ $errors->first('file') }}</p>
            @endif
            <form method="POST" action="{{ route('dhiran.attachments.store') }}" enctype="multipart/form-data" data-turbo-frame="_top"
                  class="dh-upload-form border-t border-slate-100 pt-4">
                @csrf
                <input type="hidden" name="owner_type" value="dhiran_loan">
                <input type="hidden" name="owner_id" value="{{ $loan->id }}">
                <div class="flex-1">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Document type</label>
                    <select name="document_type" required class="dh-form-control dh-native-select">
                        <option value="item_photo">Pledged item photo</option>
                        <option value="id_proof_front">ID proof (front)</option>
                        <option value="id_proof_back">ID proof (back)</option>
                        <option value="address_proof">Address proof</option>
                        <option value="borrower_photo">Borrower photo</option>
                        <option value="pledge_agreement">Pledge agreement</option>
                        <option value="signed_terms">Signed terms</option>
                        <option value="valuation_proof">Valuation proof</option>
                        <option value="loan_document">Other loan document</option>
                    </select>
                </div>
                <div class="flex-1">
                    <label class="block text-xs font-medium text-slate-600 mb-1">File <span class="text-slate-400">(JPG/PNG/PDF, max 8 MB)</span></label>
                    <input type="file" name="file" required accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                           class="dh-file-input">
                </div>
                <button type="submit" class="btn btn-dark btn-sm shrink-0">Upload</button>
            </form>
            @endcan
        </div>

        {{-- Notes --}}
        @if($loan->notes)
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 mb-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-2">Notes</h2>
            <p class="text-sm text-slate-600 whitespace-pre-line">{{ $loan->notes }}</p>
        </div>
        @endif
    </div>

    {{-- Pay Interest Modal --}}
    <dialog id="payInterestModal" class="dh-dialog">
        <div class="dh-dialog-body">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Pay Interest</h3>
            <form method="POST" action="{{ route('dhiran.pay-interest', $loan) }}" data-turbo-frame="_top">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Amount</label>
                    <input type="number" step="0.01" name="amount" required placeholder="0.00"
                           class="dh-form-control">
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Payment Method</label>
                    <select name="method" class="dh-form-control dh-native-select">
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="dh-dialog-footer">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('payInterestModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-dark btn-sm">Pay Interest</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Repay Modal --}}
    <dialog id="repayModal" class="dh-dialog">
        <div class="dh-dialog-body">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Repay Loan</h3>
            <form method="POST" action="{{ route('dhiran.repay', $loan) }}" data-turbo-frame="_top">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Amount</label>
                    <input type="number" step="0.01" name="amount" required placeholder="0.00"
                           class="dh-form-control">
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Payment Method</label>
                    <select name="method" class="dh-form-control dh-native-select">
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="dh-dialog-footer">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('repayModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-dark btn-sm">Submit Payment</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Release Item Modal --}}
    <dialog id="releaseItemModal" class="dh-dialog">
        <div class="dh-dialog-body">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Release Pledged Item</h3>
            <form method="POST" action="{{ route('dhiran.release-item', $loan) }}" data-turbo-frame="_top">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Select Item</label>
                    <select name="item_id" required class="dh-form-control dh-native-select">
                        <option value="">Choose item...</option>
                        @foreach($loan->items ?? [] as $item)
                            @if(($item->status ?? 'pledged') === 'pledged')
                                <option value="{{ $item->id }}">{{ $item->description }} ({{ number_format($item->gross_weight, 3) }}g)</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <div class="dh-dialog-footer">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('releaseItemModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-dark btn-sm">Release Item</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Pre-Close Modal --}}
    <dialog id="precloseModal" class="dh-dialog">
        <div class="dh-dialog-body">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Pre-Close Loan</h3>
            <p class="text-sm text-slate-600 mb-4">This will close the loan and release all pledged items. Total outstanding: <strong>{{ $currencySymbol ?? '₹' }}{{ number_format($loan->total_outstanding ?? 0, 2) }}</strong></p>
            <form method="POST" action="{{ route('dhiran.pre-close', $loan) }}" data-turbo-frame="_top">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Payment Method</label>
                    <select name="method" class="dh-form-control dh-native-select">
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="dh-dialog-footer">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('precloseModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-dark btn-sm">Pre-Close Loan</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Renew Modal --}}
    <dialog id="renewModal" class="dh-dialog">
        <div class="dh-dialog-body">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Renew Loan</h3>
            <form method="POST" action="{{ route('dhiran.renew', $loan) }}" data-turbo-frame="_top">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">New Tenure (Months)</label>
                    <input type="number" name="tenure_months" required min="1" value="{{ $loan->tenure_months }}"
                           class="dh-form-control">
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">New Interest Rate (%/month)</label>
                    <input type="number" step="0.01" name="interest_rate_monthly" required value="{{ $loan->interest_rate_monthly }}"
                           class="dh-form-control">
                </div>
                <div class="dh-dialog-footer">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('renewModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-dark btn-sm">Renew Loan</button>
                </div>
            </form>
        </div>
    </dialog>
</x-dhiran-layout>
