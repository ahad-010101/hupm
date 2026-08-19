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

    public function requests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
