<x-app-layout>
    @php
        $initialMetal = old('metal_type', 'gold');
        $initialPurity = (float) old('purity', 22);
        $initialChoice = collect($purityProfiles)->contains(fn ($p) => $p['metal'] === $initialMetal && (float) $p['value'] === $initialPurity)
            ? (string) $initialPurity : 'custom';
    @endphp
    <x-page-header class="vault-record-page-header" title="Add Bullion to Vault" subtitle="Record a purchase, buyback or opening stock entry">
        <x-slot:actions>
            <a href="{{ route('vault.index') }}" class="btn btn-secondary btn-sm"><x-icon name="chevron-left" size="16" /> Vault</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner vault-lot-shell">
        <form method="POST" action="{{ route('vault.lots.store') }}"
              x-data="{
                  gross: @js((float) old('gross_weight', 0)),
                  costPerGram: @js((float) old('cost_per_gram', 0)),
                  source: @js(old('source', 'purchase')),
                  metalType: @js($initialMetal),
                  purityProfiles: @js($purityProfiles),
                  purityChoice: @js($initialChoice),
                  customPurity: @js($initialPurity),
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
                      this.customPurity = val === 'silver' ? 999 : 22;
                  }
              }">
            @csrf

            @if($errors->any())
                <div class="vault-lot-errors" role="alert">
                    <p>Please check the highlighted fields.</p>
                    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="vault-lot-grid">
                <section class="vault-lot-card vault-lot-form" aria-labelledby="vault-lot-details-title">
                    <fieldset class="vault-lot-fields">
                        <legend id="vault-lot-details-title">Lot details</legend>
                        <div class="vault-lot-field">
                            <label for="lot-metal">Metal type <span aria-hidden="true">*</span></label>
                            <select id="lot-metal" name="metal_type" required x-model="metalType" @change="onMetalChange($event.target.value)" class="vault-lot-control" aria-invalid="{{ $errors->has('metal_type') ? 'true' : 'false' }}" @error('metal_type') aria-describedby="lot-metal-error" @enderror>
                                <option value="gold" @selected($initialMetal === 'gold')>Gold</option>
                                <option value="silver" @selected($initialMetal === 'silver')>Silver</option>
                            </select>
                            @error('metal_type')<p id="lot-metal-error" class="vault-lot-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="vault-lot-field vault-lot-source">
                            <label for="lot-source">Source <span aria-hidden="true">*</span></label>
                            <select id="lot-source" name="source" required x-model="source" class="vault-lot-control" aria-invalid="{{ $errors->has('source') ? 'true' : 'false' }}">
                                <option value="purchase" @selected(old('source', 'purchase') === 'purchase')>Purchase (from supplier)</option>
                                <option value="buyback" @selected(old('source') === 'buyback')>Buyback (from customer)</option>
                                <option value="opening" @selected(old('source') === 'opening')>Opening stock</option>
                            </select>
                            @error('source')<p class="vault-lot-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="vault-lot-field">
                            <label for="lot-gross">Gross weight (g) <span aria-hidden="true">*</span></label>
                            <input id="lot-gross" type="number" step="0.001" min="0.001" name="gross_weight" value="{{ old('gross_weight', 0) }}" required x-model.number="gross" class="vault-lot-control" aria-invalid="{{ $errors->has('gross_weight') ? 'true' : 'false' }}" @error('gross_weight') aria-describedby="lot-gross-error" @enderror>
                            @error('gross_weight')<p id="lot-gross-error" class="vault-lot-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="vault-lot-field">
                            <label for="lot-purity">Purity <span aria-hidden="true">*</span></label>
                            <select id="lot-purity" x-ref="puritySelect" x-show="availablePurities.length" x-model="purityChoice"
                                    x-effect="let selected = purityChoice; availablePurities; $nextTick(() => $refs.puritySelect.value = selected)"
                                    class="vault-lot-control" aria-describedby="lot-purity-help" aria-invalid="{{ $errors->has('purity') ? 'true' : 'false' }}">
                                <template x-for="p in availablePurities" :key="p.label">
                                    <option :value="String(p.value)" x-text="p.label"></option>
                                </template>
                                <option value="custom">Custom value</option>
                            </select>
                            <label for="lot-custom-purity" class="jf-sr-only">Custom purity</label>
                            <input id="lot-custom-purity" type="number" step="0.01" min="1" :max="metalType === 'silver' ? 1000 : 24"
                                   x-model.number="customPurity" :required="purityChoice === 'custom' || !availablePurities.length"
                                   x-show="purityChoice === 'custom' || !availablePurities.length" x-cloak
                                   :placeholder="metalType === 'silver' ? 'e.g. 958' : 'e.g. 22'"
                                   aria-describedby="lot-purity-help" class="vault-lot-control vault-lot-custom-purity" aria-invalid="{{ $errors->has('purity') ? 'true' : 'false' }}">
                            <input type="hidden" name="purity" value="{{ old('purity', 22) }}" :value="purity">
                            <p id="lot-purity-help" class="vault-lot-help" x-text="metalType === 'silver' ? 'Fineness · 1–1000' : 'Karat · 1–24'">Karat · 1–24</p>
                            @error('purity')<p class="vault-lot-error">{{ $message }}</p>@enderror
                        </div>
                    </fieldset>

                    <fieldset class="vault-lot-billing">
                        <legend>Cost &amp; supplier</legend>
                        <div class="vault-lot-field">
                            <label for="lot-cost">Cost per gram (₹)</label>
                            <input id="lot-cost" type="number" step="0.01" min="0" name="cost_per_gram" value="{{ old('cost_per_gram', 0) }}" x-model.number="costPerGram" class="vault-lot-control" aria-describedby="lot-cost-help" aria-invalid="{{ $errors->has('cost_per_gram') ? 'true' : 'false' }}">
                            <p id="lot-cost-help" class="vault-lot-help">Optional. Used for vault costing.</p>
                            @error('cost_per_gram')<p class="vault-lot-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="vault-lot-field">
                            <label for="lot-payment">Paid via</label>
                            @php $oldMode = old('payment_mode', 'cash'); @endphp
                            <select id="lot-payment" name="payment_mode" class="vault-lot-control" aria-describedby="lot-payment-help" aria-invalid="{{ $errors->has('payment_mode') ? 'true' : 'false' }}">
                                @foreach(['cash' => 'Cash (drawer)', 'upi' => 'UPI', 'bank' => 'Bank', 'card' => 'Card', 'wallet' => 'Wallet', 'other' => 'Other'] as $value => $label)
                                    <option value="{{ $value }}" @selected($oldMode === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p id="lot-payment-help" class="vault-lot-help">When a cost is paid. Cash reduces cash in hand.</p>
                            @error('payment_mode')<p class="vault-lot-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="vault-lot-field vault-lot-vendor" x-show="source !== 'opening'">
                            <label for="lot-vendor">Supplier / Vendor</label>
                            <select id="lot-vendor" name="vendor_id" class="vault-lot-control" aria-invalid="{{ $errors->has('vendor_id') ? 'true' : 'false' }}">
                                <option value="">Select vendor</option>
                                @foreach($vendors as $vendor)
                                    <option value="{{ $vendor->id }}" @selected(old('vendor_id') == $vendor->id)>{{ $vendor->name }}</option>
                                @endforeach
                            </select>
                            @can('vendors.manage')
                                <a href="{{ route('vendors.create') }}" target="_blank" rel="noopener" class="vault-lot-add">Add new vendor <span class="jf-sr-only">(opens in a new tab)</span><x-icon name="external-link" size="14" /></a>
                            @endcan
                            @error('vendor_id')<p class="vault-lot-error">{{ $message }}</p>@enderror
                        </div>
                    </fieldset>

                    <div class="vault-lot-field vault-lot-notes">
                        <label for="lot-notes">Notes <span class="vault-lot-optional">(optional)</span></label>
                        <textarea id="lot-notes" name="notes" rows="2" class="vault-lot-control" aria-invalid="{{ $errors->has('notes') ? 'true' : 'false' }}">{{ old('notes') }}</textarea>
                        @error('notes')<p class="vault-lot-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="vault-lot-actions">
                        <a href="{{ route('vault.index') }}" class="btn btn-secondary btn-sm">Cancel</a>
                        <button type="submit" class="btn btn-primary btn-sm"><x-icon name="plus" size="16" /> Add to Vault</button>
                    </div>
                </section>

                <aside class="vault-lot-card vault-lot-preview" aria-labelledby="vault-lot-summary-title">
                    <header class="vault-lot-preview-head">
                        <h2 id="vault-lot-summary-title">Lot summary</h2>
                        <span class="vault-lot-metal" x-text="metalType === 'silver' ? 'Silver' : 'Gold'">Gold</span>
                    </header>
                    <div class="vault-lot-preview-body">
                        <dl class="vault-lot-preview-rows">
                            <div class="vault-lot-fine"><dt>Fine weight</dt><dd x-text="fine.toFixed(3) + ' g'">0.000 g</dd></div>
                            <div><dt>Gross weight</dt><dd x-text="Number(gross || 0).toFixed(3) + ' g'">0.000 g</dd></div>
                            <div><dt>Purity</dt><dd x-text="purity + (metalType === 'silver' ? '‰' : 'K')">22K</dd></div>
                            <div><dt>Source</dt><dd class="capitalize" x-text="source === 'opening' ? 'Opening stock' : source">Purchase</dd></div>
                            <div class="vault-lot-total"><dt>Total cost</dt><dd x-text="'₹' + total.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })">₹0.00</dd></div>
                        </dl>
                        <p class="vault-lot-formula" x-text="metalType === 'silver' ? 'Fine weight = gross × fineness ÷ 1000' : 'Fine weight = gross × karat ÷ 24'">Fine weight = gross × karat ÷ 24</p>
                        <p class="vault-lot-help">Updates as you type. Nothing is recorded until you choose Add to Vault.</p>
                    </div>
                </aside>
            </div>
        </form>
    </div>
</x-app-layout>
