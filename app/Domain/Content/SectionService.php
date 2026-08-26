<?php

namespace App\Domain\Content;

use App\Models\ContentPage;
use App\Models\PageSection;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every write to public content goes through here.  [WP-36, D-27]
 *
 * One place that normalises a payload, audits the change and drops the cache —
 * because those three have to happen together, and a controller that does two
 * of them leaves a public site showing content nobody can account for.
 */
class SectionService
{
    public function __construct(
        private readonly SectionCatalogue $catalogue,
        private readonly PageContent $content,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function updatePage(ContentPage $page, array $attributes): ContentPage
    {
        $page->fill($attributes);

        $this->audit->recordChange('content.page.updated', $page);

        $page->save();
        $this->content->flush();

        return $page;
    }

    /**
     * Add a section to the end of a page.
     *
     * New sections arrive **disabled**. Somebody adding a block to a live
     * public page is mid-thought, and publishing an empty one the instant they
     * press Add is a worse default than making them switch it on.
     */
    public function add(ContentPage $page, string $type): PageSection
    {
        if (! $this->catalogue->has($type)) {
            throw new InvalidArgumentException('There is no section of that kind.');
        }

        $section = new PageSection;
        $section->forceFill([
            'content_page_id' => $page->id,
            'type' => $type,
            'position' => ((int) $page->sections()->max('position')) + 1,
            'is_enabled' => false,
            'payload' => $this->catalogue->normalise($type, []),
        ])->save();

        $this->audit->record('content.section.added', $section, ['page' => $page->slug, 'type' => $type]);
        $this->content->flush();

        return $section;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string> Problems; empty when saved.
     */
    public function update(PageSection $section, array $input, bool $enabled): array
    {
        $payload = $this->catalogue->normalise($section->type, $input);
        $problems = $this->catalogue->problems($section->type, $payload);

        if ($problems !== []) {
            return $problems;
        }

        $section->fill(['payload' => $payload, 'is_enabled' => $enabled]);

        // The changes summary names the fields that moved, not their contents:
        // an audit row is a record that somebody edited the About page, and a
        // full before-and-after of eight hundred words is not a summary.
        $this->audit->record('content.section.updated', $section, [
            'page' => $section->page->slug,
            'type' => $section->type,
            'fields' => implode(', ', array_keys(array_diff_key($payload, ['items' => null]))),
            'enabled' => $enabled ? 'yes' : 'no',
        ]);

        $section->save();
        $this->content->flush();

        return [];
    }

    /**
     * Move a section one place up or down.
     *
     * A swap rather than a renumber: only two rows change, and a position
     * sequence with gaps in it still orders correctly, so a half-finished move
     * leaves a page that reads fine.
     */
    public function move(PageSection $section, string $direction): void
    {
        $neighbour = PageSection::query()
            ->where('content_page_id', $section->content_page_id)
            ->when($direction === 'up',
                fn ($q) => $q->where('position', '<', $section->position)->orderByDesc('position'),
                fn ($q) => $q->where('position', '>', $section->position)->orderBy('position'))
            ->first();

        if (! $neighbour) {
            return; // Already at the end. Not an error, just nothing to do.
        }

        DB::transaction(function () use ($section, $neighbour) {
            [$section->position, $neighbour->position] = [$neighbour->position, $section->position];

            $section->save();
            $neighbour->save();
        });

        $this->audit->record('content.section.moved', $section, [
            'page' => $section->page->slug,
            'type' => $section->type,
            'to' => $section->position,
        ]);

        $this->content->flush();
    }

    public function remove(PageSection $section): void
    {
        $this->audit->record('content.section.removed', $section, [
            'page' => $section->page->slug,
            'type' => $section->type,
        ]);

        $section->delete();
        $this->content->flush();
    }
}
