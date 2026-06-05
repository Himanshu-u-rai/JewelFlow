{{--
    Diagonal low-opacity watermark (frozen §19). Rendered only when the meta
    carries a policy-derived watermark label (e.g. "DRAFT", "INTERNAL").
    Structural skeleton — visual polish per report happens in Phase 1.
--}}
@php($label = $meta->watermark)
<div class="report-watermark" aria-hidden="true">{{ $label }}</div>
