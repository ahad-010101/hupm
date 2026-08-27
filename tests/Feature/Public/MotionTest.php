<?php

use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Motion on the public site  [WP-36, D-05, UI §9]
|--------------------------------------------------------------------------
|
| The marketing site moves. Every bit of it is CSS, because the public site
| loads no JavaScript and that is not negotiable.
|
| The tests below guard one rule above all others: **motion may never be what
| makes content visible.** The failure mode is specific and severe — an element
| left at `opacity: 0` waiting for an animation that a browser will not run is a
| blank page whose words are technically present. On a site whose whole reason
| for being Blade is that somebody may be reading it in an emergency, that is
| not a cosmetic bug.
|
*/

uses(RefreshDatabase::class);

/** The authored stylesheet, which is what the assertions below are about. */
function motionCss(): string
{
    return file_get_contents(resource_path('css/app.css'));
}

/**
 * The same stylesheet with its comments removed.
 *
 * The comments in that file explain the rules, which means they quote them —
 * and a test that string-matches prose is a test about how something is
 * described rather than what it does. Everything asserting *absence* reads
 * this instead.
 */
function motionRules(): string
{
    return preg_replace('#/\*.*?\*/#s', '', motionCss());
}

/*
 |--------------------------------------------------------------------------
 | Content is never hidden by an animation that does not run
 |--------------------------------------------------------------------------
 */

it('hides an element only inside the reduced-motion opt-in', function () {
    $css = motionRules();

    // Everything from the opt-in onwards. Anything that sets opacity to zero
    // must be in here — outside it, a browser that cannot animate would be left
    // showing nothing at all.
    $optIn = strpos($css, '@media (prefers-reduced-motion: no-preference)');

    expect($optIn)->not->toBeFalse('The reduced-motion opt-in is missing.');

    $before = substr($css, 0, $optIn);

    // The keyframes themselves are declared before the opt-in, which is fine —
    // a keyframe hides nothing until something references it. What must not
    // appear before it is a *rule* that applies an animation or an opacity.
    $rulesBefore = preg_replace('/@keyframes\s+[\w-]+\s*\{(?:[^{}]|\{[^{}]*\})*\}/', '', $before);

    expect($rulesBefore)->not->toContain('opacity: 0')
        ->and($rulesBefore)->not->toContain('animation:')
        ->and($rulesBefore)->not->toContain('visibility: hidden');
});

it('puts the scroll reveal behind a support check as well', function () {
    $css = motionCss();

    // Chrome and Edge run scroll-driven animations today; Firefox and Safari do
    // not. Without the @supports guard those browsers would apply the animation
    // shorthand, land on the `from` frame, and never leave it.
    expect($css)->toContain('@supports (animation-timeline: view())');

    $supports = strpos($css, '@supports (animation-timeline: view())');
    $reveal = strpos($css, '.hupm-reveal {');

    expect($reveal)->toBeGreaterThan($supports);
});

it('honours a request for no motion by giving none of it', function () {
    // Not "less". The setting is a request, and vestibular disorders are common.
    expect(motionCss())->toContain('@media (prefers-reduced-motion: reduce)');
});

/*
 |--------------------------------------------------------------------------
 | It is still a site that loads no JavaScript
 |--------------------------------------------------------------------------
 */

it('D-05 adds no script to any public page', function (string $route) {
    $this->seed(ContentSeeder::class);

    $html = $this->get($route)->assertOk()->getContent();

    // The obvious way to build a scroll reveal is an IntersectionObserver. This
    // is what says no.
    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('onscroll')
        ->and($html)->not->toContain('IntersectionObserver');
})->with([
    '/', '/about', '/services', '/properties', '/resources',
    '/georgia-rental-info', '/emergency-maintenance', '/contact', '/privacy', '/terms',
]);

it('leaves the emergency page completely still', function () {
    // It is the page D-05 exists for. Somebody opens it standing in water, and
    // a fade-in is the last thing they need.
    $this->seed(ContentSeeder::class);

    $html = $this->get('/emergency-maintenance')->assertOk()->getContent();

    expect($html)->not->toContain('hupm-enter')
        ->and($html)->not->toContain('hupm-reveal');
});

/*
 |--------------------------------------------------------------------------
 | Where it is applied
 |--------------------------------------------------------------------------
 */

it('animates the hero on arrival and the rest on scroll', function () {
    $this->seed(ContentSeeder::class);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('hupm-enter')
        ->and($html)->toContain('hupm-reveal');
});

it('staggers a row of cards rather than dropping them in together', function () {
    $this->seed(ContentSeeder::class);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('hupm-reveal-1')
        ->and($html)->toContain('hupm-reveal-2')
        ->and($html)->toContain('hupm-reveal-3');
});

it('keeps the stagger classes out of Tailwind’s reach, since they are interpolated', function () {
    $css = motionRules();

    // `hupm-reveal-{{ $index }}` in a Blade template is only safe because these
    // rules sit outside @layer, where Tailwind's purge cannot remove them. The
    // same interpolation on a Tailwind utility silently produces a class that is
    // not in the built CSS — a bug this project has hit once already.
    $reveal = strpos($css, '.hupm-reveal-1');
    $layer = strpos($css, '@layer');

    expect($reveal)->not->toBeFalse();
    expect($layer)->toBeFalse('Motion rules moved into a layer; the interpolated stagger classes will be purged.');
});

it('ships the motion rules in the built stylesheet', function () {
    // The authored file is one thing; what the host serves is another. Vite
    // builds locally and the compiled asset is uploaded (TDD §2), so a rule that
    // did not survive the build is a rule nobody will notice is missing.
    $manifest = public_path('build/manifest.json');

    if (! file_exists($manifest)) {
        $this->markTestSkipped('No build present; run npm run build.');
    }

    $entry = json_decode(file_get_contents($manifest), true)['resources/css/app.css'] ?? null;

    expect($entry)->not->toBeNull('app.css is not in the Vite manifest.');

    $built = file_get_contents(public_path('build/'.$entry['file']));

    expect($built)->toContain('hupm-rise')
        ->and($built)->toContain('prefers-reduced-motion')
        ->and($built)->toContain('animation-timeline');
});
