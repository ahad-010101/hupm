<?php

namespace App\Models;

use App\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A file in the document vault.
 *
 * Mutable until signed, frozen afterwards (FR-SIG-02). Once a tenant has put
 * their name to a document, its bytes, its hash and its metadata are evidence:
 * `signature_events.document_sha256` records what was on screen at the moment
 * of signing (BR-26), and that reference is worthless if the row can still
 * change. Replacement is a new version through `supersedes_document_id`.
 */
class Document extends Model
{
    use Immutable;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_signed' => 'boolean',
            'visible_to_tenant' => 'boolean',
            'size_bytes' => 'integer',
            'version' => 'integer',
        ];
    }

    protected function mutableAttributes(): array
    {
        // getOriginal, not the current attribute: the transition into signed is
        // itself a permitted write, but every write after it is not.
        if ((bool) $this->getOriginal('is_signed') === true) {
            return [];
        }

        return ['is_signed', 'visible_to_tenant', 'title', 'category', 'lease_id'];
    }
}
