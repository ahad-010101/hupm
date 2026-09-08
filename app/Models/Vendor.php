<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A contractor.  [FR-MNT-03, NG-6]
 *
 * A data record and nothing more. There is no vendor login in v1, so nothing
 * here is a credential and nothing here authenticates — the plumber is phoned,
 * not invited.
 *
 * Soft-deleted because a vendor who did work last year must stay resolvable on
 * the ticket that names them, long after they stop being someone we call.
 */
class Vendor extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['name', 'trade', 'phone', 'email', 'notes', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * The portal accounts belonging to this contractor.  [WP-44]
     *
     * NG-6 said a contractor was a record and not an account, and until 5 Sep
     * 2026 that was true. It was reversed deliberately; the record is still the
     * thing that matters, and a contractor without a login is still a complete
     * contractor.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** True once an account exists and a password has been set. */
    public function hasPortalAccess(): bool
    {
        return $this->users()->where('status', User::STATUS_ACTIVE)->exists();
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
