<?php

return [
    // S3-16. The first expiry date that is enforced (YYYY-MM-DD). Points whose
    // expiry fell before it — the backlog left while expiry could not run —
    // are never expired automatically. Unset: loyalty:expire only reports.
    'expiry_active_from' => env('LOYALTY_EXPIRY_ACTIVE_FROM'),
];
