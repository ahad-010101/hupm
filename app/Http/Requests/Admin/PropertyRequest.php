<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use App\Support\AddressCatalogue;
use Illuminate\Validation\Validator;

/**
 * Property create and update.  [FR-REG-01, AC-REG-02, D-19]
 *
 * Address validation is driven by the country rather than hard-coded to US
 * rules. The five-digit ZIP check AC-REG-02 requires still applies in full —
 * it is simply the United States' rule, applied because the country is the
 * United States, instead of applied to everyone.
 */
class PropertyRequest extends BaseFormRequest
{
    public function __construct(private readonly AddressCatalogue $addresses)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()->can('manage-portfolio');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'country_code' => ['required', 'string', 'size:2'],
            'street_address' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            // Length and membership are checked in withValidator, because both
            // depend on the country.
            'state' => ['nullable', 'string', 'max:64'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'county' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $country = strtoupper((string) $this->input('country_code'));

            if (! $this->addresses->isValidCountry($country)) {
                $validator->errors()->add('country_code', 'Choose a country from the list.');

                return; // every rule below depends on knowing the country
            }

            $format = $this->addresses->formatFor($country);
            $this->validateSubdivision($validator, $country, $format);
            $this->validatePostalCode($validator, $country, $format);
        });
    }

    /** @param array<string, mixed> $format */
    private function validateSubdivision(Validator $validator, string $country, array $format): void
    {
        $state = $this->input('state');
        $label = $format['administrative_area_label'];

        if (! $format['has_subdivisions']) {
            return; // e.g. the United Kingdom — free text, nothing to check
        }

        if (blank($state)) {
            $validator->errors()->add('state', "Choose a {$label} from the list.");

            return;
        }

        if (! $this->addresses->isValidSubdivision($country, $state)) {
            $validator->errors()->add('state', "That {$label} does not belong to the selected country.");
        }
    }

    /** @param array<string, mixed> $format */
    private function validatePostalCode(Validator $validator, string $country, array $format): void
    {
        $postalCode = trim((string) $this->input('postal_code'));
        $label = $format['postal_code_label'];

        if ($postalCode === '') {
            if ($format['postal_code_required']) {
                // AC-REG-02's reasoning generalised: the code is what the
                // weather job groups by, so it is never merely cosmetic.
                $validator->errors()->add(
                    'postal_code',
                    "A {$label} code is required. In the United States this determines which weather alerts residents receive.",
                );
            }

            return;
        }

        $pattern = $format['postal_code_pattern'];

        // AC-REG-02 for the US resolves to exactly five digits, from the
        // country's own pattern rather than a rule written twice.
        if ($pattern && ! preg_match('/^(?:'.$pattern.')$/', $postalCode)) {
            $validator->errors()->add(
                'postal_code',
                $country === 'US'
                    ? 'Enter a five-digit ZIP code. This determines which weather alerts residents receive.'
                    : "That is not a valid {$label} code for the selected country.",
            );
        }
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country_code' => strtoupper(trim((string) $this->input('country_code', 'US'))),
            'state' => strtoupper(trim((string) $this->input('state'))) ?: null,
            'postal_code' => trim((string) $this->input('postal_code')) ?: null,
        ]);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'country_code' => 'country',
            'street_address' => 'address line 1',
            'address_line_2' => 'address line 2',
            'state' => 'state or province',
            'postal_code' => 'postal code',
        ];
    }

    /**
     * The validated payload, ready for the model. Controllers never pass
     * `$request->all()` into a model (I-11); they take this.
     *
     * @return array<string, mixed>
     */
    public function attributesForModel(): array
    {
        return $this->safe()->only([
            'name', 'country_code', 'street_address', 'address_line_2',
            'city', 'state', 'postal_code', 'county', 'notes',
        ]);
    }
}
