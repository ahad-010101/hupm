<?php

namespace App\Http\Requests\Portal;

use App\Domain\Maintenance\MaintenanceService;
use App\Http\Requests\BaseFormRequest;
use App\Models\MaintenanceRequest;
use Illuminate\Validation\Rule;

/**
 * A tenant reporting a repair.  [FR-MNT-01, AC-MNT-01/02]
 *
 * The emergency acknowledgement is validated server-side as well as in the
 * form. BR-23 exists because someone with a gas leak should be dialling 911
 * rather than filling in a web form, and a rule enforced only in JavaScript is
 * not enforced.
 */
class SubmitMaintenanceRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create-maintenance-request');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // AC-MNT-01. `accepted` rejects false, 0, "" and absent alike.
            'emergency_acknowledged' => ['accepted'],

            'category' => ['required', Rule::in(array_keys(MaintenanceRequest::CATEGORIES))],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'date_began' => ['nullable', 'date', 'before_or_equal:today'],
            'permission_to_enter' => ['required', 'boolean'],
            'preferred_contact' => ['required', Rule::in(['email', 'phone'])],
            'contact_phone' => ['nullable', 'string', 'max:30', 'required_if:preferred_contact,phone'],
            'pets_present' => ['boolean'],
            'best_access_time' => ['nullable', 'string', 'max:100'],
            'is_emergency' => ['boolean'],

            // AC-MNT-02: rejected inline, with the rest of the form preserved —
            // which Inertia does for us as long as this is a validation error
            // and not an exception.
            'photos' => ['nullable', 'array', 'max:'.MaintenanceService::MAX_FILES],
            'photos.*' => [
                'file',
                'mimetypes:'.implode(',', MaintenanceService::ALLOWED_MIME),
                'max:'.(MaintenanceService::MAX_BYTES / 1024),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'emergency_acknowledged.accepted' => 'Please confirm you have read what to do in an emergency.',
            'description.min' => 'Please describe the problem in a little more detail — at least a sentence.',
            'description.required' => 'Please tell us what is wrong.',
            'permission_to_enter.required' => 'Please say whether we may enter if you are out.',
            'contact_phone.required_if' => 'Please give us a phone number to reach you on.',
            'photos.max' => 'You can attach up to five photos or videos.',
            'photos.*.max' => 'Each photo or video has to be under 20 MB.',
            'photos.*.mimetypes' => 'Photos must be JPG, PNG or HEIC, and videos MP4.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Checkboxes that were never ticked do not arrive at all.
        $this->merge([
            'pets_present' => $this->boolean('pets_present'),
            'is_emergency' => $this->boolean('is_emergency'),
        ]);
    }
}
