<?php

namespace App\Http\Requests\Portal;

use App\Domain\Documents\DocumentVault;
use App\Http\Requests\BaseFormRequest;

/**
 * A resident sending a document in.  [WP-42, FR-DOC-01]
 *
 * There is **no tenant_id here on purpose**. It comes from the session in the
 * controller — a resident uploads against their own record or not at all, and a
 * field the browser could set would be the whole vulnerability (I-9, BR-20).
 *
 * There is no category either. Residents file everything as `correspondence`
 * and the title carries the meaning; the other fourteen categories are the
 * landlord's filing system, and "Rent increase notice" uploaded by a resident
 * is worse than an unsorted document because somebody may later trust it.
 */
class UploadDocumentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        // A tenant with no tenant record has nothing to upload against.
        return $this->user()?->tenant_id !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],

            // The vault checks MIME against the extension as well, and that is
            // the check that actually decides — a .php renamed .jpg passes here
            // and is refused there. This exists so the common mistake gets a
            // field-level message rather than an exception.
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,docx',
                'max:'.intdiv(DocumentVault::MAX_BYTES, 1024),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Give this a name, so the office knows what it is.',
            'file.required' => 'Choose a file to send.',
            'file.mimes' => 'Send a PDF, a photo (JPG or PNG) or a Word document.',
            'file.max' => 'That file is too large. The limit is '
                .intdiv(DocumentVault::MAX_BYTES, 1048576).'MB.',
        ];
    }
}
