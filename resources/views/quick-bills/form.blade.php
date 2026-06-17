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
        $reviewPanelOpen = false;
        foreach ($errors->keys() as $errorKey) {
            foreach (['pricing_mode', 'gst_rate', 'discount_type', 'discount_value', 'round_off', 'payments'] as $prefix) {
                if ($errorKey === $prefix || str_starts_with($errorKey, $prefix.'.')) {
                    $reviewPanelOpen = true;
                    break 2;
                }
            }
        }
    @endphp

    <x-page-header class="ops-treatment-header qb-form-header">
        <div>
            <h1 class="page-title">{{ $editing ? 'Edit Quick Bill' : 'New Quick Bill' }}</h1>
        </div>
        <div class="page-actions flex flex-wrap items-center gap-2">
            @if($editing)
                <span class="qb-form-header-pill">{{ $quickBill->bill_number }}</span>
                <span class="qb-form-header-pill is-status">{{ ucfirst($quickBill->status) }}</span>
            @endif
            <a href="{{ route('quick-bills.index') }}" class="qb-form-back-action inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                Back to Register
            </a>
        </div>
    </x-page-header>

    {{-- Keep this page layout independent from generated utility coverage. --}}
    <style>
        .qb-entry-layout,
        .qb-top-grid,
        .qb-item-edit-grid {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        @media (min-width: 1280px) {
            .qb-entry-layout {
                display: grid;
                grid-template-columns: minmax(0, 1fr) 300px;
                align-items: start;
            }

            .qb-review-rail {
                position: sticky;
                top: 1.5rem;
            }

            .qb-item-edit-grid {
                display: grid;
                grid-template-columns: minmax(0, 1fr) 300px;
                align-items: start;
            }
        }

        @media (min-width: 1024px) {
            .qb-top-grid {
                display: grid;
                grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);
                align-items: stretch;
            }

            .qb-top-grid > * {
                height: 100%;
            }
        }

        .qb-review-backdrop {
            position: fixed;
            inset: 0;
            z-index: 70;
            background: rgb(15 23 42 / 0.38);
        }

        .qb-review-drawer {
            position: fixed;
            inset: 0 0 0 auto;
            z-index: 80;
            display: flex;
            width: min(440px, 100%);
            flex-direction: column;
            border-left: 1px solid #cbd5e1;
            background: #fff;
        }

        .qb-review-scroll {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 1rem;
        }

        .qb-tax-mode {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.25rem;
            border-radius: 0.75rem;
            background: #f1f5f9;
            padding: 0.25rem;
        }

        .qb-review-actions {
            flex: 0 0 auto;
            border-top: 1px solid #e2e8f0;
            background: #fff;
            padding: 1rem;
        }

        @media (max-width: 767px) {
            .qb-review-drawer {
                inset: 0;
                width: 100%;
                border-left: 0;
            }
        }

        /* ---- Quick bill form visual system ----
           Keep the payment rail/drawer and all Alpine/name bindings intact.
           This block only normalizes the form surface to the flat SaaS system
           used on the quick-bill register/detail pages. */
        .qb-form-header {
            --qb-bg: #f6f7f9;
            --qb-surface: #ffffff;
            --qb-soft: #f3f5f8;
            --qb-line: #cbd5e1;
            --qb-line-soft: #e2e8f0;
            --qb-ink: #1f2430;
            --qb-text: #4a4334;
            --qb-muted: #64748b;
            --qb-dark: #b45309;
        }

        .content-header.ops-treatment-header.qb-form-header {
            background: #fff !important;
            border-bottom-color: #e2e8f0 !important;
            box-shadow: none !important;
        }

        .qb-form-header .page-actions {
            align-items: center;
            gap: .5rem;
        }

        .qb-form-header .page-actions > .qb-form-back-action:not([class*="btn-"]) {
            min-height: 40px;
            border-color: var(--qb-line) !important;
            border-radius: 10px !important;
            background: #fff !important;
            color: var(--qb-ink) !important;
            box-shadow: none !important;
            transform: none !important;
        }

        .qb-form-header .page-actions > .qb-form-back-action:not([class*="btn-"]):hover {
            background: var(--qb-soft) !important;
            border-color: var(--qb-line) !important;
            box-shadow: none !important;
            transform: none !important;
        }

        .qb-form-header-pill {
            display: inline-flex;
            min-height: 32px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--qb-line-soft);
            border-radius: 999px;
            background: #fff;
            padding: 0 .75rem;
            color: var(--qb-text);
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            box-shadow: none;
        }

        .qb-form-header-pill.is-status {
            background: var(--qb-soft);
            color: var(--qb-ink);
        }

        .qb-edit-page {
            --qb-bg: #f6f7f9;
            --qb-surface: #ffffff;
            --qb-soft: #f3f5f8;
            --qb-soft-strong: #eef2f7;
            --qb-line: #cbd5e1;
            --qb-line-soft: #e2e8f0;
            --qb-ink: #1f2430;
            --qb-text: #4a4334;
            --qb-muted: #64748b;
            /* Accent is JewelFlow gold (matches the index + detail pages). */
            --qb-dark: #b45309;
            --qb-dark-hover: #92400e;
            --qb-focus: rgba(245, 158, 11, .2);
            --app-card-radius: 14px;
            --app-card-shadow: none;
            --app-card-shadow-hover: none;
            --app-control-bg: #ffffff;
            --app-control-border: #cbd5e1;
            --app-control-border-focus: #f59e0b;
            --jf-shadow-xs: none;
            --jf-shadow-sm: none;
            --jf-shadow-md: none;
            --jf-shadow-lg: none;
            --jf-shadow-xl: none;
            color: var(--qb-ink);
        }

        .qb-edit-page *,
        .qb-edit-page *::before,
        .qb-edit-page *::after {
            box-sizing: border-box;
        }

        .qb-edit-page :is(.shadow-sm, .shadow-lg, .shadow-inner),
        .qb-edit-page [class*="shadow-"] {
            box-shadow: none !important;
        }

        .content-inner.qb-edit-page :is(
            [class*="bg-white"][class*="border"],
            [class*="bg-white"][class*="shadow"],
            .rounded-2xl.border.bg-white,
            .rounded-xl.border.bg-white,
            .rounded-lg.shadow-sm,
            .bg-white.shadow-sm.border
        ) {
            border-color: var(--qb-line-soft) !important;
            box-shadow: none !important;
        }

        .content-inner.qb-edit-page :is(button, a.inline-flex, .btn) {
            box-shadow: none !important;
        }

        .qb-edit-page :is(.rounded-2xl, .rounded-xl, .rounded-lg) {
            border-radius: 12px !important;
        }

        .qb-edit-page :is(.border-slate-100, .border-slate-200, .border-slate-300) {
            border-color: var(--qb-line-soft) !important;
        }

        .qb-edit-page .bg-slate-50,
        .qb-edit-page .bg-slate-50\/50 {
            background-color: var(--qb-soft) !important;
        }

        .qb-edit-page .bg-white\/40,
        .qb-edit-page .border-white\/60 {
            background-color: #fff !important;
            border-color: var(--qb-line-soft) !important;
            backdrop-filter: none !important;
        }

        .qb-edit-page .bg-amber-50 {
            background-color: #fff7ed !important;
        }

        .qb-edit-page .border-amber-200 {
            border-color: #fed7aa !important;
        }

        .qb-edit-page .text-amber-700 {
            color: #9a3412 !important;
        }

        .qb-edit-page .bg-slate-900 { background-color: var(--qb-dark) !important; }
        .qb-edit-page .hover\:bg-slate-800:hover,
        .qb-edit-page .bg-slate-900.hover\:bg-slate-800:hover { background-color: var(--qb-dark-hover) !important; }

        .qb-edit-page input:not([type="hidden"]),
        .qb-edit-page select,
        .qb-edit-page textarea {
            border: 1px solid var(--qb-line) !important;
            border-radius: 10px !important;
            background: #fff !important;
            color: var(--qb-ink) !important;
            box-shadow: none !important;
        }

        .content-inner.qb-edit-page :where(
            input[type='text'],
            input[type='email'],
            input[type='number'],
            input[type='date'],
            input[type='tel'],
            input[type='password'],
            select,
            textarea
        ) {
            border: 1px solid var(--qb-line) !important;
            border-radius: 10px !important;
            background: #fff !important;
            color: var(--qb-ink) !important;
            box-shadow: none !important;
        }

        .content-inner.qb-edit-page :is(
            input:not([type='hidden']),
            select,
            textarea
        )[class*="bg-white"],
        .content-inner.qb-edit-page :is(
            input:not([type='hidden']),
            select,
            textarea
        )[class*="border"],
        .content-inner.qb-edit-page :is(
            input:not([type='hidden']),
            select,
            textarea
        )[class*="shadow"] {
            border: 1px solid var(--qb-line) !important;
            border-radius: 10px !important;
            background: #fff !important;
            color: var(--qb-ink) !important;
            box-shadow: none !important;
        }

        .qb-edit-page input:not([type="hidden"]):hover,
        .qb-edit-page select:hover,
        .qb-edit-page textarea:hover {
            border-color: #94a3b8 !important;
        }

        .qb-edit-page input:focus,
        .qb-edit-page select:focus,
        .qb-edit-page textarea:focus {
            background: #fff !important;
            border-color: var(--qb-dark) !important;
            outline: none;
            box-shadow: 0 0 0 3px var(--qb-focus) !important;
        }

        .content-inner.qb-edit-page :where(
            input[type='text'],
            input[type='email'],
            input[type='number'],
            input[type='date'],
            input[type='tel'],
            input[type='password'],
            select,
            textarea
        ):focus {
            background: #fff !important;
            border-color: var(--qb-dark) !important;
            outline: none !important;
            box-shadow: 0 0 0 3px var(--qb-focus) !important;
        }

        .qb-edit-page input[readonly],
        .qb-edit-page input:disabled,
        .qb-edit-page select:disabled,
        .qb-edit-page textarea:disabled {
            background: var(--qb-soft-strong) !important;
            color: var(--qb-muted) !important;
        }

        .qb-edit-page input::placeholder,
        .qb-edit-page textarea::placeholder {
            color: #64748b !important;
        }

        .qb-edit-page .text-slate-900,
        .qb-edit-page .text-slate-800 {
            color: var(--qb-ink) !important;
        }

        .qb-edit-page .text-slate-700 {
            color: var(--qb-text) !important;
        }

        .qb-edit-page .text-slate-600,
        .qb-edit-page .text-slate-500 {
            color: var(--qb-muted) !important;
        }

        .qb-edit-page .text-sm.font-semibold.text-slate-900,
        .qb-edit-page .text-base.font-semibold.text-slate-900 {
            font-weight: 600 !important;
            letter-spacing: 0 !important;
        }

        .qb-edit-page .font-bold,
        .qb-edit-page .font-black {
            font-weight: 600 !important;
        }

        .qb-review-rail > div:not(.qb-review-backdrop),
        .qb-edit-page form > .qb-entry-layout > div > .space-y-6 > div,
        .qb-edit-page .qb-top-grid > div,
        .qb-edit-page .qb-item-edit-grid > div > .rounded-2xl,
        .qb-edit-page .qb-item-edit-grid > div > .space-y-4 > .rounded-2xl {
            border-color: var(--qb-line-soft) !important;
            background-color: #fff !important;
            box-shadow: none !important;
        }

        .qb-edit-page .qb-top-grid > div,
        .qb-review-rail > div:not(.qb-review-backdrop),
        .qb-edit-page form > .qb-entry-layout > div > .space-y-6 > .overflow-hidden {
            border-radius: 14px !important;
        }

        .qb-edit-page .qb-top-grid > div:first-child,
        .qb-edit-page .qb-item-edit-grid,
        .qb-edit-page .qb-review-rail {
            min-width: 0;
        }

        .qb-edit-page template + div,
        .qb-edit-page [class*="bg-slate-50"].rounded-2xl {
            background-color: var(--qb-soft) !important;
        }

        .qb-edit-page .qb-tax-mode {
            background: var(--qb-soft) !important;
        }

        .qb-edit-page .qb-tax-mode button {
            box-shadow: none !important;
        }

        .qb-review-backdrop {
            background: rgb(15 23 42 / .18) !important;
        }

        .qb-review-drawer {
            border-left-color: var(--qb-line);
            box-shadow: none !important;
        }

        .qb-review-drawer :is(.shadow-sm, .shadow-lg, .shadow-inner),
        .qb-review-drawer [class*="shadow-"] {
            box-shadow: none !important;
        }

        .qb-making-select-icon {
            display: none;
        }

        @media (prefers-reduced-motion: reduce) {
            .qb-edit-page * { transition: none !important; }
        }

        /* ---- Desktop-only price-grid layout (>=768px) ----
           Put Wastage directly under Rate (left column) and Making over Stone
           (right column), using the extra desktop width. Column fill order:
           col1 = Rate -> Wastage, col2 = Making -> Stone. Mobile is untouched
           (it keeps the natural row order from the markup). */
        @media (min-width: 768px) {
            .qb-price-grid {
                grid-auto-flow: column;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                grid-template-rows: auto auto;
                align-items: start;
            }
            .qb-price-grid .qb-rate-field    { order: 1; }
            .qb-price-grid .qb-wastage-field { order: 2; }
            .qb-price-grid .qb-making-field  { order: 3; }
            .qb-price-grid .qb-stone-field   { order: 4; }

            /* Show the custom chevron on the making-type select on desktop too. */
            .qb-making-select-wrap { position: relative; }
            .qb-making-select-icon {
                position: absolute;
                top: 1.30rem;
                right: .7rem;
                display: flex;
                height: 16px;
                width: 16px;
                align-items: center;
                justify-content: center;
                color: #475569;
                pointer-events: none;
            }
            .content-inner.qb-edit-page select.qb-making-type-select {
                appearance: none;
                -webkit-appearance: none;
                background-image: none !important;
                padding-right: 2rem !important;
            }
        }

        @media (max-width: 767px) {
            .qb-form-header .page-actions {
                gap: .375rem;
            }

            .qb-form-header-pill {
                min-height: 30px;
                padding-inline: .625rem;
                font-size: 11px;
            }

            .qb-form-header .page-actions > .qb-form-back-action {
                display: none !important;
            }

            .qb-edit-page {
                margin-left: -4px;
                margin-right: -4px;
            }

            .qb-mobile-metric-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: .5rem !important;
            }

            .qb-mobile-metric-grid > div {
                min-height: 58px;
                padding: .6rem .65rem !important;
            }

            .qb-mobile-metric-grid > div > div:first-child {
                font-size: 10px !important;
                letter-spacing: .1em !important;
            }

            .qb-mobile-product-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: .65rem !important;
            }

            .qb-mobile-product-grid label,
            .qb-mobile-weight-grid label {
                margin-bottom: .35rem !important;
                font-size: 12px !important;
                line-height: 1.15 !important;
            }

            .qb-mobile-weight-grid {
                grid-template-columns: minmax(0, 1fr) minmax(76px, .78fr) minmax(0, 1fr) !important;
                gap: .5rem !important;
            }

            .qb-mobile-net-field {
                grid-column: auto !important;
            }

            .qb-mobile-weight-grid input,
            .qb-mobile-product-grid :is(input, select) {
                min-height: 40px !important;
                padding: .55rem .6rem !important;
                font-size: 13px !important;
            }

            .qb-making-select-wrap {
                position: relative;
            }

            .qb-making-select-icon {
                position: absolute;
                top: 50%;
                right: .65rem;
                display: flex;
                height: 16px;
                width: 16px;
                transform: translateY(-50%);
                align-items: center;
                justify-content: center;
                color: #475569;
                pointer-events: none;
            }

            .content-inner.qb-edit-page select.qb-making-type-select {
                appearance: none;
                padding-right: 2rem !important;
            }
        }
    </style>

    <div class="content-inner max-w-[1380px] mx-auto qb-edit-page" x-data="quickBillForm({
        customers: @js($customerDirectory),
        items: @js($initialItems),
        payments: @js($initialPayments),
        purityProfiles: @js($purityProfiles ?? []),
        enabledMetals: @js($enabledMetals ?? []),
        paymentMethods: @js($paymentMethods ?? []),
        selectedCustomerId: @js(old('customer_id', $quickBill->customer_id)),
        customerName: @js(old('customer_name', $quickBill->customer_name)),
        customerMobile: @js(old('customer_mobile', $quickBill->customer_mobile)),
        customerAddress: @js(old('customer_address', $quickBill->customer_address)),
        pricingMode: @js(old('pricing_mode', $quickBill->pricing_mode ?: 'gst_exclusive')),
        gstRate: @js((float) old('gst_rate', $quickBill->gst_rate ?? ($shop?->gst_rate ?? 3))),
        discountType: @js(old('discount_type', $quickBill->discount_type)),
        discountValue: @js((float) old('discount_value', $quickBill->discount_value ?? 0)),
        roundOff: @js((float) old('round_off', $quickBill->round_off ?? 0)),
        reviewOpen: @js($reviewPanelOpen),
    })" x-effect="document.body.classList.toggle('overflow-hidden', reviewOpen)" @keydown.escape.window="closeReview()">
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

        <form method="POST" action="{{ $editing ? route('quick-bills.update', $quickBill) : route('quick-bills.store') }}" class="space-y-6">
            @csrf
            @if($editing)
                @method('PUT')
            @endif

            <div class="qb-entry-layout">
                <div class="space-y-6 min-w-0">
                    <div class="qb-top-grid">
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                            <div class="mb-4">
                                <div class="text-sm font-semibold text-slate-900">Customer details</div>
                            </div>
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

                        <div x-data="{ open: true }" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
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
                                <div class="space-y-4">
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

                                            <div class="qb-mobile-metric-grid mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
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

                                    <div x-show="openIndex === index" x-transition x-cloak class="qb-item-edit-grid mt-4">
                                        <div class="space-y-4">
                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-slate-600">Description</label>
                                                <input :name="'items['+index+'][description]'" x-model="item.description" type="text" placeholder="Gold ring / pendant / chain" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:ring-slate-900/10">
                                            </div>

                                            <div class="qb-mobile-product-grid grid gap-3 grid-cols-2 xl:grid-cols-4">
                                                <div>
                                                    <label class="mb-2 block text-sm font-medium text-slate-600">Metal</label>
                                                    <template x-if="metalChoices().length">
                                                        <select :name="'items['+index+'][metal_type]'" x-model="item.metal_type" @change="onMetalChange(item)" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                            <template x-for="m in metalChoices()" :key="m"><option :value="cap(m)" x-text="cap(m)"></option></template>
                                                        </select>
                                                    </template>
                                                    <template x-if="!metalChoices().length">
                                                        <input :name="'items['+index+'][metal_type]'" x-model="item.metal_type" type="text" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                    </template>
                                                </div>
                                                <div>
                                                    <label class="mb-2 block text-sm font-medium text-slate-600">Purity</label>
                                                    {{-- Gold/silver: standards dropdown (drives the purity factor).
                                                         Platinum/copper (no profiles): optional grade text - price is
                                                         net x rate, purity never multiplies. --}}
                                                    <template x-if="purityChoicesFor(item.metal_type).length">
                                                        <select :name="'items['+index+'][purity]'" x-model="item.purity" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                            <template x-for="p in purityChoicesFor(item.metal_type)" :key="p.label"><option :value="p.label" x-text="p.label"></option></template>
                                                        </select>
                                                    </template>
                                                    <template x-if="!purityChoicesFor(item.metal_type).length">
                                                        <div>
                                                            <input :name="'items['+index+'][purity]'" x-model="item.purity" type="text" placeholder="Optional" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                            <p class="mt-1 text-xs text-slate-400">Optional for platinum/copper (price is net × rate). Gold: karats · Silver: fineness.</p>
                                                        </div>
                                                    </template>
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
                                                    <div class="qb-mobile-weight-grid grid gap-3 grid-cols-2">
                                                        <div>
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Gross</label>
                                                            <input :name="'items['+index+'][gross_weight]'" x-model.number="item.gross_weight" @input="recalcNet(item)" type="number" step="0.001" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div>
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Stone wt</label>
                                                            <input :name="'items['+index+'][stone_weight]'" x-model.number="item.stone_weight" @input="recalcNet(item)" type="number" step="0.001" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div class="qb-mobile-net-field col-span-2">
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Net wt <span class="text-slate-400 font-normal">(auto)</span></label>
                                                            <input :name="'items['+index+'][net_weight]'" x-model.number="item.net_weight" @input="onNetInput(item)" type="number" step="0.001" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                    <div class="qb-price-grid grid gap-3 grid-cols-2">
                                                        <div class="qb-rate-field">
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Rate (pure 24K/999)</label>
                                                            <input :name="'items['+index+'][rate]'" x-model.number="item.rate" type="number" step="0.01" min="0" placeholder="Pure metal rate / g" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div class="qb-making-field">
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Making</label>
                                                            <div class="qb-making-select-wrap mb-2">
                                                                <select :name="'items['+index+'][making_charge_type]'" x-model="item.making_charge_type" class="qb-making-type-select w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                                    <option value="fixed">Fixed ₹</option>
                                                                    <option value="percentage">% of metal</option>
                                                                    <option value="per_gram">₹ / gram</option>
                                                                </select>
                                                                <span class="qb-making-select-icon" aria-hidden="true">
                                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
                                                                        <path d="m6 9 6 6 6-6" />
                                                                    </svg>
                                                                </span>
                                                            </div>
                                                            <input :name="'items['+index+'][making_charge]'" x-model.number="item.making_charge" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                            <input type="hidden" :name="'items['+index+'][making_charge_value]'" :value="item.making_charge">
                                                            <p class="mt-1 text-xs text-slate-400" x-show="item.making_charge_type !== 'fixed'" x-text="item.making_charge_type === 'percentage' ? ('= ₹' + currency(lineMaking(item)).replace('₹','') + ' (' + (item.making_charge||0) + '% of metal)') : ('= ₹' + currency(lineMaking(item)).replace('₹','') + ' (₹' + (item.making_charge||0) + '/g)')"></p>
                                                        </div>
                                                        <div class="qb-stone-field">
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Stone amount</label>
                                                            <input :name="'items['+index+'][stone_charge]'" x-model.number="item.stone_charge" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div class="qb-wastage-field">
                                                            <label class="mb-2 block text-sm font-medium text-slate-600">Wastage %</label>
                                                            <input :name="'items['+index+'][wastage_percent]'" x-model.number="item.wastage_percent" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-900 focus:ring-slate-900/10">
                                                            <p class="mt-1 text-xs text-slate-400" x-show="safeNumber(item.wastage_percent) > 0" x-text="'= ' + currency(lineWastage(item)) + ' (' + (item.wastage_percent || 0) + '% of metal)'"></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="space-y-4">
                                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-inner">
                                                <div class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Line total</div>
                                                <div class="mt-1 text-2xl font-bold text-slate-900" x-text="currency(lineTotal(item))"></div>
                                                <div class="mt-4 grid grid-cols-2 gap-2">
                                                    <div class="rounded-xl border border-white/60 bg-white/40 px-3 py-2.5 backdrop-blur-sm">
                                                        <div class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Pieces</div>
                                                        <div class="mt-1 text-sm font-semibold text-slate-900" x-text="Number(item.pcs || 1)"></div>
                                                    </div>
                                                    <div class="rounded-xl border border-white/60 bg-white/40 px-3 py-2.5 backdrop-blur-sm">
                                                        <div class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Wastage</div>
                                                        <div class="mt-1 text-sm font-semibold text-slate-900" x-text="Number(item.wastage_percent || 0).toFixed(2) + '%'"></div>
                                                    </div>
                                                    <div class="col-span-2 rounded-xl border border-white/60 bg-white/40 px-3 py-2.5 backdrop-blur-sm">
                                                        <div class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Extra charges</div>
                                                        <div class="mt-1 text-sm font-semibold text-slate-900" x-text="currency(safeNumber(item.hallmark_charge) + safeNumber(item.rhodium_charge) + safeNumber(item.other_charge))"></div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition-all">
                                                <button type="button" @click="item._chargesOpen = !item._chargesOpen" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-slate-50">
                                                    <div>
                                                        <div class="text-sm font-medium text-slate-900">Additional charges</div>
                                                        <div class="mt-0.5 text-[11px] text-slate-500">Hallmark, Rhodium, etc.</div>
                                                    </div>
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-400 transition-transform" :class="item._chargesOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" />
                                                    </svg>
                                                </button>

                                                <div x-show="item._chargesOpen" x-transition x-cloak class="border-t border-slate-100 p-4">
                                                    <div class="grid gap-3 grid-cols-2">
                                                        <div>
                                                            <label class="mb-1.5 block text-xs font-medium text-slate-500 uppercase tracking-wider">Hallmark</label>
                                                            <input :name="'items['+index+'][hallmark_charge]'" x-model.number="item.hallmark_charge" type="number" step="0.01" min="0" class="w-full rounded-lg border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div>
                                                            <label class="mb-1.5 block text-xs font-medium text-slate-500 uppercase tracking-wider">Rhodium</label>
                                                            <input :name="'items['+index+'][rhodium_charge]'" x-model.number="item.rhodium_charge" type="number" step="0.01" min="0" class="w-full rounded-lg border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-slate-900 focus:ring-slate-900/10">
                                                        </div>
                                                        <div class="col-span-2">
                                                            <label class="mb-1.5 block text-xs font-medium text-slate-500 uppercase tracking-wider">Other</label>
                                                            <input :name="'items['+index+'][other_charge]'" x-model.number="item.other_charge" type="number" step="0.01" min="0" class="w-full rounded-lg border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-slate-900 focus:ring-slate-900/10">
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

                </div>

                <div class="qb-review-rail">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Review</div>
                        <p class="mt-2 text-sm leading-5 text-slate-600">Finish GST, discount, payment, and issue details after item entry.</p>

                        <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm text-slate-600">Line total</span>
                                <span class="text-lg font-semibold text-slate-900" x-text="currency(subtotal)"></span>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-3 text-xs text-slate-500">
                                <span x-text="items.length === 1 ? '1 item line' : items.length + ' item lines'"></span>
                                <span x-text="payments.length === 0 ? 'Payment pending' : (payments.length === 1 ? '1 payment row' : payments.length + ' payment rows')"></span>
                            </div>
                        </div>

                        <button type="button" @click="openReview()" class="mt-4 inline-flex min-h-[48px] w-full items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                            Review & Payment
                        </button>
                    </div>

                    <div x-show="reviewOpen" x-transition.opacity x-cloak class="qb-review-backdrop" @click="closeReview()"></div>
                    <section x-show="reviewOpen" x-transition x-cloak class="qb-review-drawer" role="dialog" aria-modal="true" aria-label="Review and payment">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                            <div>
                                <div class="text-base font-semibold text-slate-900">Review & Payment</div>
                                <div class="mt-1 text-xs text-slate-500">GST, payment tracker, and final totals</div>
                            </div>
                            <button type="button" @click="closeReview()" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-600 transition hover:bg-slate-50" aria-label="Close review">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="qb-review-scroll space-y-4">
                    {{-- Side: Pricing & Tax --}}
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-sm font-bold uppercase tracking-widest text-slate-800">Tax & Discount</h3>
                            <input type="hidden" name="pricing_mode" :value="pricingMode">
                        </div>

                        <div class="qb-tax-mode">
                            <button type="button" @click="pricingMode='no_gst'" class="rounded-lg px-2 py-2 text-[11px] font-bold transition" :class="pricingMode === 'no_gst' ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-900'">No GST</button>
                            <button type="button" @click="pricingMode='gst_exclusive'" class="rounded-lg px-2 py-2 text-[11px] font-bold transition" :class="pricingMode === 'gst_exclusive' ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-900'">Excl.</button>
                            <button type="button" @click="pricingMode='gst_inclusive'" class="rounded-lg px-2 py-2 text-[11px] font-bold transition" :class="pricingMode === 'gst_inclusive' ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-900'">Incl.</button>
                        </div>

                        <div class="mt-5 space-y-4">
                            <div>
                                <div class="mb-2 flex items-center justify-between">
                                    <label class="text-xs font-semibold text-slate-600">GST rate (%)</label>
                                    <div class="flex items-center gap-1.5">
                                        <button type="button" @click="if (pricingMode !== 'no_gst') gstRate = 0" :disabled="pricingMode === 'no_gst'" class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-30">0%</button>
                                        <button type="button" @click="if (pricingMode !== 'no_gst') gstRate = 3" :disabled="pricingMode === 'no_gst'" class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-30">3%</button>
                                    </div>
                                </div>
                                <input type="number" name="gst_rate" x-model.number="gstRate" :readonly="pricingMode === 'no_gst'" step="0.01" min="0" max="100" class="w-full rounded-xl border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 focus:border-slate-900 focus:ring-slate-900/10 disabled:bg-slate-50">
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-2 block text-xs font-semibold text-slate-600">Discount type</label>
                                    <select name="discount_type" x-model="discountType" class="w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-slate-900 focus:ring-slate-900/10">
                                        <option value="">None</option>
                                        <option value="fixed">Fixed</option>
                                        <option value="percent">%</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-semibold text-slate-600">Value</label>
                                    <input type="number" name="discount_value" x-model.number="discountValue" step="0.01" min="0" placeholder="0.00" class="w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900">
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Side: Payment Tracker --}}
                    <div x-data="{ open: true }" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-slate-100">
                            <h3 class="text-sm font-bold uppercase tracking-widest text-slate-800">Payments</h3>
                            <button type="button" @click="addPayment()" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-3 py-1.5 text-[11px] font-bold text-white shadow-sm hover:bg-slate-800 transition">
                                Add
                            </button>
                        </div>

                        <div class="p-4 space-y-3">
                            <template x-if="payments.length === 0">
                                <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 px-4 py-6 text-center">
                                    <p class="text-xs text-slate-400">No payments added yet.</p>
                                </div>
                            </template>

                            <template x-for="(payment, index) in payments" :key="index">
                                <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3 space-y-2">
                                    <div class="flex items-center gap-2">
                                        <select :name="'payments['+index+'][payment_mode]'" x-model="payment.payment_mode" @change="onPaymentModeChange(payment)" class="flex-1 rounded-lg border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-900 font-medium">
                                            <option value="Cash">Cash</option>
                                            <option value="UPI">UPI</option>
                                            <option value="Card">Card</option>
                                            <option value="Bank">Bank</option>
                                            <option value="Wallet">Wallet</option>
                                            <option value="Other">Other</option>
                                        </select>
                                        <input :name="'payments['+index+'][amount]'" x-model.number="payment.amount" type="number" step="0.01" min="0" placeholder="Amount" class="w-32 rounded-lg border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-900 font-bold text-right">
                                        <button type="button" @click="removePayment(index)" class="text-rose-500 hover:text-rose-700">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                    {{-- UPI / Bank / Wallet → pick the saved account from shop settings. --}}
                                    <template x-if="needsMethod(payment.payment_mode)">
                                        <div>
                                            <select :name="'payments['+index+'][payment_method_id]'" x-model="payment.payment_method_id" class="w-full rounded-lg border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-900">
                                                <option value="">Select account…</option>
                                                <template x-for="m in methodsForType(payment.payment_mode)" :key="m.id"><option :value="m.id" x-text="m.label"></option></template>
                                            </select>
                                            <p x-show="methodsForType(payment.payment_mode).length === 0" class="mt-1 text-[11px] text-amber-600">No <span x-text="payment.payment_mode"></span> account in Settings - add one to record this.</p>
                                        </div>
                                    </template>
                                    <input :name="'payments['+index+'][reference_no]'" x-model="payment.reference_no" type="text" placeholder="Ref / note (e.g. Transaction ID)" class="w-full rounded-lg border-slate-200 bg-white px-2 py-1.5 text-[11px] text-slate-600">
                                    <input type="hidden" :name="'payments['+index+'][notes]'" x-model="payment.notes">
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Side: Totals --}}
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-slate-500">Subtotal</span>
                                <span class="font-medium text-slate-700" x-text="currency(subtotal)"></span>
                            </div>
                            <div x-show="discountAmount > 0" class="flex items-center justify-between text-sm">
                                <span class="text-slate-500">Discount</span>
                                <span class="font-medium text-rose-600" x-text="'-' + currency(discountAmount)"></span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-slate-500">Taxable</span>
                                <span class="font-medium text-slate-700" x-text="currency(taxableAmount)"></span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-slate-500">GST (<span x-text="gstRate + '%'"></span>)</span>
                                <span class="font-medium text-slate-700" x-text="currency(gstAmount)"></span>
                            </div>
                            <div class="pt-3 flex items-center justify-between border-t border-slate-100">
                                <span class="text-base font-bold text-slate-900">Grand Total</span>
                                <span class="text-xl font-black text-slate-900" x-text="currency(totalAmount)"></span>
                            </div>
                        </div>

                        <div class="mt-5 space-y-2">
                            <div class="flex items-center justify-between text-sm px-3 py-2 bg-slate-50 rounded-xl">
                                <span class="text-slate-500 font-medium">Paid</span>
                                <span class="font-bold text-emerald-600" x-text="currency(paidAmount)"></span>
                            </div>
                            <div class="flex items-center justify-between text-sm px-3 py-2 bg-slate-50 rounded-xl">
                                <span class="text-slate-500 font-medium">Due</span>
                                <span class="font-bold" :class="dueAmount > 0 ? 'text-rose-600' : 'text-slate-900'" x-text="currency(dueAmount)"></span>
                            </div>
                        </div>

                        <div class="mt-6 flex flex-col gap-3">
                            @if(!$editing || $quickBill->status === \App\Models\QuickBill::STATUS_DRAFT)
                                <button type="submit" name="save_action" value="issue" class="w-full flex min-h-[52px] items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-slate-200 transition hover:bg-slate-800 active:scale-[0.98]">
                                    Issue Quick Bill
                                </button>
                                <button type="submit" name="save_action" value="draft" class="w-full flex min-h-[52px] items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 active:scale-[0.98]">
                                    Save Draft
                                </button>
                            @else
                                <button type="submit" name="save_action" value="issue" class="w-full flex min-h-[52px] items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-slate-200 transition hover:bg-slate-800 active:scale-[0.98]">
                                    Update Quick Bill
                                </button>
                            @endif
                            @if($editing)
                                <a href="{{ route('quick-bills.show', $quickBill) }}" class="w-full flex min-h-[52px] items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-bold text-slate-600 shadow-sm transition hover:bg-slate-50 active:scale-[0.98]">
                                    Cancel Changes
                                </a>
                            @endif
                        </div>
                    </div>
                        </div>
                    </section>
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
                making_charge_type: item.making_charge_type || 'fixed',
                stone_charge: Number(item.stone_charge || 0),
                hallmark_charge: Number(item.hallmark_charge || 0),
                rhodium_charge: Number(item.rhodium_charge || 0),
                other_charge: Number(item.other_charge || 0),
                wastage_percent: Number(item.wastage_percent || 0),
                line_discount: Number(item.line_discount || 0),
                _chargesOpen: chargesOpen,
                // Treat a saved net that doesn't equal gross-stone as a manual
                // override, so editing an existing bill won't silently rewrite it.
                _netManual: Number(item.net_weight || 0) > 0
                    && Math.abs(Number(item.net_weight || 0) - Math.max(0, Number(item.gross_weight || 0) - Number(item.stone_weight || 0))) > 0.0005,
            });
            const blankItem = () => initItem({
                description: '', hsn_code: '', metal_type: 'Gold', purity: '22K',
                pcs: 1, gross_weight: 0, stone_weight: 0, net_weight: 0,
                rate: 0, making_charge: 0, making_charge_type: 'fixed', stone_charge: 0,
                hallmark_charge: 0, rhodium_charge: 0, other_charge: 0,
                wastage_percent: 0, line_discount: 0,
            }, false);

            return {
                customers: config.customers || [],
                purityProfiles: config.purityProfiles || [],
                enabledMetals: config.enabledMetals || [],
                paymentMethods: config.paymentMethods || [],
                selectedCustomerId: config.selectedCustomerId ? String(config.selectedCustomerId) : '',
                customerName: config.customerName || '',
                customerMobile: config.customerMobile || '',
                customerAddress: config.customerAddress || '',
                pricingMode: config.pricingMode || 'gst_exclusive',
                gstRate: Number(config.gstRate || 0),
                discountType: config.discountType || '',
                discountValue: Number(config.discountValue || 0),
                roundOff: Number(config.roundOff || 0),
                reviewOpen: Boolean(config.reviewOpen),
                openIndex: 0,
                items: (config.items || []).map((item, i) => initItem(item,
                    Number(item.hallmark_charge || 0) > 0 || Number(item.rhodium_charge || 0) > 0 || Number(item.other_charge || 0) > 0
                )),
                payments: (config.payments || []).map(payment => ({
                    payment_mode: ({ cash: 'Cash', upi: 'UPI', card: 'Card', bank: 'Bank', wallet: 'Wallet', other: 'Other' })[String(payment.payment_mode || '').toLowerCase()] || (payment.payment_mode || 'Cash'),
                    payment_method_id: payment.payment_method_id || '',
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
                        payment_method_id: '',
                        reference_no: '',
                        amount: 0,
                        notes: '',
                    });
                },
                removePayment(index) {
                    this.payments.splice(index, 1);
                },
                openReview() {
                    this.reviewOpen = true;
                },
                closeReview() {
                    this.reviewOpen = false;
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
                // Cascading metal/purity from the shop's configured standards.
                cap(s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); },
                metalChoices() {
                    // Metals the owner enabled (gold/silver + platinum/copper if on);
                    // fall back to whatever has purity profiles, then gold/silver.
                    if (this.enabledMetals.length) return this.enabledMetals;
                    const fromProfiles = [...new Set(this.purityProfiles.map(p => p.metal))];
                    return fromProfiles.length ? fromProfiles : ['gold', 'silver'];
                },
                purityChoicesFor(metal) {
                    const m = String(metal || '').toLowerCase();
                    return this.purityProfiles.filter(p => p.metal === m);
                },
                onMetalChange(item) {
                    const choices = this.purityChoicesFor(item.metal_type);
                    item.purity = choices.length ? choices[0].label : '';
                },
                // Payment-method picker (UPI / Bank / Wallet) from shop settings.
                needsMethod(mode) { return ['upi', 'bank', 'wallet'].includes(String(mode || '').toLowerCase()); },
                methodsForType(mode) {
                    const t = String(mode || '').toLowerCase();
                    return this.paymentMethods.filter(m => m.type === t);
                },
                onPaymentModeChange(payment) {
                    if (this.needsMethod(payment.payment_mode)) {
                        const list = this.methodsForType(payment.payment_mode);
                        payment.payment_method_id = list.length ? list[0].id : '';
                    } else {
                        payment.payment_method_id = '';
                    }
                },
                netWeightOf(item) {
                    const gross = this.safeNumber(item.gross_weight);
                    const stoneWeight = this.safeNumber(item.stone_weight);
                    return this.safeNumber(item.net_weight) > 0 ? this.safeNumber(item.net_weight) : Math.max(0, gross - stoneWeight);
                },
                // Metal value = net × PURE rate × purity factor. Gold = purity/24
                // (karats), silver = purity/1000 (fineness); unknown metal or no
                // purity ⇒ factor 1. Mirrors QuickBillService exactly so the
                // preview matches what's saved.
                metalValueOf(item) {
                    const net = this.netWeightOf(item);
                    const rate = this.safeNumber(item.rate);
                    const purityNum = parseFloat(String(item.purity || '').replace(/[^0-9.]/g, '')) || 0;
                    const metal = String(item.metal_type || '').toLowerCase();
                    let factor = 1;
                    if (purityNum > 0) {
                        if (metal.includes('gold')) factor = Math.min(purityNum, 24) / 24;
                        else if (metal.includes('silver')) factor = Math.min(purityNum, 1000) / 1000;
                    }
                    return net * rate * factor;
                },
                // Resolve making per mode (preview only - server re-resolves on save).
                // percentage = of metal value; per_gram = of net weight.
                lineMaking(item) {
                    const metalValue = this.metalValueOf(item);
                    const v = this.safeNumber(item.making_charge);
                    const type = item.making_charge_type || 'fixed';
                    if (type === 'percentage') return metalValue * (v / 100);
                    if (type === 'per_gram') return this.netWeightOf(item) * v;
                    return v;
                },
                // Wastage preview amount = wastage% of metal value (mirrors
                // lineTotal + QuickBillService). Preview only; server re-resolves.
                lineWastage(item) {
                    return this.metalValueOf(item) * (this.safeNumber(item.wastage_percent) / 100);
                },
                // Net weight auto-fills from gross - stone as the owner types, to
                // save time. It stays editable: once the user types directly in
                // the Net field (_netManual), auto-fill stops overriding them.
                recalcNet(item) {
                    if (item._netManual) return;
                    // $nextTick so x-model has committed the new gross/stone before
                    // we read them (the @input handler and x-model share the event).
                    this.$nextTick(() => {
                        if (item._netManual) return;
                        const auto = Math.max(0, this.safeNumber(item.gross_weight) - this.safeNumber(item.stone_weight));
                        item.net_weight = auto > 0 ? Number(auto.toFixed(3)) : 0;
                    });
                },
                onNetInput(item) {
                    // A manual entry pins the field; clearing it re-enables auto.
                    this.$nextTick(() => {
                        item._netManual = this.safeNumber(item.net_weight) > 0;
                    });
                },
                lineTotal(item) {
                    const making = this.lineMaking(item);
                    const stoneCharge = this.safeNumber(item.stone_charge);
                    const hallmarkCharge = this.safeNumber(item.hallmark_charge);
                    const rhodiumCharge = this.safeNumber(item.rhodium_charge);
                    const otherCharge = this.safeNumber(item.other_charge);
                    const wastagePercent = this.safeNumber(item.wastage_percent);
                    const discount = this.safeNumber(item.line_discount);
                    const metalValue = this.metalValueOf(item);
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
