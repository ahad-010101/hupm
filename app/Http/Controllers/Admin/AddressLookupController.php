<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Counties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * States for a country, cities for a state.  [D-19]
 *
 * Feeds the cascading country → state → city selects on the property form.
 * Data comes from nnjeim/world (250 countries, 5,000 states, 150,000 cities),
 * seeded into the database rather than bundled into JavaScript — the whole
 * city list is about 10 MB, and there is no CDN in front of this host.
 *
 * The only client-side fetch in the application. TDD §3.3 rules out
 * client-side fetching for PAGE LOADS; this is not one.
 *
 * Cached forever: the list of Georgian cities does not change between deploys.
 */
class AddressLookupController extends Controller
{
    public function states(Request $request): JsonResponse
    {
        $countryCode = strtoupper((string) $request->string('country'));

        $states = cache()->rememberForever("address.states.{$countryCode}", fn () => DB::table('states')
            ->join('countries', 'countries.id', '=', 'states.country_id')
            ->where('countries.iso2', $countryCode)
            ->orderBy('states.name')
            ->get(['states.id', 'states.name'])
            ->all());

        return response()->json(['states' => $states]);
    }

    /**
     * Counties for a state.  [FR-NTF-03]
     *
     * Keyed by state *name*, not id, because these do not come from the world
     * tables — {@see Counties} holds them, and an empty list is a legitimate
     * answer meaning "we have none for this state, offer a text box".
     */
    public function counties(Request $request): JsonResponse
    {
        return response()->json([
            'counties' => Counties::forState($request->string('state')->value()),
        ]);
    }

    public function cities(Request $request): JsonResponse
    {
        $stateId = $request->integer('state');

        $cities = cache()->rememberForever("address.cities.{$stateId}", fn () => DB::table('cities')
            ->where('state_id', $stateId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all());

        return response()->json(['cities' => $cities]);
    }
}
