<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use App\Models\HousingAuthority;
use Illuminate\Validation\Rule;

/**
 * Housing authority create and update.  [FR-REG-01, GATE Q-2]
 */
class HousingAuthorityRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-portfolio');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            // [GATE Q-2] Unanswered, and one of the five blocking questions.
            // A lump_sum answer adds an allocation screen to WP-12, because
            // someone must decide which tenants one cheque covers (risk R-9).
            'remittance_type' => ['required', Rule::in([
                HousingAuthority::REMITTANCE_PER_TENANT,
                HousingAuthority::REMITTANCE_LUMP_SUM,
            ])],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForModel(): array
    {
        return $this->safe()->only([
            'name', 'contact_name', 'contact_email', 'contact_phone', 'remittance_type',
        ]);
    }
}
