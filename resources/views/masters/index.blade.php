<x-app-layout>
    <x-page-header
        title="Masters"
        subtitle="Reusable business records used across sales, inventory, purchasing and manufacturing." />

    <div class="content-inner">
        @php
            $sections = [
                'parties' => 'Parties',
                'product' => 'Product Setup',
                'config'  => 'Related Config',
            ];
            $hasAnyCard = $cards->flatten(1)->isNotEmpty();
        @endphp

        @if($hasAnyCard)
            @foreach($sections as $key => $label)
                @continue(! ($cards->has($key) && $cards[$key]->isNotEmpty()))
                <section class="mb-8" aria-labelledby="masters-{{ $key }}-heading">
                    <h2 id="masters-{{ $key }}-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-3">{{ $label }}</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach($cards[$key] as $card)
                            <a href="{{ route($card['route'], $card['params'] ?? []) . (isset($card['fragment']) ? '#' . $card['fragment'] : '') }}"
                               data-masters-card="{{ $card['icon'] }}"
                               class="group flex items-start gap-4 bg-white rounded-xl shadow-sm border border-gray-200 p-5 transition hover:shadow-md hover:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-500/40">
                                <span class="shrink-0 inline-flex h-11 w-11 items-center justify-center rounded-lg bg-amber-50 text-amber-600" aria-hidden="true">
                                    @include('masters._icon', ['icon' => $card['icon']])
                                </span>
                                <span class="min-w-0">
                                    <span class="block font-semibold text-gray-900">{{ $card['title'] }}</span>
                                    <span class="block text-sm text-gray-500 mt-1">{{ $card['description'] }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @else
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">
                <p class="text-gray-800 font-medium">No master records are available for your access.</p>
                <p class="text-sm text-gray-500 mt-1">Ask your shop owner to grant access if you need customers, categories or other master data.</p>
            </div>
        @endif
    </div>
</x-app-layout>
