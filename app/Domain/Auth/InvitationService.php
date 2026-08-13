<?php

namespace App\Domain\Auth;

use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplate;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\URL;

/**
 * Account provisioning.  [FR-AUTH-02, AC-AUTH-05]
 *
 * There is no self-registration. An admin creates the account; the system emails
 * a signed link; the tenant chooses their own password. The link doubles as
 * email verification — possession of the mailbox is the proof (TDD §4), which is
 * why there is no separate verification step.
 *
 * **Single use** is the part that needs care. A signed URL with an expiry is not
 * single-use: it works repeatedly until it expires. So the signature covers a
 * token derived from the account's own state — its password and status — both
 * of which change the moment the link is used. Setting a password therefore
 * invalidates the link that set it, with nothing to store, expire or clean up.
 */
class InvitationService
{
    /** FR-AUTH-02 step 3. */
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create the login for a tenant and email the set-password link.
     *
     * Returns null when the tenant has no email address (Q-4, AC-AUTH-06) —
     * the tenant record stands on its own and simply has no portal account.
     */
    public function invite(int $tenantId, string $name, ?string $email): ?User
    {
        if ($email === null || trim($email) === '') {
            $this->audit->record('auth.invite.skipped', null, [
                'tenant_id' => $tenantId,
                'reason' => 'no email address on file',
            ]);

            return null;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
        ]);

        // Privilege is set explicitly, never mass-assigned (I-11).
        $user->forceFill([
            'tenant_id' => $tenantId,
            'role' => User::ROLE_TENANT,
            'status' => User::STATUS_INVITED,
            'password' => null,
        ])->save();

        $this->sendSetPasswordLink($user);

        return $user;
    }

    /** Also used by the resend action when a link has expired (AC-AUTH-05). */
    public function sendSetPasswordLink(User $user): void
    {
        $expiresAt = now()->addDays(self::EXPIRY_DAYS);

        $this->notifications->send(
            NotificationTemplate::WelcomeSetPassword,
            $user->email,
            [
                'name' => $user->name,
                'url' => $this->setPasswordUrl($user, $expiresAt),
                'expiresOn' => $expiresAt->timezone(config('app.timezone'))->format('j F Y'),
            ],
            tenantId: $user->tenant_id,
            userId: $user->id,
        );

        $this->audit->record('auth.invite.sent', $user);
    }

    public function setPasswordUrl(User $user, ?\DateTimeInterface $expiresAt = null): string
    {
        return URL::temporarySignedRoute(
            'password.set',
            $expiresAt ?? now()->addDays(self::EXPIRY_DAYS),
            ['user' => $user->id, 'token' => self::stateToken($user)],
        );
    }

    /**
     * A fingerprint of the account state the link is valid for.
     *
     * Changing the password or the status changes this, so a used link stops
     * validating. Includes the email so a re-addressed account invalidates too.
     */
    public static function stateToken(User $user): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$user->id, $user->email, $user->password ?? '', $user->status]),
            config('app.key'),
        );
    }
}
