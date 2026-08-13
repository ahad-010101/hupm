<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An account. Provisioned by an admin — there is no self-registration (TDD §4).
 *
 * A tenant may exist with no user row at all (AC-AUTH-06, Q-4): some of the 26
 * have no email address, so they have no way to log in and are served over the
 * phone. `tenant_id` is therefore the link, not the identity.
 *
 * Single role per user, no hierarchy — with three roles a hierarchy is
 * over-engineering (TDD §5.1).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TENANT = 'tenant';

    public const ROLE_OWNER = 'owner';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * `role`, `status` and `tenant_id` are deliberately NOT fillable. They are
     * privilege, and privilege is never mass-assigned from request input
     * (I-11) — a controller must set them explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isTenant(): bool
    {
        return $this->role === self::ROLE_TENANT;
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    /** Only an active account with a password may authenticate (FR-AUTH-01). */
    public function canAuthenticate(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->password !== null;
    }

    /** Where this user lands after login (FR-AUTH-01 step 5). */
    public function homeRoute(): string
    {
        return match ($this->role) {
            self::ROLE_ADMIN => '/admin',
            self::ROLE_OWNER => '/owner',
            default => '/portal',
        };
    }
}
