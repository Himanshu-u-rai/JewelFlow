<x-app-layout>
    <x-page-header
        title="Masters"
        subtitle="Reusable business records used across sales, inventory, purchasing and manufacturing." />

    <div class="content-inner masters-page">
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
                <section class="masters-section" aria-labelledby="masters-{{ $key }}-heading">
                    <h2 id="masters-{{ $key }}-heading">{{ $label }}</h2>
                    <ul class="masters-links" role="list">
                        @foreach($cards[$key] as $card)
                            <li>
                                <a href="{{ route($card['route'], $card['params'] ?? []) . (isset($card['fragment']) ? '#' . $card['fragment'] : '') }}"
                                   data-masters-card="{{ $card['icon'] }}"
                                   class="masters-link"
                                   aria-labelledby="masters-{{ $card['icon'] }}-title"
                                   aria-describedby="masters-{{ $card['icon'] }}-description">
                                    <span class="masters-link-icon" aria-hidden="true">
                                        @include('masters._icon', ['icon' => $card['icon']])
                                    </span>
                                    <span class="masters-link-copy">
                                        <span id="masters-{{ $card['icon'] }}-title" class="masters-link-title">{{ $card['title'] }}</span>
                                        <span id="masters-{{ $card['icon'] }}-description" class="masters-link-description">{{ $card['description'] }}</span>
                                    </span>
                                    <span class="masters-link-arrow" aria-hidden="true">
                                        <x-icon name="chevron-right" size="16" />
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @else
            <div class="masters-empty">
                <p>No master records are available for your access.</p>
                <p>Ask your shop owner to grant access if you need customers, categories or other master data.</p>
            </div>
        @endif
    </div>
</x-app-layout>
