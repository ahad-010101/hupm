<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AddressCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Subdivisions and form rules for a country.  [D-19]
 *
 * The only client-side fetch in the application. TDD §3.3 rules out
 * client-side fetching for PAGE LOADS, and this is not one — it answers "what
 * does this country's address form look like" when the country changes, and
 * shipping the subdivisions of all 256 countries with every property form
 * would be several megabytes to avoid one small request.
 *
 * Read-only reference data, but still behind admin auth: there is no reason for
 * an unauthenticated endpoint to exist.
 */
class AddressLookupController extends Controller
{
    public function __invoke(Request $request, AddressCatalogue $addresses): JsonResponse
    {
        $country = strtoupper((string) $request->string('country'));

        if (! $addresses->isValidCountry($country)) {
            return response()->json(['message' => 'Unknown country.'], 422);
        }

        return response()->json([
            'subdivisions' => $addresses->subdivisions($country),
            'format' => $addresses->formatFor($country),
        ]);
    }
}
