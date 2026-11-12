{{-- Dhiran live search (owner 2026-11-12): results follow the typing without reloading the page or taking the cursor
     out of the box. Typing pauses 350 ms, then only the page's results frame (turbo-frame#dh-results) is refreshed with
     the form's current filters; the URL follows, so refresh, back and Export keep the search. Without that frame or
     Turbo, the form is submitted normally. The site-wide 400 ms auto-submit (app.js) leaves this box alone. --}}
@props(['value' => '', 'placeholder' => 'Search', 'label' => 'Search'])
<div class="dh-fbar-search" data-dhiran-search
     x-data="{
         q: @js((string) $value),
         go() {
             const form = this.$el.closest('form');
             if (!form) return;
             if (!document.getElementById('dh-results') || !window.Turbo) return form.requestSubmit();
             const url = new URL(form.action, location.href);
             for (const [key, val] of new FormData(form)) if (val !== '' && key !== 'page') url.searchParams.append(key, val);
             const perPage = new URL(location.href).searchParams.get('per_page');
             if (perPage) url.searchParams.set('per_page', perPage);
             window.Turbo.visit(url.toString(), { frame: 'dh-results', action: 'replace' });
         },
     }">
    <x-icon name="search" size="16" />
    <input type="search" name="search" x-model="q" value="{{ $value }}" placeholder="{{ $placeholder }}" aria-label="{{ $label }}"
           autocomplete="off" enterkeyhint="search" @input.debounce.350ms="go()" @keydown.enter.prevent="go()">
    <button type="button" class="dh-fbar-clear" x-show="q" x-cloak @click="q = ''; $nextTick(() => { go(); $el.parentElement.querySelector('input').focus() })" aria-label="Clear search">
        <x-icon name="x" size="14" />
    </button>
</div>
