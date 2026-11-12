{{-- CSV export of the page's filtered records, every page of them (owner 2026-11-11). A plain download: no Turbo. --}}
{{-- Live search changes the page address without reloading, so the link is built from the address when clicked. --}}
<a href="{{ request()->fullUrlWithQuery(['export' => 'csv', 'page' => null, 'per_page' => null]) }}" class="btn btn-secondary btn-sm" data-turbo="false" download data-dhiran-export
   onclick="const u = new window.URL(window.location.href); u.searchParams.delete('page'); u.searchParams.delete('per_page'); u.searchParams.set('export', 'csv'); this.href = u.toString();"
   title="Download every matching record as a spreadsheet (CSV)">
    <x-icon name="download" size="14" />
    Export CSV
</a>
