<x-app-layout>
    <style>
        /* ──────────────────────────────────────────────────────────────
           Add Bullion to Vault — refined product form.
           One locked accent (teal), one radius system (cards 16 / controls
           & buttons 12 / metal token is an intentional circular badge),
           hairline structure, calm type weights. The Live Calculation panel
           is the signature "live readout", with Fine Weight as the hero.
           ────────────────────────────────────────────────────────────── */
        .vault-lot-shell {
            --vl-border: #e7ebf1;
            --vl-border-soft: #eef1f6;
            --vl-border-strong: #d9dfe8;
            --vl-ink: #0f172a;
            --vl-ink-2: #3d4861;
            --vl-muted: #6a7588;
            --vl-accent: #0d9488;
            --vl-accent-deep: #0f766e;
            --vl-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 12px 28px -16px rgba(16, 24, 40, .16);
            --vl-ease: cubic-bezier(0.23, 1, 0.32, 1);
            max-width: 1180px;
        }

        .vault-lot-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 350px;
            gap: 20px;
            align-items: start;
        }

        .vault-lot-card {
            border: 1px solid var(--vl-border);
            border-radius: 16px;
            background: #ffffff;
            box-shadow: var(--vl-shadow);
        }

        /* Motion-safe entrance — base state visible, keyframe only under
           motion-allowed (headless/reduced-motion render immediately). */
        @media (prefers-reduced-motion: no-preference) {
            .vault-lot-grid > * {
                animation: vlRise 0.5s var(--vl-ease) both;
            }
            .vault-lot-grid > *:last-child { animation-delay: 0.06s; }
            @keyframes vlRise {
                from { opacity: 0; transform: translateY(8px); }
                to   { opacity: 1; transform: translateY(0); }
            }
        }

        /* Form lives in one card; fields are a compact 2-column grid so the
           whole form fits the viewport without a long vertical scroll. */
        .vault-lot-form {
            padding: 24px;
        }

        .vault-lot-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px 22px;
            align-items: start;
        }

        /* Lightweight group heading spanning both columns, set off by a
           hairline — structure without the height of full section blocks. */
        .vault-lot-group {
            grid-column: 1 / -1;
            margin: 14px 0 0;
            padding-top: 14px;
            border-top: 1px solid var(--vl-border-soft);
            color: var(--vl-ink);
            font-size: 12.5px;
            font-weight: 650;
        }

        .vault-lot-group--first {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }

        .vault-lot-field {
            display: block;
            min-width: 0;
        }

        .vault-lot-field--full {
            grid-column: 1 / -1;
        }

        .vault-lot-textarea {
            min-height: 68px;
            padding-top: 10px;
            resize: vertical;
        }

        .vault-lot-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 7px;
            color: var(--vl-ink-2);
            font-size: 12.5px;
            font-weight: 600;
        }

        .vault-lot-control {
            width: 100%;
            border-radius: 12px;
            border: 1px solid var(--vl-border-strong);
            background: #f4f6fa;
            color: var(--vl-ink);
            font-size: 14px;
            min-height: 44px;
            transition: border-color .16s var(--vl-ease), box-shadow .16s var(--vl-ease), background-color .16s var(--vl-ease);
        }

        .vault-lot-control:focus {
            border-color: var(--vl-accent-deep);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .13);
            outline: none;
        }

        /* Manual purity input sits just under the standards dropdown when
           "Custom value" is picked. */
        .vault-lot-custom-purity {
            margin-top: 10px;
        }

        .vault-lot-help {
            margin-top: 7px;
            color: var(--vl-muted);
            font-size: 11.5px;
            line-height: 1.5;
        }

        /* "Add new vendor" affordance under the Supplier/Vendor select — a
           proper button, not a bare text link. */
        .vault-lot-add {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            padding: 8px 13px;
            border-radius: 12px;
            border: 1px dashed #a3d6cf;
            background: rgba(15, 118, 110, .05);
            color: var(--vl-accent-deep);
            font-size: 12.5px;
            font-weight: 650;
            line-height: 1;
            text-decoration: none;
            transition: background-color .16s var(--vl-ease), border-color .16s var(--vl-ease), transform .16s var(--vl-ease);
        }

        .vault-lot-add:hover {
            background: rgba(15, 118, 110, .1);
            border-color: var(--vl-accent-deep);
        }

        .vault-lot-add:active {
            transform: scale(.97);
        }

        .vault-lot-add svg {
            width: 14px;
            height: 14px;
        }

        .vault-lot-add-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 999px;
            background: rgba(15, 118, 110, .12);
        }

        /* ─── Live Calculation panel ─── */
        .vault-lot-preview {
            position: sticky;
            top: 92px;
        }

        .vault-lot-preview-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 20px 22px;
            border-bottom: 1px solid var(--vl-border-soft);
        }

        /* Metal token — a clean circular badge carrying the metal's identity
           (gold / silver). Refined, no gaudy inset ring. */
        .vault-lot-metal-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.01em;
            text-transform: uppercase;
            background: linear-gradient(150deg, #fbe9c2 0%, #e8c067 100%);
            color: #7a4f15;
            box-shadow: inset 0 0 0 1px rgba(122, 79, 21, .18);
        }

        .vault-lot-metal-chip.is-silver {
            background: linear-gradient(150deg, #f3f5f8 0%, #c7cfdb 100%);
            color: #41506a;
            box-shadow: inset 0 0 0 1px rgba(65, 80, 106, .18);
        }

        .vault-lot-preview-body {
            padding: 20px 22px 22px;
        }

        /* Hero readout: Fine Weight is the whole purpose of this form. */
        .vault-lot-fine {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding-bottom: 18px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--vl-border-soft);
        }

        .vault-lot-fine-label {
            color: var(--vl-muted);
            font-size: 12px;
            font-weight: 600;
        }

        .vault-lot-fine-value {
            color: var(--vl-accent-deep);
            font-size: 30px;
            font-weight: 700;
            line-height: 1.05;
            letter-spacing: -0.02em;
            font-variant-numeric: tabular-nums;
        }

        .vault-lot-preview-rows {
            margin: 0;
            display: grid;
        }

        .vault-lot-preview-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 9px 0;
        }

        .vault-lot-preview-row:first-child { padding-top: 0; }
        .vault-lot-preview-row:last-child { padding-bottom: 0; }

        .vault-lot-preview-label {
            color: var(--vl-muted);
            font-size: 12.5px;
            font-weight: 500;
        }

        .vault-lot-preview-value {
            color: var(--vl-ink);
            font-size: 13.5px;
            font-weight: 600;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .vault-lot-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px solid var(--vl-border-soft);
        }

        .vault-lot-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            border-radius: 12px;
            border: 1px solid var(--vl-accent-deep);
            background: var(--vl-accent-deep);
            padding: 10px 18px;
            color: #ffffff;
            font-size: 14px;
            font-weight: 650;
            box-shadow: 0 1px 2px rgba(15, 118, 110, .22);
            transition: background-color .16s var(--vl-ease), transform .16s var(--vl-ease);
        }

        .vault-lot-submit:hover {
            background: #115e56;
        }

        .vault-lot-submit:active {
            transform: scale(.98);
        }

        .vault-lot-cancel {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            border-radius: 12px;
            border: 1px solid var(--vl-border-strong);
            background: #ffffff;
            padding: 10px 16px;
            color: var(--vl-ink-2);
            font-size: 14px;
            font-weight: 600;
            transition: background-color .16s var(--vl-ease), border-color .16s var(--vl-ease);
        }

        .vault-lot-cancel:hover {
            background: #f7f9fc;
            border-color: #c5cedb;
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

            .vault-lot-section,
            .vault-lot-preview-head,
            .vault-lot-preview-body {
                padding: 16px;
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

        <form method="POST" action="{{ route('vault.lots.store') }}"
              x-data="{
                  gross: 0,
                  costPerGram: 0,
                  source: 'purchase',
                  metalType: 'gold',
                  purityProfiles: @js($purityProfiles),
                  purityChoice: @js((string) old('purity', '22')),
                  customPurity: @js((float) old('purity', 22)),
                  get availablePurities() { return this.purityProfiles.filter(p => p.metal === this.metalType); },
                  get purity() {
                      return this.purityChoice === 'custom'
                          ? (parseFloat(this.customPurity) || 0)
                          : (parseFloat(this.purityChoice) || 0);
                  },
                  get fine() {
                      if (this.metalType === 'silver') return this.gross * (this.purity / 1000);
                      return this.gross * (this.purity / 24);
                  },
                  get total() { return this.gross * this.costPerGram; },
                  onMetalChange(val) {
                      const list = this.purityProfiles.filter(p => p.metal === val);
                      this.purityChoice = list.length ? String(list[0].value) : 'custom';
                  }
              }"
              x-init="if (purityChoice !== 'custom' && !availablePurities.some(p => String(p.value) === purityChoice)) { purityChoice = availablePurities.length ? String(availablePurities[0].value) : 'custom'; }">
            @csrf

            <div class="vault-lot-grid">
                <div class="vault-lot-card vault-lot-form">
                    <div class="vault-lot-fields">
                        <p class="vault-lot-group vault-lot-group--first">Lot details</p>

                        <label class="vault-lot-field">
                            <span class="vault-lot-label">Metal Type *</span>
                            <select name="metal_type" required x-model="metalType" @change="onMetalChange($event.target.value)" class="vault-lot-control">
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

                        <p class="vault-lot-group">Weight &amp; purity</p>

                        <label class="vault-lot-field">
                            <span class="vault-lot-label">Gross Weight (g) *</span>
                            <input type="number" step="0.001" min="0.001" name="gross_weight" required x-model.number="gross" class="vault-lot-control">
                        </label>

                        <label class="vault-lot-field">
                            <span class="vault-lot-label">Purity *</span>
                            <select x-show="availablePurities.length" x-model="purityChoice" class="vault-lot-control">
                                <template x-for="p in availablePurities" :key="p.label">
                                    <option :value="String(p.value)" x-text="p.label"></option>
                                </template>
                                <option value="custom">Custom value</option>
                            </select>
                            <input type="number" step="0.01" min="1" :max="metalType === 'silver' ? 1000 : 24"
                                   x-model.number="customPurity"
                                   x-show="purityChoice === 'custom' || !availablePurities.length"
                                   :placeholder="metalType === 'silver' ? 'e.g. 958' : 'e.g. 22'"
                                   class="vault-lot-control vault-lot-custom-purity">
                            <input type="hidden" name="purity" :value="purity">
                            <p class="vault-lot-help" x-show="metalType === 'gold'">Karat value. Example: 24, 22, 20, 18, 14.</p>
                            <p class="vault-lot-help" x-show="metalType === 'silver'">Fineness value. Example: 999 or 925.</p>
                        </label>

                        <p class="vault-lot-group">Cost &amp; supplier</p>

                        <label class="vault-lot-field">
                            <span class="vault-lot-label">Cost per Gram (₹)</span>
                            <input type="number" step="0.01" min="0" name="cost_per_gram" x-model.number="costPerGram" class="vault-lot-control">
                            <p class="vault-lot-help">Optional. Used for vault costing and cash value.</p>
                        </label>

                        <label class="vault-lot-field">
                            <span class="vault-lot-label">Paid via</span>
                            @php $oldMode = old('payment_mode', 'cash'); @endphp
                            <select name="payment_mode" class="vault-lot-control">
                                <option value="cash"   {{ $oldMode === 'cash'   ? 'selected' : '' }}>Cash (drawer)</option>
                                <option value="upi"    {{ $oldMode === 'upi'    ? 'selected' : '' }}>UPI</option>
                                <option value="bank"   {{ $oldMode === 'bank'   ? 'selected' : '' }}>Bank</option>
                                <option value="card"   {{ $oldMode === 'card'   ? 'selected' : '' }}>Card</option>
                                <option value="wallet" {{ $oldMode === 'wallet' ? 'selected' : '' }}>Wallet</option>
                                <option value="other"  {{ $oldMode === 'other'  ? 'selected' : '' }}>Other</option>
                            </select>
                            <p class="vault-lot-help">Only matters when a cost is paid. Cash reduces cash in hand.</p>
                        </label>

                        <div class="vault-lot-field" x-show="source !== 'opening'">
                            <label>
                                <span class="vault-lot-label">Supplier / Vendor</span>
                                <select name="vendor_id" class="vault-lot-control">
                                    <option value="">Select vendor</option>
                                    @foreach($vendors as $vendor)
                                        <option value="{{ $vendor->id }}" {{ old('vendor_id') == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <a href="{{ route('vendors.create') }}" target="_blank" class="vault-lot-add">
                                <span class="vault-lot-add-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                                </span>
                                Add new vendor
                            </a>
                        </div>

                        <label class="vault-lot-field vault-lot-field--full">
                            <span class="vault-lot-label">Notes</span>
                            <textarea name="notes" rows="2" class="vault-lot-control vault-lot-textarea"></textarea>
                        </label>
                    </div>

                    <div class="vault-lot-actions">
                        <button type="submit" class="vault-lot-submit">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                            Add to Vault
                        </button>
                        <a href="{{ route('vault.index') }}" class="vault-lot-cancel">Cancel</a>
                    </div>
                </div>

                <aside class="vault-lot-card vault-lot-preview">
                    <div class="vault-lot-preview-head">
                        <div>
                            <h2 class="vault-lot-title">Live Calculation</h2>
                            <p class="vault-lot-copy">Updates as you type.</p>
                        </div>
                        <div class="vault-lot-metal-chip" :class="{ 'is-silver': metalType === 'silver' }" x-text="metalType === 'silver' ? 'Ag' : 'Au'">Au</div>
                    </div>

                    <div class="vault-lot-preview-body">
                        <div class="vault-lot-fine">
                            <span class="vault-lot-fine-label">Fine weight</span>
                            <span class="vault-lot-fine-value" x-text="fine.toFixed(3) + ' g'">0.000 g</span>
                        </div>

                        <div class="vault-lot-preview-rows">
                            <div class="vault-lot-preview-row">
                                <span class="vault-lot-preview-label">Source</span>
                                <span class="vault-lot-preview-value capitalize" x-text="source.replace('_', ' ')">purchase</span>
                            </div>
                            <div class="vault-lot-preview-row">
                                <span class="vault-lot-preview-label">Gross weight</span>
                                <span class="vault-lot-preview-value" x-text="gross.toFixed(3) + ' g'">0.000 g</span>
                            </div>
                            <div class="vault-lot-preview-row">
                                <span class="vault-lot-preview-label">Purity</span>
                                <span class="vault-lot-preview-value" x-text="purity + (metalType === 'silver' ? '' : 'K')">22K</span>
                            </div>
                            <div class="vault-lot-preview-row">
                                <span class="vault-lot-preview-label">Total cost</span>
                                <span class="vault-lot-preview-value" x-text="'₹' + total.toLocaleString('en-IN', { minimumFractionDigits: 2 })">₹0.00</span>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </div>
</x-app-layout>
