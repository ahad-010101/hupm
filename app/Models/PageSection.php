<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One block of a public page.  [WP-36, D-27]
 *
 * `payload` is shaped by the section's type. Read it defensively — every Blade
 * partial uses `?? ''` — because a type's fields can gain a key long after the
 * rows that predate it were written.
 */
class PageSection extends Model
{
    protected $fillable = ['content_page_id', 'type', 'position', 'is_enabled', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_enabled' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class, 'content_page_id');
    }

    /** One field, or a fallback. Never assume a key exists. */
    public function field(string $key, mixed $default = ''): mixed
    {
        return $this->payload[$key] ?? $default;
    }
}
