<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Content\SectionCatalogue;
use App\Domain\Content\SectionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentPageRequest;
use App\Http\Requests\Admin\ContentSectionRequest;
use App\Models\ContentPage;
use App\Models\PageSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public site, edited.  [WP-36, D-27]
 *
 * Pages can be edited but **not created or deleted** — `routes/public.php` is
 * the whole list of public URLs, and it stays that way. Adding a page is a
 * one-line code change; changing what one says is not.
 *
 * Every write goes through {@see SectionService}, which normalises the payload,
 * audits the change and drops the cache together. A controller that did two of
 * those three would leave a public site showing content nobody can account for.
 */
class WebsiteController extends Controller
{
    public function __construct(
        private readonly SectionCatalogue $catalogue,
        private readonly SectionService $sections,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Website/Index', [
            'pages' => ContentPage::query()
                ->withCount([
                    'sections',
                    'sections as hidden_sections_count' => fn ($q) => $q->where('is_enabled', false),
                ])
                ->orderBy('nav_position')
                ->orderBy('id')
                ->get()
                ->map(fn (ContentPage $page) => [
                    'slug' => $page->slug,
                    'title' => $page->title,
                    'nav_label' => $page->navLabel(),
                    'url' => $this->urlFor($page),
                    'is_published' => $page->is_published,
                    'show_in_nav' => $page->show_in_nav,
                    'sections' => $page->sections_count,
                    'hidden' => $page->hidden_sections_count,
                    'updated_at' => $page->updated_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function edit(ContentPage $page): Response
    {
        return Inertia::render('Admin/Website/Edit', [
            'page' => [
                'slug' => $page->slug,
                'title' => $page->title,
                'nav_label' => $page->nav_label,
                'meta_description' => $page->meta_description,
                'is_published' => $page->is_published,
                'show_in_nav' => $page->show_in_nav,
                'nav_position' => $page->nav_position,
                'url' => $this->urlFor($page),
            ],
            'sections' => $page->sections->map(fn (PageSection $section) => [
                'id' => $section->id,
                'type' => $section->type,
                'label' => $this->catalogue->describe($section->type)['label'] ?? $section->type,
                'summary' => $this->summarise($section),
                'is_enabled' => $section->is_enabled,
                'payload' => $section->payload ?? [],
            ])->all(),
            // The catalogue renders the form. A new section type is a catalogue
            // entry plus a Blade partial, and no new React at all.
            'catalogue' => $this->catalogue->all(),
            'routeOptions' => $this->catalogue->routeOptions(),
        ]);
    }

    public function update(ContentPageRequest $request, ContentPage $page): RedirectResponse
    {
        $this->sections->updatePage($page, $request->attributesForModel());

        return back()->with('status', 'Page settings saved.');
    }

    public function storeSection(Request $request, ContentPage $page): RedirectResponse
    {
        $type = (string) $request->input('type');

        if (! $this->catalogue->has($type)) {
            return back()->withErrors(['type' => 'There is no section of that kind.']);
        }

        $this->sections->add($page, $type);

        return back()->with('status', 'Section added. It is hidden until you switch it on.');
    }

    public function updateSection(
        ContentSectionRequest $request,
        ContentPage $page,
        PageSection $section,
    ): RedirectResponse {
        // 404 rather than 403 for a section belonging to another page: a
        // "forbidden" reply confirms the row exists, which is itself a leak
        // (I-9). The same rule the rest of the console follows.
        abort_unless($section->content_page_id === $page->id, 404);

        $problems = $this->sections->update(
            $section,
            (array) $request->input('payload', []),
            $request->boolean('is_enabled'),
        );

        if ($problems !== []) {
            return back()->withErrors(['payload' => implode(' ', $problems)]);
        }

        return back()->with('status', 'Section saved.');
    }

    public function moveSection(
        Request $request,
        ContentPage $page,
        PageSection $section,
    ): RedirectResponse {
        abort_unless($section->content_page_id === $page->id, 404);

        $direction = $request->input('direction') === 'up' ? 'up' : 'down';

        $this->sections->move($section, $direction);

        return back()->with('status', 'Section moved.');
    }

    public function destroySection(ContentPage $page, PageSection $section): RedirectResponse
    {
        abort_unless($section->content_page_id === $page->id, 404);

        $this->sections->remove($section);

        return back()->with('status', 'Section removed.');
    }

    /** The public address of a page, for the "View page" link. */
    private function urlFor(ContentPage $page): ?string
    {
        $name = 'public.'.$page->slug;

        return app('router')->has($name) ? route($name) : null;
    }

    /**
     * A line of the section's own words, for the collapsed row.
     *
     * A list of "Cards, Cards, Text, Cards" tells an editor nothing about which
     * one they want.
     */
    private function summarise(PageSection $section): string
    {
        foreach (['heading', 'question', 'body'] as $key) {
            $value = trim(strip_tags((string) $section->field($key)));

            if ($value !== '') {
                return mb_strimwidth($value, 0, 80, '…');
            }
        }

        return 'No heading yet';
    }
}
