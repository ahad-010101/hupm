<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;

/**
 * Record a chargeback by hand.  [WP-48]
 *
 * There is no gateway event behind this request, and there never will be: a
 * card chargeback is settled between the cardholder's issuer and the
 * merchant's acquirer, and Authorize.Net — a gateway, not the processor — is
 * not a party to it. The acquirer writes to the client; this is where that
 * letter becomes a ledger movement.
 *
 * So the reason is **required** and free text. It is the only provenance the
 * record will ever have, and "returned" with no explanation is not something
 * anybody can audit a year later when the resident disputes the balance.
 */
class RecordChargebackRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        // The same permission as recording an offline payment: both are an
        // admin asserting a movement of money the system did not observe.
        return $this->user()->can('record-offline-payment');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // What the acquirer said, in words. Shown on the resident's
            // returned-payment email, so it has to read as English.
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            // The acquirer's reason code where there is one — '4853',
            // 'R01'. Optional, because not every letter carries one.
            'code' => ['nullable', 'string', 'max:10'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the payment came back. The resident is told this, and it '
                .'is the only record of why the balance moved.',
        ];
    }
}
