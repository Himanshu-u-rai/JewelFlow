<x-app-layout>
    <x-error-page :standalone="false" title="Opening Balance setup in progress" message="Live billing will unlock after the owner locks opening balances." icon="clock" tone="teal" :actions="[
        ['label' => 'Reload', 'reload' => true, 'kind' => 'secondary', 'icon' => 'rotate-cw'],
    ]" />
</x-app-layout>
