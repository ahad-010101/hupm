<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use App\Support\Counties;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Property create and update.  [FR-REG-01, AC-REG-02, D-19]
 *
 * Country, state and city are chosen from the seeded reference tables, so each
 * one is checked against the level above it: a state must belong to the chosen
 * country and a city to the chosen state. Without that the dropdowns look
 * cascading but a crafted POST can still store "Ontario, United States".
 *
 * Names are stored rather than reference ids. A property's address should not
 * change or break if the reference data is ever reseeded, and "Atlanta" is
 * legible in an export where `city_id = 41023` is not.
 */
class PropertyRequest extends BaseFormRequest
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
            'country_code' => ['required', 'string', 'size:2', Rule::exists('countries', 'iso2')],
            'state' => ['required', 'string', 'max:64'],
            'city' => ['required', 'string', 'max:100'],
            'street_address' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:16'],
            'county' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return; // the checks below assume the basics passed
            }

            $this->assertStateBelongsToCountry($validator);
            $this->assertCityBelongsToState($validator);
            $this->assertCountyBelongsToState($validator);
            $this->assertPostalCode($validator);
        });
    }

    /**
     * The same cascade rule as city, applied to county.  [FR-NTF-03]
     *
     * Only where a list exists to check against — outside Georgia the field is
     * free text and there is nothing to verify, so nothing is rejected. That
     * matches the postal-code reasoning: refusing an address we cannot check is
     * worse than accepting an odd one.
     *
     * Where a list does exist the check is exact, because the county is matched
     * against the weather service's own spelling. "Dekalb" would store happily
     * and then never match an alert again.
     */
    private function assertCountyBelongsToState(Validator $validator): void
    {
        if (! Counties::valid($this->input('state'), $this->input('county'))) {
            $validator->errors()->add('county', 'Choose a county from the list.');
        }
    }

    private function assertStateBelongsToCountry(Validator $validator): void
    {
        $exists = DB::table('states')
            ->join('countries', 'countries.id', '=', 'states.country_id')
            ->where('countries.iso2', $this->input('country_code'))
            ->where('states.name', $this->input('state'))
            ->exists();

        if (! $exists) {
            $validator->errors()->add('state', 'Choose a state or province from the list.');
        }
    }

    private function assertCityBelongsToState(Validator $validator): void
    {
        $exists = DB::table('cities')
            ->join('states', 'states.id', '=', 'cities.state_id')
            ->join('countries', 'countries.id', '=', 'states.country_id')
            ->where('countries.iso2', $this->input('country_code'))
            ->where('states.name', $this->input('state'))
            ->where('cities.name', $this->input('city'))
            ->exists();

        if (! $exists) {
            $validator->errors()->add('city', 'Choose a city from the list.');
        }
    }

    /**
     * AC-REG-02.
     *
     * Five digits in the United States, where ZIP drives weather-alert
     * targeting. Elsewhere the format varies far too much to guess at, so the
     * field is required but its shape is not policed — a wrong guess would
     * reject valid addresses, which is worse than accepting an odd one.
     */
    private function assertPostalCode(Validator $validator): void
    {
        if ($this->input('country_code') !== 'US') {
            return;
        }

        if (! preg_match('/^\d{5}$/', (string) $this->input('postal_code'))) {
            $validator->errors()->add(
                'postal_code',
                'Enter a five-digit ZIP code. This determines which weather alerts residents receive.',
            );
        }
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country_code' => strtoupper(trim((string) $this->input('country_code', 'US'))),
            'postal_code' => trim((string) $this->input('postal_code')),
        ]);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'country_code' => 'country',
            'street_address' => 'address',
            'address_line_2' => 'address line 2',
            'state' => 'state or province',
            'postal_code' => 'postal code',
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForModel(): array
    {
        return $this->safe()->only([
            'name', 'country_code', 'street_address', 'address_line_2',
            'city', 'state', 'postal_code', 'county', 'notes',
        ]);
    }
}
