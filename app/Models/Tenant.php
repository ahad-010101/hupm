<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A resident.
 *
 * A tenant may have no user account at all (Q-4, AC-AUTH-06) — two of the 26
 * have no email address. The tenant record is the system's subject; the user
 * row is only a way to log in, and its absence must never block anything.
 *
 * Soft deletes apply here and to vendors only (DB §A4): financial history has
 * to survive a tenant moving out.
 */
class Tenant extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
        // Business state, not privilege — `former` simply means moved out. The
        // account's role and status live on `users` and are never fillable.
        'status',
    ];

    public function user(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /** The current tenancy, if there is one. */
    public function activeLease(): ?Lease
    {
        return $this->leases()->where('status', Lease::STATUS_ACTIVE)->first();
    }

    /** True once a login exists and a password has been set. */
    public function hasPortalAccess(): bool
    {
        return $this->users()->where('status', User::STATUS_ACTIVE)->exists();
    }

    /**
     * Whether this tenant may be archived, mirroring Property and Unit.
     *
     * An ended lease is no obstacle — archiving is a soft delete and the whole
     * point is that the ledger, the payments and the tenancy survive it. Only a
     * live tenancy blocks, because charges would keep posting against a record
     * that no longer appears anywhere.
     */
    public function isDeletable(): bool
    {
        return ! $this->leases()->where('status', Lease::STATUS_ACTIVE)->exists();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** True when this tenant cannot be emailed — surfaces to admin (AC-NTF-03). */
    public function isContactable(): bool
    {
        return $this->email !== null && trim($this->email) !== '';
    }
}
