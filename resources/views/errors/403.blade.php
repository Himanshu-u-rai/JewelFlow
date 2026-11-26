@php
    $home = route('dashboard');
    $prev = url()->previous() !== url()->current() ? url()->previous() : $home;
    // The refusal's own message when it has one (plan or turned-off reasons reach the owner only).
    $reason = trim((string) (($exception ?? null)?->getMessage() ?? ''));
    $reason = in_array($reason, ['', 'This action is unauthorized.', 'Forbidden', 'Unauthorized.'], true) ? null : $reason;
@endphp
<x-error-page title="Not allowed" :message="$reason ?? \App\Support\NotAvailable::MESSAGE" icon="lock" :actions="[
    ['label' => 'Back', 'href' => $prev, 'kind' => 'secondary', 'key' => 'back', 'icon' => 'arrow-left'],
    ['label' => 'Dashboard', 'href' => $home, 'kind' => 'primary', 'key' => 'dashboard'],
]" />
