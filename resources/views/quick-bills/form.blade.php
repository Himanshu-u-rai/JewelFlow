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
            <p class="text-sm text-gray-600 mt-1">Flexible jewellery billing outside the main invoice and ledger flow.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('quick-bills.index') }}"
               class="btn btn-dark btn-sm">
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
            <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                <div class="font-semibold mb-2">Please fix the highlighted quick bill details.</div>
                <ul class="list-disc pl-5 space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $editing ? route('quick-bills.update', $quickBill) : route('quick-bills.store') }}" class="space-y-6">
            @csrf
            @if($editing)
                @method('PUT')
            @endif

            <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-start">
                <div class="space-y-6 md:col-span-4">
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="border-b border-slate-200 px-6 py-4">
                            <h2 class="text-lg font-semibold text-slate-900">Bill Basics</h2>
                            <p class="mt-1 text-sm text-slate-500">Pick a customer or fill a walk-in bill manually.</p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 p-6">
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Existing Customer</label>
                                <select name="customer_id" x-model="selectedCustomerId" @change="applyCustomer($event.target.value)" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                    <option value="">Walk-in / Manual</option>
                                    @foreach($customers as $customer)
                                        <option value="{{ $customer->id }}">{{ $customer->name }} · {{ $customer->mobile }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Bill Date</label>
                                <input type="date" name="bill_date" value="{{ old('bill_date', optional($quickBill->bill_date)->format('Y-m-d') ?: now()->toDateString()) }}" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Customer Name</label>
                                    <input type="text" name="customer_name" x-model="customerName" placeholder="Walk-in customer or party name" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Mobile</label>
                                    <input type="text" name="customer_mobile" x-model="customerMobile" placeholder="Customer mobile number" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Address</label>
                                <textarea name="customer_address" x-model="customerAddress" rows="3" placeholder="Billing address (optional)" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500"></textarea>
                            </div>
                        </div>
                    </div>

                    @if($editing)
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Bill Record</h3>
                            <div class="mt-4 space-y-3 text-sm text-slate-600">
                                <div class="flex items-center justify-between gap-3">
                                    <span>Bill No.</span>
                                    <span class="font-mono font-medium text-slate-800">{{ $quickBill->bill_number }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span>Status</span>
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-slate-700">{{ ucfirst($quickBill->status) }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="space-y-6 md:col-span-8">
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 class="text-lg font-semibold text-slate-900">Bill Items</h2>
                                <p class="mt-1 text-sm text-slate-500">Jewellery-only manual lines. This does not touch stock or invoices.</p>
                            </div>
                            <button type="button" @click="addItem()" class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                                Add Row
                            </button>
                        </div>

                        <div class="p-6 space-y-4">
                            <template x-for="(item, index) in items" :key="index">
                                <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 shadow-sm">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Item Line</p>
                                            <p class="mt-1 text-sm font-semibold text-slate-800" x-text="'Jewellery Item ' + (index + 1)"></p>
                                        </div>
                                            <button type="button" @click="removeItem(index)" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-rose-200 bg-white text-rose-600 hover:bg-rose-50">
                                                ×
                                            </button>
                                        </div>

                                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
                                        <div class="lg:col-span-12">
                                            <label class="block text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Description</label>
                                            <input :name="'items['+index+'][description]'" x-model="item.description" type="text" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500" placeholder="Gold ring / pendant / chain">
                                        </div>

                                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:col-span-7">
                                            <div>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Metal</label>
                                                <input :name="'items['+index+'][metal_type]'" x-model="item.metal_type" type="text" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Purity</label>
                                                <input :name="'items['+index+'][purity]'" x-model="item.purity" type="text" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">HSN</label>
                                                <input :name="'items['+index+'][hsn_code]'" x-model="item.hsn_code" type="text" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Pcs</label>
                                                <input :name="'items['+index+'][pcs]'" x-model.number="item.pcs" type="number" min="1" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Gross</label>
                                                <input :name="'items['+index+'][gross_weight]'" x-model.number="item.gross_weight" type="number" step="0.001" min="0" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Stone Wt</label>
                                                <input :name="'items['+index+'][stone_weight]'" x-model.number="item.stone_weight" type="number" step="0.001" min="0" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:col-span-5">
                                            <div>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Net Wt</label>
                                                <input :name="'items['+index+'][net_weight]'" x-model.number="item.net_weight" type="number" step="0.001" min="0" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Rate</label>
                                                <input :name="'items['+index+'][rate]'" x-model.number="item.rate" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Making</label>
                                                <input :name="'items['+index+'][making_charge]'" x-model.number="item.making_charge" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Stone Amt</label>
                                                <input :name="'items['+index+'][stone_charge]'" x-model.number="item.stone_charge" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Wastage %</label>
                                                <input :name="'items['+index+'][wastage_percent]'" x-model.number="item.wastage_percent" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                                                <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Line Total</div>
                                                <div class="mt-1 text-base font-semibold text-slate-900" x-text="currency(lineTotal(item))"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <input type="hidden" :name="'items['+index+'][line_discount]'" x-model.number="item.line_discount">

                                    <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                        <span class="rounded-full bg-white px-3 py-1.5 ring-1 ring-slate-200">Gross <strong class="ml-1 text-slate-700" x-text="Number(item.gross_weight || 0).toFixed(3)"></strong></span>
                                        <span class="rounded-full bg-white px-3 py-1.5 ring-1 ring-slate-200">Net <strong class="ml-1 text-slate-700" x-text="Number(item.net_weight || 0).toFixed(3)"></strong></span>
                                        <span class="rounded-full bg-white px-3 py-1.5 ring-1 ring-slate-200">Purity <strong class="ml-1 text-slate-700" x-text="item.purity || '—'"></strong></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="space-y-6 md:col-span-12">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="text-lg font-semibold text-slate-900">Bill Controls</h2>
                        <p class="mt-1 text-sm text-slate-500">One bill-level discount, GST mode, round-off, and live totals.</p>

                        <input type="hidden" name="pricing_mode" :value="pricingMode">

                        <div class="mt-5 space-y-4">
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Pricing Mode</label>
                                <div class="grid grid-cols-3 gap-2">
                                    <button type="button" @click="pricingMode='no_gst'" class="rounded-xl px-3 py-2 text-xs font-semibold transition" :class="pricingMode==='no_gst' ? 'bg-slate-900 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">No GST</button>
                                    <button type="button" @click="pricingMode='gst_exclusive'" class="rounded-xl px-3 py-2 text-xs font-semibold transition" :class="pricingMode==='gst_exclusive' ? 'bg-amber-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">Exclusive</button>
                                    <button type="button" @click="pricingMode='gst_inclusive'" class="rounded-xl px-3 py-2 text-xs font-semibold transition" :class="pricingMode==='gst_inclusive' ? 'bg-sky-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">Inclusive</button>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                                    <div class="flex items-center justify-between gap-2 mb-2">
                                        <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">GST Rate (%)</label>
                                        <div class="flex items-center gap-1.5">
                                            <button type="button" @click="gstRate = 0" class="rounded-full bg-white px-2.5 py-1 text-[10px] font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100">0%</button>
                                            <button type="button" @click="gstRate = 3" class="rounded-full bg-white px-2.5 py-1 text-[10px] font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100">3%</button>
                                        </div>
                                    </div>
                                    <input type="number" name="gst_rate" x-model.number="gstRate" step="0.01" min="0" max="100" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Bill Discount</label>
                                    <div class="grid grid-cols-[110px_1fr] gap-2">
                                        <select name="discount_type" x-model="discountType" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                            <option value="">None</option>
                                            <option value="fixed">Fixed</option>
                                            <option value="percent">Percent</option>
                                        </select>
                                        <input type="number" name="discount_value" x-model.number="discountValue" step="0.01" min="0" placeholder="0.00" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Round Off</label>
                                    <input type="number" name="round_off" x-model.number="roundOff" step="0.01" class="w-full rounded-xl border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                            </div>
                          
                        </div>

                        <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Subtotal</div>
                                    <div class="mt-1 font-semibold text-slate-900" x-text="currency(subtotal)"></div>
                                </div>
                                <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Discount</div>
                                    <div class="mt-1 font-semibold text-slate-900" x-text="'- ' + currency(discountAmount)"></div>
                                </div>
                                <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Taxable</div>
                                    <div class="mt-1 font-semibold text-slate-900" x-text="currency(taxableAmount)"></div>
                                </div>
                                <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">GST</div>
                                    <div class="mt-1 font-semibold text-slate-900" x-text="currency(gstAmount)"></div>
                                </div>
                                <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Paid</div>
                                    <div class="mt-1 font-semibold text-slate-900" x-text="currency(paidAmount)"></div>
                                </div>
                                <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Round Off</div>
                                    <div class="mt-1 font-semibold text-slate-900" x-text="currency(roundOff)"></div>
                                </div>
                            </div>
                            <div class="mt-4 rounded-2xl bg-slate-900 px-4 py-4 text-white">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">Grand Total</span>
                                    <span class="text-2xl font-semibold" x-text="currency(totalAmount)"></span>
                                </div>
                                <div class="mt-2 flex items-center justify-between text-sm">
                                    <span class="text-slate-300">Due</span>
                                    <span class="font-semibold" :class="dueAmount > 0 ? 'text-amber-300' : 'text-emerald-300'" x-text="currency(dueAmount)"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden md:col-span-7">
                    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">Payment Notes</h2>
                            <p class="mt-1 text-sm text-slate-500">Optional split payment tracker for this quick bill only.</p>
                        </div>
                        <button type="button" @click="addPayment()" class="inline-flex items-center rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm border border-slate-200 hover:bg-slate-50">
                            Add Payment
                        </button>
                    </div>

                    <div class="p-6 space-y-3">
                        <template x-if="payments.length === 0">
                            <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                No payment rows yet. Leave this empty if the bill is fully due.
                            </div>
                        </template>

                        <template x-for="(payment, index) in payments" :key="index">
                            <div class="grid grid-cols-1 lg:grid-cols-[140px_1fr_140px_auto] gap-3 items-start">
                                <select :name="'payments['+index+'][payment_mode]'" x-model="payment.payment_mode" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                    <option value="Cash">Cash</option>
                                    <option value="UPI">UPI</option>
                                    <option value="Card">Card</option>
                                    <option value="Bank">Bank</option>
                                    <option value="Other">Other</option>
                                </select>
                                <input :name="'payments['+index+'][reference_no]'" x-model="payment.reference_no" type="text" placeholder="Reference / note" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                <input :name="'payments['+index+'][amount]'" x-model.number="payment.amount" type="number" step="0.01" min="0" placeholder="Amount" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                <button type="button" @click="removePayment(index)" class="inline-flex h-[42px] w-[42px] items-center justify-center rounded-xl border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100">
                                    ×
                                </button>
                                <input type="hidden" :name="'payments['+index+'][notes]'" x-model="payment.notes">
                            </div>
                        </template>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden md:col-span-5">
                    <div class="border-b border-slate-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-slate-900">Notes & Terms</h2>
                        <p class="mt-1 text-sm text-slate-500">Internal notes stay in the register. Terms print on the bill.</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 p-6">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Internal Notes</label>
                            <textarea name="notes" rows="4" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500" placeholder="Any freeform quick bill notes...">{{ old('notes', $quickBill->notes) }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Bill Terms</label>
                            <textarea name="terms" rows="4" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500" placeholder="Terms printed on the quick bill...">{{ old('terms', $quickBill->terms ?: $shop?->billingSettings?->terms_and_conditions) }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if(!$editing || $quickBill->status === \App\Models\QuickBill::STATUS_DRAFT)
                    <button type="submit" name="save_action" value="draft" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                        Save Draft
                    </button>
                    <button type="submit" name="save_action" value="issue" class="inline-flex items-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Issue Quick Bill
                    </button>
                @else
                    <button type="submit" name="save_action" value="issue" class="inline-flex items-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Update Quick Bill
                    </button>
                @endif
                @if($editing)
                    <a href="{{ route('quick-bills.show', $quickBill) }}" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                        Cancel
                    </a>
                @endif
            </div>
        </form>
    </div>

    <script>
        window.quickBillForm = function(config) {
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
                items: (config.items || []).map(item => ({
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
                    wastage_percent: Number(item.wastage_percent || 0),
                    line_discount: Number(item.line_discount || 0),
                })),
                payments: (config.payments || []).map(payment => ({
                    payment_mode: payment.payment_mode || 'Cash',
                    reference_no: payment.reference_no || '',
                    amount: Number(payment.amount || 0),
                    notes: payment.notes || '',
                })),
                addItem() {
                    this.items.push({
                        description: '',
                        hsn_code: '',
                        metal_type: 'Gold',
                        purity: '22K',
                        pcs: 1,
                        gross_weight: 0,
                        stone_weight: 0,
                        net_weight: 0,
                        rate: 0,
                        making_charge: 0,
                        stone_charge: 0,
                        wastage_percent: 0,
                        line_discount: 0,
                    });
                },
                removeItem(index) {
                    if (this.items.length === 1) {
                        this.items[0] = {
                            description: '',
                            hsn_code: '',
                            metal_type: 'Gold',
                            purity: '22K',
                            pcs: 1,
                            gross_weight: 0,
                            stone_weight: 0,
                            net_weight: 0,
                            rate: 0,
                            making_charge: 0,
                            stone_charge: 0,
                            wastage_percent: 0,
                            line_discount: 0,
                        };
                        return;
                    }
                    this.items.splice(index, 1);
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
                    const wastagePercent = this.safeNumber(item.wastage_percent);
                    const discount = this.safeNumber(item.line_discount);
                    const metalValue = net * rate;
                    const wastageAmount = metalValue * (wastagePercent / 100);
                    return Math.max(0, metalValue + making + stoneCharge + wastageAmount - discount);
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
