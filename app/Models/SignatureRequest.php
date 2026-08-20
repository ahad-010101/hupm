<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request for someone to sign a document.  [FR-SIG-02]
 *
 * The row is a state machine with an evidence trail beside it: every movement
 * writes a `signature_events` row, and those are what an ESIGN dispute is
 * actually argued over. The status here is a convenience for querying; the
 * events are the record.
 */
class SignatureRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VIEWED = 'viewed';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** The executed PDF, once there is one. */
    public function signedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'signed_document_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignatureEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_VIEWED], true);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_VIEWED]);
    }
}
