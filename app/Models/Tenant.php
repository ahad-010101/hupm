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
    ];

    public function user(): HasMany
    {
        return $this->hasMany(User::class);
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
