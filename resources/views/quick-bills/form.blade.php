<x-app-layout>
    @php
        $editing = $quickBill->exists;
        $shop = auth()->user()->shop;
        $customerDirectory = $customers->map(fn ($customer) => [
            'id' => $customer->id,
            'name' => $customer->name,
            'mobile' => $customer->mobile,
            'address' => $customer->address,
        ])->values();
    @endphp

    <x-page-header class="ops-treatment-header">
        <div>
            <h1 class="page-title">{{ $editing ? 'Edit Quick Bill' : 'New Quick Bill' }}</h1>
        </div>
        <div class="page-actions flex flex-wrap items-center gap-2">
            @if($editing)
                <span class="inline-flex items-center rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">{{ $quickBill->bill_number }}</span>
                <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">{{ ucfirst($quickBill->status) }}</span>
            @endif
            <a href="{{ route('quick-bills.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                Back to Register
            </a>
        </div>
    </x-page-header>

    <div class="content-inner max-w-[1380px] mx-auto ops-treatment-page" x-data="quickBillForm({
        customers: @js($customerDirectory),
        items: @js($initialItems),
        payments: @js($initialPayments),
        selectedCustomerId: @js(old('customer_id', $quickBill->customer_id)),
        customerName: @js(old('customer_name', $quickBill->customer_name)),
        customerMobile: @js(old('customer_mobile', $quickBill->customer_mobile)),
        customerAddress: @js(old('customer_address', $quickBill->customer_address)),
        pricingMode: @js(old('pricing_mode', $quickBill->pricing_mode ?: 'gst_exclusive')),
        gstRate: @js((float) old('gst_rate', $quickBill->gst_rate ?? ($shop?->gst_rate ?? 3))),
        discountType: @js(old('discount_type', $quickBill->discount_type)),
        discountValue: @js((float) old('discount_value', $quickBill->discount_value ?? 0)),
        roundOff: @js((float) old('round_off', $quickBill->round_off ?? 0)),
    })">
        @if($errors->any())
            <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 shadow-sm">
                <div class="mb-2 font-semibold">Please fix the highlighted quick bill details.</div>
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php
            $notesPanelValue = trim((string) old('notes', $quickBill->notes));
            $termsPanelValue = trim((string) old('terms', $quickBill->terms ?: $shop?->billingSettings?->terms_and_conditions));
            $notesPanelOpen = $notesPanelValue !== '' || $termsPanelValue !== '';
            $paymentDraft = old('payments', $initialPayments ?? []);
            $paymentsPanelOpen = is_countable($paymentDraft) && count($paymentDraft) > 0;
        @endphp

        <form method="POST" action="{{ $editing ? route('quick-bills.update', $quickBill) : route('quick-bills.store') }}" class="space-y-6">
            @csrf
            @if($editing)
                @method('PUT')
            @endif

            <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 xl:col-span-7">
                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-12">
                                <div class="sm:col-span-2 xl:col-span-7">
                                    <label class="mb-2 block text-sm font-medium text-slate-600">Existing customer</label>
                                    <select name="customer_id" x-model="selectedCustomerId" @change="applyCustomer($event.target.value)" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                        <option value="">Walk-in / Manual</option>
                                        @foreach($customers as $customer)
                                            <option value="{{ $customer->id }}">{{ $customer->name }} · {{ $customer->mobile }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="xl:col-span-5">
                                    <label class="mb-2 block text-sm font-medium text-slate-600">Bill date</label>
                                    <input type="date" name="bill_date" value="{{ old('bill_date', optional($quickBill->bill_date)->format('Y-m-d') ?: now()->toDateString()) }}" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                </div>
                                <div class="xl:col-span-6">
                                    <label class="mb-2 block text-sm font-medium text-slate-600">Customer name</label>
                                    <input type="text" name="customer_name" x-model="customerName" placeholder="Walk-in customer or party name" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:ring-slate-900/10">
                                </div>
                                <div class="xl:col-span-6">
                                    <label class="mb-2 block text-sm font-medium text-slate-600">Mobile</label>
                                    <input type="text" name="customer_mobile" x-model="customerMobile" placeholder="Customer mobile number" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:ring-slate-900/10">
                                </div>
                                <div class="sm:col-span-2 xl:col-span-12">
                                    <label class="mb-2 block text-sm font-medium text-slate-600">Address</label>
                                    <textarea name="customer_address" x-model="customerAddress" rows="3" placeholder="Billing address (optional)" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:ring-slate-900/10"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="xl:col-span-5" x-data="{ open: {{ $notesPanelOpen ? 'true' : 'false' }} }">
                            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                                <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left sm:px-6">
                                    <div>
                                        <div class="text-sm font-semibold text-slate-900">Notes & Terms</div>
                                        <div class="mt-1 text-xs text-slate-500">Keep collapsed when the bill does not need extra text.</div>
                                    </div>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-500 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" />
                                    </svg>
                                </button>

                                <div x-show="open" x-transition x-cloak class="border-t border-slate-200 p-5 sm:p-6">
                                    <div class="grid gap-4 2xl:grid-cols-2">
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-600">Internal notes</label>
                                            <textarea name="notes" rows="4" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:ring-slate-900/10" placeholder="Any freeform quick bill notes...">{{ old('notes', $quickBill->notes) }}</textarea>
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-600">Bill terms</label>
                                            <textarea name="terms" rows="4" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:ring-slate-900/10" placeholder="Terms printed on the quick bill...">{{ old('terms', $quickBill->terms ?: $shop?->billingSettings?->terms_and_conditions) }}</textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 sm:px-6">
                            <div class="text-sm font-semibold text-slate-900" x-text="items.length === 1 ? '1 item line' : items.length + ' item lines'"></div>
                            <button type="button" @click="addItem()" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                                Add Row
                            </button>
                        </div>

                        <div class="space-y-4 p-4 sm:p-6">
                            <template x-for="(item, index) in items" :key="index">
                                <div
                                    class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5"
                                >
                                    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-start">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <div class="text-sm font-semibold text-slate-900" x-text="'Item ' + (index + 1)"></div>
                                                <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200" x-text="item.metal_type || 'Metal'"></span>
                                                <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200" x-text="item.purity || 'Purity'"></span>
                                            </div>

                                            <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                                                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                                                    <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">Gross</div>
                                                    <div class="mt-1 text-sm font-semibold text-slate-900" x-text="Number(item.gross_weight || 0).toFixed(3)"></div>
                                                </div>
                                                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                                                    <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">Net</div>
                                                    <div class="mt-1 text-sm font-semibold text-slate-900" x-text="(safeNumber(item.net_weight) > 0 ? safeNumber(item.net_weight) : Math.max(0, safeNumber(item.gross_weight) - safeNumber(item.stone_weight))).toFixed(3)"></div>
                                                </div>
                                                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                                                    <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">Rate</div>
                                                    <div class="mt-1 text-sm font-semibold text-slate-900" x-text="currency(item.rate || 0)"></div>
                                                </div>
                                                <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5">
                                                    <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-amber-700">Total</div>
                                                    <div class="mt-1 text-base font-semibold text-slate-900" x-text="currency(lineTotal(item))"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2 xl:justify-end">
                                            <button type="button" @click="toggleItem(index)" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                                                <span x-text="openIndex === index ? 'Collapse' : 'Expand'"></span>
                                            </button>
                                            <button type="button" @click="removeItem(index)" class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-white px-3 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-50">
                                                Remove
                                            </button>
                                        </div>
                                    </div>

                                    <div x-show="openIndex === index" x-transition x-cloak class="mt-4 grid gap-4 xl:grid-cols-12">
                                        <div class="space-y-4 xl:col-span-8">
                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-slate-600">Description</label>
                                                <input :name="'items['+index+'][description]'" x-model="item.description" type="text" placeholder="Gold ring / pendant / chain" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:ring-slate-900/10">
                                            </div>

                                            <div class="grid gap-3 grid-cols-2 xl:grid-cols-4">
                                                <div>
                                                    <label class="mb-2 block text-sm font-medium text-slate-600">Metal</label>
                                                    <input :name="'items['+index+'][metal_type]'" x-model="item.metal_type" type="text" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                </div>
                                                <div>
                                                    <label class="mb-2 block text-sm font-medium text-slate-600">Purity</label>
                                                    <input :name="'items['+index+'][purity]'" x-model="item.purity" type="text" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                </div>
                                                <div>
                                                    <label class="mb-2 block text-sm font-medium text-slate-600">HSN</label>
                                                    <input :name="'items['+index+'][hsn_code]'" x-model="item.hsn_code" type="text" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                </div>
                                                <div>
                                                    <label class="mb-2 block text-sm font-medium text-slate-600">Pcs</label>
                                                    <input :name="'items['+index+'][pcs]'" x-model.number="item.pcs" type="number" min="1" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                </div>
                                            </div>

                                            <div class="grid gap-4 lg:grid-cols-2">
                                                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                    <div class="grid gap-3 grid-cols-2">
                                                        <div>
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Gross</label>
                                                            <input :name="'items['+index+'][gross_weight]'" x-model.number="item.gross_weight" type="number" step="0.001" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div>
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Stone wt</label>
                                                            <input :name="'items['+index+'][stone_weight]'" x-model.number="item.stone_weight" type="number" step="0.001" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div class="col-span-2">
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Net wt</label>
                                                            <input :name="'items['+index+'][net_weight]'" x-model.number="item.net_weight" type="number" step="0.001" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                    <div class="grid gap-3 grid-cols-2">
                                                        <div>
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Rate</label>
                                                            <input :name="'items['+index+'][rate]'" x-model.number="item.rate" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div>
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Making</label>
                                                            <input :name="'items['+index+'][making_charge]'" x-model.number="item.making_charge" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div>
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Stone amount</label>
                                                            <input :name="'items['+index+'][stone_charge]'" x-model.number="item.stone_charge" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div>
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Wastage %</label>
                                                            <input :name="'items['+index+'][wastage_percent]'" x-model.number="item.wastage_percent" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="space-y-4 xl:col-span-4">
                                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-700">Line total</div>
                                                <div class="mt-1 text-2xl font-semibold text-slate-900" x-text="currency(lineTotal(item))"></div>
                                                <div class="mt-4 grid grid-cols-2 gap-2">
                                                    <div class="rounded-xl border border-white/80 bg-white/80 px-3 py-2.5">
                                                        <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">Pieces</div>
                                                        <div class="mt-1 text-sm font-semibold text-slate-900" x-text="Number(item.pcs || 1)"></div>
                                                    </div>
                                                    <div class="rounded-xl border border-white/80 bg-white/80 px-3 py-2.5">
                                                        <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">Wastage</div>
                                                        <div class="mt-1 text-sm font-semibold text-slate-900" x-text="Number(item.wastage_percent || 0).toFixed(2) + '%'"></div>
                                                    </div>
                                                    <div class="col-span-2 rounded-xl border border-white/80 bg-white/80 px-3 py-2.5">
                                                        <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">Extra charges</div>
                                                        <div class="mt-1 text-sm font-semibold text-slate-900" x-text="currency(safeNumber(item.hallmark_charge) + safeNumber(item.rhodium_charge) + safeNumber(item.other_charge))"></div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                                                <button type="button" @click="item._chargesOpen = !item._chargesOpen" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                                                    <div>
                                                        <div class="text-sm font-medium text-slate-900">Additional charges</div>
                                                        <div class="mt-1 text-xs text-slate-500">Open only when hallmark, rhodium, or other needs entry.</div>
                                                    </div>
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-500 transition-transform" :class="item._chargesOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" />
                                                    </svg>
                                                </button>

                                                <div x-show="item._chargesOpen" x-transition x-cloak class="border-t border-slate-200 p-4">
                                                    <div class="grid gap-3 grid-cols-2">
                                                        <div>
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Hallmark</label>
                                                            <input :name="'items['+index+'][hallmark_charge]'" x-model.number="item.hallmark_charge" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div>
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Rhodium</label>
                                                            <input :name="'items['+index+'][rhodium_charge]'" x-model.number="item.rhodium_charge" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div class="col-span-2">
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Other</label>
                                                            <input :name="'items['+index+'][other_charge]'" x-model.number="item.other_charge" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <input type="hidden" :name="'items['+index+'][line_discount]'" x-model.number="item.line_discount">
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div x-data="{ open: {{ $paymentsPanelOpen ? 'true' : 'false' }} }" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-6">
                            <div class="text-sm font-semibold text-slate-900" x-text="payments.length === 0 ? 'Payment tracker' : (payments.length === 1 ? '1 payment' : payments.length + ' payments')"></div>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="addPayment(); open = true" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                    Add Payment
                                </button>
                                <button type="button" @click="open = !open" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                                    <span x-text="open ? 'Collapse' : 'Expand'"></span>
                                </button>
                            </div>
                        </div>

                        <div x-show="open" x-transition x-cloak class="border-t border-slate-200 p-5 sm:p-6">
                            <div class="space-y-3">
                                <template x-if="payments.length === 0">
                                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                        No payment rows yet. Leave this empty if the bill is fully due.
                                    </div>
                                </template>

                                <template x-for="(payment, index) in payments" :key="index">
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                        <div class="grid grid-cols-2 gap-3 xl:grid-cols-[150px_minmax(0,1fr)_150px_auto]">
                                            <select :name="'payments['+index+'][payment_mode]'" x-model="payment.payment_mode" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                <option value="Cash">Cash</option>
                                                <option value="UPI">UPI</option>
                                                <option value="Card">Card</option>
                                                <option value="Bank">Bank</option>
                                                <option value="Other">Other</option>
                                            </select>
                                            <input :name="'payments['+index+'][amount]'" x-model.number="payment.amount" type="number" step="0.01" min="0" placeholder="Amount" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:ring-slate-900/10">
                                            <input :name="'payments['+index+'][reference_no]'" x-model="payment.reference_no" type="text" placeholder="Reference / note" class="col-span-2 w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:ring-slate-900/10 xl:col-span-1">
                                            <button type="button" @click="removePayment(index)" class="col-span-2 inline-flex items-center justify-center rounded-xl border border-rose-200 bg-white px-3 py-3 text-sm font-medium text-rose-700 transition hover:bg-rose-50 xl:col-span-1">
                                                Remove
                                            </button>
                                        </div>
                                        <input type="hidden" :name="'payments['+index+'][notes]'" x-model="payment.notes">
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-6 xl:sticky xl:top-6">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <input type="hidden" name="pricing_mode" :value="pricingMode">

                        <div class="grid grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1">
                            <button type="button" @click="pricingMode='no_gst'" class="rounded-lg px-3 py-2.5 text-xs font-semibold transition" :class="pricingMode === 'no_gst' ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:text-slate-900'">No GST</button>
                            <button type="button" @click="pricingMode='gst_exclusive'" class="rounded-lg px-3 py-2.5 text-xs font-semibold transition" :class="pricingMode === 'gst_exclusive' ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:text-slate-900'">Exclusive</button>
                            <button type="button" @click="pricingMode='gst_inclusive'" class="rounded-lg px-3 py-2.5 text-xs font-semibold transition" :class="pricingMode === 'gst_inclusive' ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:text-slate-900'">Inclusive</button>
                        </div>

                        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-1">
                            <div class="md:col-span-2 xl:col-span-1">
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <label class="block text-sm font-medium text-slate-600">GST rate (%)</label>
                                    <div class="flex items-center gap-1.5">
                                        <button
                                            type="button"
                                            @click="if (pricingMode !== 'no_gst') gstRate = 0"
                                            :disabled="pricingMode === 'no_gst'"
                                            class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-600 transition"
                                            :class="pricingMode === 'no_gst' ? 'cursor-not-allowed opacity-50' : 'hover:bg-slate-50'"
                                        >0%</button>
                                        <button
                                            type="button"
                                            @click="if (pricingMode !== 'no_gst') gstRate = 3"
                                            :disabled="pricingMode === 'no_gst'"
                                            class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-600 transition"
                                            :class="pricingMode === 'no_gst' ? 'cursor-not-allowed opacity-50' : 'hover:bg-slate-50'"
                                        >3%</button>
                                    </div>
                                </div>
                                <input
                                    type="number"
                                    name="gst_rate"
                                    x-model.number="gstRate"
                                    :readonly="pricingMode === 'no_gst'"
                                    step="0.01"
                                    min="0"
                                    max="100"
                                    class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10"
                                    :class="pricingMode === 'no_gst' ? 'cursor-not-allowed bg-slate-100 text-slate-500' : ''"
                                >
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-600">Discount type</label>
                                <select name="discount_type" x-model="discountType" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                    <option value="">None</option>
                                    <option value="fixed">Fixed</option>
                                    <option value="percent">Percent</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-600">Discount value</label>
                                <input type="number" name="discount_value" x-model.number="discountValue" step="0.01" min="0" placeholder="0.00" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:ring-slate-900/10">
                            </div>

                            <div class="md:col-span-2 xl:col-span-1">
                                <label class="mb-2 block text-sm font-medium text-slate-600">Round off</label>
                                <input type="number" name="round_off" x-model.number="roundOff" step="0.01" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">Subtotal</div>
                                <div class="mt-1 text-base font-semibold text-slate-900" x-text="currency(subtotal)"></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">Discount</div>
                                <div class="mt-1 text-base font-semibold text-slate-900" x-text="'- ' + currency(discountAmount)"></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">Taxable</div>
                                <div class="mt-1 text-base font-semibold text-slate-900" x-text="currency(taxableAmount)"></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">GST</div>
                                <div class="mt-1 text-base font-semibold text-slate-900" x-text="currency(gstAmount)"></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">Paid</div>
                                <div class="mt-1 text-base font-semibold text-slate-900" x-text="currency(paidAmount)"></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500">Round off</div>
                                <div class="mt-1 text-base font-semibold text-slate-900" x-text="currency(roundOff)"></div>
                            </div>
                        </div>

                        <div class="mt-3 rounded-2xl bg-slate-900 px-4 py-4 text-white">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-medium text-slate-300">Grand total</span>
                                <span class="text-2xl font-semibold" x-text="currency(totalAmount)"></span>
                            </div>
                            <div class="mt-3 flex items-center justify-between text-sm">
                                <span class="text-slate-300">Due</span>
                                <span class="font-semibold" :class="dueAmount > 0 ? 'text-amber-300' : 'text-emerald-300'" x-text="currency(dueAmount)"></span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="flex flex-col gap-3">
                            @if(!$editing || $quickBill->status === \App\Models\QuickBill::STATUS_DRAFT)
                                <button type="submit" name="save_action" value="issue" class="inline-flex min-h-[48px] items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                                    Issue Quick Bill
                                </button>
                                <button type="submit" name="save_action" value="draft" class="inline-flex min-h-[48px] items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                    Save Draft
                                </button>
                            @else
                                <button type="submit" name="save_action" value="issue" class="inline-flex min-h-[48px] items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                                    Update Quick Bill
                                </button>
                            @endif
                            @if($editing)
                                <a href="{{ route('quick-bills.show', $quickBill) }}" class="inline-flex min-h-[48px] items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                    Cancel
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        window.quickBillForm = function(config) {
            const initItem = (item, chargesOpen) => ({
                description: item.description || '',
                hsn_code: item.hsn_code || '',
                metal_type: item.metal_type || '',
                purity: item.purity || '',
                pcs: Number(item.pcs || 1),
                gross_weight: Number(item.gross_weight || 0),
                stone_weight: Number(item.stone_weight || 0),
                net_weight: Number(item.net_weight || 0),
                rate: Number(item.rate || 0),
                making_charge: Number(item.making_charge || 0),
                stone_charge: Number(item.stone_charge || 0),
                hallmark_charge: Number(item.hallmark_charge || 0),
                rhodium_charge: Number(item.rhodium_charge || 0),
                other_charge: Number(item.other_charge || 0),
                wastage_percent: Number(item.wastage_percent || 0),
                line_discount: Number(item.line_discount || 0),
                _chargesOpen: chargesOpen,
            });
            const blankItem = () => initItem({
                description: '', hsn_code: '', metal_type: 'Gold', purity: '22K',
                pcs: 1, gross_weight: 0, stone_weight: 0, net_weight: 0,
                rate: 0, making_charge: 0, stone_charge: 0,
                hallmark_charge: 0, rhodium_charge: 0, other_charge: 0,
                wastage_percent: 0, line_discount: 0,
            }, false);

            return {
                customers: config.customers || [],
                selectedCustomerId: config.selectedCustomerId ? String(config.selectedCustomerId) : '',
                customerName: config.customerName || '',
                customerMobile: config.customerMobile || '',
                customerAddress: config.customerAddress || '',
                pricingMode: config.pricingMode || 'gst_exclusive',
                gstRate: Number(config.gstRate || 0),
                discountType: config.discountType || '',
                discountValue: Number(config.discountValue || 0),
                roundOff: Number(config.roundOff || 0),
                openIndex: 0,
                items: (config.items || []).map((item, i) => initItem(item,
                    Number(item.hallmark_charge || 0) > 0 || Number(item.rhodium_charge || 0) > 0 || Number(item.other_charge || 0) > 0
                )),
                payments: (config.payments || []).map(payment => ({
                    payment_mode: payment.payment_mode || 'Cash',
                    reference_no: payment.reference_no || '',
                    amount: Number(payment.amount || 0),
                    notes: payment.notes || '',
                })),
                toggleItem(index) {
                    this.openIndex = this.openIndex === index ? -1 : index;
                },
                addItem() {
                    this.items.push(blankItem());
                    this.openIndex = this.items.length - 1;
                },
                removeItem(index) {
                    if (this.items.length === 1) {
                        this.items[0] = blankItem();
                        this.openIndex = 0;
                        return;
                    }
                    this.items.splice(index, 1);
                    if (this.openIndex >= this.items.length) {
                        this.openIndex = this.items.length - 1;
                    }
                },
                addPayment() {
                    this.payments.push({
                        payment_mode: 'Cash',
                        reference_no: '',
                        amount: 0,
                        notes: '',
                    });
                },
                removePayment(index) {
                    this.payments.splice(index, 1);
                },
                applyCustomer(customerId) {
                    const customer = this.customers.find(entry => String(entry.id) === String(customerId));
                    if (!customer) {
                        return;
                    }
                    this.customerName = customer.name || '';
                    this.customerMobile = customer.mobile || '';
                    this.customerAddress = customer.address || '';
                },
                safeNumber(value) {
                    const num = Number(value);
                    return Number.isFinite(num) ? num : 0;
                },
                lineTotal(item) {
                    const gross = this.safeNumber(item.gross_weight);
                    const stoneWeight = this.safeNumber(item.stone_weight);
                    const net = this.safeNumber(item.net_weight) > 0 ? this.safeNumber(item.net_weight) : Math.max(0, gross - stoneWeight);
                    const rate = this.safeNumber(item.rate);
                    const making = this.safeNumber(item.making_charge);
                    const stoneCharge = this.safeNumber(item.stone_charge);
                    const hallmarkCharge = this.safeNumber(item.hallmark_charge);
                    const rhodiumCharge = this.safeNumber(item.rhodium_charge);
                    const otherCharge = this.safeNumber(item.other_charge);
                    const wastagePercent = this.safeNumber(item.wastage_percent);
                    const discount = this.safeNumber(item.line_discount);
                    const metalValue = net * rate;
                    const wastageAmount = metalValue * (wastagePercent / 100);
                    return Math.max(0, metalValue + making + stoneCharge + hallmarkCharge + rhodiumCharge + otherCharge + wastageAmount - discount);
                },
                currency(value) {
                    const amount = this.safeNumber(value);
                    return '₹' + amount.toLocaleString('en-IN', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                },
                get subtotal() {
                    return this.items.reduce((sum, item) => sum + this.lineTotal(item), 0);
                },
                get discountAmount() {
                    const subtotal = this.subtotal;
                    if (!this.discountType || this.discountValue <= 0) {
                        return 0;
                    }
                    const discount = this.discountType === 'percent'
                        ? subtotal * (this.safeNumber(this.discountValue) / 100)
                        : this.safeNumber(this.discountValue);
                    return Math.min(subtotal, Math.max(0, discount));
                },
                get afterDiscount() {
                    return Math.max(0, this.subtotal - this.discountAmount);
                },
                get taxableAmount() {
                    if (this.pricingMode === 'gst_inclusive') {
                        const divisor = 1 + (this.safeNumber(this.gstRate) / 100);
                        return divisor > 0 ? this.afterDiscount / divisor : this.afterDiscount;
                    }
                    return this.afterDiscount;
                },
                get gstAmount() {
                    if (this.pricingMode === 'no_gst') {
                        return 0;
                    }
                    if (this.pricingMode === 'gst_inclusive') {
                        return this.afterDiscount - this.taxableAmount;
                    }
                    return this.taxableAmount * (this.safeNumber(this.gstRate) / 100);
                },
                get paidAmount() {
                    return this.payments.reduce((sum, payment) => sum + this.safeNumber(payment.amount), 0);
                },
                get totalAmount() {
                    if (this.pricingMode === 'gst_exclusive') {
                        return this.taxableAmount + this.gstAmount + this.safeNumber(this.roundOff);
                    }
                    return this.afterDiscount + this.safeNumber(this.roundOff);
                },
                get dueAmount() {
                    return Math.max(0, this.totalAmount - this.paidAmount);
                },
            };
        };
    </script>
</x-app-layout>
