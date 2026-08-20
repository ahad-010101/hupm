<?php

namespace App\Models;

use App\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Electronic Records Consent.  [FR-SIG-01, BR-25]
 *
 * Immutable. The whole point of this row is that somebody agreed to something
 * specific at a specific moment, and a row that can be updated afterwards
 * proves neither.
 *
 * `consent_text_sha256` is what makes it verifiable years later: the text lives
 * in version control (ElectronicRecordsConsent), and the hash here either still
 * matches it or does not.
 */
class ConsentRecord extends Model
{
    use Immutable;

    public const TYPE_ELECTRONIC_RECORDS = 'electronic_records';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['agreed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
