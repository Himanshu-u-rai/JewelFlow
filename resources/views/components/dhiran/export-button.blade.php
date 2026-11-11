{{-- CSV export of the page's filtered records, every page of them (owner 2026-11-11). A plain download: no Turbo. --}}
<a href="{{ request()->fullUrlWithQuery(['export' => 'csv', 'page' => null, 'per_page' => null]) }}" class="btn btn-secondary btn-sm" data-turbo="false" download
   title="Download every matching record as a spreadsheet (CSV)">
    <x-icon name="download" size="14" />
    Export CSV
</a>
