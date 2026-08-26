<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;

/**
 * One section's content.  [WP-36, D-27]
 *
 * Deliberately thin. The per-field rules live in `SectionCatalogue`, which is
 * also what renders the form — one description, so the two cannot disagree
 * about what a section holds. This request checks only what is true of every
 * section regardless of type.
 */
class ContentSectionRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-portfolio');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
