<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo, video or invoice on a ticket.
 *
 * Stored outside the web root and served only through an authenticated
 * controller — a tenant's photograph of their own bathroom is not a public
 * URL waiting to be guessed.
 *
 * `kind = invoice` is never visible to a tenant (AC-MNT-09). What the repair
 * cost is between the landlord and the contractor.
 */
class MaintenanceAttachment extends Model
{
    public const KIND_TENANT_MEDIA = 'tenant_media';

    public const KIND_VENDOR_MEDIA = 'vendor_media';

    public const KIND_INVOICE = 'invoice';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'visible_to_tenant' => 'boolean',
            'created_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function scopeVisibleToTenant(Builder $query): Builder
    {
        return $query->where('visible_to_tenant', true)
            ->where('kind', '!=', self::KIND_INVOICE);
    }
}
