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

    /**
     * Outside parties with a narrow reason to sign in.  [WP-43, WP-44]
     *
     * A housing authority sees the portion it funds and pays it. A contractor
     * sees the jobs assigned to them. Neither can reach anything else, and each
     * is bound to exactly one subject through the column below.
     */
    public const ROLE_HOUSING_AUTHORITY = 'housing_authority';

    public const ROLE_VENDOR = 'vendor';

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

    /** The agency this account speaks for, if it is an agency account. */
    public function housingAuthority(): BelongsTo
    {
        return $this->belongsTo(HousingAuthority::class);
    }

    /** The contractor this account belongs to, if it is a contractor account. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function isHousingAuthority(): bool
    {
        return $this->role === self::ROLE_HOUSING_AUTHORITY;
    }

    public function isVendor(): bool
    {
        return $this->role === self::ROLE_VENDOR;
    }

    /** Only an active account with a password may authenticate (FR-AUTH-01). */
    public function canAuthenticate(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->password !== null;
    }

    /** Where this user lands after login (FR-AUTH-01 step 5). */
    /**
     * Two letters for the avatar in the public header.  [WP-54]
     *
     * Initials rather than the name itself. AC-PUB-01 says no tenant name may
     * appear in a public response, and while this one is the viewer's own and
     * the response is `Cache-Control: private`, "JD" keeps the rule plainly
     * true instead of relying on an argument about whose name it is.
     */
    public function initials(): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim((string) $this->name)) ?: []));

        $letters = array_map(
            static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)),
            array_slice($words, 0, 2),
        );

        return implode('', $letters) ?: '?';
    }

    public function homeRoute(): string
    {
        return match ($this->role) {
            self::ROLE_ADMIN => '/admin',
            self::ROLE_OWNER => '/owner',
            // [WP-43, WP-44] Each outside party lands on the only thing they
            // can see. Falling through to /portal would 403 them at the door.
            self::ROLE_HOUSING_AUTHORITY => '/agency',
            self::ROLE_VENDOR => '/work',
            default => '/portal',
        };
    }
}
