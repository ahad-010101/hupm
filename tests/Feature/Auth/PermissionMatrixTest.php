<?php

use App\Models\User;
use App\Support\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| TDD §5.3 permission matrix  [WP-04 DoD]
|--------------------------------------------------------------------------
|
| One assertion per cell — 24 capabilities × 3 roles = 72.
|
| The matrix is data (App\Support\PermissionMatrix) and the Gate is defined
| from it, so this suite checks that the running authorisation layer agrees
| with the specification rather than checking a copy against a copy.
|
*/

uses(RefreshDatabase::class);

dataset('matrix cells', function () {
    foreach (PermissionMatrix::table() as $capability => $_) {
        foreach (PermissionMatrix::roles() as $role) {
            yield "{$capability} / {$role}" => [$capability, $role];
        }
    }
});

it('§5.3 enforces every cell', function (string $capability, string $role) {
    $user = User::factory()->create(['role' => $role]);

    $expected = PermissionMatrix::allows($capability, $role);

    expect(Gate::forUser($user)->allows($capability))->toBe(
        $expected,
        sprintf(
            '%s / %s should be %s (matrix says %s)',
            $capability,
            $role,
            $expected ? 'allowed' : 'denied',
            PermissionMatrix::cell($capability, $role),
        ),
    );
})->with('matrix cells');

it('covers all 24 capabilities from the specification', function () {
    expect(PermissionMatrix::capabilities())->toHaveCount(24);
});

it('I-4 denies a tenant the housing authority portion', function () {
    // Singled out because it is the invariant most easily broken by a helpful
    // change elsewhere, and a tenant seeing the HA portion is a contract
    // problem, not a UI bug.
    $tenant = User::factory()->create(['role' => User::ROLE_TENANT]);

    expect(Gate::forUser($tenant)->allows('view-ha-portion'))->toBeFalse();
});

it('denies a tenant every administrative capability', function () {
    $tenant = User::factory()->create(['role' => User::ROLE_TENANT]);

    foreach ([
        'record-offline-payment', 'post-charge', 'reverse-ledger-entry',
        'manage-portfolio', 'view-all-tenants', 'manage-ticket', 'upload-document',
        'create-signature-request', 'send-notice', 'release-management-review',
        'approve-arrangement', 'run-reports', 'run-data-import', 'view-audit-log',
    ] as $capability) {
        expect(Gate::forUser($tenant)->allows($capability))->toBeFalse("tenant must not $capability");
    }
});

it('denies an admin the two capabilities reserved for tenants', function () {
    // The matrix marks these "—" for admin: an admin records an offline
    // payment rather than making one, and cannot sign on a tenant's behalf.
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('make-payment'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('sign-document'))->toBeFalse();
});
