<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;

/**
 * Page settings.  [WP-36, D-27]
 *
 * The slug is absent on purpose. It binds to a route in `routes/public.php`,
 * and an editable one would let somebody point `/about` at a page the router
 * has never heard of.
 */
class ContentPageRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        // The same ability every other admin write uses. A capability of its own
        // would be a 25th row in the TDD §5.3 matrix, which is a spec amendment
        // rather than something to add in passing.
        return $this->user()->can('manage-portfolio');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'nav_label' => ['nullable', 'string', 'max:40'],
            // 160 is where search engines truncate. Enforced rather than hinted,
            // because a description cut mid-word reads worse than a short one.
            'meta_description' => ['nullable', 'string', 'max:160'],
            'is_published' => ['required', 'boolean'],
            'show_in_nav' => ['required', 'boolean'],
            'nav_position' => ['required', 'integer', 'min:0', 'max:999'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForModel(): array
    {
        return $this->safe()->only([
            'title', 'nav_label', 'meta_description', 'is_published', 'show_in_nav', 'nav_position',
        ]);
    }
}
