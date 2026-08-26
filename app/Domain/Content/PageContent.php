<?php

namespace App\Domain\Content;

use App\Models\ContentPage;
use App\Models\PageSection;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * What the public site reads.  [WP-36, D-27]
 *
 * The only class the public pages use to reach content, and it touches nothing
 * but `content_pages` and `page_sections`. That is load-bearing: a test greps
 * `app/Http/Controllers/Public/*` for tenant models, and AC-PUB-01's guarantee
 * rests on the public side having no way to reach resident data at all.
 *
 * Cached the same way settings are — one `rememberForever` per page, flushed on
 * any write. The public site is the most-read and least-changed thing in the
 * system, and on shared hosting a query per section per request is a cost worth
 * not paying.
 */
class PageContent
{
    private const PREFIX = 'hupm.content.';

    public function __construct(private readonly Cache $cache) {}

    /**
     * A page and its visible sections, in order.
     *
     * Returns a fallback rather than null for a slug with no row. A fresh
     * install has no content, and the honest behaviour there is a page that
     * renders and says so — not a 500, and not a 404 for a URL that genuinely
     * exists in the router.
     *
     * @return array{title: string, meta_description: string, published: bool, sections: list<array{type: string, payload: array<string, mixed>}>}
     */
    public function forSlug(string $slug): array
    {
        return $this->cache->rememberForever(self::PREFIX.'page.'.$slug, function () use ($slug) {
            $page = ContentPage::with('sections')->where('slug', $slug)->first();

            if (! $page) {
                return $this->blank();
            }

            return [
                'title' => $page->title,
                'meta_description' => (string) $page->meta_description,
                'published' => $page->is_published,
                'sections' => $page->sections
                    ->filter(fn (PageSection $section) => $section->is_enabled)
                    ->map(fn (PageSection $section) => [
                        'type' => $section->type,
                        'payload' => $section->payload ?? [],
                    ])
                    ->values()
                    ->all(),
            ];
        });
    }

    /**
     * The header navigation.
     *
     * Driven from the table so a page added to the nav appears without editing
     * the layout — which is how `/services` arrives.
     *
     * @return list<array{route: string, label: string}>
     */
    public function navigation(): array
    {
        return $this->cache->rememberForever(self::PREFIX.'nav', function () {
            return ContentPage::query()
                ->where('is_published', true)
                ->where('show_in_nav', true)
                ->orderBy('nav_position')
                ->orderBy('id')
                ->get()
                ->map(fn (ContentPage $page) => [
                    'route' => 'public.'.$page->slug,
                    'label' => $page->navLabel(),
                ])
                // A row whose route was removed from the code must not take the
                // whole header down with it.
                ->filter(fn (array $item) => app('router')->has($item['route']))
                ->values()
                ->all();
        });
    }

    /**
     * Forget everything.
     *
     * Called on any content write. Coarse on purpose: the public site changes a
     * handful of times a year, and a targeted invalidation that misses the nav
     * is a stale header nobody can explain.
     */
    public function flush(): void
    {
        $this->cache->forget(self::PREFIX.'nav');

        foreach (ContentPage::pluck('slug') as $slug) {
            $this->cache->forget(self::PREFIX.'page.'.$slug);
        }
    }

    /** @return array{title: string, meta_description: string, published: bool, sections: list<mixed>} */
    private function blank(): array
    {
        return ['title' => '', 'meta_description' => '', 'published' => true, 'sections' => []];
    }
}
