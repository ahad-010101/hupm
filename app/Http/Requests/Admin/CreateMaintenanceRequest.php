<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An admin raising a repair.  [WP-46, FR-MNT-01]
 *
 * The resident's own form (`Portal\SubmitMaintenanceRequest`) asks a person
 * about their own home. This asks the office about somebody else's, so the
 * questions differ:
 *
 *  - **Which lease**, because there is no session to take it from.
 *  - **Internal**, which the resident's form has no concept of.
 *  - **A contractor and a charge**, both optional, both things an admin often
 *    already knows when the telephone is still in their hand.
 *
 * `permission_to_enter` and `preferred_contact` are questions about the
 * resident that an admin frequently cannot answer. They default rather than
 * block: a form that refuses to record a burst pipe until somebody guesses
 * whether there is a dog is a form the office stops using.
 */
class CreateMaintenanceRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-portfolio');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lease_id' => ['required', 'integer', 'exists:leases,id'],
            'category' => ['required', Rule::in(array_keys(MaintenanceRequest::CATEGORIES))],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'date_began' => ['nullable', 'date'],

            'is_emergency' => ['required', 'boolean'],
            'internal' => ['required', 'boolean'],

            // Defaulted in prepareForValidation, so the office is never blocked
            // on a detail about somebody else's household.
            'permission_to_enter' => ['required', 'boolean'],
            'preferred_contact' => ['required', Rule::in(['email', 'phone'])],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'pets_present' => ['required', 'boolean'],
            'best_access_time' => ['nullable', 'string', 'max:100'],

            // Optional, and only offered for contractors currently on the list.
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('active', true)],

            // Billing at creation. `required_if` rather than `nullable`: a
            // ticked box with no amount is somebody who meant to charge and
            // did not, which is worse than either answer.
            'bill_resident' => ['required', 'boolean'],
            'billed_amount' => [
                'required_if:bill_resident,true', 'nullable',
                'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2',
            ],
            'billed_reason' => ['required_if:bill_resident,true', 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('lease_id')) {
                return;
            }

            $lease = $this->lease();

            // A ticket against an ended tenancy has no unit to attend and no
            // resident to tell. The database would allow it; nothing else
            // should.
            if ($lease && $lease->status !== Lease::STATUS_ACTIVE) {
                $validator->errors()->add(
                    'lease_id',
                    'That tenancy has ended. A repair can only be raised against an active lease.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_emergency' => $this->boolean('is_emergency'),
            'internal' => $this->boolean('internal'),
            'pets_present' => $this->boolean('pets_present'),
            'bill_resident' => $this->boolean('bill_resident'),
            // Not "yes we may enter" by default. Assuming permission nobody
            // gave is the one default here that could put somebody in a
            // resident's home uninvited.
            'permission_to_enter' => $this->boolean('permission_to_enter'),
            'preferred_contact' => $this->input('preferred_contact') ?: 'phone',
        ]);
    }

    public function lease(): ?Lease
    {
        return Lease::with('tenant')->find($this->integer('lease_id'));
    }

    /** The attributes MaintenanceService::submit() expects. */
    public function ticketAttributes(): array
    {
        return $this->safe()->only([
            'category', 'description', 'date_began', 'permission_to_enter',
            'preferred_contact', 'contact_phone', 'pets_present',
            'best_access_time', 'is_emergency',
        ]);
    }

    public function billedAmount(): Money
    {
        return $this->money('billed_amount');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'description.min' => 'Describe what is wrong in a little more detail.',
            'billed_amount.required_if' => 'Enter the amount to charge the resident, or untick the box.',
            'billed_reason.required_if' => 'Say why the resident is being charged. It becomes part of the permanent record.',
        ];
    }
}
