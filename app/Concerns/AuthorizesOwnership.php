<?php

namespace App\Concerns;

use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Ownership denial → 404 plus an audit row.  [I-9, AC-AUTH-09, BR-20]
 *
 * Laravel's `$this->authorize()` aborts with 403, which is the wrong answer for
 * a record belonging to someone else: 403 means "this exists and you may not
 * have it", and that alone tells tenant A that tenant B is a customer here. So
 * ownership failures return 404 instead.
 *
 * A wrong-ROLE failure is different and stays 403 — see EnsureRole. Conflating
 * the two is easy, which is why they live in separate places with separate
 * names.
 *
 * Every denial is audited. Someone walking IDs is exactly what this trail is
 * for, and the 404 they receive is deliberately indistinguishable from a
 * genuinely missing record.
 */
trait AuthorizesOwnership
{
    protected function authorizeOwnership(string $ability, Model $model): void
    {
        if (Gate::allows($ability, $model)) {
            return;
        }

        app(AuditLogger::class)->record('auth.ownership.denied', $model, [
            'ability' => $ability,
            'actor_tenant_id' => request()->user()?->tenant_id,
            'subject_tenant_id' => $model->tenant_id ?? null,
            'path' => request()->path(),
        ]);

        abort(404);
    }
}
