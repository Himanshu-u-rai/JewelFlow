@php
    $home = route('dashboard');
@endphp
<x-error-page title="Too many requests" message="Please wait a moment, then try again." icon="clock" :actions="[
    ['label' => 'Try again', 'reload' => true, 'kind' => 'primary'],
    ['label' => 'Dashboard', 'href' => $home, 'kind' => 'secondary', 'key' => 'dashboard'],
]" />
