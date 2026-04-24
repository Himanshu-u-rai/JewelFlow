<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Routes that should be excluded from CSRF verification.
     */
    protected $except = [
        'subscription/payment/webhook',
    ];
}
