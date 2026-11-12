<x-error-page title="Session expired" message="Your session has timed out. Sign in again to continue." icon="log-in" :actions="[
    ['label' => 'Sign in', 'href' => route('login'), 'kind' => 'primary', 'key' => 'signin', 'icon' => 'log-in'],
]" />
