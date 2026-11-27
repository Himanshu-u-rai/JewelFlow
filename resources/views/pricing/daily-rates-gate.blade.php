{{-- The daily metal-rate gate. The app layout draws the rate form over this page; the
     page itself deliberately holds no shop data, so hiding the form shows nothing. --}}
<x-app-layout>
    <div class="p-6 text-sm text-slate-500" role="status">
        {{ __("Save today's metal rates to continue.") }}
    </div>
</x-app-layout>
