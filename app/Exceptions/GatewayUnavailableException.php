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
    /**
     * What a tenant is told when we genuinely could not get an answer.
     *
     * "Nothing has been charged" is only true when no transaction was created,
     * which is the case for an outage. It is **not** true of every rejection —
     * see DUPLICATE — so it belongs in this message rather than being appended
     * to all of them by the controller.
     */
    public const OUTAGE = "We couldn't reach the payment provider. Nothing has been charged. Please try again shortly.";

    /**
     * A duplicate, which is a different situation and needs different advice.
     *
     * Authorize.Net refuses an identical transaction inside a short window. The
     * dangerous part is what it implies: the *first* one may well have gone
     * through. Telling this tenant "nothing has been charged, try again
     * shortly" is how somebody pays their rent twice — the outcome AC-PAY-02
     * and I-6 exist to prevent.
     */
    public const DUPLICATE = 'It looks like this payment has just been submitted. '
        .'Please wait a few minutes and check your account before trying again, '
        .'in case the first one has already gone through.';

    /** Configuration, not an outage. There is nothing for the tenant to retry. */
    public const NOT_AVAILABLE = 'Online payments are not available just now. '
        .'Please contact the office to pay by cheque or money order.';

    /** @param array<string, mixed> $context */
    public function __construct(
        string $message = self::OUTAGE,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
