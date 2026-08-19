<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The payment gateway could not be reached, or answered with an error.
 *
 * Distinct from a validation failure on purpose: FR-PAY-01 F1 says a gateway
 * problem leaves the payment `failed`, the ledger entry `void` and **the
 * balance untouched**, with a retry offered. That is a different outcome from
 * "the tenant asked for something we will not do", and collapsing the two
 * would tell a tenant their payment was rejected when in fact nobody was home.
 *
 * The message is safe to show a tenant. Detail for the log goes in $context,
 * which must never carry bank data (I-5).
 */
class GatewayUnavailableException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message = 'We could not reach the payment provider.',
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
