<x-app-layout>
    <x-page-header title="Issue Bullion to Karigar" subtitle="Creates a Job Order, debits the vault, generates a Delivery Challan" />

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <form method="POST" action="{{ route('job-orders.store') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 max-w-4xl"
              x-data="jobOrderForm()">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Karigar *</label>
                    <select name="karigar_id" required class="w-full rounded-md border-gray-300 text-sm" x-model="karigarId" @change="onKarigarChange">
                        <option value="">Select karigar…</option>
                        @foreach($karigars as $k)
                            <option value="{{ $k->id }}" data-wastage="{{ $k->default_wastage_percent ?? 2 }}" {{ (string) request('karigar') === (string) $k->id ? 'selected' : '' }}>{{ $k->name }} @if($k->gst_number) — {{ $k->gst_number }} @endif</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Metal Type *</label>
                    <select name="metal_type" required class="w-full rounded-md border-gray-300 text-sm" x-model="metalType">
                        <option value="gold">Gold</option>
                        <option value="silver">Silver</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Purity *</label>
                    <input type="number" step="0.01" name="purity" required min="1" :max="metalType === 'silver' ? 1000 : 24" class="w-full rounded-md border-gray-300 text-sm" x-model="purity">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Allowed Wastage % *</label>
                    <input type="number" step="0.01" name="allowed_wastage_percent" required min="0" max="25" class="w-full rounded-md border-gray-300 text-sm" x-model="allowedWastage">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Issue Date *</label>
                    <input type="date" name="issue_date" required value="{{ now()->toDateString() }}" class="w-full rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Expected Return Date</label>
                    <input type="date" name="expected_return_date" class="w-full rounded-md border-gray-300 text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full rounded-md border-gray-300 text-sm"></textarea>
                </div>
            </div>

            <div class="border-t border-gray-200 pt-4 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-800">Bullion Issued (from vault)</h3>
                    <button type="button" @click="addLine" class="text-xs text-teal-700 hover:underline">+ Add another lot</button>
                </div>

                <template x-for="(line, idx) in lines" :key="idx">
                    <div class="grid grid-cols-12 gap-2 mb-2 items-end">
                        <div class="col-span-5">
                            <label class="block text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Lot</label>
                            <select :name="'issuances[' + idx + '][metal_lot_id]'" required class="w-full rounded-md border-gray-300 text-sm" x-model="line.metal_lot_id" @change="onLotChange(idx, $event)">
                                <option value="">Select lot…</option>
                                @foreach($lots as $lot)
                                    <option value="{{ $lot->id }}" data-purity="{{ $lot->purity }}" data-remaining="{{ $lot->fine_weight_remaining }}">
                                        Lot #{{ $lot->lot_number }} ({{ $lot->source }}) — {{ rtrim(rtrim(number_format($lot->purity, 2), '0'), '.') }}K — {{ number_format($lot->fine_weight_remaining, 3) }}g fine
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Gross (g)</label>
                            <input type="number" step="0.001" min="0" :name="'issuances[' + idx + '][gross_weight]'" required class="w-full rounded-md border-gray-300 text-sm" x-model="line.gross_weight" @input="recomputeFine(idx)">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Fine (g)</label>
                            <input type="number" step="0.001" min="0.0001" :name="'issuances[' + idx + '][fine_weight]'" required class="w-full rounded-md border-gray-300 text-sm font-semibold" x-model="line.fine_weight">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Purity</label>
                            <input type="number" step="0.01" min="1" :max="metalType === 'silver' ? 1000 : 24" :name="'issuances[' + idx + '][purity]'" required class="w-full rounded-md border-gray-300 text-sm" x-model="line.purity" @input="recomputeFine(idx)">
                        </div>
                        <div class="col-span-1 text-right">
                            <button type="button" @click="removeLine(idx)" x-show="lines.length > 1" class="text-rose-600 text-xs">×</button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="bg-amber-50 rounded-lg p-4 mb-5">
                <div class="grid grid-cols-3 gap-4 text-sm">
                    <div><div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Total Gross</div><div class="font-mono font-bold" x-text="totalGross.toFixed(3) + 'g'">0.000g</div></div>
                    <div><div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Total Fine</div><div class="font-mono font-bold" x-text="totalFine.toFixed(3) + 'g'">0.000g</div></div>
                    <div><div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Expected Return (fine)</div><div class="font-mono font-bold" x-text="expectedReturn.toFixed(3) + 'g'">0.000g</div></div>
                </div>
            </div>

            {{-- Advance to Karigar --}}
            <div class="border-t border-gray-200 pt-4 mb-5">
                <div class="flex items-center gap-2 mb-3">
                    <h3 class="text-sm font-semibold text-gray-800">Advance to Karigar</h3>
                    <span class="text-xs text-gray-400">— leave amount blank if no advance is given</span>
                </div>
                <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Advance Amount</label>
                            <input type="number" step="0.01" min="0" name="advance_amount"
                                   placeholder="0.00"
                                   class="w-full rounded-md border-gray-300 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Payment Mode</label>
                            <select name="advance_mode" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="cash">Cash</option>
                                <option value="upi">UPI</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Account</label>
                            <select name="advance_payment_method_id" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="">— Select account —</option>
                                @foreach($paymentMethods as $pm)
                                    <option value="{{ $pm->id }}">{{ $pm->name }} ({{ $pm->type }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-success btn-sm">Issue & Print Challan</button>
                <a href="{{ route('job-orders.index') }}" class="text-sm text-gray-500">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        function jobOrderForm() {
            return {
                karigarId: '',
                metalType: 'gold',
                purity: 22,
                allowedWastage: 2,
                lines: [{ metal_lot_id: '', gross_weight: '', fine_weight: '', purity: 22 }],
                get totalGross() { return this.lines.reduce((s, l) => s + (parseFloat(l.gross_weight) || 0), 0); },
                get totalFine() { return this.lines.reduce((s, l) => s + (parseFloat(l.fine_weight) || 0), 0); },
                get expectedReturn() { return this.totalFine * (1 - (parseFloat(this.allowedWastage) || 0) / 100); },
                addLine() { this.lines.push({ metal_lot_id: '', gross_weight: '', fine_weight: '', purity: this.purity }); },
                removeLine(i) { this.lines.splice(i, 1); },
                onKarigarChange(e) {
                    const opt = e.target.selectedOptions[0];
                    if (opt && opt.dataset.wastage) this.allowedWastage = parseFloat(opt.dataset.wastage);
                },
                onLotChange(idx, e) {
                    const opt = e.target.selectedOptions[0];
                    if (opt && opt.dataset.purity) {
                        this.lines[idx].purity = parseFloat(opt.dataset.purity);
                        this.recomputeFine(idx);
                    }
                },
                recomputeFine(idx) {
                    const g = parseFloat(this.lines[idx].gross_weight) || 0;
                    const p = parseFloat(this.lines[idx].purity) || 0;
                    // Gold: karat/24; Silver: fineness/1000
                    const fine = this.metalType === 'silver' ? g * p / 1000 : g * p / 24;
                    this.lines[idx].fine_weight = fine.toFixed(3);
                },
            };
        }
    </script>
</x-app-layout>
