<x-app-layout>
    <x-page-header title="Import a historical file" subtitle="Upload a CSV or XLSX export. Nothing is read yet — you confirm the column mapping on the next screen.">
        <x-slot:actions>
            <a href="{{ route('historical.index') }}"
               class="inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition-colors hover:border-amber-400 hover:bg-amber-50 hover:text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
               data-historical-back>
                <svg class="h-4 w-4 shrink-0 text-amber-700" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path d="M12.5 5 7.5 10l5 5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span>Back to historical sales</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-upload-page max-w-4xl" style="--app-control-bg: #ffffff; --app-control-border: #cbd5e1; --app-control-border-focus: #b45309;">
        <x-app-alerts />

        @include('historical._workflow-steps', ['currentStep' => 'upload'])

        <form method="POST" action="{{ route('historical.upload.store') }}" enctype="multipart/form-data" class="grid gap-5" data-historical-form="upload">
            @csrf

            <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header>
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Step 1 of 4</p>
                    <h2 class="mt-1 text-lg font-semibold text-slate-900">Choose the source file</h2>
                    <p class="mt-1 text-sm text-slate-500">Create a private import batch, then confirm how its columns map to Historical Sales.</p>
                </div>

                <div class="grid gap-5 p-4 sm:p-6" data-historical-card-body>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 sm:p-5" data-historical-upload-source>
                        <label for="file" class="block text-sm font-semibold text-slate-700 mb-2">File <span class="text-rose-600">*</span></label>
                        <input type="file" id="file" name="file" accept=".csv,.txt,.xlsx" required aria-required="true" class="w-full min-h-[44px] rounded-lg border border-slate-300 bg-white px-3 py-2">
                        <p class="mt-2 text-xs leading-5 text-slate-500">Accepted formats: .csv or .xlsx. Maximum size: 20 MB. This file is stored privately for your shop only — no one outside your team can read it.</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" data-historical-upload-details>
                        <div>
                            <label for="label" class="mb-2 block text-sm font-semibold text-slate-700">Batch label <span class="text-rose-600">*</span></label>
                            <input type="text" id="label" name="label" value="{{ old('label') }}" required aria-required="true" placeholder="e.g. Tally FY 2021-22" class="w-full min-h-[44px] rounded-lg">
                            <p class="mt-1 text-xs text-slate-500">Use a label your team will recognise during review.</p>
                        </div>
                        <div>
                            <label for="historical_import_profile_id" class="mb-2 block text-sm font-semibold text-slate-700">Saved mapping profile <span class="font-normal text-slate-400">(optional)</span></label>
                            <select id="historical_import_profile_id" name="historical_import_profile_id" class="w-full min-h-[44px] rounded-lg">
                                <option value="">— New mapping —</option>
                                @foreach($profiles as $profile)
                                    <option value="{{ $profile->id }}" @selected(old('historical_import_profile_id') == $profile->id)>
                                        {{ $profile->name }} ({{ $profile->source_system ?? 'n/a' }}, {{ $profile->layout_type }})
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Reuse an earlier profile or map the columns from scratch.</p>
                        </div>
                        <div>
                            <label for="source_system" class="mb-2 block text-sm font-semibold text-slate-700">Source system <span class="font-normal text-slate-400">(optional)</span></label>
                            <input type="text" id="source_system" name="source_system" value="{{ old('source_system') }}" placeholder="Tally, Busy, Marg…" class="w-full min-h-[44px] rounded-lg">
                        </div>
                        <div>
                            <label for="cutover_date" class="mb-2 block text-sm font-semibold text-slate-700">Cutover date <span class="font-normal text-slate-400">(optional)</span></label>
                            <input type="date" id="cutover_date" name="cutover_date" value="{{ old('cutover_date') }}" class="w-full min-h-[44px] rounded-lg">
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
