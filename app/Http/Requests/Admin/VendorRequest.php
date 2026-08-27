<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * A contractor's details.  [API-ADM-38, FR-MNT-03, NG-6]
 *
 * **Nothing here is a credential.** There is no vendor portal in v1 (NG-6) — the
 * plumber is telephoned, not invited — so the email address is somewhere to send
 * a job, never something to sign in with. That is why there is no password rule
 * on this request and no user row behind a vendor.
 *
 * Only the name is required. A contractor known to the office as "Mike, boiler
 * man" and a mobile number is a real record; refusing it until somebody invents
 * a trade and an email address is how a form stops being used.
 */
class VendorRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-portfolio');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:150',
                // Two contractors with the same name is almost always the same
                // contractor entered twice, and a duplicate is discovered on the
                // day somebody rings the wrong number.
                Rule::unique('vendors', 'name')
                    ->whereNull('deleted_at')
                    ->ignore($this->route('vendor')),
            ],
            'trade' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a contractor with that name.',
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForModel(): array
    {
        return $this->safe()->only(['name', 'trade', 'phone', 'email', 'notes', 'active']);
    }
}
