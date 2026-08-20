<x-app-layout>
    <x-page-header title="Import a historical file" subtitle="Upload a CSV or XLSX export. Nothing is read yet — you confirm the column mapping on the next screen.">
        <x-slot:actions>
            <a href="{{ route('historical.index') }}" class="btn btn-sm min-h-[44px]">← Historical sales</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-upload-page max-w-3xl">
        <x-app-alerts />

        @include('historical._workflow-steps', ['currentStep' => 'upload'])

        <form method="POST" action="{{ route('historical.upload.store') }}" enctype="multipart/form-data" class="grid gap-5" data-historical-form="upload">
            @csrf

            <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header>
                    <h2 class="text-lg font-semibold text-slate-900">Choose the source file</h2>
                    <p class="mt-1 text-sm text-slate-500">Create a private import batch, then confirm how its columns map to Historical Sales.</p>
                </div>

                <div class="grid gap-5 p-4 sm:p-6" data-historical-card-body>
                    <div>
                        <label for="label" class="block text-sm font-semibold text-slate-700 mb-2">Batch label <span class="text-rose-600">*</span></label>
                        <input type="text" id="label" name="label" value="{{ old('label') }}" required aria-required="true" placeholder="e.g. Tally FY 2021-22" class="w-full">
                        <p class="mt-1 text-xs text-slate-500">Use a label your team will recognise during review.</p>
                    </div>

                    <div>
                        <label for="file" class="block text-sm font-semibold text-slate-700 mb-2">File <span class="text-rose-600">*</span></label>
                        <input type="file" id="file" name="file" accept=".csv,.txt,.xlsx" required aria-required="true" class="w-full min-h-[44px]">
                        <p class="mt-1 text-xs text-slate-500">Accepted formats: .csv or .xlsx. Maximum size: 20 MB. This file is stored privately for your shop only — no one outside your team can read it.</p>
                    </div>

                    <div>
                        <label for="historical_import_profile_id" class="block text-sm font-semibold text-slate-700 mb-2">Reuse a saved mapping profile <span class="text-slate-400 font-normal">(optional)</span></label>
                        <select id="historical_import_profile_id" name="historical_import_profile_id" class="w-full min-h-[44px]">
                            <option value="">— New mapping —</option>
                            @foreach($profiles as $profile)
                                <option value="{{ $profile->id }}" @selected(old('historical_import_profile_id') == $profile->id)>
                                    {{ $profile->name }} ({{ $profile->source_system ?? 'n/a' }}, {{ $profile->layout_type }})
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Choose a profile from an earlier import to reuse its column mapping, or leave as "New mapping" to map columns from scratch on the next screen.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="source_system" class="block text-sm font-semibold text-slate-700 mb-2">Source system <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="text" id="source_system" name="source_system" value="{{ old('source_system') }}" placeholder="Tally, Busy, Marg…" class="w-full">
                        </div>
                        <div>
                            <label for="cutover_date" class="block text-sm font-semibold text-slate-700 mb-2">Cutover date <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="date" id="cutover_date" name="cutover_date" value="{{ old('cutover_date') }}" class="w-full">
                            <p class="mt-1 text-xs text-slate-500">The date JewelFlows became your live system.</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6" data-historical-card-footer>
                    <p class="text-xs text-slate-500">Uploading creates a draft batch only. Nothing is published from this screen.</p>
                    <button class="btn btn-primary min-h-[44px]" type="submit">Upload &amp; continue to mapping</button>
                </div>
            </section>
        </form>
    </div>
</x-app-layout>
