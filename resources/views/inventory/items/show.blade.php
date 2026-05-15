@php
    $isRetailer = $isRetailer ?? auth()->user()->shop?->isRetailer();
    $retailerHeaderCategoryLabel = $item->category ?: 'Uncategorized';
    $retailerHeaderDesignLabel = $item->design ?: 'Untitled item';
@endphp

<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Item</h1>
            <p class="text-sm text-gray-500 mt-1">
                <span class="font-mono font-semibold text-amber-700">{{ $item->barcode }}</span>
                <span class="mx-2">•</span>
                <span>{{ $isRetailer ? $retailerHeaderCategoryLabel : $item->category }}</span>
                @if($isRetailer || $item->design)
                    <span class="mx-2">•</span>
                    <span>{{ $isRetailer ? $retailerHeaderDesignLabel : $item->design }}</span>
                @endif
            </p>
        </div>
        <div class="page-actions flex flex-wrap gap-2">
            @if(!empty($publicShareUrl))
                <a href="{{ $publicShareUrl }}" target="_blank" rel="noopener noreferrer"
                   class="btn btn-dark">
                    <svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 3h7m0 0v7m0-7L10 14"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5v14a1 1 0 001 1h14"/>
                    </svg>
                    <span class="hidden sm:inline">Public Page</span>
                </a>
                <button type="button"
                        data-share-url="{{ $publicShareUrl }}"
                        onclick="copyShareLink(this.dataset.shareUrl)"
                        class="btn btn-secondary">
                    <svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                    </svg>
                    <span class="hidden sm:inline">Copy Link</span>
                </button>
            @endif
            @if($item->status === 'in_stock')
                <a href="{{ route('inventory.items.edit', $item) }}"
                   class="inline-flex items-center px-3 sm:px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors text-sm font-medium">
                    <svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <span class="hidden sm:inline">Edit</span>
                </a>
                <form method="POST" action="{{ route('inventory.items.destroy', $item) }}"
                      data-confirm-message="Are you sure you want to delete this item?{{ $isRetailer ? '' : ' The gold will be returned to the source lot.' }}"
                      data-ajax-delete
                      data-delete-redirect="{{ route('inventory.items.index') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="inline-flex items-center px-3 sm:px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm font-medium">
                        <svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        <span class="hidden sm:inline">Delete</span>
                    </button>
                </form>
            @endif
            <a href="{{ route('inventory.items.index') }}"
               class="inline-flex items-center px-3 sm:px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors text-sm font-medium">
                <svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                <span class="hidden sm:inline">Back to Items</span>
            </a>
        </div>
    </x-page-header>

    <div class="content-inner inventory-item-show-page {{ $isRetailer ? 'inventory-item-show-page--retailer' : '' }}">
        @php
            $isRetailer = auth()->user()->shop?->isRetailer();
            $makingCharges = (float) ($item->making_charges ?? 0);
            $stoneCharges = (float) ($item->stone_charges ?? 0);
            $hallmarkCharges = (float) ($item->hallmark_charges ?? 0);
            $rhodiumCharges = (float) ($item->rhodium_charges ?? 0);
            $otherCharges = (float) ($item->other_charges ?? 0);
            // cost_price = metal value only; overhead_cost = all charges combined.
            // Use overhead_cost directly now that charges are no longer mixed into cost_price.
            $retailerChargeTotal = (float) ($item->overhead_cost
                ?? ($makingCharges + $stoneCharges + $hallmarkCharges + $rhodiumCharges + $otherCharges));
            $goldPortion = max(0, (float) $item->cost_price);
            $retailerMetalBase = (float) $item->cost_price;

            if (!$isRetailer) {
                $fineGoldBase = (float) ($item->net_metal_weight * ($item->purity / 24));
                $wastageGrams = (float) ($item->wastage ?? 0);
                $fineGoldUsed = $fineGoldBase + $wastageGrams;
                $lotRate = $lot ? (float) ($lot->cost_per_fine_gram ?? 0) : 0;
                $estimatedGoldCost = $lotRate > 0 ? ($fineGoldUsed * $lotRate) : null;
            }

            // Margin is intentionally not computed.
            // The retailer pricing model defines:
            //   selling_price = (net_weight × today's metal rate) + overhead_cost
            // and `repriceInStockItems()` rewrites selling_price daily as the
            // owner saves new rates. By construction:
            //   selling_price − cost_price − overhead_cost ≡ 0
            // So a per-item "margin" is mathematically always zero and would
            // be misleading. Retailer profit lives inside making/wastage/rate
            // markups, not as a separate field on the item.
            $totalCost = (float) $item->cost_price + $retailerChargeTotal;

            $designLabel = $item->design ?: 'Untitled item';
            $categoryLabel = $item->category ?: 'Uncategorized';
            $subCategoryLabel = $item->sub_category ?: 'No sub-category';
            $metalTypeLabel = $item->metal_type ? ucfirst($item->metal_type) : '—';
            $purityLabel = $item->purity_label ?: '—';
            $vendorLabel = $item->vendor?->name ?: '—';
            $huidLabel = $item->huid ?: '—';
            $hallmarkDateLabel = $item->hallmark_date ? \Carbon\Carbon::parse($item->hallmark_date)->format('d M Y') : '—';
            $createdDateLabel = $item->created_at ? $item->created_at->format('d M Y') : '—';
            $createdTimeLabel = $item->created_at ? $item->created_at->format('h:i A') : '—';
            $updatedDateLabel = $item->updated_at ? $item->updated_at->format('d M Y') : '—';
            $updatedTimeLabel = $item->updated_at ? $item->updated_at->format('h:i A') : '—';
            $sourcePurchaseLabel = $item->stock_purchase_id
                ? ($item->stockPurchase?->purchase_number ?? "PUR-{$item->stock_purchase_id}")
                : 'Not linked';
            $invoiceLabel = $item->invoice?->invoice_number ?: 'Not linked';
            $soldDateLabel = ($item->status == 'sold')
                ? ($item->sold_at ? $item->sold_at->format('d M Y') : $updatedDateLabel)
                : 'Not sold';
            $retailerImageGallery = collect($item->image_gallery)
                ->map(function ($path) {
                    $path = trim((string) $path);
                    $url = \Illuminate\Support\Str::startsWith($path, ['http://', 'https://'])
                        ? $path
                        : asset('storage/' . preg_replace('/^storage\//', '', ltrim($path, '/')));

                    return ['path' => $path, 'url' => $url];
                })
                ->filter(fn ($image) => filled($image['path']))
                ->values();
            $retailerImageGalleryCount = $retailerImageGallery->count();
        @endphp

        @if($isRetailer)
            <div class="inventory-item-retailer-shell">
                <section class="inventory-item-retailer-hero">
                    <div class="inventory-item-retailer-media">
                        @if($retailerImageGalleryCount)
                            <div
                                id="retailer-item-carousel"
                                class="inventory-item-retailer-carousel hs-carousel"
                                data-hs-carousel='{"isAutoPlay": true}'
                                x-data="{
                                    active: 0,
                                    total: {{ $retailerImageGalleryCount }},
                                    autoplayTimer: null,
                                    syncFromScroll() {
                                        if (!this.$refs.track) return;
                                        const width = Math.max(this.$refs.track.clientWidth, 1);
                                        this.active = Math.max(0, Math.min(this.total - 1, Math.round(this.$refs.track.scrollLeft / width)));
                                    },
                                    goTo(index) {
                                        if (!this.$refs.track || this.total < 1) return;
                                        const target = ((index % this.total) + this.total) % this.total;
                                        this.$refs.track.scrollTo({
                                            left: this.$refs.track.clientWidth * target,
                                            behavior: 'smooth'
                                        });
                                        this.active = target;
                                    },
                                    prev() { this.goTo(this.active - 1); },
                                    next() { this.goTo(this.active + 1); },
                                    stopAutoPlay() {
                                        if (this.autoplayTimer) {
                                            clearInterval(this.autoplayTimer);
                                            this.autoplayTimer = null;
                                        }
                                    },
                                    startAutoPlay() {
                                        this.stopAutoPlay();
                                        if (this.total <= 1) return;
                                        this.autoplayTimer = setInterval(() => {
                                            if (!document.hidden) this.next();
                                        }, 4200);
                                    },
                                    init() {
                                        this.$nextTick(() => {
                                            this.syncFromScroll();
                                            this.startAutoPlay();
                                        });
                                    }
                                }"
                                x-init="init()"
                                @mouseenter="stopAutoPlay()"
                                @mouseleave="startAutoPlay()"
                                @focusin="stopAutoPlay()"
                                @focusout="startAutoPlay()"
                            >
                                <div
                                    class="inventory-item-retailer-carousel-track hs-carousel-body"
                                    x-ref="track"
                                    @scroll.debounce.80ms="syncFromScroll()"
                                >
                                    @foreach($retailerImageGallery as $galleryIndex => $galleryImage)
                                        <div class="inventory-item-retailer-carousel-slide hs-carousel-slide">
                                            <img src="{{ $galleryImage['url'] }}" alt="{{ $designLabel }} image {{ $galleryIndex + 1 }}" class="inventory-item-retailer-image">
                                        </div>
                                    @endforeach
                                </div>

                                @if($retailerImageGalleryCount > 1)
                                    <button
                                        type="button"
                                        class="inventory-item-retailer-carousel-nav inventory-item-retailer-carousel-nav--prev hs-carousel-prev"
                                        aria-label="Previous item image"
                                        @click="prev()"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        class="inventory-item-retailer-carousel-nav inventory-item-retailer-carousel-nav--next hs-carousel-next"
                                        aria-label="Next item image"
                                        @click="next()"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                    </button>
                                    <div class="inventory-item-retailer-carousel-dots hs-carousel-pagination" aria-label="Item image selector">
                                        @foreach($retailerImageGallery as $galleryIndex => $galleryImage)
                                            <button
                                                type="button"
                                                aria-label="Show item image {{ $galleryIndex + 1 }}"
                                                :class="{ 'is-active': active === {{ $galleryIndex }} }"
                                                @click="goTo({{ $galleryIndex }})"
                                            ></button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @else
                            <div class="inventory-item-retailer-image inventory-item-retailer-image--empty" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                                    <path d="M3.27 6.96 12 12.01l8.73-5.05"/>
                                    <path d="M12 22.08V12"/>
                                </svg>
                            </div>
                        @endif
                    </div>

                    {{-- Hero summary: title + status + barcode chip → price-and-CTA
                         row → cost breakdown. Edit lives in the page header above
                         (no duplicate). Operational fields (Vendor, HUID, Weights)
                         live in the detail cards below. --}}
                    <div class="inventory-item-retailer-summary">
                        <div class="inventory-item-retailer-title-row">
                            <div class="inventory-item-retailer-title-block">
                                <p>{{ $categoryLabel }} / {{ $subCategoryLabel }}</p>
                                <h2>{{ $designLabel }}</h2>
                            </div>
                            <div class="inventory-item-retailer-title-tags">
                                @if($item->status == 'in_stock')
                                    <span class="inventory-item-retailer-status inventory-item-retailer-status--stock">In Stock</span>
                                @elseif($item->status == 'sold')
                                    <span class="inventory-item-retailer-status inventory-item-retailer-status--sold">Sold</span>
                                @else
                                    <span class="inventory-item-retailer-status inventory-item-retailer-status--neutral">{{ ucfirst($item->status) }}</span>
                                @endif
                                <span class="inventory-item-retailer-barcode-chip" title="Barcode">{{ $item->barcode }}</span>
                            </div>
                        </div>

                        <div class="inventory-item-retailer-price-band">
                            <div class="inventory-item-retailer-price-card inventory-item-retailer-price-card--main">
                                <span>Selling Price / MRP</span>
                                <strong>₹{{ number_format($item->selling_price, 2) }}</strong>
                            </div>
                            <a href="{{ route('pos.index') }}?barcode={{ $item->barcode }}"
                               class="inventory-item-retailer-action inventory-item-retailer-action--primary inventory-item-retailer-action--inline-cta {{ $item->status == 'in_stock' ? '' : 'is-disabled' }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17"/>
                                </svg>
                                Sell in POS
                            </a>
                        </div>

                        {{-- Cost breakdown — moved into the hero so the full
                             pricing story sits in one panel. Header still shows
                             the Cost / Total summary on the right. --}}
                        <section class="inventory-item-retailer-card inventory-item-retailer-card--pricing inventory-item-retailer-card--in-hero">
                            <div class="inventory-item-retailer-card-head">
                                <h3>Cost Breakdown</h3>
                                <span>Cost ₹{{ number_format($item->cost_price, 2) }} · Total ₹{{ number_format($totalCost, 2) }}</span>
                            </div>
                            <div class="inventory-item-retailer-ledger">
                                <div>
                                    <span>Metal Base</span>
                                    <strong>₹{{ number_format($retailerMetalBase, 2) }}</strong>
                                </div>
                                <div>
                                    <span>Making</span>
                                    <strong>₹{{ number_format($makingCharges, 2) }}</strong>
                                </div>
                                <div>
                                    <span>Stone</span>
                                    <strong>₹{{ number_format($stoneCharges, 2) }}</strong>
                                </div>
                                <div>
                                    <span>Hallmark</span>
                                    <strong>₹{{ number_format($hallmarkCharges, 2) }}</strong>
                                </div>
                                <div>
                                    <span>Rhodium</span>
                                    <strong>₹{{ number_format($rhodiumCharges, 2) }}</strong>
                                </div>
                                <div>
                                    <span>Other</span>
                                    <strong>₹{{ number_format($otherCharges, 2) }}</strong>
                                </div>
                            </div>
                        </section>
                    </div>
                </section>

                <div class="inventory-item-retailer-record-layout">
                    <div class="inventory-item-retailer-main-column">
                        {{-- Cost Breakdown moved up into the hero summary block;
                             not rendered here again. --}}

                        {{-- Sale snapshot: status / invoice link / sold date /
                             availability. Sits in the main column between the
                             Cost Breakdown and the Labels card so the two
                             columns have similar heights at desktop width. --}}
                        <section class="inventory-item-retailer-card inventory-item-retailer-card--sale">
                            <div class="inventory-item-retailer-card-head">
                                <h3>Sale</h3>
                            </div>

                            <div class="inventory-item-retailer-sale-grid">
                                <div>
                                    <span>Sale Status</span>
                                    <strong>{{ $item->status == 'sold' ? 'Sold' : 'Not sold' }}</strong>
                                </div>
                                <div>
                                    <span>Invoice</span>
                                    @if($item->status == 'sold' && $item->invoice)
                                        <a href="{{ route('invoices.show', $item->invoice) }}">{{ $invoiceLabel }}</a>
                                    @else
                                        <strong>{{ $invoiceLabel }}</strong>
                                    @endif
                                </div>
                                <div>
                                    <span>Sold on</span>
                                    <strong>{{ $soldDateLabel }}</strong>
                                </div>
                                <div>
                                    <span>Availability</span>
                                    <strong>{{ $item->status == 'in_stock' ? 'Available for POS' : ucfirst($item->status ?: 'Unknown') }}</strong>
                                </div>
                            </div>
                        </section>

                        {{-- Labels & navigation: secondary tasks for this item.
                             Kept separate from the hero so the hero stays focused
                             on the two primary actions (Sell, Edit). --}}
                        <section class="inventory-item-retailer-card inventory-item-retailer-card--actions">
                            <div class="inventory-item-retailer-card-head">
                                <h3>Labels &amp; Navigation</h3>
                            </div>
                            <div class="inventory-item-retailer-secondary-actions">
                                <form method="POST" action="{{ route('tags.print') }}" target="_blank">
                                    @csrf
                                    <input type="hidden" name="item_ids[]" value="{{ $item->id }}">
                                    <input type="hidden" name="label_size" value="medium">
                                    <input type="hidden" name="include_barcode_image" value="1">
                                    <input type="hidden" name="print_format" value="standard">
                                    <button type="submit" class="inventory-item-retailer-action inventory-item-retailer-action--dark">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                        </svg>
                                        Print Standard
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('tags.print') }}" target="_blank">
                                    @csrf
                                    <input type="hidden" name="item_ids[]" value="{{ $item->id }}">
                                    <input type="hidden" name="label_size" value="medium">
                                    <input type="hidden" name="include_barcode_image" value="1">
                                    <input type="hidden" name="print_format" value="folded">
                                    <input type="hidden" name="folded_size" value="95x12">
                                    <button type="submit" class="inventory-item-retailer-action inventory-item-retailer-action--muted">Folded 95x12</button>
                                </form>
                                <form method="POST" action="{{ route('tags.print') }}" target="_blank">
                                    @csrf
                                    <input type="hidden" name="item_ids[]" value="{{ $item->id }}">
                                    <input type="hidden" name="label_size" value="medium">
                                    <input type="hidden" name="include_barcode_image" value="1">
                                    <input type="hidden" name="print_format" value="folded">
                                    <input type="hidden" name="folded_size" value="95x15">
                                    <button type="submit" class="inventory-item-retailer-action inventory-item-retailer-action--muted">Folded 95x15</button>
                                </form>
                                <a href="{{ route('inventory.items.index') }}" class="inventory-item-retailer-action inventory-item-retailer-action--muted">
                                    All Items
                                </a>
                            </div>
                        </section>
                    </div>

                    <div class="inventory-item-retailer-side-column">
                        <section class="inventory-item-retailer-card inventory-item-retailer-card--weights">
                            <div class="inventory-item-retailer-card-head">
                                <h3>Weights</h3>
                                <span>grams</span>
                            </div>
                            <div class="inventory-item-retailer-metric-grid">
                                <div>
                                    <span>Stone</span>
                                    <strong>{{ number_format($item->stone_weight ?? 0, 3) }}</strong>
                                </div>
                                <div>
                                    <span>Net Metal</span>
                                    <strong>{{ number_format($item->net_metal_weight, 3) }}</strong>
                                </div>
                                <div>
                                    <span>Gross</span>
                                    <strong>{{ number_format($item->gross_weight, 3) }}</strong>
                                </div>
                                <div>
                                    <span>Purity</span>
                                    <strong>{{ $purityLabel }}</strong>
                                </div>
                            </div>
                        </section>

                        <section class="inventory-item-retailer-card inventory-item-retailer-card--vendor">
                            <div class="inventory-item-retailer-card-head">
                                <h3>Vendor & Dates</h3>
                            </div>
                            <div class="inventory-item-retailer-kv-list">
                                <div>
                                    <span>Vendor</span>
                                    <strong>{{ $vendorLabel }}</strong>
                                </div>
                                <div>
                                    <span>HUID</span>
                                    <strong class="font-mono">{{ $huidLabel }}</strong>
                                </div>
                                <div>
                                    <span>Hallmark Date</span>
                                    <strong>{{ $hallmarkDateLabel }}</strong>
                                </div>
                                <div>
                                    <span>Created</span>
                                    <strong>{{ $createdDateLabel }}</strong>
                                    <em>{{ $createdTimeLabel }}</em>
                                </div>
                                <div>
                                    <span>Updated</span>
                                    <strong>{{ $updatedDateLabel }}</strong>
                                    <em>{{ $updatedTimeLabel }}</em>
                                </div>
                            </div>
                            @if($item->stock_purchase_id)
                                <a href="{{ route('inventory.purchases.show', $item->stock_purchase_id) }}" class="inventory-item-retailer-source-link">
                                    <span>
                                        <small>Source Purchase</small>
                                        <strong>{{ $sourcePurchaseLabel }}</strong>
                                    </span>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </a>
                            @else
                                <div class="inventory-item-retailer-source-link inventory-item-retailer-source-link--empty">
                                    <span>
                                        <small>Source Purchase</small>
                                        <strong>{{ $sourcePurchaseLabel }}</strong>
                                    </span>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
                                    </svg>
                                </div>
                            @endif
                        </section>
                    </div>
                </div>
            </div>
        @else
        <div class="grid grid-cols-12 items-start gap-3 sm:gap-4">
            <!-- Bento: Item -->
            <div class="col-span-12 lg:col-span-8 lg:row-span-2 lg:min-h-[400px] bg-white rounded-xl border border-gray-200 shadow-sm p-3 sm:p-4 lg:p-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 lg:gap-6 items-stretch">
                    <div class="flex justify-center md:justify-start">
                        <div class="w-full h-full min-h-[240px] md:min-h-[300px]">
                            @if($retailerImageGalleryCount)
                                @include('partials.image-carousel', [
                                    'urls'     => $retailerImageGallery->pluck('url')->all(),
                                    'alt'      => $item->design ?: $item->barcode,
                                    'idPrefix' => 'mfg-item-' . $item->id,
                                ])
                            @else
                                <div class="rounded-xl bg-gray-100 flex items-center justify-center w-full h-full min-h-[240px] md:min-h-[300px]">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                                        <path d="M3.27 6.96 12 12.01l8.73-5.05"/>
                                        <path d="M12 22.08V12"/>
                                    </svg>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="min-w-0 flex flex-col">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-xs text-gray-500">Design</div>
                                <div class="text-lg font-semibold text-gray-900 truncate">{{ $item->design ?: '—' }}</div>
                                <div class="text-sm text-gray-600 truncate">
                                    {{ $item->category }}{{ $item->sub_category ? ' / ' . $item->sub_category : '' }}
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                @if($item->status == 'in_stock')
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">In Stock</span>
                                @elseif($item->status == 'sold')
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">Sold</span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">{{ ucfirst($item->status) }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="mt-3 text-xs text-gray-500">Barcode</div>
                        <div class="font-mono text-base font-semibold text-amber-700 bg-amber-50 px-3 py-1.5 rounded-lg inline-flex">
                            {{ $item->barcode }}
                        </div>

                        <div class="mt-3 grid grid-cols-1 gap-2">
                            {{-- Inside the manufacturer branch — always show Metal when set. --}}
                            @if($item->metal_type)
                                <div class="rounded-lg bg-gray-50 border border-gray-100 p-2.5">
                                    <div class="text-[11px] text-gray-500">Metal</div>
                                    <div class="text-sm font-semibold text-slate-700">{{ ucfirst($item->metal_type) }}</div>
                                </div>
                            @endif
                            <div class="rounded-lg bg-gray-50 border border-gray-100 p-2.5">
                                <div class="text-[11px] text-gray-500">Purity</div>
                                <div class="text-sm font-semibold text-yellow-700">{{ $item->purity_label }}</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 border border-gray-100 p-2.5">
                                <div class="text-[11px] text-gray-500">Net</div>
                                <div class="text-sm font-semibold text-gray-900">{{ number_format($item->net_metal_weight, 3) }}g</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 border border-gray-100 p-2.5">
                                <div class="text-[11px] text-gray-500">Gross</div>
                                <div class="text-sm font-semibold text-gray-900">{{ number_format($item->gross_weight, 3) }}g</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bento: Weight & Gold -->
            <div class="col-span-12 sm:col-span-6 lg:col-span-4 bg-white rounded-xl border border-gray-200 shadow-sm p-2.5 sm:p-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">Weight & Gold</h2>
                    <div class="text-[11px] text-gray-500">grams</div>
                </div>

                <div class="mt-2.5 grid grid-cols-2 gap-2.5">
                    <div class="rounded-lg bg-gray-50 border border-gray-100 p-2.5">
                        <div class="text-[11px] text-gray-500">Stone</div>
                        <div class="text-sm sm:text-base font-semibold text-gray-900">{{ number_format($item->stone_weight ?? 0, 3) }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 border border-gray-100 p-2.5">
                        <div class="text-[11px] text-gray-500">Net Metal</div>
                        <div class="text-sm sm:text-base font-semibold text-gray-900">{{ number_format($item->net_metal_weight, 3) }}</div>
                    </div>
                </div>

                {{-- Manufacturer-only Fine/Wastage block (this whole @else branch
                     is the manufacturer view). --}}
                <div class="mt-2.5 grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <div class="rounded-lg bg-yellow-50 border border-yellow-100 p-1.5 sm:p-2">
                        <div class="text-[11px] text-yellow-700">Fine (base)</div>
                        <div class="text-sm font-semibold text-yellow-900">{{ number_format($fineGoldBase, 3) }}</div>
                    </div>
                    <div class="rounded-lg bg-yellow-50 border border-yellow-100 p-1.5 sm:p-2">
                        <div class="text-[11px] text-yellow-700">Wastage</div>
                        <div class="text-sm font-semibold text-yellow-900">{{ number_format($wastageGrams, 4) }}</div>
                    </div>
                    <div class="rounded-lg bg-yellow-50 border border-yellow-100 p-1.5 sm:p-2">
                        <div class="text-[11px] text-yellow-700">Fine (used)</div>
                        <div class="text-sm font-semibold text-yellow-900">{{ number_format($fineGoldUsed, 3) }}</div>
                    </div>
                </div>
            </div>

            <!-- Bento: Cost (Manufacturer) — Gold + Making + Stone breakdown with lot rate -->
            <div class="col-span-12 sm:col-span-6 lg:col-span-4 bg-white rounded-xl border border-gray-200 shadow-sm p-3 sm:p-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900">Cost</h2>
                        @if($lot && ($lot->cost_per_fine_gram ?? null))
                            <div class="text-[11px] text-gray-500">Rate: ₹{{ number_format($lot->cost_per_fine_gram, 2) }}/fine g</div>
                        @else
                            <div class="text-[11px] text-gray-500">Rate: —</div>
                        @endif
                    </div>
                    <div class="rounded-lg bg-amber-50 border border-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">
                        ₹{{ number_format($item->cost_price, 2) }}
                    </div>
                </div>

                <div class="mt-3">
                    <div class="text-[11px] text-gray-500">Cost Price</div>
                    <div class="text-2xl sm:text-3xl font-bold leading-tight text-gray-900">₹{{ number_format($item->cost_price, 2) }}</div>
                </div>

                <div class="mt-3 space-y-1.5 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Gold</span>
                        <span class="font-semibold text-gray-900">₹{{ number_format($goldPortion, 2) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Making</span>
                        <span class="font-semibold text-gray-900">₹{{ number_format($makingCharges, 2) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Stone</span>
                        <span class="font-semibold text-gray-900">₹{{ number_format($stoneCharges, 2) }}</span>
                    </div>
                </div>

                @if($estimatedGoldCost !== null)
                    <div class="mt-3 rounded-lg bg-yellow-50 border border-yellow-100 px-3 py-2">
                        <div class="text-[11px] text-yellow-700">Gold cost (est.)</div>
                        <div class="text-sm font-semibold text-yellow-900">₹{{ number_format($estimatedGoldCost, 2) }}</div>
                    </div>
                @endif
            </div>

            <!-- Bento: Source & Dates (Manufacturer) -->
            <div class="col-span-12 sm:col-span-6 lg:col-span-4 bg-white rounded-xl border border-gray-200 shadow-sm p-3 sm:p-4">
                <h2 class="text-sm font-semibold text-gray-900">Source & Dates</h2>

                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                        <div class="text-[11px] text-gray-500">Created</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $item->created_at->format('d M Y') }}</div>
                        <div class="text-[11px] text-gray-500">{{ $item->created_at->format('h:i A') }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                        <div class="text-[11px] text-gray-500">Updated</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $item->updated_at->format('d M Y') }}</div>
                        <div class="text-[11px] text-gray-500">{{ $item->updated_at->format('h:i A') }}</div>
                    </div>
                </div>

                {{-- Manufacturer-only: Source Lot link. --}}
                <div class="mt-3">
                    <div class="rounded-lg bg-gray-50 border border-gray-100 p-3 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-[11px] text-gray-500">Source Lot</div>
                            @if($item->metalLot)
                                <a href="{{ route('inventory.gold.show', $item->metalLot) }}" class="text-sm font-semibold text-amber-700 hover:underline">
                                    Lot #{{ $item->metalLot->lot_number }}
                                </a>
                                <div class="text-[11px] text-gray-500">
                                    {{ $item->metalLot->type ?? 'Gold' }} • {{ $item->metalLot->purity }}K
                                </div>
                            @else
                                <div class="text-sm font-semibold text-gray-800">—</div>
                            @endif
                        </div>
                        @if($item->metalLot)
                            <svg class="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Bento: Actions -->
            <div class="col-span-12 sm:col-span-6 lg:col-span-4 bg-white rounded-xl border border-gray-200 shadow-sm p-3 sm:p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">Actions</h2>
                    <div class="text-[11px] text-gray-500">fast tasks</div>
                </div>

                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <a href="{{ route('pos.index') }}?barcode={{ $item->barcode }}"
                       class="inline-flex items-center justify-center px-3 py-2.5 rounded-lg font-medium transition-colors text-sm
                              {{ $item->status == 'in_stock' ? 'bg-green-600 text-white hover:bg-green-700' : 'bg-gray-100 text-gray-400 cursor-not-allowed pointer-events-none' }}">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        Sell in POS
                    </a>

                    {{-- Label-print buttons are retailer-only and live in the
                         retailer hero section. The manufacturer view does not
                         offer label printing. --}}
                </div>

                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <a href="{{ route('inventory.items.edit', $item) }}"
                       class="inline-flex items-center justify-center px-3 py-2.5 rounded-lg font-medium transition-colors text-sm
                              {{ $item->status === 'in_stock' ? 'bg-amber-50 text-amber-700 hover:bg-amber-100' : 'bg-gray-100 text-gray-400 cursor-not-allowed pointer-events-none' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Edit
                    </a>
                    <a href="{{ route('inventory.items.index') }}"
                       class="inline-flex items-center justify-center px-3 py-2.5 bg-gray-50 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors font-medium text-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>All Items
                    </a>
                </div>
            </div>

            <!-- Bento: Sale -->
            <div class="col-span-12 sm:col-span-6 lg:col-span-4 bg-white rounded-xl border border-gray-200 shadow-sm p-3 sm:p-4">
                <h2 class="text-sm font-semibold text-gray-900">Sale</h2>

                @if($item->status == 'sold' && $item->invoice)
                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="rounded-lg bg-blue-50 border border-blue-100 p-3">
                            <div class="text-[11px] text-blue-700">Invoice</div>
                            <a href="{{ route('invoices.show', $item->invoice) }}" class="text-sm font-semibold text-blue-800 hover:underline">
                                {{ $item->invoice->invoice_number }}
                            </a>
                        </div>
                        <div class="rounded-lg bg-blue-50 border border-blue-100 p-3">
                            <div class="text-[11px] text-blue-700">Sold on</div>
                            <div class="text-sm font-semibold text-blue-800">
                                {{ $item->sold_at ? $item->sold_at->format('d M Y') : $item->updated_at->format('d M Y') }}
                            </div>
                        </div>
                    </div>
                @else
                    <div class="mt-3 rounded-lg bg-gray-50 border border-gray-100 p-3 text-sm text-gray-600">
                        This item is not sold yet.
                    </div>
                @endif
            </div>
        </div>
        @endif
    </div>

    <script>
        async function copyShareLink(link) {
            if (!link) return;

            try {
                await navigator.clipboard.writeText(link);
                alert('Share link copied.');
            } catch (_) {
                const helper = document.createElement('textarea');
                helper.value = link;
                helper.style.position = 'fixed';
                helper.style.opacity = '0';
                document.body.appendChild(helper);
                helper.select();
                document.execCommand('copy');
                document.body.removeChild(helper);
                alert('Share link copied.');
            }
        }

    </script>
</x-app-layout>
