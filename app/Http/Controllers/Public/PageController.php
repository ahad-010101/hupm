<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\PageContent;
use App\Domain\Content\SectionCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Every managed public page.  [WP-36, D-27, FR-PUB-01]
 *
 * One controller, resolved by slug, rendering an ordered list of sections into
 * Blade. Server-side, because the public site loads no JavaScript (D-05).
 *
 * **It reads nothing but content.** A test greps this directory for tenant
 * models and fails if one appears — AC-PUB-01 holds because there is no path
 * from here to a resident, not because somebody remembered to filter.
 *
 * The slug arrives from `routes/public.php`, never from the URL. There is no
 * route that resolves an arbitrary slug, which is what stops a content row
 * publishing itself over a path the router already means something by.
 */
class PageController extends Controller
{
    public function __construct(
        private readonly PageContent $content,
        private readonly SectionCatalogue $catalogue,
    ) {}

    public function __invoke(string $slug): View
    {
        $page = $this->content->forSlug($slug);

        /*
         | Filter to known types **here**, not in the view.
         |
         | The template's `@include('public.sections.'.$type)` is a file-include
         | whose path comes from a database column. A row whose type were
         | `../../../admin/something` would be a template-inclusion bug, and
         | `view()->exists()` is the wrong guard because it answers "can this
         | resolve" rather than "is this one of ours".
         |
         | The catalogue is a closed set. Nothing outside it reaches the view.
        */
        $page['sections'] = array_values(array_filter(
            $page['sections'],
            fn (array $section) => $this->catalogue->has($section['type']),
        ));

        return view('public.page', [
            'slug' => $slug,
            'page' => $page,
        ]);
    }
}
