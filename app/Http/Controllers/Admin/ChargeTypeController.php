<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChargeTypeRequest;
use App\Models\ChargeType;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Charge types.  [WP-41, FR-CHG-01]
 *
 * Controllers hold no business logic and never read `$request->all()` into a
 * model (I-11) — the FormRequest hands over a validated payload.
 */
class ChargeTypeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Charges/Types', [
            'types' => ChargeType::query()
                ->withCount(['schedules as active_schedules_count' => fn ($q) => $q->where('active', true)])
                ->orderBy('name')
                ->get()
                ->map(fn (ChargeType $type) => [
                    ...$type->only(['id', 'name', 'category', 'default_description', 'payer', 'active']),
                    'default_amount' => (string) $type->default_amount,
                    'active_schedules_count' => $type->active_schedules_count,
                    // Whether it may be removed is a data question, and the
                    // answer decides what the action says as well as whether it
                    // fires — the same pattern as properties and units.
                    'is_deletable' => $type->isDeletable(),
                ]),
            'categories' => ChargeType::CATEGORIES,
        ]);
    }

    public function store(ChargeTypeRequest $request, AuditLogger $audit): RedirectResponse
    {
        $type = ChargeType::create($request->attributesForModel());

        $audit->record('charge_type.created', $type, $request->attributesForModel());

        return back()->with('status', "{$type->name} was added.");
    }

    public function update(ChargeTypeRequest $request, ChargeType $chargeType, AuditLogger $audit): RedirectResponse
    {
        $chargeType->fill($request->attributesForModel());
        $audit->recordChange('charge_type.updated', $chargeType);
        $chargeType->save();

        // Said plainly, because it is the thing an admin will assume otherwise:
        // editing a type does not re-price the schedules already created from it.
        return back()->with(
            'status',
            'Changes saved. Residents already on this charge keep the amount they were set up with — '
                .'use Post a charge to change them.',
        );
    }

    public function destroy(ChargeType $chargeType, AuditLogger $audit): RedirectResponse
    {
        if (! $chargeType->isDeletable()) {
            return back()->withErrors([
                'charge_type' => 'This type has been used to charge residents, so it cannot be removed. '
                    .'Switch it off instead — the history stays readable.',
            ]);
        }

        $audit->record('charge_type.deleted', $chargeType, $chargeType->only(['name', 'category']));
        $chargeType->delete();

        return back()->with('status', 'Charge type removed.');
    }
}
