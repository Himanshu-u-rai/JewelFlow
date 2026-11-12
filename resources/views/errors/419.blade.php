@php
    $home = \App\Support\Realm::current() === \App\Support\Realm::DHIRAN ? url('/dhiran') : route('dashboard');
@endphp
<x-error-page title="Page expired" message="This page was open too long. Reload it and try again; your data is safe." icon="rotate-cw" :actions="[
    ['label' => 'Reload', 'reload' => true, 'kind' => 'primary', 'icon' => 'rotate-cw'],
    ['label' => 'Dashboard', 'href' => $home, 'kind' => 'secondary', 'key' => 'dashboard'],
]" />
