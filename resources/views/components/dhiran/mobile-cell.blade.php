{{-- The customer's contact in its own table column (owner 2026-11-11): a tap-to-call link, or a dash. --}}
@props(['number' => null])
@php($e164 = \App\Support\Mobile::isValid($number) ? \App\Support\Mobile::forWhatsApp($number) : null)
<td data-label="Mobile" {{ $attributes->merge(['class' => 'px-4 py-4 whitespace-nowrap text-sm']) }}>
    @if($e164)<a href="tel:+{{ $e164 }}" class="dh-tel">{{ \App\Support\Mobile::forDisplay($number) }}</a>@else<span class="text-slate-400">—</span>@endif
</td>
