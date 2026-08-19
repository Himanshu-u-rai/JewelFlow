<x-app-layout>
    <x-page-header title="Import a historical file" subtitle="Upload a CSV or XLSX export. Nothing is read yet — you confirm the column mapping on the next screen.">
        <x-slot:actions>
            <a href="{{ route('historical.index') }}" class="btn btn-sm">← Historical sales</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-upload-page max-w-2xl">
        <x-app-alerts />

        <form method="POST" action="{{ route('historical.upload.store') }}" enctype="multipart/form-data" class="grid gap-5">
            @csrf

            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                <h2 class="text-base font-semibold text-slate-800 mb-4">File</h2>
                <div class="grid gap-4">
                    <div>
                        <label for="label">Batch label <span class="text-rose-600">*</span></label>
                        <input type="text" id="label" name="label" value="{{ old('label') }}" required aria-required="true" placeholder="e.g. Tally FY 2021-22" class="w-full">
                    </div>

                    <div>
                        <label for="file">File</label>
                        <input type="file" id="file" name="file" accept=".csv,.txt,.xlsx" required aria-required="true" class="w-full">
                        <p class="text-xs text-slate-500 mt-1">Accepted formats: .csv or .xlsx. Maximum size: 20 MB. This file is stored privately for your shop only — no one outside your team can read it.</p>
                    </div>

                    <div>
                        <label for="historical_import_profile_id">Reuse a saved mapping profile <span class="text-slate-400 font-normal">(optional)</span></label>
                        <select id="historical_import_profile_id" name="historical_import_profile_id" class="w-full">
                            <option value="">— New mapping —</option>
                            @foreach($profiles as $profile)
                                <option value="{{ $profile->id }}" @selected(old('historical_import_profile_id') == $profile->id)>
                                    {{ $profile->name }} ({{ $profile->source_system ?? 'n/a' }}, {{ $profile->layout_type }})
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-slate-500 mt-1">Choose a profile from an earlier import to reuse its column mapping, or leave as "New mapping" to map columns from scratch on the next screen.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="source_system">Source system <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="text" id="source_system" name="source_system" value="{{ old('source_system') }}" placeholder="Tally, Busy, Marg…" class="w-full">
                        </div>
                        <div>
                            <label for="cutover_date">Cutover date <span class="text-slate-400 font-normal">(optional — when JewelFlow went live)</span></label>
                            <input type="date" id="cutover_date" name="cutover_date" value="{{ old('cutover_date') }}" class="w-full">
                        </div>
                    </div>
                </div>
            </div>

            <div><button class="btn btn-primary" type="submit">Upload &amp; continue to mapping</button></div>
        </form>
    </div>
</x-app-layout>
