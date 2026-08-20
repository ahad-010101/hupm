<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\GlobalSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Global search.  [FR-ADM-01]
 *
 * A GET to a page, not an XHR to a JSON endpoint: results are a place you can
 * be, so they get a URL, a back button and a shareable link — and the console
 * keeps its rule that pages are loaded by Inertia, not fetched by components.
 */
class SearchController extends Controller
{
    public function __construct(private readonly GlobalSearch $search) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Admin/Search', [
            'results' => $this->search->search((string) $request->query('q', '')),
        ]);
    }
}
