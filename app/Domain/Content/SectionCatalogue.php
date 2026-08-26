<?php

namespace App\Domain\Content;

use App\Domain\Settings\SettingsCatalogue;
use App\Support\HtmlSanitiser;

/**
 * What a section type is, and what the application will accept in one.
 *
 * The same idea as {@see SettingsCatalogue}, for the same
 * reason: **one description drives both the admin form and the validator**, so
 * the form cannot offer a field the validator would reject, and a hand-crafted
 * post cannot set one the form would not have shown.
 *
 * A type not described here cannot be created, and a field not described here
 * is dropped rather than stored. Section types are a small, known set; anything
 * else arriving from a form is a bug or an attack, not a new kind of content.
 *
 * **Nothing here can break the page.** Fields are typed values, never markup —
 * the only field kind that accepts HTML is `rich`, and that goes through
 * {@see HtmlSanitiser} on the way in, whose allowlist carries no `img`, no
 * `class` and no `style`. An admin can change every word on the public site and
 * cannot change its layout, which is the whole point of a section builder over
 * a box of HTML.
 */
class SectionCatalogue
{
    /** Repeatable groups are capped so a page cannot become a wall. */
    private const MAX_ITEMS = 8;

    public function __construct(private readonly HtmlSanitiser $sanitiser) {}

    /**
     * Every section type.
     *
     * `fields` are single values. `items` describes a repeatable group, which
     * the editor renders as a numbered list of sub-forms.
     *
     * @return array<string, array{
     *     label: string,
     *     blurb: string,
     *     fields: array<string, array{label: string, input: string, max?: int, help?: string}>,
     *     items?: array{label: string, min: int, max: int, fields: array<string, array{label: string, input: string, max?: int, help?: string}>},
     * }>
     */
    public function all(): array
    {
        return [
            'hero' => [
                'label' => 'Hero',
                'blurb' => 'The opening block. One per page, and it belongs first.',
                'fields' => [
                    'eyebrow' => ['label' => 'Eyebrow', 'input' => 'text', 'max' => 60,
                        'help' => 'A short line above the heading. Optional.'],
                    'heading' => ['label' => 'Heading', 'input' => 'text', 'max' => 120],
                    'body' => ['label' => 'Body', 'input' => 'textarea', 'max' => 400],
                    'primary_label' => ['label' => 'Button text', 'input' => 'text', 'max' => 40],
                    'primary_route' => ['label' => 'Button goes to', 'input' => 'route'],
                    'secondary_label' => ['label' => 'Second button text', 'input' => 'text', 'max' => 40],
                    'secondary_route' => ['label' => 'Second button goes to', 'input' => 'route'],
                ],
            ],

            'value_cards' => [
                'label' => 'Cards',
                'blurb' => 'Two to four short cards side by side.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'input' => 'text', 'max' => 120],
                    'intro' => ['label' => 'Intro', 'input' => 'textarea', 'max' => 400],
                ],
                'items' => [
                    'label' => 'Cards',
                    'min' => 1,
                    'max' => 4,
                    'fields' => [
                        'title' => ['label' => 'Title', 'input' => 'text', 'max' => 80],
                        'body' => ['label' => 'Body', 'input' => 'textarea', 'max' => 300],
                    ],
                ],
            ],

            'steps' => [
                'label' => 'Steps',
                'blurb' => 'A numbered sequence. Use it only where the order genuinely matters.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'input' => 'text', 'max' => 120],
                    'intro' => ['label' => 'Intro', 'input' => 'textarea', 'max' => 400],
                ],
                'items' => [
                    'label' => 'Steps',
                    'min' => 2,
                    'max' => self::MAX_ITEMS,
                    'fields' => [
                        'title' => ['label' => 'Step', 'input' => 'text', 'max' => 80],
                        'body' => ['label' => 'What happens', 'input' => 'textarea', 'max' => 300],
                    ],
                ],
            ],

