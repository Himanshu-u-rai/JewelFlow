@props([
    'title' => '',
    'subtitle' => null,
    'headers' => [],
])

<div class="admin-panel overflow-hidden">
    <div class="admin-panel-header">
        <h3 class="text-lg font-semibold text-white">{{ $title }}</h3>
        @if($subtitle)
            <p class="text-sm text-slate-400">{{ $subtitle }}</p>
        @endif
    </div>
    <div class="overflow-x-auto">
        <table class="admin-table min-w-full text-sm">
            <thead class="bg-slate-800/80 text-slate-300">
                <tr>
                    @foreach($headers as $header)
                        <th class="px-4 py-2 text-left font-semibold">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
                {{ $slot }}
            </tbody>
        </table>
    </div>
</div>
