<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to modify or delete a record that the business
 * rules define as immutable.
 *
 * This is a programming error, not a user error — nothing in the UI should ever
 * offer the action. It is deliberately not caught and rendered as a validation
 * message: an attempt to edit a posted ledger entry means a service is doing
 * something it must not, and it should fail loudly in development rather than
 * degrade quietly in production.
 */
class ImmutableRecordException extends RuntimeException
{
    public static function attributes(string $model, array $attributes): self
    {
        return new self(sprintf(
            '%s is immutable; cannot modify [%s]. Corrections are made with a reversing entry, never an edit (BR-04).',
            class_basename($model),
            implode(', ', $attributes),
        ));
    }

    public static function deletion(string $model): self
    {
        return new self(sprintf(
            '%s records cannot be deleted. The audit trail depends on them (AC-LED-01, AC-AUD-02).',
            class_basename($model),
        ));
    }
}
