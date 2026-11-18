@php use App\Support\LegalInfo as L; @endphp
<x-legal-page title="Contact Us" intro="How to reach JewelFlows support and the grievance officer.">
    <p>For help with your account, billing, or a question before you sign up, contact us here.</p>

    <div class="legal-card">
        <dl>
            <dt>Business</dt><dd>{{ config('legal.company') }}</dd>
            <dt>Email</dt><dd><a href="mailto:{{ config('app.support_email') }}">{{ config('app.support_email') }}</a></dd>
            <dt>Phone</dt><dd>{!! L::field('phone', 'phone number') !!}</dd>
            <dt>Address</dt><dd>{!! L::field('address', 'registered address') !!}</dd>
            <dt>Support hours</dt><dd>{!! L::field('hours', 'support hours') !!}</dd>
            <dt>GSTIN</dt><dd>{!! L::field('gstin', 'GSTIN') !!}</dd>
        </dl>
    </div>

    <h2>Grievance officer</h2>
    <p>Under the Information Technology Act, 2000 and its rules, and the Digital Personal Data Protection Act, 2023, you can raise a complaint about the service or about how personal data is handled with our grievance officer.</p>
    <div class="legal-card">
        <dl>
            <dt>Name</dt><dd>{!! L::field('grievance_officer', 'grievance officer name') !!}</dd>
            <dt>Email</dt><dd>{!! L::field('grievance_email', 'grievance email') !!}</dd>
            <dt>Address</dt><dd>{!! L::field('address', 'registered address') !!}</dd>
        </dl>
    </div>
    <p>We acknowledge a complaint within 24 hours and resolve it within 15 days of receiving it.</p>
</x-legal-page>
