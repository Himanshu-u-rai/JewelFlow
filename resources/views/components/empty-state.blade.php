@props([
    'title' => 'Nothing here yet',
    'description' => null,
    'compact' => false,
])

<x-ui-state
    type="empty"
    :title="$title"
    :description="$description"
    :compact="$compact"
    {{ $attributes }}
/>
