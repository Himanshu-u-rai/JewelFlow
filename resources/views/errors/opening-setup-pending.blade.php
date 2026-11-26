@php $brand = 'JewelFlows'; @endphp
<x-app-layout>
    <x-error-page :standalone="false" title="Shop setup pending" :message="'The owner must complete Opening Balance Setup or choose Start Fresh before staff can use '.$brand.'.'" icon="triangle-alert" />
</x-app-layout>
