<?php

namespace App\Concerns;

use App\Exceptions\ImmutableRecordException;

/**
 * Makes a model append-only: no deletes, and no updates outside an explicit
 * allowlist.  [INVARIANT I-3, DEVIATION D-04]
 *
 * The specs signalled immutability by omitting `updated_at` from
 * `ledger_entries` and `documents`. That does not work, because both tables
 * must still transition a status column — a posted payment becomes cleared,
 * then possibly returned; a document becomes signed. A table cannot be both
 * unwritable and required to change.
 *
 * So the column exists and the rule moves here, where it can be precise: name
 * the handful of attributes that may legitimately change, reject everything
 * else, and reject deletion outright. That is strictly stronger than the
 * original design, which could only have prevented writes by making them
 * impossible to express — and it leaves a place to audit each transition.
 *
 * A model using this trait declares what may change:
 *
 *     protected function mutableAttributes(): array
 *     {
 *         return ['status'];
 *     }
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(function ($model): void {
            $allowed = array_merge($model->mutableAttributes(), ['updated_at']);
            $illegal = array_diff(array_keys($model->getDirty()), $allowed);

            if ($illegal !== []) {
                throw ImmutableRecordException::attributes($model::class, $illegal);
            }
        });

        static::deleting(function ($model): void {
            throw ImmutableRecordException::deletion($model::class);
        });
    }

    /**
     * Attributes that may change after the record exists. Empty means fully
     * immutable.
     *
     * @return list<string>
     */
    protected function mutableAttributes(): array
    {
        return [];
    }
}
