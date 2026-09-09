<?php

namespace App\Support\Historical;

use RuntimeException;

/**
 * "Save & publish" refused for a stated business reason — a blocking finding, an
 * unresolved duplicate, an unacknowledged warning set, a HIGH opening-balance
 * overlap. It is NOT an error condition in the software sense.
 *
 * It exists so the controller can tell the two failure modes apart:
 *
 *   this exception  -> the operator gets the reason and their form back, and the
 *                      surrounding transaction rolls the attempt away to nothing.
 *   anything else   -> a genuine fault; it also rolls back, but it is not a
 *                      sentence we are willing to put in front of a jeweller as
 *                      an explanation of their own data.
 *
 * Either way nothing is persisted, which is the point: a refused direct publish
 * must not leave a stray draft behind that the operator never asked to keep.
 */
class HistoricalManualPublishRejected extends RuntimeException
{
}
