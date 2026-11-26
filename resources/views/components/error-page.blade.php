@props([
    'title',
    'message',
    'icon' => 'info',
    'tone' => 'amber',
    // Each action: ['label' => '', 'href' => '' | 'reload' => true, 'kind' => 'primary'|'secondary', 'icon' => '', 'key' => ''].
    'actions' => [],
    // true: a whole HTML page (used when the visitor may be signed out or assets may have failed).
    // false: just the card, for placing inside the signed-in app layout.
    'standalone' => true,
])
@php
    // One shared error / recovery surface (UI audit F16). Styles are inline on purpose: no Vite or session needed.
    $brand = 'JewelFlows';
    $tones = [
        'amber' => ['#b45309', '#fef3c7'],
        'red' => ['#b91c1c', '#fee2e2'],
        'slate' => ['#475569', '#f1f5f9'],
        'teal' => ['#0f766e', '#ccfbf1'],
    ];
    [$ink, $tint] = $tones[$tone] ?? $tones['amber'];
@endphp
@if ($standalone)
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ $brand }}</title>
</head>
<body style="margin:0;">
@endif
<style>
    .ep-page{min-height:{{ $standalone ? '100vh' : '70vh' }};display:flex;align-items:center;justify-content:center;padding:24px 16px;box-sizing:border-box;background:#f8f9fc;font-family:'Plus Jakarta Sans',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;color:#101828}
    .ep-card{width:100%;max-width:460px;box-sizing:border-box;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;box-shadow:0 1px 2px rgba(16,24,40,.05)}
    .ep-brand{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin:0 0 16px}
    .ep-head{display:flex;align-items:center;gap:12px;margin:0 0 10px}
    .ep-icon{flex:none;width:40px;height:40px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center}
    .ep-title{font-size:20px;font-weight:700;line-height:1.25;margin:0;overflow-wrap:anywhere}
    .ep-msg{font-size:15px;line-height:1.55;color:#475569;margin:0;overflow-wrap:anywhere}
    .ep-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px}
    .ep-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:40px;padding:0 16px;border-radius:8px;font-family:inherit;font-size:14px;font-weight:600;line-height:1;text-decoration:none;cursor:pointer;box-sizing:border-box;border:1px solid transparent}
    .ep-btn-primary{background:#b45309;border-color:#b45309;color:#fff}
    .ep-btn-primary:hover{background:#92400e;border-color:#92400e}
    .ep-btn-secondary{background:#fff;border-color:#cbd5e1;color:#334155}
    .ep-btn-secondary:hover{background:#f1f5f9}
    .ep-btn:focus-visible{outline:2px solid #b45309;outline-offset:2px}
    .ep-btn svg{width:16px;height:16px}
    @media (max-width:480px){.ep-card{padding:20px}.ep-btn{flex:1 1 100%}}
</style>
<div class="ep-page" data-error-surface>
    <main class="ep-card">
        <p class="ep-brand">{{ $brand }}</p>
        <div class="ep-head">
            <span class="ep-icon" style="background:{{ $tint }};"><x-icon :name="$icon" size="22" stroke="{{ $ink }}" /></span>
            <h1 class="ep-title">{{ $title }}</h1>
        </div>
        <p class="ep-msg">{{ $message }}</p>
        @if (count($actions))
            <div class="ep-actions">
                @foreach ($actions as $a)
                    @php $cls = 'ep-btn ep-btn-'.($a['kind'] ?? 'secondary'); @endphp
                    @if (! empty($a['reload']))
                        <button type="button" class="{{ $cls }}" data-error-action="reload" onclick="window.location.reload()">
                            @isset($a['icon'])<x-icon :name="$a['icon']" size="16" />@endisset{{ $a['label'] }}
                        </button>
                    @else
                        <a href="{{ $a['href'] }}" class="{{ $cls }}" data-error-action="{{ $a['key'] ?? 'link' }}">
                            @isset($a['icon'])<x-icon :name="$a['icon']" size="16" />@endisset{{ $a['label'] }}
                        </a>
                    @endif
                @endforeach
            </div>
        @endif
    </main>
</div>
@if ($standalone)
</body>
</html>
@endif
