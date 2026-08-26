<?php

namespace Database\Seeders;

use App\Domain\Content\PageContent;
use App\Models\ContentPage;
use App\Models\PageSection;
use Illuminate\Database\Seeder;

/**
 * The public site's shipped content.  [WP-36, D-27]
 *
 * Most of what follows is the copy WP-18 wrote into Blade, carried across word
 * for word. That copy was reviewed and is tested against; losing it while
 * making the pages editable would be a regression dressed as a feature.
 *
 * **Idempotent.** Pages are matched on slug, and a page that already has
 * sections is left alone — re-running this must never duplicate a page or
 * overwrite something the office has since written. Only a page with no
 * sections at all gets seeded.
 *
 * Written for Denise (PRD §7): 58, Housing Choice Voucher, Android phone,
 * rarely uses a computer. Short sentences, no jargon, nothing that assumes she
 * has read the page above.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $slug => $page) {
            $row = ContentPage::updateOrCreate(
                ['slug' => $slug],
                [
                    'title' => $page['title'],
                    'nav_label' => $page['nav_label'] ?? null,
                    'meta_description' => $page['meta'] ?? null,
                    'is_published' => true,
                    'show_in_nav' => $page['nav'] ?? false,
                    'nav_position' => $page['nav_position'] ?? 0,
                ],
            );

            // Somebody's edits are not ours to replace.
            if ($row->sections()->exists()) {
                continue;
            }

            foreach (array_values($page['sections']) as $position => [$type, $payload]) {
                PageSection::create([
                    'content_page_id' => $row->id,
                    'type' => $type,
                    'position' => $position,
                    'is_enabled' => true,
                    'payload' => $payload,
                ]);
            }
        }

        app(PageContent::class)->flush();
    }

    /** @return array<string, array<string, mixed>> */
    private function pages(): array
    {
        return [
            'home' => [
                'title' => 'Home',
                'nav_label' => 'Home',
                'nav' => true,
                'nav_position' => 10,
                'meta' => 'Property management for residents across metropolitan Atlanta. '
                    .'Pay rent, report a repair, and reach the office.',
                'sections' => [
                    ['hero', [
                        'eyebrow' => 'Metropolitan Atlanta',
                        'heading' => 'A home you can rely on, and an office you can reach',
                        'body' => 'Property management for residents across metropolitan Atlanta. '
                            .'Pay your rent, report a repair, and keep your paperwork in one place.',
                        'primary_label' => 'Resident login',
                        'primary_route' => 'login',
                        'secondary_label' => 'Emergency maintenance',
                        'secondary_route' => 'public.emergency',
                    ]],
                    ['value_cards', [
                        'heading' => 'What you can do in your account',
                        'intro' => '',
                        'items' => [
                            ['title' => 'Pay rent online',
                                'body' => 'Pay by bank transfer at any hour. Payments take two to five '
                                    .'business days to clear, and your balance updates when they do.'],
                            ['title' => 'Report a repair',
                                'body' => 'Send photographs, say when you are home, and follow the job '
                                    .'through to the day it is closed.'],
                            ['title' => 'Keep your paperwork',
                                'body' => 'Your lease, notices and signed agreements, in one place, '
                                    .'downloadable whenever you need them.'],
                        ],
                    ]],
                    ['steps', [
                        'heading' => 'How a repair gets fixed',
                        'intro' => 'Nothing here depends on you chasing us.',
                        'items' => [
                            ['title' => 'You report it',
                                'body' => 'From your account, with photographs and the times you are home.'],
                            ['title' => 'We triage it',
                                'body' => 'It gets a number and a priority the same working day.'],
                            ['title' => 'Someone is booked',
                                'body' => 'You see who is coming and when, before they arrive.'],
                            ['title' => 'You close it',
                                'body' => 'When we think it is done we ask you. It stays open until you agree.'],
                        ],
                    ]],
                    ['stats', [
                        'heading' => 'Where we work',
                        'items' => [
                            ['value' => '27', 'label' => 'Homes managed',
                                'note' => 'Across twenty-five properties in metropolitan Atlanta.'],
                            ['value' => '24/7', 'label' => 'Emergency line',
                                'note' => 'A real number, answered at every hour, for leaks and no heat.'],
                            ['value' => '2–5 days', 'label' => 'For a payment to clear',
                                'note' => 'Bank payments take that long everywhere. Your account shows '
                                    .'yours as processing until it does.'],
                        ],
                    ]],
                    ['emergency_callout', [
                        'heading' => 'Something urgent right now?',
                        'body' => 'A leak, no heat, no power or a smell of gas needs a telephone call, '
                            .'not a form. For fire or gas, call 911 first.',
                    ]],
                    ['cta_band', [
                        'heading' => 'Looking for a home?',
                        'body' => 'Availability changes constantly and we do not keep a live vacancy list '
                            .'online. See what is currently advertised, or write to us and we will tell '
                            .'you what is coming up.',
                        'primary_label' => 'Available properties',
                        'primary_route' => 'public.properties',
                    ]],
                ],
            ],

            'about' => [
                'title' => 'About us',
                'nav_label' => 'About',
                'nav' => true,
                'nav_position' => 20,
                'meta' => 'Who we are, the homes we manage, and how to reach the office.',
                'sections' => [
                    ['hero', [
                        'eyebrow' => 'About',
                        'heading' => 'We manage homes, in person',
                        'body' => 'Residential property across metropolitan Atlanta, day to day: '
                            .'collecting rent, keeping homes in repair, and answering the telephone '
                            .'when something goes wrong.',
                        'primary_label' => 'Contact us',
                        'primary_route' => 'public.contact',
                        'secondary_label' => '',
                        'secondary_route' => '',
                    ]],
                    ['value_cards', [
                        'heading' => 'How we work',
                        'intro' => '',
                        'items' => [
                            ['title' => 'Repairs are tracked, not remembered',
                                'body' => 'Every request gets a number and a written trail, from the day '
                                    .'it is reported to the day you agree it is finished. You close it, not us.'],
                            ['title' => 'Rent is on the record',
                                'body' => 'Every charge, payment and adjustment appears on your account '
                                    .'with a date. Nothing is held in somebody\'s notebook.'],
                            ['title' => 'Vouchers are routine here',
                                'body' => 'Most of the homes we manage are let to Housing Choice Voucher '
                                    .'holders. Your share is the only figure you ever have to think about.'],
                            ['title' => 'You can reach a person',
                                'body' => 'The telephone is answered during office hours, and there is a '
                                    .'number for emergencies at every other hour.'],
                        ],
                    ]],
                    ['cta_band', [
                        'heading' => 'Questions about your tenancy?',
                        'body' => 'Write to the office and we will answer. If it is urgent, telephone instead.',
                        'primary_label' => 'Contact the office',
                        'primary_route' => 'public.contact',
                    ]],
                ],
            ],

            'services' => [
                'title' => 'What we handle',
                'nav_label' => 'Services',
                'nav' => true,
                'nav_position' => 30,
                'meta' => 'Repairs, rent, your Housing Choice Voucher share, notices and documents — '
                    .'what we look after for the people living in our homes.',
                'sections' => [
                    ['hero', [
                        'eyebrow' => 'For residents',
                        'heading' => 'What we handle for you',
                        'body' => 'Everything below is part of renting with us. None of it costs extra, '
                            .'and none of it needs chasing.',
                        'primary_label' => 'Resident login',
                        'primary_route' => 'login',
                        'secondary_label' => 'Contact us',
                        'secondary_route' => 'public.contact',
                    ]],
                    ['value_cards', [
                        'heading' => 'Day to day',
                        'intro' => '',
                        'items' => [
                            ['title' => 'Repairs and maintenance',
                                'body' => 'Reported from your phone with photographs, tracked to completion, '
                                    .'and closed only when you say it is fixed.'],
                            ['title' => 'Rent and receipts',
                                'body' => 'Pay online at any hour. Every charge and payment is on your '
                                    .'account with a date, and a receipt follows every one that clears.'],
                            ['title' => 'Notices and documents',
                                'body' => 'Your lease and anything we send you, kept where you can find it '
                                    .'and download it, not in a drawer at the office.'],
                        ],
                    ]],
                    ['prose', [
                        'heading' => 'If you have a Housing Choice Voucher',
                        'body' => '<p>Most of the homes we manage are let to voucher holders, so none of '
                            .'this is unusual to us.</p><p>Your rent is split between you and the housing '
                            .'authority. <strong>You only ever see and pay your own share.</strong> The '
                            .'authority pays theirs directly, on its own schedule, and it is never added '
                            .'to your balance or counted against you if it arrives late.</p><p>If your '
                            .'income or your household changes, tell us and tell your housing authority. '
                            .'That usually changes your share, and the sooner it is recorded the smaller '
                            .'the correction.</p>',
                    ]],
                    ['steps', [
                        'heading' => 'Moving in',
                        'intro' => 'What happens between agreeing a home and getting the keys.',
                        'items' => [
                            ['title' => 'The lease',
                                'body' => 'Signed online. You read it in full before anything can be signed, '
                                    .'and you keep a copy in your account.'],
                            ['title' => 'Your account',
                                'body' => 'Set up with your own password. Everything about your tenancy '
                                    .'lives there from day one.'],
                            ['title' => 'The first payment',
                                'body' => 'We show you what is due and when. Bank payments take two to five '
                                    .'business days, so allow for it near the due date.'],
                            ['title' => 'Settling in',
                                'body' => 'Report anything that is not right in the first weeks. It is far '
                                    .'easier to put right then than at the end of a tenancy.'],
                        ],
                    ]],
                    ['faq', [
                        'heading' => 'Questions we are asked most',
                        'items' => [
                            ['question' => 'When does my payment show on my account?',
                                'answer' => 'Bank payments take two to five business days to clear. Until '
                                    .'they do, your balance still shows the full amount with the payment '
                                    .'listed as processing beneath it. That is normal and there is nothing '
                                    .'further to do.'],
                            ['question' => 'What if I cannot pay in full this month?',
                                'answer' => 'Telephone the office before the due date rather than after. An '
                                    .'arrangement agreed in advance is a very different conversation from '
                                    .'one agreed afterwards, and we would far rather have it early.'],
                            ['question' => 'Who do I call at two in the morning?',
                                'answer' => 'The emergency number, for a leak, no heat, no power or a smell '
                                    .'of gas. For fire or gas, call 911 first and tell us afterwards. '
                                    .'Anything that can safely wait until morning should go through the '
                                    .'repair form instead.'],
                            ['question' => 'Do I need a computer?',
                                'answer' => 'No. Everything works on a phone, including paying rent, '
                                    .'reporting a repair and signing a document.'],
                        ],
                    ]],
                    ['cta_band', [
                        'heading' => 'Already renting with us?',
                        'body' => 'Everything above is in your account. Signing in takes a moment.',
                        'primary_label' => 'Go to my account',
                        'primary_route' => 'login',
                    ]],
                ],
            ],

            'properties' => [
                'title' => 'Available properties',
                'nav_label' => 'Properties',
                'nav' => true,
                'nav_position' => 40,
                'meta' => 'Homes currently advertised, and how to ask about what is coming up.',
                'sections' => [
                    ['hero', [
                        'eyebrow' => 'Availability',
                        'heading' => 'Available properties',
                        'body' => 'Availability changes quickly, so please confirm with the office before '
                            .'making arrangements.',
                        'primary_label' => 'Ask about availability',
                        'primary_route' => 'public.contact',
                        'secondary_label' => '',
                        'secondary_route' => '',
                    ]],
                    ['listings', [
                        'heading' => 'Currently advertised',
                        'intro' => 'Please confirm with the office before making arrangements.',
                        'entries' => '',
                        'empty_text' => 'We have nothing advertised at the moment. Homes here turn over '
                            .'steadily and are often taken before they are advertised at all. Write to us '
                            .'with what you are looking for and we will tell you what is coming up.',
                    ]],
                    ['prose', [
                        'heading' => 'Housing Choice Vouchers',
                        'body' => '<p>We let to voucher holders as a matter of course. Tell us which '
                            .'housing authority issued yours when you write, and we will tell you what we '
                            .'have that qualifies.</p>',
                    ]],
                    ['cta_band', [
                        'heading' => 'Tell us what you are looking for',
                        'body' => 'How many bedrooms you need, which part of the city, and whether you '
                            .'hold a voucher. We will tell you what is coming up.',
                        'primary_label' => 'Contact us',
                        'primary_route' => 'public.contact',
                    ]],
                ],
            ],

            'resources' => [
                'title' => 'Resident resources',
                'nav_label' => 'Resources',
                'nav' => true,
                'nav_position' => 50,
                'meta' => 'Paying rent, reporting repairs, your rights as a Georgia tenant, '
                    .'and who to contact.',
                'sections' => [
                    ['hero', [
                        'eyebrow' => 'Residents',
                        'heading' => 'Resident resources',
                        'body' => 'The things residents ask us about most often.',
                        'primary_label' => 'Go to my account',
                        'primary_route' => 'login',
                        'secondary_label' => 'Contact the office',
                        'secondary_route' => 'public.contact',
                    ]],
                    ['emergency_callout', [
                        'heading' => 'Emergency maintenance',
                        'body' => 'A leak, no heat, no power or a lockout. For fire or gas, call 911 first.',
                    ]],
                    ['prose', [
                        'heading' => 'Paying rent',
                        'body' => '<p>Rent is paid online from your account, by bank transfer. You can pay '
                            .'at any hour, including weekends.</p><p><strong>Bank payments take two to five '
                            .'business days to clear.</strong> Until they do, your balance still shows the '
                            .'full amount with the payment listed as processing beneath it. That is normal, '
                            .'and there is nothing further to do. If you are paying close to the due date, '
                            .'allow for it.</p><p>If you cannot pay in full, telephone the office before the '
                            .'due date rather than after. An arrangement agreed in advance is a very '
                            .'different conversation from one agreed afterwards.</p>',
                    ]],
                    ['prose', [
                        'heading' => 'Repairs that are not urgent',
                        'body' => '<p>Report these from your account rather than by telephone. The form asks '
                            .'for photographs, when the problem started, when you are home, and whether we '
                            .'may let ourselves in — which is most of what a contractor needs to arrive '
                            .'prepared.</p><p>You will get a number, and you will see the job move through '
                            .'triage, scheduling and completion. <strong>You close it, not us</strong>: when '
                            .'we think it is finished we ask you to confirm, and it stays open until you '
                            .'do.</p>',
                    ]],
                    ['prose', [
                        'heading' => 'If your circumstances change',
                        'body' => '<p>Tell us and tell your housing authority. A change in income or '
                            .'household usually changes your share of the rent, and the sooner it is '
                            .'recorded the smaller the correction.</p><p>Keep your telephone number and '
                            .'email up to date in your account. Notices, receipts and repair updates all go '
                            .'to whatever is there.</p>',
                    ]],
                    ['cta_band', [
                        'heading' => 'Your rights as a Georgia tenant',
                        'body' => 'Where to read the rules for yourself, and where to get help that is not '
                            .'from us.',
                        'primary_label' => 'Georgia rental information',
                        'primary_route' => 'public.georgia',
                    ]],
                ],
            ],

            'georgia' => [
                'title' => 'Georgia rental information',
                'nav' => false,
                'meta' => 'Official Georgia tenant-landlord resources, the DCA handbook, and where to '
                    .'get independent advice.',
                'sections' => [
                    ['hero', [
                        'eyebrow' => 'Georgia',
                        'heading' => 'Georgia rental information',
                        'body' => 'Where to read the rules for yourself, and where to get help that is '
                            .'not from us.',
                        'primary_label' => '',
                        'primary_route' => '',
                        'secondary_label' => '',
                        'secondary_route' => '',
                    ]],
                    ['prose', [
                        'heading' => '',
                        'body' => '<p>Nothing on this page is legal advice, and none of it is our summary '
                            .'of the law. These are the state\'s own materials. If your situation is '
                            .'serious, speak to one of the independent services below rather than to us.</p>',
                    ]],
                    ['link_cards', [
                        'heading' => 'Official Georgia resources',
                        'intro' => '',
                        'items' => [
                            ['title' => 'Georgia Department of Community Affairs',
                                'url' => 'https://www.dca.ga.gov/',
                                'blurb' => 'The state housing agency. Administers Housing Choice Vouchers '
                                    .'and publishes the tenant-landlord handbook.'],
                            ['title' => 'Georgia Landlord-Tenant Handbook',
                                'url' => 'https://www.dca.ga.gov/safe-affordable-housing/rental-housing-development/georgia-landlord-tenant-handbook',
                                'blurb' => 'The state\'s own guide to deposits, repairs, notice periods '
                                    .'and eviction.'],
                            ['title' => 'Housing Choice Voucher programme',
                                'url' => 'https://www.dca.ga.gov/safe-affordable-housing/rental-assistance/housing-choice-voucher-program-formerly-known-section-8',
                                'blurb' => 'How the voucher works, what it covers, and what changes your '
                                    .'share of the rent.'],
                            ['title' => 'HUD — Georgia',
                                'url' => 'https://www.hud.gov/states/georgia/renting',
                                'blurb' => 'Federal housing information, including fair-housing complaints.'],
                        ],
                    ]],
                    ['link_cards', [
                        'heading' => 'Independent help',
                        'intro' => 'None of these is us, and none of them reports to us.',
                        'items' => [
                            ['title' => 'Georgia Legal Services Program',
                                'url' => 'https://www.glsp.org/',
                                'blurb' => 'Free civil legal help outside metropolitan Atlanta, for those '
                                    .'who qualify.'],
                            ['title' => 'Atlanta Legal Aid Society',
                                'url' => 'https://atlantalegalaid.org/',
                                'blurb' => 'Free civil legal help in Fulton, DeKalb, Clayton, Cobb and '
                                    .'Gwinnett.'],
                            ['title' => 'Georgia Fair Housing',
                                'url' => 'https://www.hud.gov/program_offices/fair_housing_equal_opp',
                                'blurb' => 'If you believe you have been discriminated against.'],
                            ['title' => 'Georgia 211',
                                'url' => 'https://www.211.org/',
                                'blurb' => 'Rent, utility and food assistance, by telephone or online.'],
                        ],
                    ]],
                    ['prose', [
                        'heading' => 'If you are struggling to pay',
                        'body' => '<p>Telephone us before the due date. We would far rather agree an '
                            .'arrangement in writing than chase arrears, and an arrangement made in advance '
                            .'is on much better terms than one made afterwards. Georgia 211 above can also '
                            .'point you at rent assistance you may be entitled to.</p>',
                    ]],
                ],
            ],

            'contact' => [
                'title' => 'Contact us',
                'nav_label' => 'Contact',
                'nav' => true,
                'nav_position' => 60,
                'meta' => 'Telephone, write to, or email the office.',
                // Copy above the form only. The form itself is code: its
                // honeypot, timing trap and no-office-email fallback were
                // reviewed as a piece, and none of them is content.
                'sections' => [
                    ['hero', [
                        'eyebrow' => 'Contact',
                        'heading' => 'Contact us',
                        'body' => 'Telephone during office hours, write to us at any time, or use the '
                            .'form below. If it is urgent, please telephone rather than write.',
                        'primary_label' => '',
                        'primary_route' => '',
                        'secondary_label' => '',
                        'secondary_route' => '',
                    ]],
                ],
            ],

            'privacy' => [
                'title' => 'Privacy Policy',
                'nav' => false,
                'sections' => [
                    ['prose', [
                        'heading' => 'Privacy Policy',
                        'body' => $this->legalHolding(),
                    ]],
                ],
            ],

            'terms' => [
                'title' => 'Terms of Use',
                'nav' => false,
                'sections' => [
                    ['prose', [
                        'heading' => 'Terms of Use',
                        'body' => $this->legalHolding(),
                    ]],
                ],
            ],
        ];
    }

    /**
     * The holding text for Privacy and Terms.
     *
     * Not invented policy. A made-up privacy policy is a promise nobody has
     * checked we keep — and now that the page is editable, the client can
     * publish the real one without a deploy, which is the point.
     */
    private function legalHolding(): string
    {
        return '<p>This document is being prepared and is not published yet.</p>'
            .'<p>In the meantime, if you have a question about how we hold your information or about '
            .'the terms you are dealing with us under, write to the office and we will answer it '
            .'directly.</p>';
    }
}
