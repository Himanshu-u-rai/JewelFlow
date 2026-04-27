<x-app-layout>
    <style>
        .vault-lot-shell {
            max-width: 1180px;
        }

        .vault-lot-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 340px;
            gap: 18px;
            align-items: start;
        }

        .vault-lot-card {
            border: 1px solid #dbe3ee;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 14px 28px rgba(15, 23, 42, .06);
        }

        .vault-lot-section {
            padding: 18px;
            border-bottom: 1px solid #e2e8f0;
        }

        .vault-lot-section:last-child {
            border-bottom: 0;
        }

        .vault-lot-title {
            margin: 0;
            color: #0f172a;
            font-size: 15px;
            font-weight: 900;
        }

        .vault-lot-copy {
            margin-top: 3px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.45;
        }

        .vault-lot-field {
            display: block;
        }

        .vault-lot-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 6px;
            color: #334155;
            font-size: 12px;
            font-weight: 800;
        }

        .vault-lot-control {
            width: 100%;
            border-radius: 13px;
            border-color: #cbd5e1;
            background: #f8fafc;
            color: #0f172a;
            font-size: 14px;
            min-height: 44px;
        }

        .vault-lot-control:focus {
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
        }

        .vault-lot-help {
            margin-top: 6px;
            color: #94a3b8;
            font-size: 11px;
            line-height: 1.4;
        }

        .vault-lot-preview {
            position: sticky;
            top: 92px;
        }

        .vault-lot-preview-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px;
            border-bottom: 1px solid #e2e8f0;
        }

        .vault-lot-metal-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 54px;
            height: 54px;
            border-radius: 999px;
            border: 1px solid #f59e0b;
            background: #fffbeb;
            color: #92400e;
            box-shadow: inset 0 0 0 5px #fef3c7, 0 10px 18px rgba(245, 158, 11, .14);
            font-size: 13px;
            font-weight: 950;
            text-transform: uppercase;
        }

        .vault-lot-preview-body {
            padding: 16px;
        }

        .vault-lot-preview-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            border-bottom: 1px solid #edf2f7;
            padding: 11px 0;
        }

        .vault-lot-preview-row:first-child {
            padding-top: 0;
        }

        .vault-lot-preview-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .vault-lot-preview-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
        }

        .vault-lot-preview-value {
            color: #0f172a;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 15px;
            font-weight: 900;
            text-align: right;
        }

        .vault-lot-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .vault-lot-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            border-radius: 13px;
            border: 1px solid #0f766e;
            background: #0f766e;
            padding: 10px 16px;
            color: #ffffff;
            font-size: 14px;
            font-weight: 900;
            box-shadow: 0 12px 24px rgba(15, 118, 110, .18);
        }

        .vault-lot-cancel {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            border-radius: 13px;
            border: 1px solid #dbe3ee;
            background: #ffffff;
            padding: 10px 15px;
            color: #475569;
            font-size: 14px;
            font-weight: 800;
        }

        @media (max-width: 960px) {
            .vault-lot-grid {
                grid-template-columns: 1fr;
            }

            .vault-lot-preview {
                position: static;
                order: -1;
            }
        }

        @media (max-width: 640px) {
            .vault-lot-shell {
                padding-inline: 10px;
            }

            .vault-lot-card {
                border-radius: 16px;
            }

            .vault-lot-section,
            .vault-lot-preview-head,
            .vault-lot-preview-body {
                padding: 14px;
            }

            .vault-lot-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .vault-lot-submit,
            .vault-lot-cancel {
                width: 100%;
            }
        }
    </style>

    <x-page-header title="Add Bullion to Vault" subtitle="Record a fresh purchase, buyback, or opening stock entry" />

    <div class="content-inner vault-lot-shell">
        <x-app-alerts class="mb-4" />

        <form method="POST" action="{{ route('vault.lots.store') }}"
              x-data="{ gross: 0, purity: 22, costPerGram: 0, source: 'purchase', metalType: 'gold',
                        get fine() {
                            if (this.metalType === 'silver') return this.gross * (this.purity / 1000);
                            return this.gross * (this.purity / 24);
                        },
                        get total() { return this.gross * this.costPerGram; } }">
            @csrf

            <div class="vault-lot-grid">
                <div class="vault-lot-card">
                    <section class="vault-lot-section">
                        <h2 class="vault-lot-title">Lot Details</h2>
                        <p class="vault-lot-copy">Choose what entered the vault and where this bullion came from.</p>

                        <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <label class="vault-lot-field">
                                <span class="vault-lot-label">Metal Type *</span>
                                <select name="metal_type" required x-model="metalType" class="vault-lot-control">
                                    <option value="gold">Gold</option>
                                    <option value="silver">Silver</option>
                                </select>
                            </label>

                            <label class="vault-lot-field">
                                <span class="vault-lot-label">Source *</span>
                                <select name="source" required x-model="source" class="vault-lot-control">
                                    <option value="purchase">Purchase (from supplier)</option>
                                    <option value="buyback">Buyback (from customer)</option>
                                    <option value="opening">Opening Stock</option>
                                </select>
                            </label>
                        </div>
                    </section>

                    <section class="vault-lot-section">
                        <h2 class="vault-lot-title">Weight & Purity</h2>
                        <p class="vault-lot-copy">Fine weight is calculated automatically from gross weight and purity.</p>

                        <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <label class="vault-lot-field">
                                <span class="vault-lot-label">Gross Weight (g) *</span>
                                <input type="number" step="0.001" min="0.001" name="gross_weight" required x-model.number="gross" class="vault-lot-control">
                            </label>

                            <label class="vault-lot-field">
                                <span class="vault-lot-label">Purity *</span>
                                <input type="number" step="0.01" min="1" :max="metalType === 'silver' ? 1000 : 24" name="purity" required x-model.number="purity" class="vault-lot-control">
                                <p class="vault-lot-help" x-show="metalType === 'gold'">Karat value. Example: 24, 22, 20, 18, 14.</p>
                                <p class="vault-lot-help" x-show="metalType === 'silver'">Fineness value. Example: 999 or 925.</p>
                            </label>
                        </div>
                    </section>

                    <section class="vault-lot-section">
                        <h2 class="vault-lot-title">Cost & Supplier</h2>
                        <p class="vault-lot-copy">Cost is optional, but helps calculate average cost per fine gram.</p>

                        <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <label class="vault-lot-field">
                                <span class="vault-lot-label">Cost per Gram (₹)</span>
                                <input type="number" step="0.01" min="0" name="cost_per_gram" x-model.number="costPerGram" class="vault-lot-control">
                                <p class="vault-lot-help">Used only for vault costing and cash transaction value.</p>
                            </label>

                            <label class="vault-lot-field" x-show="source !== 'opening'">
                                <span class="vault-lot-label">Supplier / Vendor</span>
                                <select name="vendor_id" class="vault-lot-control">
                                    <option value="">Select vendor</option>
                                    @foreach($vendors as $vendor)
                                        <option value="{{ $vendor->id }}" {{ old('vendor_id') == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                    @endforeach
                                </select>
                                <p class="vault-lot-help">Optional. <a href="{{ route('vendors.create') }}" class="font-semibold text-teal-700 hover:underline" target="_blank">Add vendor</a> if not listed.</p>
                            </label>

                            <label class="vault-lot-field sm:col-span-2">
                                <span class="vault-lot-label">Notes</span>
                                <textarea name="notes" rows="3" class="vault-lot-control min-h-[96px]"></textarea>
                            </label>
                        </div>
                    </section>

                    <section class="vault-lot-section">
                        <div class="vault-lot-actions">
                            <button type="submit" class="vault-lot-submit">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                                Add to Vault
                            </button>
                            <a href="{{ route('vault.index') }}" class="vault-lot-cancel">Cancel</a>
                        </div>
                    </section>
                </div>

                <aside class="vault-lot-card vault-lot-preview">
                    <div class="vault-lot-preview-head">
                        <div>
                            <h2 class="vault-lot-title">Live Calculation</h2>
                            <p class="vault-lot-copy">Preview before adding the lot.</p>
                        </div>
                        <div class="vault-lot-metal-chip" x-text="metalType === 'silver' ? 'Ag' : 'Au'">Au</div>
                    </div>

                    <div class="vault-lot-preview-body">
                        <div class="vault-lot-preview-row">
                            <span class="vault-lot-preview-label">Source</span>
                            <span class="vault-lot-preview-value capitalize" x-text="source.replace('_', ' ')">purchase</span>
                        </div>
                        <div class="vault-lot-preview-row">
                            <span class="vault-lot-preview-label">Gross Weight</span>
                            <span class="vault-lot-preview-value" x-text="gross.toFixed(3) + 'g'">0.000g</span>
                        </div>
                        <div class="vault-lot-preview-row">
                            <span class="vault-lot-preview-label">Purity</span>
                            <span class="vault-lot-preview-value" x-text="purity + (metalType === 'silver' ? '' : 'K')">22K</span>
                        </div>
                        <div class="vault-lot-preview-row">
                            <span class="vault-lot-preview-label">Fine Weight</span>
                            <span class="vault-lot-preview-value text-amber-700" x-text="fine.toFixed(3) + 'g'">0.000g</span>
                        </div>
                        <div class="vault-lot-preview-row">
                            <span class="vault-lot-preview-label">Total Cost</span>
                            <span class="vault-lot-preview-value" x-text="'₹' + total.toLocaleString('en-IN', { minimumFractionDigits: 2 })">₹0.00</span>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </div>
</x-app-layout>
