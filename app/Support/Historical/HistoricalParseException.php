<?php

namespace App\Support\Historical;

use RuntimeException;

/**
 * A source value could not be read under the profile the operator confirmed.
 *
 * Always carries a message an operator can act on ("what to change"), never a
 * parser-internal one. These become blocking preview errors, so the text ends up
 * in front of a jeweller, not a developer.
 */
class HistoricalParseException extends RuntimeException
{
}
