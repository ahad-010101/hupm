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

        return $this->createAccount($name, $email, User::ROLE_TENANT, ['tenant_id' => $tenantId]);
    }

    /**
     * The login for an agency.  [WP-43]
     *
     * Same flow as a tenant — a token, a set-password link, no password ever
     * chosen by us. Only the role and the subject column differ.
     */
    public function inviteHousingAuthority(int $housingAuthorityId, string $name, ?string $email): ?User
    {
        if ($email === null || trim($email) === '') {
            $this->audit->record('auth.invite.skipped', null, [
                'housing_authority_id' => $housingAuthorityId,
                'reason' => 'no email address on file',
            ]);

            return null;
        }

        return $this->createAccount($name, $email, User::ROLE_HOUSING_AUTHORITY, [
            'housing_authority_id' => $housingAuthorityId,
        ]);
    }

    /**
     * The login for a contractor.  [WP-44, reverses NG-6]
     *
     * WP-37 asserted a contractor is a record and not an account. The client
     * asked for the portal on 5 Sep 2026; the reversal is recorded in the plan
     * rather than worked around here.
     */
    public function inviteVendor(int $vendorId, string $name, ?string $email): ?User
    {
        if ($email === null || trim($email) === '') {
            $this->audit->record('auth.invite.skipped', null, [
                'vendor_id' => $vendorId,
                'reason' => 'no email address on file',
            ]);

            return null;
        }

        return $this->createAccount($name, $email, User::ROLE_VENDOR, ['vendor_id' => $vendorId]);
    }

    /**
     * One account-creation path for all three.
     *
     * @param  array<string, int>  $subject  the single column binding this login to its subject
     */
    private function createAccount(string $name, string $email, string $role, array $subject): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
        ]);

        // Privilege is set explicitly, never mass-assigned (I-11).
        $user->forceFill([
            ...$subject,
            'role' => $role,
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
