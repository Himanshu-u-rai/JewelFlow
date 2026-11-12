@php
    $home = \App\Support\Realm::current() === \App\Support\Realm::DHIRAN ? url('/dhiran') : route('dashboard');
    $prev = url()->previous() !== url()->current() ? url()->previous() : $home;
@endphp
<x-error-page title="Page not found" message="This page doesn't exist or may have been moved." icon="zoom-out" tone="slate" :actions="[
    ['label' => 'Back', 'href' => $prev, 'kind' => 'secondary', 'key' => 'back', 'icon' => 'arrow-left'],
    ['label' => 'Dashboard', 'href' => $home, 'kind' => 'primary', 'key' => 'dashboard'],
]" />
