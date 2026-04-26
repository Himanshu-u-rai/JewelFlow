<x-app-layout>
    <x-page-header :title="'Receive Items — ' . $jobOrder->job_order_number" :subtitle="'Karigar: ' . ($jobOrder->karigar?->name ?? '—')">
        <x-slot:actions>
            <a href="{{ route('job-orders.show', $jobOrder) }}" class="btn btn-secondary btn-sm">← Back</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-4">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                <div><div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Issued fine</div><div class="font-mono font-bold">{{ number_format($jobOrder->issued_fine_weight, 3) }}g</div></div>
                <div><div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Already received</div><div class="font-mono font-bold">{{ number_format($jobOrder->returned_fine_weight, 3) }}g</div></div>
                <div><div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Outstanding</div><div class="font-mono font-bold">{{ number_format($jobOrder->outstanding_fine, 3) }}g</div></div>
                <div><div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Allowed wastage</div><div class="font-mono font-bold">{{ number_format($jobOrder->allowed_wastage_fine, 3) }}g ({{ $jobOrder->allowed_wastage_percent }}%)</div></div>
            </div>
        </div>

        <form method="POST" action="{{ route('job-orders.receive.store', $jobOrder) }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5"
              x-data="receiveForm({{ (float) $jobOrder->purity }}, '{{ $jobOrder->metal_type }}')">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Receipt Date *</label>
                    <input type="date" name="receipt_date" required value="{{ now()->toDateString() }}" class="w-full rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Notes</label>
                    <input type="text" name="notes" class="w-full rounded-md border-gray-300 text-sm">
                </div>
            </div>

            <div class="border-t border-gray-200 pt-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-800">Finished Items Received</h3>
                    <button type="button" @click="addLine" class="text-xs text-teal-700 hover:underline">+ Add line</button>
                </div>

                <table class="w-full text-sm mb-3">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wide text-gray-500">
                            <th class="text-left py-1 font-semibold">Description *</th>
                            <th class="text-left py-1 font-semibold">HSN</th>
                            <th class="text-right py-1 font-semibold">Pcs *</th>
                            <th class="text-right py-1 font-semibold">Gross *</th>
                            <th class="text-right py-1 font-semibold">Stone</th>
                            <th class="text-right py-1 font-semibold">Net *</th>
                            <th class="text-right py-1 font-semibold">Purity *</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(line, idx) in lines" :key="idx">
                            <tr class="border-t border-gray-100">
                                <td class="py-1 pr-1"><input type="text" :name="'items[' + idx + '][description]'" required x-model="line.description" class="w-full rounded-md border-gray-300 text-sm"></td>
                                <td class="py-1 pr-1"><input type="text" :name="'items[' + idx + '][hsn_code]'" x-model="line.hsn_code" placeholder="7113" class="w-full rounded-md border-gray-300 text-sm" style="width:75px;"></td>
                                <td class="py-1 pr-1 text-right"><input type="number" min="1" :name="'items[' + idx + '][pieces]'" required x-model="line.pieces" class="rounded-md border-gray-300 text-sm text-right" style="width:60px;"></td>
                                <td class="py-1 pr-1 text-right"><input type="number" step="0.001" min="0.001" :name="'items[' + idx + '][gross_weight]'" required x-model="line.gross_weight" @input="recompute(idx)" class="rounded-md border-gray-300 text-sm text-right" style="width:90px;"></td>
                                <td class="py-1 pr-1 text-right"><input type="number" step="0.001" min="0" :name="'items[' + idx + '][stone_weight]'" x-model="line.stone_weight" @input="recompute(idx)" class="rounded-md border-gray-300 text-sm text-right" style="width:80px;"></td>
                                <td class="py-1 pr-1 text-right"><input type="number" step="0.001" min="0.001" :name="'items[' + idx + '][net_weight]'" required x-model="line.net_weight" class="rounded-md border-gray-300 text-sm text-right font-semibold" style="width:90px;"></td>
                                <td class="py-1 pr-1 text-right"><input type="number" step="0.01" min="1" :max="metalType === 'silver' ? 1000 : 24" :name="'items[' + idx + '][purity]'" required x-model="line.purity" class="rounded-md border-gray-300 text-sm text-right" style="width:75px;"></td>
                                <td class="py-1 text-right"><button type="button" x-show="lines.length > 1" @click="removeLine(idx)" class="text-rose-600 text-xs">×</button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="bg-emerald-50 rounded-lg p-4 mb-5">
                <div class="grid grid-cols-4 gap-4 text-sm">
                    <div><div class="text-[10px] uppercase tracking-wide text-emerald-700 font-semibold">Total Pcs</div><div class="font-mono font-bold" x-text="totalPieces">0</div></div>
                    <div><div class="text-[10px] uppercase tracking-wide text-emerald-700 font-semibold">Total Gross</div><div class="font-mono font-bold" x-text="totalGross.toFixed(3) + 'g'">0.000g</div></div>
                    <div><div class="text-[10px] uppercase tracking-wide text-emerald-700 font-semibold">Total Net</div><div class="font-mono font-bold" x-text="totalNet.toFixed(3) + 'g'">0.000g</div></div>
                    <div><div class="text-[10px] uppercase tracking-wide text-emerald-700 font-semibold">Total Fine (est.)</div><div class="font-mono font-bold" x-text="totalFine.toFixed(3) + 'g'">0.000g</div></div>
                </div>
                <p class="text-[11px] text-amber-700 mt-2" x-show="exceedsTolerance">⚠ Wastage may exceed tolerance. The system will flag this; you can still submit and acknowledge later.</p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-success btn-sm">Save Receipt</button>
                <a href="{{ route('job-orders.show', $jobOrder) }}" class="text-sm text-gray-500">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        function receiveForm(defaultPurity, metalType) {
            return {
                metalType: metalType,
                lines: [{ description: '', hsn_code: '7113', pieces: 1, gross_weight: '', stone_weight: 0, net_weight: '', purity: defaultPurity }],
                get totalPieces() { return this.lines.reduce((s, l) => s + (parseInt(l.pieces) || 0), 0); },
                get totalGross() { return this.lines.reduce((s, l) => s + (parseFloat(l.gross_weight) || 0), 0); },
                get totalNet() { return this.lines.reduce((s, l) => s + (parseFloat(l.net_weight) || 0), 0); },
                // FIX #1 (JS) + #6: use karat-aware formula; gold = purity/24, silver = purity/1000
                get totalFine() {
                    return this.lines.reduce((s, l) => {
                        const net = parseFloat(l.net_weight) || 0;
                        const p   = parseFloat(l.purity) || 0;
                        return s + (this.metalType === 'silver' ? net * p / 1000 : net * p / 24);
                    }, 0);
                },
                // FIX #18: warn when wastage (issued - returned) would EXCEED allowed wastage
                get issuedFine() { return {{ (float) $jobOrder->issued_fine_weight }}; },
                get alreadyReturned() { return {{ (float) $jobOrder->returned_fine_weight }}; },
                get allowedWastage() { return {{ (float) $jobOrder->allowed_wastage_fine }}; },
                get exceedsTolerance() {
                    const actualWastage = this.issuedFine - this.alreadyReturned - this.totalFine;
                    return actualWastage > this.allowedWastage + 0.001;
                },
                addLine() { this.lines.push({ description: '', hsn_code: '7113', pieces: 1, gross_weight: '', stone_weight: 0, net_weight: '', purity: defaultPurity }); },
                removeLine(i) { this.lines.splice(i, 1); },
                recompute(idx) {
                    const g = parseFloat(this.lines[idx].gross_weight) || 0;
                    const s = parseFloat(this.lines[idx].stone_weight) || 0;
                    this.lines[idx].net_weight = Math.max(0, g - s).toFixed(3);
                },
            };
        }
    </script>
</x-app-layout>
