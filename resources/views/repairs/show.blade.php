@php
    $status = $repair->status === 'pending' ? 'received' : $repair->status;
    $statusLabel = match($status) {
        'in_repair' => 'In repair',
        'ready' => 'Ready for pickup',
        'delivered' => 'Delivered',
        default => 'Received',
    };
    $statusClass = match($status) {
        'in_repair' => 'is-progress',
        'ready' => 'is-ready',
        'delivered' => 'is-delivered',
        default => 'is-received',
    };
    $workflow = [
        ['key' => 'received', 'label' => 'Received'],
        ['key' => 'in_repair', 'label' => 'In repair'],
        ['key' => 'ready', 'label' => 'Ready'],
        ['key' => 'delivered', 'label' => 'Delivered'],
    ];
    $statusIndex = collect($workflow)->search(fn ($step) => $step['key'] === $status);
    $statusIndex = $statusIndex === false ? 0 : $statusIndex;
    $repairImageUrl = $repair->resolveImageUrl();
@endphp

<x-app-layout>
    <x-page-header class="repairs-show-header">
        <div class="repairs-show-title-block">
            <h1 class="page-title">Repair #{{ $repair->repair_number ?? '—' }}</h1>
            <p class="page-subtitle">Opened {{ optional($repair->created_at)->format('d M Y') }}</p>
        </div>
        <div class="page-actions repairs-show-header-actions">
            <a href="{{ route('repairs.index') }}" class="repairs-show-action repairs-show-action--back">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 12H5m7 7-7-7 7-7"/>
                </svg>
                <span>Back to Repairs</span>
            </a>
            @if($repair->status !== 'delivered')
                <a href="{{ route('repairs.edit', $repair) }}" class="repairs-show-action repairs-show-action--primary">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <span>Edit Repair</span>
                </a>
            @elseif($repair->invoice_id)
                <a href="{{ route('invoices.show', $repair->invoice_id) }}" class="repairs-show-action repairs-show-action--primary">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.414V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span>View Invoice</span>
                </a>
            @endif
        </div>
    </x-page-header>

    <div class="content-inner repairs-show-page">
        <section class="repairs-show-workflow" aria-label="Repair progress">
            <div class="repairs-show-workflow-head">
                <div>
                    <span class="repairs-show-label">Current status</span>
                    <strong>{{ $statusLabel }}</strong>
                </div>
                <span class="repairs-show-status {{ $statusClass }}">{{ $statusLabel }}</span>
            </div>
            <ol class="repairs-show-steps">
                @foreach($workflow as $index => $step)
                    <li class="{{ $index < $statusIndex ? 'is-complete' : ($index === $statusIndex ? 'is-current' : '') }}">
                        <span class="repairs-show-step-dot">
                            @if($index < $statusIndex)
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                </svg>
                            @else
                                {{ $index + 1 }}
                            @endif
                        </span>
                        <span>{{ $step['label'] }}</span>
                    </li>
                @endforeach
            </ol>
        </section>

        <div class="repairs-show-layout">
            <main class="repairs-show-main">
                @php($challanRef = 'REP-' . str_pad((string) $repair->repair_number, 3, '0', STR_PAD_LEFT))
                {{-- Intake challan: read-only receipt for the customer. Printing, downloading
                     or sharing it never changes the repair, its number or any payment. --}}
                <section class="repairs-show-card" data-challan-share-box aria-labelledby="repair-challan-heading">
                    <header class="repairs-show-card-head">
                        <div>
                            <span class="repairs-show-label">Customer challan</span>
                            <h2 id="repair-challan-heading">Intake receipt {{ $challanRef }}</h2>
                        </div>
                    </header>
                    <div style="display:flex; flex-wrap:wrap; gap:.5rem; padding:0 1rem 1rem;">
                        <a href="{{ route('repairs.challan', $repair) }}" target="_blank" rel="noopener" class="repairs-show-action">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6z"/></svg>
                            <span>Print challan</span>
                        </a>
                        <a href="{{ route('repairs.challan.pdf', $repair) }}" class="repairs-show-action" data-turbo="false">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
                            <span>Download PDF</span>
                        </a>
                        <button type="button" class="repairs-show-action" data-challan-share
                                data-url="{{ route('repairs.challan.pdf', $repair) }}" data-filename="{{ $challanRef }}-challan.pdf">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.7 10.7l6.6-3.4M8.7 13.3l6.6 3.4M18 8a3 3 0 100-6 3 3 0 000 6zM6 15a3 3 0 100-6 3 3 0 000 6zm12 7a3 3 0 100-6 3 3 0 000 6z"/></svg>
                            <span data-challan-share-label>Share challan</span>
                        </button>
                        @if($customerWhatsApp['link'])
                            <a href="{{ $customerWhatsApp['link'] }}" target="_blank" rel="noopener noreferrer" class="repairs-show-action">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                                <span>WhatsApp customer</span>
                            </a>
                        @endif
                    </div>
                    <p style="padding:0 1rem; margin:0 0 .5rem; font-size:.85rem;">
                        @if($customerWhatsApp['link'])
                            WhatsApp customer opens a chat with {{ \App\Support\Mobile::forDisplay($repair->customer?->mobile) }} in the WhatsApp account on this device, with a short message filled in. Nothing is sent until you press Send. To include the challan, tap Download PDF, then in that chat tap Attach (📎) → Document and choose {{ $challanRef }}-challan.pdf.
                        @else
                            {{ $customerWhatsApp['problem'] }}
                        @endif
                    </p>
                    <p data-challan-share-status role="status" aria-live="polite" style="padding:0 1rem; margin:0 0 .5rem; font-size:.85rem;"></p>
                    <p data-challan-share-fallback hidden style="padding:0 1rem 1rem; margin:0; font-size:.85rem;">
                        To send it on WhatsApp: tap Download PDF, open the customer’s chat in WhatsApp, tap Attach (📎) → Document and choose {{ $challanRef }}-challan.pdf. You send the message yourself.
                    </p>
                </section>

                <section class="repairs-show-card repairs-show-card--details">
                    <header class="repairs-show-card-head">
                        <div>
                            <span class="repairs-show-label">Repair item</span>
                            <h2>{{ $repair->item_description }}</h2>
                        </div>
                        <span class="repairs-show-ticket">REP-{{ str_pad($repair->repair_number, 3, '0', STR_PAD_LEFT) }}</span>
                    </header>

                    <div class="repairs-show-description">
                        <span class="repairs-show-label">Work requested</span>
                        <p>{{ $repair->description ?: 'No repair instructions recorded.' }}</p>
                    </div>

                    <dl class="repairs-show-facts">
                        <div>
                            <dt>Metal</dt>
                            <dd>{{ ucfirst($repair->metal_type ?? 'gold') }}</dd>
                        </div>
                        <div>
                            <dt>Purity</dt>
                            <dd>{{ $repair->purityLabel() ?? 'Not recorded' }}</dd>
                        </div>
                        <div>
                            <dt>Gross weight</dt>
                            <dd>{{ number_format((float) $repair->gross_weight, 3) }} g</dd>
                        </div>
                        <div>
                            <dt>Quantity (pieces)</dt>
                            <dd>{{ $repair->item_quantity ?? 'Not recorded' }}</dd>
                        </div>
                        <div>
                            <dt>Expected ready by</dt>
                            <dd>{{ $repair->due_date?->format('d M Y') ?? 'Not recorded' }}</dd>
                        </div>
                        <div>
                            <dt>Condition at receipt</dt>
                            <dd style="white-space:pre-line">{{ $repair->intake_condition ?: 'Not recorded' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="repairs-show-card repairs-show-card--customer">
                    <header class="repairs-show-card-head">
                        <div>
                            <span class="repairs-show-label">Customer</span>
                            <h2>{{ $repair->customer?->name ?? 'Customer unavailable' }}</h2>
                        </div>
                    </header>
                    <dl class="repairs-show-customer-grid">
                        <div>
                            <dt>Mobile</dt>
                            <dd>{{ \App\Support\Mobile::forDisplay($repair->customer?->mobile) ?: 'Not recorded' }}</dd>
                        </div>
                        <div>
                            <dt>Received</dt>
                            <dd>{{ optional($repair->created_at)->format('d M Y, h:i A') ?? 'Not recorded' }}</dd>
                        </div>
                    </dl>
                </section>
            </main>

            <aside class="repairs-show-rail">
                <section class="repairs-show-card repairs-show-card--cost">
                    <header class="repairs-show-card-head">
                        <div>
                            <span class="repairs-show-label">Repair value</span>
                            <h2>Cost summary</h2>
                        </div>
                    </header>
                    <dl class="repairs-show-cost-list">
                        <div>
                            <dt>Estimated cost</dt>
                            <dd>₹{{ number_format((float) ($repair->estimated_cost ?? 0), 2) }}</dd>
                        </div>
                        <div>
                            <dt>Final cost</dt>
                            <dd>{{ $repair->final_cost !== null ? '₹'.number_format((float) $repair->final_cost, 2) : 'Pending' }}</dd>
                        </div>
                        @if($repair->invoice_id)
                            <div>
                                <dt>Invoice</dt>
                                <dd><a href="{{ route('invoices.show', $repair->invoice_id) }}">View invoice</a></dd>
                            </div>
                        @endif
                    </dl>
                </section>

                <section class="repairs-show-card repairs-show-card--photo">
                    <header class="repairs-show-card-head">
                        <div>
                            <span class="repairs-show-label">Reference</span>
                            <h2>Item photo</h2>
                        </div>
                    </header>
                    <div class="repairs-show-photo">
                        @if($repairImageUrl)
                            <img src="{{ $repairImageUrl }}" alt="{{ $repair->item_description }}">
                        @else
                            <div class="repairs-show-photo-empty">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2 1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <span>No photo uploaded</span>
                            </div>
                        @endif
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>
