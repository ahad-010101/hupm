<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Property create and update.  [FR-REG-01, AC-REG-02]
 *
 * Field lengths are the schema's, not arbitrary: rejecting a 200-character name
 * here produces a field-level message, while letting it through produces a
 * truncation or a database error the admin cannot act on.
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
            'street_address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            // CHAR(2). Defaults to GA but is not hard-coded — the portfolio is
            // Georgia today, and a state field that cannot change is a trap.
            'state' => ['required', 'string', 'size:2', 'alpha'],
            // AC-REG-02: exactly five digits. ZIP drives weather-alert
            // targeting (WP-21), so "close enough" means a tenant in a tornado
            // warning zone never hears about it. `digits:5` rather than
            // `numeric` — the latter accepts 30301.0 and negative numbers.
            'zip' => ['required', 'digits:5'],
            'county' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'zip.digits' => 'Enter a five-digit ZIP code. This determines which weather alerts residents receive.',
            'zip.required' => 'A ZIP code is required. It determines which weather alerts residents receive.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'state' => strtoupper((string) $this->input('state', 'GA')),
            'zip' => trim((string) $this->input('zip')),
        ]);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'street_address' => 'street address',
            'zip' => 'ZIP code',
        ];
    }

    /**
     * The validated payload, ready for the model.
     *
     * Controllers never pass `$request->all()` into a model (I-11); they take
     * this.
     *
     * @return array<string, mixed>
     */
    public function attributesForModel(): array
    {
        return $this->safe()->only([
            'name', 'street_address', 'city', 'state', 'zip', 'county', 'notes',
        ]);
    }
}
