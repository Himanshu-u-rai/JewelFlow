<?php

namespace App\Models\Concerns;

use App\Support\Historical\HistoricalLifecycle;
use LogicException;

/**
 * Application-layer half of published-record immutability.
 *
 * The PostgreSQL triggers are the authority — they hold even against raw
 * `DB::table()->update()`. This trait exists so the failure arrives as a clear
 * LogicException at the call site instead of a SQLSTATE from three layers down,
 * and so the allow-listed lifecycle columns are additionally gated on WHO is
 * changing them (see HistoricalLifecycle).
 *
 * The using model must implement `isPublishedRecord()` and
 * `publishedUpdatableColumns()`.
 */
trait ImmutableWhenPublished
{
    public static function bootImmutableWhenPublished(): void
    {
        static::updating(function ($model): void {
            if (! $model->isPublishedRecord()) {
                return;
            }

            $dirty       = array_keys($model->getDirty());
            $disallowed  = array_values(array_diff($dirty, $model::publishedUpdatableColumns()));

            if ($disallowed !== []) {
                throw new LogicException(sprintf(
                    '%s #%s is published and immutable; cannot modify: %s.',
                    class_basename($model),
                    (string) $model->getKey(),
                    implode(', ', $disallowed)
                ));
            }

            if (! HistoricalLifecycle::unlocked()) {
                throw new LogicException(sprintf(
                    '%s #%s is published; %s may only be changed through the historical '
                    . 'lifecycle service so the actor, timestamp and reason are recorded.',
                    class_basename($model),
                    (string) $model->getKey(),
                    implode(', ', $dirty)
                ));
            }
        });

        static::deleting(function ($model): void {
            if ($model->isPublishedRecord()) {
                throw new LogicException(sprintf(
                    '%s #%s is published and cannot be deleted. Use void or supersede.',
                    class_basename($model),
                    (string) $model->getKey()
                ));
            }
        });
    }
}
