<?php

namespace App\Support\Historical;

use Closure;

/**
 * Runtime gate for the few fields a PUBLISHED historical record may still change
 * (status, void audit, supersede link, customer link).
 *
 * The database trigger decides WHICH columns may move after publication; this
 * decides WHO may move them. Without it, any controller could flip a published
 * document to `void` by calling `$doc->update(['status' => 'void'])` and skip the
 * reason/actor capture entirely. Only code running inside
 * HistoricalDocumentLifecycleService opens this gate.
 */
final class HistoricalLifecycle
{
    private static bool $unlocked = false;

    public static function unlocked(): bool
    {
        return self::$unlocked;
    }

    /**
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback): mixed
    {
        $previous = self::$unlocked;
        self::$unlocked = true;

        try {
            return $callback();
        } finally {
            self::$unlocked = $previous;
        }
    }
}
