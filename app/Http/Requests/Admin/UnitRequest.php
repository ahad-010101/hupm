<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use App\Models\Unit;
use Illuminate\Validation\Rule;

/**
 * Unit create and update.  [FR-REG-01, AC-REG-01]
 */
class UnitRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-portfolio');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $property = $this->route('property');
        $unit = $this->route('unit');

        return [
            'unit_number' => [
                'required',
                'string',
                'max:20',
                // AC-REG-01: unique WITHIN the property, not globally — "1" is a
                // perfectly good unit number at twenty different addresses. The
                // database enforces this too (unique index on property_id +
                // unit_number); this exists so the admin gets a field-level
                // message instead of a 500.
                Rule::unique('units', 'unit_number')
                    ->where('property_id', $property->id)
                    ->ignore($unit?->id),
            ],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            // DECIMAL(3,1): 1, 1.5, 2 are all valid; 1.25 is not.
            'bathrooms' => ['nullable', 'numeric', 'min:0', 'max:99.9', 'decimal:0,1'],
            'status' => ['required', Rule::in([
                Unit::STATUS_OCCUPIED, Unit::STATUS_VACANT, Unit::STATUS_OFF_MARKET,
            ])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'unit_number.unique' => 'This property already has a unit with that number.',
            'bathrooms.decimal' => 'Bathrooms may have at most one decimal place, for example 1.5.',
        ];
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return ['unit_number' => 'unit number'];
    }

    /** @return array<string, mixed> */
    public function attributesForModel(): array
    {
        return $this->safe()->only(['unit_number', 'bedrooms', 'bathrooms', 'status']);
    }
}
