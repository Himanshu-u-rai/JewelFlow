@php $brand = \App\Support\Realm::current() === \App\Support\Realm::DHIRAN ? 'Dhiran' : 'JewelFlows'; @endphp
<x-error-page title="We'll be right back" :message="$brand.' is undergoing scheduled maintenance. We expect to be back online shortly. Thank you for your patience.'" icon="wrench" :actions="[
    ['label' => 'Reload', 'reload' => true, 'kind' => 'secondary', 'icon' => 'rotate-cw'],
]" />
