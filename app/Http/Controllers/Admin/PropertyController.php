<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PropertyRequest;
use App\Models\LedgerEntry;
use App\Models\Property;
use App\Support\AuditLogger;
use App\Support\Counties;
use App\Support\ListSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    /**
     * Sortable columns.  [WP-38]
     *
     * `units_count` is the alias `withCount` already adds below, so ordering by
     * it needs no extra query.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'created_at' => 'created_at',
        'name' => 'name',
        'city' => 'city',
        'county' => 'county',
        'units_count' => 'units_count',
    ];

    public function index(Request $request): Response
    {
        // Newest first, so a property added a moment ago is the first thing on
        // the screen rather than sitting alphabetically in the middle of 25.
        $sort = ListSort::resolve($request, self::SORTABLE, default: 'created_at');

        $query = Property::query()
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
            });

        $properties = ListSort::apply($query, $sort, self::SORTABLE)
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Properties/Index', [
            'properties' => $properties,
            'filters' => ['search' => $request->string('search')->value()],
            'sort' => $sort,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Properties/Form', [
            'property' => null,
            ...$this->addressOptions('US', null),
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
        $property->load(['units' => fn ($q) => $q->withCount('leases')->orderBy('unit_number')]);

        // One grouped query for every unit on the page rather than one each.
        // Read only — LedgerService remains the sole writer of ledger_entries
        // (I-2).
        $ledgerCounts = LedgerEntry::query()
            ->join('leases', 'leases.id', '=', 'ledger_entries.lease_id')
            ->whereIn('leases.unit_id', $property->units->pluck('id'))
            ->groupBy('leases.unit_id')
            ->selectRaw('leases.unit_id as unit_id, COUNT(*) as total')
            ->pluck('total', 'unit_id')
            // Both sides cast: a driver that hands back string keys would make
            // every lookup below miss silently.
            ->mapWithKeys(fn ($total, $unitId) => [(int) $unitId => (int) $total]);

        return Inertia::render('Admin/Properties/Show', [
            'property' => $property,
            'units' => $property->units->map(fn ($unit) => [
                ...$unit->only(['id', 'unit_number', 'bedrooms', 'bathrooms', 'status']),
                // Computed here rather than in the view: whether a unit may be
                // removed is a data question, and the answer decides what the
                // action says as much as whether it fires. The counts travel
                // with it so the refusal can name what is in the way instead of
                // leaving the admin to guess.
                'is_deletable' => (int) $unit->leases_count === 0,
                'lease_count' => (int) $unit->leases_count,
                'ledger_count' => $ledgerCounts->get($unit->id, 0),
            ]),
            'deletion' => [
                'allowed' => $property->units->every(fn ($unit) => (int) $unit->leases_count === 0),
                'unit_count' => $property->units->count(),
                'blocking_units' => $property->units->filter(fn ($unit) => (int) $unit->leases_count > 0)->count(),
            ],
        ]);
    }

    public function edit(Property $property): Response
    {
        return Inertia::render('Admin/Properties/Form', [
            'property' => $property,
            ...$this->addressOptions($property->country_code ?? 'US', $property->state),
        ]);
    }

    /**
     * Everything the address cascade needs on first paint: all countries, and
     * the states and cities for whatever is already selected. Changing a
     * dropdown afterwards fetches the next level from AddressLookupController.
     *
     * Without pre-loading, editing an existing property would briefly show
     * empty state and city dropdowns over correct stored values.
     *
     * @return array<string, mixed>
     */
    private function addressOptions(string $countryCode, ?string $stateName): array
    {
        $countries = cache()->rememberForever('address.countries', fn () => DB::table('countries')
            ->orderByRaw("FIELD(iso2, 'US', 'CA') DESC") // the ones we operate in, first
            ->orderBy('name')
            ->get(['iso2', 'name'])
            ->all());

        $states = cache()->rememberForever("address.states.{$countryCode}", fn () => DB::table('states')
            ->join('countries', 'countries.id', '=', 'states.country_id')
            ->where('countries.iso2', $countryCode)
            ->orderBy('states.name')
            ->get(['states.id', 'states.name'])
            ->all());

        $stateId = collect($states)->firstWhere('name', $stateName)?->id;

        return [
            'countries' => $countries,
            'states' => $states,
            'cities' => $stateId
                ? cache()->rememberForever("address.cities.{$stateId}", fn () => DB::table('cities')
                    ->where('state_id', $stateId)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->all())
                : [],
            'selectedStateId' => $stateId,
            // Empty for a state we hold no list for, which the form reads as
            // "offer a text box instead" rather than as an error.
            'counties' => Counties::forState($stateName),
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
        $blocking = $property->unitsWithHistory();

        if ($blocking > 0) {
            return back()->withErrors([
                'property' => $blocking === 1
                    ? 'One unit at this property has lease history, so the property cannot be removed. Financial history is kept permanently.'
                    : "{$blocking} units at this property have lease history, so the property cannot be removed. Financial history is kept permanently.",
            ]);
        }

        $units = $property->units()->get();

        // Every remaining unit is lease-free, so none of them holds a financial
        // record and they go with the property in one act rather than making
        // the admin clear them by hand first. Each is audited separately so the
        // log can still reconstruct exactly what was removed.
        DB::transaction(function () use ($property, $units, $audit) {
            foreach ($units as $unit) {
                $audit->record('unit.deleted', $unit, $unit->only(['property_id', 'unit_number']));
                $unit->delete();
            }

            $audit->record('property.deleted', $property, $property->only(['name', 'street_address', 'postal_code']));
            $property->delete();
        });

        return redirect()
            ->route('admin.properties.index')
            ->with('status', $units->isEmpty()
                ? 'Property removed.'
                : 'Property removed, along with its '.$units->count().' '.($units->count() === 1 ? 'unit' : 'units').'.');
    }
}
