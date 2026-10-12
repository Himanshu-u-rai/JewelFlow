@props(['customer' => null, 'realm' => 'erp', 'size' => 'list'])
@php
    $photo = $customer?->profilePhoto;
    $initials = $customer ? mb_strtoupper(mb_substr($customer->first_name ?? '', 0, 1).mb_substr($customer->last_name ?? '', 0, 1)) : 'CU';
    $meta = $customer?->exists ? route(($realm === 'dhiran' ? 'dhiran.borrowers' : 'customers').'.photo.show', $customer) : '';
@endphp
<span {{ $attributes->class(['customer-avatar', 'customer-avatar--profile' => $size === 'profile']) }}
      data-customer-avatar data-photo-meta="{{ $meta }}" role="img" aria-label="{{ $customer?->name ?? 'Customer' }} photo">
    <span data-photo-initials @if($photo) hidden @endif>{{ $initials ?: 'CU' }}</span>
    <img data-photo-image @if($photo) src="{{ $photo->url($realm) }}" @else hidden @endif alt="" loading="lazy" decoding="async">
</span>
