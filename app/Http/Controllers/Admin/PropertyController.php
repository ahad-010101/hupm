<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PropertyRequest;
use App\Models\Property;
use App\Support\AddressCatalogue;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Properties.  [FR-REG-01, API-ADM-02, API-ADM-03]
 *
 * Controllers hold no business logic and never read `$request->all()` into a
 * model (I-11) — the FormRequest hands over a validated payload.
 */
class PropertyController extends Controller
{
    public function index(Request $request): Response
    {
        $properties = Property::query()
            ->withCount([
                'units',
                'units as occupied_units_count' => fn ($q) => $q->where('status', 'occupied'),
                'units as vacant_units_count' => fn ($q) => $q->where('status', 'vacant'),
            ])
            ->when($request->string('search')->trim()->value(), function ($query, string $search) {
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('street_address', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('postal_code', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Properties/Index', [
            'properties' => $properties,
            'filters' => ['search' => $request->string('search')->value()],
        ]);
    }

    public function create(AddressCatalogue $addresses): Response
    {
        return Inertia::render('Admin/Properties/Form', [
            'property' => null,
            ...$this->addressOptions($addresses, 'US'),
        ]);
    }

    public function store(PropertyRequest $request, AuditLogger $audit): RedirectResponse
    {
        $property = Property::create($request->attributesForModel());

        $audit->record('property.created', $property, $request->attributesForModel());

        return redirect()
            ->route('admin.properties.show', $property)
            ->with('status', "{$property->name} was added.");
    }

    public function show(Property $property): Response
    {
        $property->load(['units' => fn ($q) => $q->orderBy('unit_number')]);

        return Inertia::render('Admin/Properties/Show', [
            'property' => $property,
            'units' => $property->units->map(fn ($unit) => [
                ...$unit->only(['id', 'unit_number', 'bedrooms', 'bathrooms', 'status']),
                // Computed here rather than in the view: whether a unit may be
                // removed is a data question, and the answer decides whether
                // the button renders at all.
                'is_deletable' => $unit->isDeletable(),
            ]),
        ]);
    }

    public function edit(Property $property, AddressCatalogue $addresses): Response
    {
        return Inertia::render('Admin/Properties/Form', [
            'property' => $property,
            ...$this->addressOptions($addresses, $property->country_code ?? 'US'),
        ]);
    }

    /**
     * Country list plus the selected country's subdivisions and labels, so the
     * form renders correctly on first paint. Changing the country afterwards
     * fetches from AddressLookupController.
     *
     * @return array<string, mixed>
     */
    private function addressOptions(AddressCatalogue $addresses, string $countryCode): array
    {
        return [
            'countries' => $addresses->countries(),
            'subdivisions' => $addresses->subdivisions($countryCode),
            'addressFormat' => $addresses->formatFor($countryCode),
        ];
    }

    public function update(PropertyRequest $request, Property $property, AuditLogger $audit): RedirectResponse
    {
        $property->fill($request->attributesForModel());
        $audit->recordChange('property.updated', $property);
        $property->save();

        return redirect()
            ->route('admin.properties.show', $property)
            ->with('status', 'Changes saved.');
    }

    public function destroy(Property $property, AuditLogger $audit): RedirectResponse
    {
        // The database would refuse this anyway (RESTRICT), but a foreign-key
        // violation surfaces as a 500 the admin cannot act on. Checking first
        // turns it into a sentence that says what to do next.
        if (! $property->isDeletable()) {
            return back()->withErrors([
                'property' => 'This property still has units. Remove or reassign them first.',
            ]);
        }

        $audit->record('property.deleted', $property, $property->only(['name', 'street_address', 'postal_code']));
        $property->delete();

        return redirect()
            ->route('admin.properties.index')
            ->with('status', 'Property removed.');
    }
}
