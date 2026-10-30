<?php

namespace App\Support;

/**
 * The largest rupee amount a form accepts. Money columns are numeric(18,2)
 * (below 10^16); an amount past that fails inside PostgreSQL as a server error
 * instead of a validation answer, so every money field caps here first.
 */
final class MoneyLimit
{
    public const MAX = '9999999999999.99';

    /** "max:…" for a validation rule list. */
    public const RULE = 'max:' . self::MAX;
}
