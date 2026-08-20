<?php

namespace App\Models;

use App\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One link in the evidence chain.  [FR-SIG-02, TDD §9.3]
 *
 * Append-only and fully immutable — not even a status may change, unlike the
 * ledger. Under ESIGN/UETA the value of this row is that it could not have been
 * written afterwards to suit somebody, so there is no legitimate reason to edit
 * one and every reason to make it impossible.
 *
 * `occurred_at` is TIMESTAMP(3): a signature and the scroll that preceded it can
 * land in the same second, and an evidence trail that cannot order its own
 * events is not evidence (the same precision problem as D-18).
 */
class SignatureEvent extends Model
{
    use Immutable;

    public const CREATED = 'created';

    public const SENT = 'sent';

    public const OPENED = 'opened';

    public const SCROLLED_COMPLETE = 'scrolled_complete';

    public const SIGNED = 'signed';

    public const DECLINED = 'declined';

    protected $guarded = ['*'];

    public $timestamps = false;

    /** Milliseconds, or two events in one second cannot be ordered. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }
}
