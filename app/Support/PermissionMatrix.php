<?php

namespace App\Support;

use App\Models\User;

/**
 * The TDD §5.3 permission matrix, as data.  [WP-04]
 *
 * The DoD asks for the matrix to be "expressed as an executable test". A test
 * that restates the table in its own words only proves the table was copied
 * twice. So the table lives here, the Gate is defined *from* it, and the test
 * asserts the Gate against it — which makes drift between the specification and
 * the running system impossible rather than merely unlikely.
 *
 * Each feature work package uses the capability that already exists here
 * instead of inventing an ability name, so authorisation stays in one place.
 *
 * Cell values:
 *   ALLOW    — permitted outright
 *   DENY     — refused (the spec's ✗ and — collapse to this; both mean "no")
 *   OWN      — permitted, but only for the user's own records. The Gate says
 *              yes and a Policy narrows it to the row. Ownership failures are
 *              404, never 403 (I-9) — see AuthorizesOwnership.
 *   SUMMARY  — permitted in aggregate only, never per tenant.
 */
class PermissionMatrix
{
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    public const OWN = 'own';

    public const SUMMARY = 'summary';

    /**
     * Verbatim from TDD §5.3, in the same order.
     *
     * @return array<string, array<string, string>>
     */
    public static function table(): array
    {
        return [
            //                              admin          tenant         owner
            'view-public-site' => [self::ALLOW, self::ALLOW, self::ALLOW],
            'view-own-dashboard' => [self::ALLOW, self::ALLOW, self::ALLOW],
            'view-own-ledger' => [self::ALLOW, self::ALLOW, self::DENY],
            // I-4: a tenant must never see this, in the UI, the props or an export.
            'view-ha-portion' => [self::ALLOW, self::DENY, self::ALLOW],
            'make-payment' => [self::DENY, self::ALLOW, self::DENY],
            'record-offline-payment' => [self::ALLOW, self::DENY, self::DENY],
            'post-charge' => [self::ALLOW, self::DENY, self::DENY],
            'reverse-ledger-entry' => [self::ALLOW, self::DENY, self::DENY],
            'manage-portfolio' => [self::ALLOW, self::DENY, self::DENY],
            'view-all-tenants' => [self::ALLOW, self::DENY, self::SUMMARY],
            'view-tenant-contact' => [self::ALLOW, self::OWN, self::DENY],
            'create-maintenance-request' => [self::ALLOW, self::ALLOW, self::DENY],
            'manage-ticket' => [self::ALLOW, self::DENY, self::DENY],
            'view-ticket-detail' => [self::ALLOW, self::OWN, self::DENY],
            'upload-document' => [self::ALLOW, self::DENY, self::DENY],
            // AC-AUTH-10: an owner on any document route gets 403.
            'download-document' => [self::ALLOW, self::OWN, self::DENY],
            'sign-document' => [self::DENY, self::ALLOW, self::DENY],
            'create-signature-request' => [self::ALLOW, self::DENY, self::DENY],
            'send-notice' => [self::ALLOW, self::DENY, self::DENY],
            'release-management-review' => [self::ALLOW, self::DENY, self::DENY],
            'approve-arrangement' => [self::ALLOW, self::DENY, self::DENY],
            'run-reports' => [self::ALLOW, self::DENY, self::ALLOW],
            'run-data-import' => [self::ALLOW, self::DENY, self::DENY],
            'view-audit-log' => [self::ALLOW, self::DENY, self::DENY],
        ];
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return [User::ROLE_ADMIN, User::ROLE_TENANT, User::ROLE_OWNER];
    }

    /** @return list<string> */
    public static function capabilities(): array
    {
        return array_keys(self::table());
    }

    /** The cell value for a capability and role. */
    public static function cell(string $capability, string $role): string
    {
        $index = array_search($role, self::roles(), true);

        if ($index === false || ! isset(self::table()[$capability])) {
            return self::DENY;
        }

        return self::table()[$capability][$index];
    }

    /**
     * Whether the Gate should permit this capability at all.
     *
     * OWN and SUMMARY pass the Gate and are narrowed afterwards — by a Policy
     * for OWN, by the query for SUMMARY. The Gate answers "may this role do
     * this kind of thing", not "may they do it to this row".
     */
    public static function allows(string $capability, string $role): bool
    {
        return self::cell($capability, $role) !== self::DENY;
    }
}
