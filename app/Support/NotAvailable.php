<?php

namespace App\Support;

/**
 * What a refused user is told, on the web and the app: that it is not
 * available, never why (which permission, who decides). The shop owes its
 * staff no reason (owner's rule, 2026-10-30).
 */
final class NotAvailable
{
    public const MESSAGE = "This isn't available on your account.";

    /** A non-owner refused by the period lock: no cutoff date (owner's choice, 2026-11-19). */
    public const LOCKED_ENTRY = "This entry can't be saved.";

    /**
     * A plan or turned-off reason (read-only, suspended, lapsed) is the owner's
     * to hear; anyone else gets the plain message. No signed-in user (CLI,
     * queue) keeps the reason for the logs.
     */
    public static function unlessOwner(string $reason, ?\App\Models\User $user = null, string $plain = self::MESSAGE): string
    {
        $user ??= auth()->user();

        return $user === null || $user->isOwner() ? $reason : $plain;
    }

    /** The period-lock refusal: the owner hears the cutoff date, anyone else only that the entry can't be saved. */
    public static function financialLock(string $lockDate): string
    {
        return self::unlessOwner("Financial lock is active through {$lockDate}.", null, self::LOCKED_ENTRY);
    }
}
