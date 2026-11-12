<x-dhiran-layout title="Upload Old Loans">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Upload Old Loans</h1>
            <p class="text-sm text-gray-500 mt-1">Bring in many loans from your paper book at once</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.old-loans.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="arrow-left" size="14" class="mr-1" />
                Old Loans
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner">
        <div class="max-w-3xl mx-auto space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">
                <div class="dh-section-title"><x-icon name="download" /> 1. Fill in the template</div>
                <p class="text-sm text-slate-600 mb-3">One row per pledged item. Rows with the same ticket number are one loan; write the loan and customer details on its first row. Dates like 15-01-2026. Up to {{ $maxRows }} rows per file.</p>
                <a href="{{ route('dhiran.old-loans.template') }}" class="btn btn-secondary btn-sm" data-turbo="false">
                    <x-icon name="download" size="14" class="mr-1" /> Download template (CSV)
                </a>
                <details class="mt-4">
                    <summary class="text-sm font-semibold cursor-pointer">What each column means</summary>
                    <dl class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1 text-sm">
                        @foreach($columns as $key => $meaning)
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1"><dt class="font-mono text-slate-700">{{ $key }}</dt><dd class="text-slate-500 text-right">{{ $meaning }}</dd></div>
                        @endforeach
                    </dl>
                </details>
            </div>

            <form method="POST" action="{{ route('dhiran.old-loans.upload.store') }}" enctype="multipart/form-data" data-turbo-frame="_top"
                  class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">
                @csrf
                <div class="dh-section-title"><x-icon name="upload" /> 2. Upload it</div>
                <p class="text-sm text-slate-600 mb-3">CSV or Excel (.xlsx). You will see every loan and any problems before anything is saved.</p>
                <input type="file" name="file" accept=".csv,.xlsx" required class="dh-input">
                @error('file')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="flex justify-end mt-4">
                    <button type="submit" class="btn btn-primary btn-sm"><x-icon name="eye" size="14" class="mr-1" /> Check the file</button>
                </div>
            </form>
        </div>
    </div>
</x-dhiran-layout>
