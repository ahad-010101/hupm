<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A message sent to residents.  [FR-NTF-02]
 *
 * **A sent notice cannot be deleted** (AC-NTF-06). Not hidden behind a policy,
 * not soft-deleted — the model refuses. A notice is the evidence that somebody
 * was told something, and "we sent it" is a claim that only means anything if
 * the record could not have been tidied away afterwards.
 *
 * The body is admin-authored HTML and the only place this product renders
 * `dangerouslySetInnerHTML`. It is sanitised server-side against an allowlist
 * before it is ever stored (TDD §6.2), so what is in this column is already
 * safe — the rendering side is not where the guarantee lives.
 */
class Notice extends Model
{
    public const AUDIENCE_TENANT = 'tenant';

    public const AUDIENCE_PROPERTY = 'property';

    public const AUDIENCE_SELECTED = 'selected';

    public const AUDIENCE_ALL = 'all';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'audience_ref' => 'array',
            'recipient_count' => 'integer',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public static function booted(): void
    {
        static::deleting(function (Notice $notice): void {
            // AC-NTF-06. Retained permanently (FR-NTF-02).
            throw new RuntimeException(
                'A notice cannot be deleted. It is the record that a resident was told something.'
            );
        });
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NoticeRecipient::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(NoticeAttachment::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->whereNotNull('sent_at');
    }
}
