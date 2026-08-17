<?php

namespace App\Http\Requests\Admin;

use App\Domain\Payments\PaymentRecordingService;
use App\Http\Requests\BaseFormRequest;
use App\Models\Lease;
use App\Support\BusinessCalendar;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Record one offline payment.  [FR-PAY-05, API-ADM-15]
 *
 * Note what is **not** here: any check on the lease's delinquency state. An
 * admin-recorded payment works at all times, including Management Review
 * (BR-12, I-12, AC-PAY-15).
 */
class RecordPaymentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('record-offline-payment');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Resolved in the company timezone (D-07). Laravel's `today` would use
        // the app timezone, which is UTC — so between 7pm and midnight in
        // Georgia an admin would be told the current date is in the future.
        $today = app(BusinessCalendar::class)->today()->toDateString();

        return [
            'lease_id' => ['required', 'exists:leases,id'],
            'payer' => ['required', Rule::in(['tenant', 'housing_authority'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'received_on' => ['required', 'date', 'before_or_equal:'.$today],
            'method' => ['required', Rule::in(PaymentRecordingService::MANUAL_METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            // Generated when the form rendered, so a double submit or a browser
            // retry lands on the same payment rather than taking the money
            // twice (risk R-13).
            'idempotency_key' => ['required', 'string', 'size:36'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lease = Lease::find($this->input('lease_id'));

            if (! $lease) {
                return;
            }

            if ($lease->status !== Lease::STATUS_ACTIVE) {
                $validator->errors()->add(
                    'lease_id',
                    'That lease has ended. Record the payment against the lease it belongs to.',
                );
            }

            // A cheque with no number cannot be traced back to a bank statement,
            // which is the whole point of recording one.
            if (in_array($this->input('method'), ['cheque', 'money_order'], true)
                && trim((string) $this->input('reference')) === '') {
                $validator->errors()->add(
                    'reference',
                    'Enter the cheque or money order number so this can be matched to a bank statement later.',
                );
            }

            if ($this->input('payer') === 'housing_authority' && ! $lease->housing_authority_id) {
                $validator->errors()->add(
                    'payer',
                    'This lease has no housing authority, so there is no authority portion to pay.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.gt' => 'Enter the amount received. A payment of zero is not a payment.',
            'received_on.before_or_equal' => 'A payment cannot be received in the future. Enter the date it actually arrived.',
        ];
    }
}