            'stats' => [
                'label' => 'Figures',
                'blurb' => 'Two to four large numbers with a label each.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'input' => 'text', 'max' => 120],
                ],
                'items' => [
                    'label' => 'Figures',
                    'min' => 2,
                    'max' => 4,
                    'fields' => [
                        'value' => ['label' => 'Figure', 'input' => 'text', 'max' => 20,
                            'help' => 'Short. "27" or "2–5 days", not a sentence.'],
                        'label' => ['label' => 'Label', 'input' => 'text', 'max' => 60],
                        'note' => ['label' => 'Note', 'input' => 'textarea', 'max' => 200],
                    ],
                ],
            ],

            'prose' => [
                'label' => 'Text',
                'blurb' => 'A heading and some paragraphs. The only block that accepts formatting.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'input' => 'text', 'max' => 120],
                    'body' => ['label' => 'Body', 'input' => 'rich', 'max' => 8000,
                        'help' => 'Paragraphs, bold, italics, lists, sub-headings and links. '
                            .'Anything else is removed when you save.'],
                ],
            ],

            'faq' => [
                'label' => 'Questions',
                'blurb' => 'Questions that open and close. Works with JavaScript switched off.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'input' => 'text', 'max' => 120],
                ],
                'items' => [
                    'label' => 'Questions',
                    'min' => 1,
                    'max' => self::MAX_ITEMS,
                    'fields' => [
                        'question' => ['label' => 'Question', 'input' => 'text', 'max' => 160],
                        'answer' => ['label' => 'Answer', 'input' => 'textarea', 'max' => 800],
                    ],
                ],
            ],

            'cta_band' => [
                'label' => 'Call to action',
                'blurb' => 'A full-width band with one button. Usually the last block on a page.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'input' => 'text', 'max' => 120],
                    'body' => ['label' => 'Body', 'input' => 'textarea', 'max' => 300],
                    'primary_label' => ['label' => 'Button text', 'input' => 'text', 'max' => 40],
                    'primary_route' => ['label' => 'Button goes to', 'input' => 'route'],
                ],
            ],

            'link_cards' => [
                'label' => 'Links',
                'blurb' => 'Cards linking somewhere else. The only block that may leave the site.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'input' => 'text', 'max' => 120],
                    'intro' => ['label' => 'Intro', 'input' => 'textarea', 'max' => 400],
                ],
                'items' => [
                    'label' => 'Links',
                    'min' => 1,
                    'max' => self::MAX_ITEMS,
                    'fields' => [
                        'title' => ['label' => 'Title', 'input' => 'text', 'max' => 80],
                        'url' => ['label' => 'Address', 'input' => 'url', 'max' => 300],
                        'blurb' => ['label' => 'Description', 'input' => 'textarea', 'max' => 300],
                    ],
                ],
            ],

            'listings' => [
                'label' => 'Available properties',
                'blurb' => 'Homes currently advertised, one per line. Never a resident\'s address.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'input' => 'text', 'max' => 120],
                    'intro' => ['label' => 'Intro', 'input' => 'textarea', 'max' => 400],
                    'entries' => ['label' => 'Listings', 'input' => 'lines', 'max' => 4000,
                        'help' => 'One per line. Leave it empty and the page invites an enquiry '
                            .'instead of showing an empty list.'],
                    'empty_text' => ['label' => 'When there are none', 'input' => 'textarea', 'max' => 400],
                ],
            ],

            'emergency_callout' => [
                'label' => 'Emergency notice',
                'blurb' => 'The red panel pointing at the emergency page. The number comes from Settings.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'input' => 'text', 'max' => 120],
                    'body' => ['label' => 'Body', 'input' => 'textarea', 'max' => 300],
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function describe(string $type): ?array
    {
        return $this->all()[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return $this->describe($type) !== null;
    }

    /** Types an editor may add, as value => label. @return array<string, string> */
    public function options(): array
    {
        return array_map(fn (array $spec) => $spec['label'], $this->all());
    }

    /**
     * Where a button may point.
     *
     * Route **names**, never free URLs. A typo in a URL is a dead button
     * discovered by a resident; a name not in this list is refused at the point
     * somebody tries to save it. It also means no internal button can be turned
     * into a link off the site.
     *
     * @return array<string, string>
     */
    public function routeOptions(): array
    {
        return [
            'login' => 'Resident login',
            'public.home' => 'Home',
            'public.about' => 'About',
            'public.services' => 'Services',
            'public.properties' => 'Available properties',
            'public.resources' => 'Resident resources',
            'public.georgia' => 'Georgia rental information',
            'public.emergency' => 'Emergency maintenance',
            'public.contact' => 'Contact us',
        ];
    }

    /**
     * Clean a submitted payload down to what this type actually holds.
     *
     * Returns only described fields, trimmed, capped, sanitised where the field
     * accepts formatting, with blank repeatable items dropped. Anything else
     * the request carried is discarded rather than rejected — an unknown key is
     * far more often a stale form than an attack, and losing a section's whole
     * edit over one is a poor trade.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalise(string $type, array $input): array
    {
        $spec = $this->describe($type);

        if ($spec === null) {
            return [];
        }

        $clean = [];

        foreach ($spec['fields'] as $key => $field) {
            $clean[$key] = $this->value($field, $input[$key] ?? '');
        }

        if (isset($spec['items'])) {
            $rows = is_array($input['items'] ?? null) ? $input['items'] : [];
            $clean['items'] = [];

            foreach (array_slice($rows, 0, $spec['items']['max']) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $item = [];

                foreach ($spec['items']['fields'] as $key => $field) {
                    $item[$key] = $this->value($field, $row[$key] ?? '');
                }

                // An entirely blank row is somebody who added one and changed
                // their mind, not a card they meant to publish empty.
                if (implode('', $item) !== '') {
                    $clean['items'][] = $item;
                }
            }
        }

        return $clean;
    }

    /**
     * Is this payload publishable?
     *
     * Deliberately forgiving. The only things refused are an unknown type and a
     * repeatable group with fewer rows than its type needs to render — a
     * two-column card row with no cards is a broken page, whereas a missing
     * intro line is just a shorter one.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string> Problems, empty when it is fine.
     */
    public function problems(string $type, array $payload): array
    {
        $spec = $this->describe($type);

        if ($spec === null) {
            return ['That is not a section type.'];
        }

        $problems = [];

        foreach ($spec['fields'] as $key => $field) {
            if ($field['input'] === 'route' && ($payload[$key] ?? '') !== ''
                && ! array_key_exists($payload[$key], $this->routeOptions())) {
                $problems[] = "{$field['label']} is not somewhere this button can go.";
            }

            if ($field['input'] === 'url' && ($payload[$key] ?? '') !== ''
                && ! $this->isSafeUrl((string) $payload[$key])) {
                $problems[] = "{$field['label']} must be a http:// or https:// address.";
            }
        }

        if (isset($spec['items'])) {
            $count = count($payload['items'] ?? []);

            if ($count < $spec['items']['min']) {
                $problems[] = sprintf(
                    '%s needs at least %d.',
                    $spec['items']['label'],
                    $spec['items']['min'],
                );
            }

            foreach ($payload['items'] ?? [] as $row) {
                foreach ($spec['items']['fields'] as $key => $field) {
                    if ($field['input'] === 'url' && ($row[$key] ?? '') !== ''
                        && ! $this->isSafeUrl((string) $row[$key])) {
                        $problems[] = "One {$field['label']} is not a http:// or https:// address.";
                    }
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * One field's stored value.
     *
     * @param  array{label: string, input: string, max?: int, help?: string}  $field
     */
    private function value(array $field, mixed $raw): mixed
    {
        $text = is_scalar($raw) ? trim((string) $raw) : '';
        $max = $field['max'] ?? 255;

        return match ($field['input']) {
            // Sanitised here, once, on the way in — the same rule NoticeService
            // follows. What is stored is already safe, so a second renderer
            // added later cannot forget.
            'rich' => $this->sanitiser->clean(mb_substr($text, 0, $max)),

            // Normalised to \n so the renderer can split on one thing.
            'lines' => implode("\n", array_values(array_filter(array_map(
                'trim',
                preg_split('/\r\n|\r|\n/', mb_substr($text, 0, $max)) ?: [],
            ), fn (string $line) => $line !== ''))),

            default => mb_substr($text, 0, $max),
        };
    }

    /**
     * http and https only.
     *
     * An allowlist rather than a blocklist: `javascript:` is the obvious one to
     * exclude and exactly the one a blocklist misspells.
     */
    private function isSafeUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
