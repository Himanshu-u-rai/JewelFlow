{{-- Every uploaded file as a small titled tile; photos open large in one viewer (owner 2026-11-08). --}}
@props(['attachments', 'empty' => 'No documents uploaded yet.'])
@php
    $titles = [
        'item_photo' => 'Pledged item photo', 'id_proof_front' => 'ID proof (front)', 'id_proof_back' => 'ID proof (back)',
        'address_proof' => 'Address proof', 'borrower_photo' => 'Borrower photo', 'pledge_agreement' => 'Pledge agreement',
        'signed_terms' => 'Signed terms', 'valuation_proof' => 'Valuation proof', 'loan_document' => 'Other loan document',
    ];
@endphp
@if($attachments->isEmpty())
    <p class="text-sm text-slate-400 mb-4">{{ $empty }}</p>
@else
    @php $belongsTo = \App\Models\Dhiran\DhiranAttachment::ownerLabels($attachments); @endphp
    <div data-evidence-gallery x-data="{ src: '', title: '' }" class="mb-4">
        <ul class="dh-evidence-grid">
            @foreach($attachments as $att)
                @php
                    $url = route('dhiran.attachments.show', $att);
                    $title = $titles[$att->document_type] ?? ucwords(str_replace('_', ' ', $att->document_type));
                    $owner = $belongsTo[$att->id] ?? '';
                @endphp
                <li class="dh-evidence-tile">
                    @if($att->isImage())
                        <button type="button" class="dh-evidence-thumb" data-evidence-open data-src="{{ $url }}" data-title="{{ $title }}{{ $owner ? ' · '.$owner : '' }}"
                                @click="src = $el.dataset.src; title = $el.dataset.title; $refs.viewer.showModal()" aria-label="View {{ $title }} large">
                            <img src="{{ route('dhiran.attachments.show', [$att, 'thumb' => 1]) }}" alt="{{ $title }}" loading="lazy">
                        </button>
                    @else
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="dh-evidence-thumb dh-evidence-file" aria-label="Open {{ $title }}">
                            <x-icon name="file-text" size="28" />
                            <span>{{ strtoupper(pathinfo($att->original_name, PATHINFO_EXTENSION) ?: 'File') }}</span>
                        </a>
                    @endif
                    <span class="dh-evidence-title">{{ $title }}</span>
                    @if($owner)<span class="dh-evidence-owner">{{ $owner }}</span>@endif
                </li>
            @endforeach
        </ul>

        <dialog x-ref="viewer" class="dh-dialog dh-evidence-viewer" aria-label="Photo" @click.self="$refs.viewer.close()">
            <div class="dh-evidence-viewer-head">
                <h3 class="text-base font-semibold text-slate-900" x-text="title"></h3>
                <div class="flex items-center gap-2">
                    <a :href="src" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Open in new tab</a>
                    <button type="button" class="btn btn-dark btn-sm" @click="$refs.viewer.close()">Close</button>
                </div>
            </div>
            <div class="dh-evidence-viewer-body"><img :src="src" :alt="title"></div>
        </dialog>
    </div>
@endif
