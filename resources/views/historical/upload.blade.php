<x-app-layout>
    <div class="page" style="padding:1rem;max-width:700px;margin:0 auto;">
        <x-app-alerts />

        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h1 style="margin:0;">Import a historical file</h1>
            <a href="{{ route('historical.index') }}">← Historical sales</a>
        </div>
        <p style="color:#475569;">
            Upload a CSV or XLSX export. Nothing is read yet — you confirm the column mapping on the next screen.
        </p>

        <form method="POST" action="{{ route('historical.upload.store') }}" enctype="multipart/form-data"
              style="display:grid;gap:1rem;margin-top:1rem;">
            @csrf

            <label>Batch label
                <input type="text" name="label" value="{{ old('label') }}" required placeholder="e.g. Tally FY 2021-22" style="width:100%;">
            </label>

            <label>File (.csv or .xlsx)
                <input type="file" name="file" accept=".csv,.txt,.xlsx" required style="width:100%;">
            </label>

            <label>Reuse a saved mapping profile (optional)
                <select name="historical_import_profile_id" style="width:100%;">
                    <option value="">— New mapping —</option>
                    @foreach($profiles as $profile)
                        <option value="{{ $profile->id }}" @selected(old('historical_import_profile_id') == $profile->id)>
                            {{ $profile->name }} ({{ $profile->source_system ?? 'n/a' }}, {{ $profile->layout_type }})
                        </option>
                    @endforeach
                </select>
            </label>

            <label>Source system (optional)
                <input type="text" name="source_system" value="{{ old('source_system') }}" placeholder="Tally, Busy, Marg…" style="width:100%;">
            </label>

            <label>Cutover date (optional — when JewelFlow went live)
                <input type="date" name="cutover_date" value="{{ old('cutover_date') }}" style="width:100%;">
            </label>

            <div><button class="btn btn-primary" type="submit">Upload &amp; continue to mapping</button></div>
        </form>
    </div>
</x-app-layout>
