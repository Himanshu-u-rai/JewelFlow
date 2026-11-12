@php
    $home = \App\Support\Realm::current() === \App\Support\Realm::DHIRAN ? url('/dhiran') : route('dashboard');
    $prev = url()->previous() !== url()->current() ? url()->previous() : $home;
@endphp
<x-error-page title="Something went wrong" message="An unexpected error occurred. Please try again in a few minutes." icon="triangle-alert" tone="red" :actions="[
    ['label' => 'Back', 'href' => $prev, 'kind' => 'secondary', 'key' => 'back', 'icon' => 'arrow-left'],
    ['label' => 'Dashboard', 'href' => $home, 'kind' => 'primary', 'key' => 'dashboard'],
]" />
