<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tokenised bank account.  [FR-PAY-02, AC-PAY-06, INVARIANT I-5]
 *
 * There is no account number here and there is no column that could hold one.
 * Authorize.Net CIM holds the instrument; this row holds two opaque ids and a
 * string like "Checking ••••6789" that the gateway itself produced. An
 * architecture test asserts the table never grows a bank-data column.
 */
class PaymentProfile extends Model
{
    public const STATUS_ACTIVE = 'active';

    /** Set by an ACH notification of change: the account moved, retoken it. */
    public const STATUS_NEEDS_UPDATE = 'needs_update';

    public const STATUS_REMOVED = 'removed';

    protected $guarded = ['*'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
