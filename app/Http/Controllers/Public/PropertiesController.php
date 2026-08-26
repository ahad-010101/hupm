<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Contracts\View\View;

/**
 * Available Properties.  [FR-PUB-01, BR-22, UI §1]
 *
 * "Static content maintained by ADMIN", and the emphasis is on *static*. The
 * obvious implementation — list the units with no active lease — is exactly the
 * one BR-22 forbids, because a public list of empty units is a public statement
 * about who is and is not at home. This page therefore never touches `units`,
 * `leases` or `tenants`; there is nothing to filter, because nothing is read.
 *
 * The office writes the listings into a setting, one per line. That is not a
 * content management system and is not meant to become one — HUPM explicitly
 * does not build a listing engine with applications or screening.
 */
class PropertiesController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    public function __invoke(): View
    {
        $raw = trim($this->settings->string('public.available_properties'));

        // One listing per line, blank lines dropped. Deliberately not markdown
        // or HTML: this is admin-authored text going onto a public page, and
        // the safest renderer for that is the one that does not render anything.
        $listings = $raw === ''
            ? []
            : collect(preg_split('/\r\n|\r|\n/', $raw))
                ->map(fn (string $line) => trim($line))
                ->filter()
                ->values()
                ->all();

        return view('public.properties', ['listings' => $listings]);
    }
}
