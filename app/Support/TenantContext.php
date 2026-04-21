<?php

namespace App\Support;

class TenantContext
{
    private static ?int $shopId = null;

    public static function set(?int $shopId): void
    {
        self::$shopId = $shopId;
    }

    public static function get(): ?int
    {
        return self::$shopId;
    }

    public static function clear(): void
    {
        self::$shopId = null;
    }

    /**
     * Execute callback within an explicit tenant context.
     */
    public static function runFor(int $shopId, callable $callback): mixed
    {
        $previous = self::$shopId;
        self::$shopId = $shopId;

        try {
            return $callback();
        } finally {
            self::$shopId = $previous;
        }
    }
}
