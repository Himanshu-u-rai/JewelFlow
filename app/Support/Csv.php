<?php

namespace App\Support;

/**
 * The one place this app decides what a CSV escape character is.
 *
 * PHP 8.4 deprecates calling fputcsv()/fgetcsv() without $escape, because PHP 9
 * changes the default from "\\" to "" — so every bare call in this codebase was
 * both emitting a deprecation notice AND silently signed up for a behaviour
 * change on the next major.
 *
 * We take "" (RFC 4180) deliberately, not just to silence the notice:
 *
 *   - Writing: the legacy "\\" escape can emit a field that no spreadsheet
 *     parses back correctly — a value ending in a backslash swallows the
 *     closing quote. "" doubles quotes instead, which is what Excel, Sheets and
 *     every other consumer of our exports expect.
 *   - Reading: "" means a shop's `C:\path\` or `10\"` reads back literally
 *     rather than as a half-eaten escape sequence.
 *
 * HistoricalSourceFileReader already passed "" explicitly; this generalises
 * that same choice to the other twenty call sites instead of leaving the
 * codebase with two different ideas of what a CSV is.
 */
final class Csv
{
    public const DELIMITER = ',';
    public const ENCLOSURE = '"';
    public const ESCAPE    = '';

    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $row
     */
    public static function put($handle, array $row): void
    {
        fputcsv($handle, $row, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
    }

    /**
     * One row, or false at end of file — same contract as fgetcsv().
     *
     * @param  resource  $handle
     * @return array<int, string|null>|false
     */
    public static function get($handle): array|false
    {
        return fgetcsv($handle, 0, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
    }
}
