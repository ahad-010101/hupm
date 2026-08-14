<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Tenant create and update.  [FR-REG-02, AC-AUTH-06, Q-4]
 */
class TenantRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-portfolio');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = $this->route('tenant');

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],

            // Nullable by design. Q-4 is unanswered and some of the 26 tenants
            // have no address; a tenant record must be able to exist without one
            // (AC-AUTH-06). Unique when present, because an address identifies a
            // login and two tenants cannot share one.
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('tenants', 'email')->ignore($tenant?->id)->whereNull('deleted_at'),
            ],

            'phone' => ['nullable', 'string', 'max:30'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:65535'],
            'status' => ['required', Rule::in(['active', 'former'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'Another tenant already uses that email address.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // An empty string from a form field is "no email", not an invalid one.
        // Storing '' would break the NULL check the no-login path depends on.
        $email = trim((string) $this->input('email'));

        $this->merge(['email' => $email === '' ? null : $email]);
    }

    /** @return array<string, mixed> */
    public function attributesForModel(): array
    {
        return $this->safe()->only([
            'first_name', 'last_name', 'email', 'phone',
            'emergency_contact_name', 'emergency_contact_phone', 'notes', 'status',
        ]);
    }
}
