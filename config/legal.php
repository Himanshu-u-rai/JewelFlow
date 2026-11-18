<?php

/*
| Business details shown on the public policy pages (About, Contact, Terms, Privacy, Refund, Delivery).
| Fill every null before release: an empty value shows on the page as a highlighted "to be added" note,
| so nothing is invented. Razorpay's website review and India's IT Rules 2021 / DPDP Act 2023 expect a
| registered address, a contact phone and a named grievance officer.
*/

return [
    'company'           => 'Factual Technologies',
    'entity'            => null, // e.g. "a sole proprietorship" or "Factual Technologies Private Limited (CIN …)"
    'address'           => null, // registered business address
    'phone'             => null,
    'hours'             => null, // e.g. "Monday to Saturday, 10 AM to 7 PM IST"
    'gstin'             => null,
    'grievance_officer' => null, // name of the grievance officer
    'grievance_email'   => null, // falls back to the support email
    'jurisdiction'      => null, // city whose courts hear disputes, e.g. "Jaipur, Rajasthan"
    'effective_date'    => '16 November 2026',
];
