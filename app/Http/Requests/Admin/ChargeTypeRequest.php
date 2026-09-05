<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use App\Models\ChargeType;
use Illuminate\Validation\Rule;

/**
 * Charge type create and update.  [WP-41, FR-CHG-01]
 */
class ChargeTypeRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-portfolio');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->route('charge_type');

        return [
            // Unique, because the name is what an admin picks from a dropdown
            // under time pressure. Two "Garbage collection" rows differing by a
            // trailing space is how the wrong amount gets billed to 26 people.
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('charge_types', 'name')->ignore($type?->id),
            ],
            'category' => ['required', Rule::in(array_keys(ChargeType::CATEGORIES))],
            'default_description' => ['required', 'string', 'max:150'],
            'default_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'payer' => ['required', 'in:tenant,housing_authority'],
            'active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a charge type with that name.',
            'default_description.required' => 'Enter the wording residents will see on their ledger.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'default_description' => 'ledger wording',
            'default_amount' => 'default amount',
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForModel(): array
    {
        return $this->safe()->only([
            'name', 'category', 'default_description', 'default_amount', 'payer', 'active',
        ]);
    }
}
