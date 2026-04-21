@extends('layouts.catalog')

@section('title', $page->title . ' — ' . $shop->name)

@section('head')
<style>
    .cms-page {
        padding: 56px 0 72px;
    }

    .cms-page-inner {
        max-width: 720px;
        margin: 0 auto;
    }

    .cms-page h1 {
        font-size: clamp(28px, 4vw, 40px);
        margin-bottom: 8px;
        text-align: center;
    }

    .cms-page-divider {
        width: 48px;
        height: 2px;
        background: var(--accent);
        margin: 20px auto 40px;
        border-radius: 2px;
    }

    .cms-page-content {
        font-size: 16px;
        line-height: 1.8;
        color: var(--text-secondary);
    }

    .cms-page-content h2 {
        font-size: 24px;
        color: var(--text-primary);
        margin: 36px 0 12px;
    }

    .cms-page-content h3 {
        font-size: 20px;
        color: var(--text-primary);
        margin: 28px 0 10px;
    }

    .cms-page-content p {
        margin-bottom: 16px;
    }

    .cms-page-content ul,
    .cms-page-content ol {
        margin-bottom: 16px;
        padding-left: 24px;
    }

    .cms-page-content li {
        margin-bottom: 6px;
    }

    .cms-page-content a {
        color: var(--accent);
        text-decoration: underline;
    }

    .cms-page-content strong {
        color: var(--text-primary);
        font-weight: 600;
    }

    .cms-page-content blockquote {
        border-left: 3px solid var(--accent);
        padding: 12px 20px;
        margin: 20px 0;
        background: var(--bg-secondary);
        border-radius: 0 8px 8px 0;
        font-style: italic;
    }
</style>
@endsection

@section('content')
    <section class="cms-page">
        <div class="cat-container">
            <div class="cms-page-inner">
                <h1>{{ $page->title }}</h1>
                <div class="cms-page-divider"></div>
                <div class="cms-page-content">
                    {!! nl2br(e($page->content)) !!}
                </div>
            </div>
        </div>
    </section>
@endsection
