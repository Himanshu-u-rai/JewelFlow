<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Item</h1>
            <p class="text-sm text-gray-500 mt-1">
                <span class="font-mono font-semibold text-amber-700">{{ $item->barcode }}</span>
                <span class="mx-2">•</span>
                <span>{{ $item->category }}</span>
                @if($item->design)
                    <span class="mx-2">•</span>
                    <span>{{ $item->design }}</span>
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

    <div class="content-inner">
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

            // True margin = selling_price minus both metal cost and overhead charges.
            $margin = (float) $item->selling_price - (float) $item->cost_price - $retailerChargeTotal;
            $totalCost = (float) $item->cost_price + $retailerChargeTotal;
            $marginPct = $totalCost > 0 ? ($margin / $totalCost) * 100 : 0;
        @endphp

        <div class="grid grid-cols-12 items-start gap-3 sm:gap-4">
            <!-- Bento: Item -->
            <div class="col-span-12 lg:col-span-8 lg:row-span-2 lg:min-h-[400px] bg-white rounded-xl border border-gray-200 shadow-sm p-3 sm:p-4 lg:p-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 lg:gap-6 items-stretch">
                    <div class="flex justify-center md:justify-start">
                        <div class="w-full h-full">
                            @if($item->image)
                                <img src="{{ asset('storage/' . $item->image) }}" alt="{{ $item->design }}" class="rounded-xl object-cover bg-gray-100 w-full h-full min-h-[240px] md:min-h-[300px]">
                            @else
                                <div class="rounded-xl bg-gray-100 flex items-center justify-center w-full h-full min-h-[240px] md:min-h-[300px]">
                                    <span class="text-4xl"></span>
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
                            @if($isRetailer && $item->metal_type)
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

                @if(!$isRetailer)
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
                @endif
            </div>

            <!-- Bento: Pricing -->
            <div class="col-span-12 sm:col-span-6 lg:col-span-4 bg-white rounded-xl border border-gray-200 shadow-sm p-3 sm:p-4">
                @if($isRetailer)
                    {{-- Retailer: Cost → Selling → Margin --}}
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="text-sm font-semibold text-gray-900">Pricing</h2>
                        <div class="rounded-lg {{ $margin >= 0 ? 'bg-green-50 border-green-100 text-green-700' : 'bg-red-50 border-red-100 text-red-700' }} border px-2.5 py-1 text-xs font-semibold">
                            {{ number_format($marginPct, 1) }}% margin
                        </div>
                    </div>

                    <div class="mt-3">
                        <div class="text-[11px] text-gray-500">Selling Price / MRP</div>
                        <div class="text-2xl sm:text-3xl font-bold leading-tight text-amber-600">₹{{ number_format($item->selling_price, 2) }}</div>
                    </div>

                    <div class="mt-3 space-y-1.5 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Cost Price</span>
                            <span class="font-semibold text-gray-900">₹{{ number_format($item->cost_price, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Profit / Margin</span>
                            <span class="font-bold {{ $margin >= 0 ? 'text-green-600' : 'text-red-600' }}">₹{{ number_format($margin, 2) }}</span>
                        </div>
                        <hr class="border-gray-200">
                        <div class="text-[11px] text-gray-400 uppercase tracking-wider">Cost Breakdown (records)</div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Metal (Base)</span>
                            <span class="text-gray-700">₹{{ number_format($retailerMetalBase, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Making</span>
                            <span class="text-gray-700">₹{{ number_format($makingCharges, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Stone</span>
                            <span class="text-gray-700">₹{{ number_format($stoneCharges, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Hallmark</span>
                            <span class="text-gray-700">₹{{ number_format($hallmarkCharges, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Rhodium</span>
                            <span class="text-gray-700">₹{{ number_format($rhodiumCharges, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Other</span>
                            <span class="text-gray-700">₹{{ number_format($otherCharges, 2) }}</span>
                        </div>
                    </div>
                @else
                    {{-- Manufacturer: Gold/Making/Stone breakdown with lot rate --}}
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
                @endif
            </div>

            <!-- Bento: Source / Dates -->
            <div class="col-span-12 sm:col-span-6 lg:col-span-4 bg-white rounded-xl border border-gray-200 shadow-sm p-3 sm:p-4">
                <h2 class="text-sm font-semibold text-gray-900">{{ $isRetailer ? 'Vendor & Dates' : 'Source & Dates' }}</h2>

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

                @if($isRetailer)
                    {{-- Retailer: Vendor + HUID --}}
                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                            <div class="text-[11px] text-gray-500">Vendor</div>
                            <div class="text-sm font-semibold text-gray-900">{{ $item->vendor?->name ?? '—' }}</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                            <div class="text-[11px] text-gray-500">HUID</div>
                            <div class="text-sm font-semibold text-gray-900 font-mono">{{ $item->huid ?? '—' }}</div>
                        </div>
                    </div>
                    @if($item->hallmark_date)
                    <div class="mt-3">
                        <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                            <div class="text-[11px] text-gray-500">Hallmark Date</div>
                            <div class="text-sm font-semibold text-gray-900">{{ \Carbon\Carbon::parse($item->hallmark_date)->format('d M Y') }}</div>
                        </div>
                    </div>
                    @endif
                    @if($item->stock_purchase_id)
                    <div class="mt-3">
                        <div class="rounded-lg bg-amber-50 border border-amber-200 p-3 flex items-center justify-between gap-3">
                            <div>
                                <div class="text-[11px] text-amber-700">Source Purchase</div>
                                <a href="{{ route('inventory.purchases.show', $item->stock_purchase_id) }}" class="text-sm font-semibold text-amber-700 hover:underline font-mono">
                                    {{ $item->stockPurchase?->purchase_number ?? "PUR-{$item->stock_purchase_id}" }}
                                </a>
                            </div>
                            <svg class="w-4 h-4 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                    </div>
                    @endif
                @else
                    {{-- Manufacturer: Source Lot --}}
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
                @endif
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

                    @if($isRetailer)
                        <div class="w-full space-y-2">
                            <form method="POST" action="{{ route('tags.print') }}" target="_blank" class="w-full">
                                @csrf
                                <input type="hidden" name="item_ids[]" value="{{ $item->id }}">
                                <input type="hidden" name="label_size" value="medium">
                                <input type="hidden" name="include_barcode_image" value="1">
                                <input type="hidden" name="print_format" value="standard">
                                <button type="submit"
                                        class="w-full inline-flex items-center justify-center px-3 py-2.5 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition-colors font-medium text-sm">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                    </svg>
                                    Print Standard
                                </button>
                            </form>
                            <div class="grid grid-cols-2 gap-2">
                                <form method="POST" action="{{ route('tags.print') }}" target="_blank">
                                    @csrf
                                    <input type="hidden" name="item_ids[]" value="{{ $item->id }}">
                                    <input type="hidden" name="include_barcode_image" value="1">
                                    <input type="hidden" name="print_format" value="folded">
                                    <input type="hidden" name="folded_size" value="95x12">
                                    <button type="submit"
                                            class="w-full inline-flex items-center justify-center px-3 py-2.5 bg-gray-100 text-gray-800 rounded-lg hover:bg-gray-200 transition-colors font-medium text-sm">
                                        Folded 95x12
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('tags.print') }}" target="_blank">
                                    @csrf
                                    <input type="hidden" name="item_ids[]" value="{{ $item->id }}">
                                    <input type="hidden" name="include_barcode_image" value="1">
                                    <input type="hidden" name="print_format" value="folded">
                                    <input type="hidden" name="folded_size" value="95x15">
                                    <button type="submit"
                                            class="w-full inline-flex items-center justify-center px-3 py-2.5 bg-gray-100 text-gray-800 rounded-lg hover:bg-gray-200 transition-colors font-medium text-sm">
                                        Folded 95x15
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endif
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
