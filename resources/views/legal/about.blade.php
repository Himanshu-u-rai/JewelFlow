@php use App\Support\LegalInfo as L; @endphp
<x-legal-page title="About JewelFlows" intro="JewelFlows is jewellery business software by Factual Technologies: Retail for jewellery shops and Dhiran for gold loan businesses.">
    <p>JewelFlows is business software for the jewellery trade, built and run by {{ config('legal.company') }}, {!! L::field('entity', 'type of business') !!}.</p>

    <h2>What we make</h2>
    <ul>
        <li><strong>JewelFlows Retail</strong> for jewellery shops: billing with GST, stock with tags and barcodes, daily metal rates, customers, old-gold exchange, schemes, EMIs, repairs, karigars and reports.</li>
        <li><strong>JewelFlows Dhiran</strong> for gold loan businesses: loans, pledged items, interest, repayments and closures.</li>
    </ul>
    <p>Retail also has an Android companion app for the counter. Each shop's records are kept separate from every other shop's.</p>

    <h2>Who it is for</h2>
    <p>JewelFlows is a business tool. It is used by shop owners and the staff they invite, each with the access the owner gives them. It is not a consumer shopping site: we do not sell jewellery or lend money ourselves.</p>

    <h2>Get in touch</h2>
    <p>Questions, demos or support: see <a href="{{ route('legal.contact') }}">Contact</a>.</p>
</x-legal-page>
