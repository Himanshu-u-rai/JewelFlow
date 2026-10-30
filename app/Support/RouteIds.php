<?php

namespace App\Support;

/**
 * A record id in a link: a positive whole number that fits a bigint. Anything
 * else (text, 20 digits, a minus sign) matches no route and is a 404, instead
 * of reaching PostgreSQL as an impossible id and failing as a server error.
 * Registered for every parameter below in AppServiceProvider; a route that
 * adds its own whereNumber() would override it, so none do.
 */
final class RouteIds
{
    public const PATTERN = '[1-9][0-9]{0,17}';

    /** Route parameters that bind a model by its numeric id. */
    public const NAMES = [
        'customer', 'invoice', 'item', 'quickBill', 'repair', 'returnOrder', 'vendor', 'kycDocument', 'session',
        'deviceSession', 'profile', 'plan', 'jobOrder', 'karigar', 'karigarInvoice', 'lot', 'metalLot', 'purchase',
        'rule', 'scheme', 'enrollment', 'exchange', 'loan', 'payment', 'staff', 'method', 'gstCategory', 'user',
        'shop', 'role', 'announcement', 'platformAdmin', 'editionRequest', 'platformEditionRequest', 'attachment',
        'document', 'batch', 'onboarding', 'entry', 'line', 'disposition', 'import', 'export', 'preset', 'page',
        'sub_category',
    ];
}
