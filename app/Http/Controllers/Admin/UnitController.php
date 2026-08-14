<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UnitRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Units, nested under their property.  [FR-REG-01, API-ADM-04]
 *
 * Nested because a unit number is only meaningful within a property — the
 * AC-REG-01 uniqueness rule needs the parent, and a top-level /admin/units
 * would invite editing a unit without knowing which building it is in.
 */
class UnitController extends Controller
{
    public function create(Property $property): Response
    {
        return Inertia::render('Admin/Units/Form', [
            'property' => $property->only(['id', 'name']),
            'unit' => null,
        ]);
    }

    public function store(UnitRequest $request, Property $property, AuditLogger $audit): RedirectResponse
    {
        $unit = $property->units()->create($request->attributesForModel());

        $audit->record('unit.created', $unit, $request->attributesForModel());

        return redirect()
            ->route('admin.properties.show', $property)
            ->with('status', "Unit {$unit->unit_number} was added.");
    }

    public function edit(Property $property, Unit $unit): Response
    {
        abort_unless($unit->property_id === $property->id, 404);

        return Inertia::render('Admin/Units/Form', [
            'property' => $property->only(['id', 'name']),
            'unit' => $unit->only(['id', 'unit_number', 'bedrooms', 'bathrooms', 'status']),
        ]);
    }

    public function update(UnitRequest $request, Property $property, Unit $unit, AuditLogger $audit): RedirectResponse
    {
        // A unit id from one property paired with another property's URL is a
        // client assertion, not a fact.
        abort_unless($unit->property_id === $property->id, 404);

        $unit->fill($request->attributesForModel());
        $audit->recordChange('unit.updated', $unit);
        $unit->save();

        return redirect()
            ->route('admin.properties.show', $property)
            ->with('status', "Unit {$unit->unit_number} was updated.");
    }

    public function destroy(Property $property, Unit $unit, AuditLogger $audit): RedirectResponse
    {
        abort_unless($unit->property_id === $property->id, 404);

        if (! $unit->isDeletable()) {
            return back()->withErrors([
                'unit' => 'This unit has lease history and cannot be removed. Mark it off-market instead.',
            ]);
        }

        $audit->record('unit.deleted', $unit, $unit->only(['unit_number', 'status']));
        $unit->delete();

        return redirect()
            ->route('admin.properties.show', $property)
            ->with('status', 'Unit removed.');
    }
}
