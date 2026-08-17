<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Illuminate\Validation\Validator;

/**
 * Split one Housing Authority cheque across tenants.  [GATE Q-2, R-9]
 *
 * The lines are the admin's reading of the authority's remittance advice. The
 * system checks the arithmetic and refuses anything that does not add up; it
 * does not decide who the money was for.
 */
class RecordRemittanceRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('record-offline-payment');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $today = app(BusinessCalendar::class)->today()->toDateString();

        return [
            'housing_authority_id' => ['required', 'exists:housing_authorities,id'],
            'total' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'received_on' => ['required', 'date', 'before_or_equal:'.$today],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'size:36'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.lease_id' => ['required', 'exists:leases,id'],
            'lines.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $split = Money::sum(array_map(
                fn (array $line) => Money::fromString((string) $line['amount']),
                $this->input('lines', []),
            ));

            $total = Money::fromString((string) $this->input('total'));

            if ($split->equals($total)) {
                return;
            }

            // Said as a difference, not as two numbers to subtract. The admin is
            // reconciling a cheque against a remittance advice and needs to know
            // how far off they are, not do the arithmetic again.
            $difference = $total->minus($split)->absolute();

            $validator->errors()->add('lines', $split->lessThan($total)
                ? "{$difference->format()} of this remittance is not yet assigned to a tenant."
                : "The split is over the remittance by {$difference->format()}.");
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'total.gt' => 'Enter the amount on the remittance.',
            'received_on.before_or_equal' => 'A remittance cannot be received in the future.',
            'lines.required' => 'Assign the remittance to at least one tenant.',
        ];
    }
}
