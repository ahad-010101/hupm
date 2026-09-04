<?php

namespace App\Http\Requests\Portal;

use App\Http\Requests\BaseFormRequest;
use App\Models\Lease;
use App\Models\Payment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A tenant starting a payment.  [API-POR-05, FR-PAY-01]
 *
 * The amount rules that depend on the lease live in PartialPaymentPolicy, not
 * here: they are business rules, they are shown to the tenant with a reason,
 * and autopay (WP-16) will need the same answers without an HTTP request in
 * sight.
 */
class CreatePaymentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()?->can('make-payment')) {
            return false;
        }

        $lease = Lease::find($this->input('lease_id'));

        // Ownership violation is a 404, never a 403 — a 403 confirms the lease
        // exists (I-9). Wrong role is the 403, and that is the check above.
        abort_if($lease === null || $lease->tenant_id !== $this->user()->tenant_id, 404);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lease_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'idempotency_key' => ['required', 'string', 'size:36'],
            // [WP-39] Optional so an older client, or a test written before
            // cards existed, still means eCheck. Whether `card` is *allowed*
            // is a settings question and lives in PaymentIntentService, which
            // autopay would need to ask without an HTTP request in sight —
            // the same reasoning that keeps the amount rules in
            // PartialPaymentPolicy rather than here.
            'method' => ['sometimes', Rule::in([Payment::METHOD_ECHECK, Payment::METHOD_CARD])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lease = Lease::find($this->input('lease_id'));

            if ($lease && $lease->status !== Lease::STATUS_ACTIVE) {
                $validator->errors()->add(
                    'lease_id',
                    'This tenancy has ended. Please contact the office about any remaining balance.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.gt' => 'Enter an amount greater than zero.',
            'amount.decimal' => 'Enter an amount in dollars and cents, such as 487.00.',
        ];
    }
}
