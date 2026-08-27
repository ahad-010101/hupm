<?php

use App\Http\Requests\Auth\LoginRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The five rate limits of TDD §6.2  [WP-34]
|--------------------------------------------------------------------------
|
| **The DoD says six. TDD §6.2 lists five.** The plan's count was wrong, not
| the specification's — the table has Login, password reset, payment
| submission, contact form and authenticated general, and nothing anywhere
| names a sixth. Recorded rather than quietly rounded.
|
| Each limit is checked twice, and the second check is the one that matters:
|
|   1. **The number.** Read from the limiter itself. Behaviour alone cannot do
|      this — a test that submits six payments and expects a refusal passes
|      whether the limit is five or fifty, so it proves the limiter is wired
|      up and nothing about the figure. That is exactly how `perHour(15)` sat
|      for weeks underneath a comment that said five.
|   2. **The wiring.** Read from the router. A limiter nothing references is a
|      closure in a service provider.
|
| Where a limit is cheap to reach — login, contact — it is also driven for
| real, because a number and a middleware name still do not prove a 429 comes
| back.
*/

uses(RefreshDatabase::class);

/** The Limit a named limiter produces for a given request. */
function limitFor(string $name, ?Request $request = null): Limit
{
    $resolver = RateLimiter::limiter($name);

    expect($resolver)->not->toBeNull("No rate limiter named '{$name}' is registered.");

    $limit = $resolver($request ?? Request::create('/', 'POST'));

    return is_array($limit) ? $limit[0] : $limit;
}

/** Every middleware string on a named route, groups included. */
function middlewareOf(string $routeName): array
{
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull("No route named '{$routeName}'.");

    return $route->gatherMiddleware();
}

/*
 |--------------------------------------------------------------------------
 | 1. Login — 5 per 15 minutes, per email + IP
 |--------------------------------------------------------------------------
 */

it('TDD 6.2 allows five login attempts in fifteen minutes, not more', function () {
    $reflection = new ReflectionClass(LoginRequest::class);

    expect($reflection->getConstant('MAX_ATTEMPTS'))->toBe(5)
        ->and($reflection->getConstant('DECAY_SECONDS'))->toBe(900);
});

it('TDD 6.2 keys the login limit on the email as well as the address', function () {
    /*
     | Both halves, and the reason is that either alone is wrong. Keyed on IP
     | only, one office or one library locks out everybody behind it after five
     | wrong guesses between them. Keyed on email only, an attacker with a list
     | of addresses gets five free guesses at each from anywhere.
    */
    $request = LoginRequest::create('/login', 'POST', ['email' => 'Anselm@Example.test']);
    $request->setLaravelSession(app('session.store'));

    $key = $request->throttleKey();

    // str_contains + toBeTrue, not toContain: Pest's toContain takes NEEDLES,
    // so a second argument is another thing searched for rather than the
    // message printed on failure.
    expect(str_contains($key, 'anselm@example.test'))
        ->toBeTrue('The email is not in the throttle key.')
        ->and(str_contains($key, (string) $request->ip()))
        ->toBeTrue('The address is not in the throttle key.');
});

it('TDD 6.2 refuses the sixth login attempt', function () {
    User::factory()->create(['email' => 'anselm@example.test', 'role' => 'tenant']);

    foreach (range(1, 5) as $ignored) {
        $this->post('/login', ['email' => 'anselm@example.test', 'password' => 'not-the-password']);
    }

    $sixth = $this->post('/login', [
        'email' => 'anselm@example.test',
        'password' => 'not-the-password',
    ])->assertInvalid(['email']);

    /*
     | Read from the response, not from the session. The lockout is thrown as a
     | ValidationException, and Laravel renders that as JSON or as a redirect
     | depending on what the caller said it accepts — so a session assertion
     | passes or fails on the Accept header rather than on the behaviour.
     |
     | The refusal names the wait rather than just refusing: an error message
     | that does not say what to do next is a support call.
    */
    $message = json_decode($sixth->getContent(), true)['errors']['email'][0]
        ?? session('errors')?->first('email')
        ?? '';

    expect(str_contains($message, 'seconds'))
        ->toBeTrue('The lockout message does not tell the person how long to wait.');
});

/*
 |--------------------------------------------------------------------------
 | 2. Password reset request — 3 per hour, per email
 |--------------------------------------------------------------------------
 */

it('TDD 6.2 allows three password reset requests an hour', function () {
    expect(AppServiceProvider::PASSWORD_RESETS_PER_HOUR)->toBe(3);

    $limit = limitFor('password-reset');

    expect($limit->maxAttempts)->toBe(3)
        ->and($limit->decaySeconds)->toBe(3600);
});

it('TDD 6.2 keys the reset limit on the address, case and spacing ignored', function () {
    // Three requests for A@b.test, a@b.test and " a@b.test " are three
    // requests, not one each. Otherwise the limit is bypassed by the shift key.
    $keys = collect(['Anselm@Example.test', 'anselm@example.test', '  anselm@example.test  '])
        ->map(fn ($email) => limitFor('password-reset', Request::create('/forgot-password', 'POST', ['email' => $email]))->key)
        ->unique();

    expect($keys)->toHaveCount(1, 'The same address produces different throttle keys.');
});

it('TDD 6.2 throttles the reset route, which shipped with no limit at all', function () {
    expect(middlewareOf('password.email'))->toContain('throttle:password-reset');
});

/*
 |--------------------------------------------------------------------------
 | 3. Payment submission — 5 per hour, per tenant
 |--------------------------------------------------------------------------
 */

it('TDD 6.2 allows five payment submissions an hour, not fifteen', function () {
    // The one this review found. The constant and the comment agreed; the code
    // did not.
    expect(AppServiceProvider::PAYMENT_ATTEMPTS_PER_HOUR)->toBe(5);

    $limit = limitFor('payments');

    expect($limit->maxAttempts)->toBe(5, 'The payment limit is not five an hour.')
        ->and($limit->decaySeconds)->toBe(3600);
});

it('TDD 6.2 keys the payment limit on the tenant rather than the address', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['role' => 'tenant', 'tenant_id' => $tenant->id]);

    $request = Request::create('/portal/pay', 'POST');
    $request->setUserResolver(fn () => $user);

    // A household behind one address is one tenant; a shared library computer
    // is several. An IP key punishes the second case and misses the first.
    expect(limitFor('payments', $request)->key)->toBe('tenant:'.$tenant->id);
});

it('TDD 6.2 throttles the payment route', function () {
    expect(middlewareOf('portal.pay.store'))->toContain('throttle:payments');
});

/*
 |--------------------------------------------------------------------------
 | 4. Contact form — 3 per hour, per IP
 |--------------------------------------------------------------------------
 */

it('TDD 6.2 allows three contact messages an hour from one address', function () {
    expect(AppServiceProvider::CONTACT_MESSAGES_PER_HOUR)->toBe(3);

    $limit = limitFor('contact');

    expect($limit->maxAttempts)->toBe(3)
        ->and($limit->decaySeconds)->toBe(3600);
});

it('TDD 6.2 refuses a fourth contact message and says to telephone instead', function () {
    app(Settings::class)->set('company.email', 'office@example.test');

    $send = fn () => $this->post('/contact', [
        'name' => 'Marisol Quintanilla',
        'email' => 'marisol@example.test',
        'subject' => 'Viewing',
        'message' => 'I would like to ask about a two bedroom, if any are free this autumn.',
        'website' => '',
    ]);

    foreach (range(1, 3) as $ignored) {
        $send();
    }

    $send()->assertSessionHasErrors('message');

    /*
     | A limit that leaves somebody with no way to reach the office is not a
     | limit, it is an outage — and this is the one limit in the system that
     | can catch a real person, because an office block or a library shares an
     | address with everybody in it.
    */
    expect(str_contains(session('errors')->first('message'), 'telephone'))
        ->toBeTrue('The refusal does not offer another way to reach the office.');
});

/*
 |--------------------------------------------------------------------------
 | 5. Authenticated general — 120 per minute, per user
 |--------------------------------------------------------------------------
 */

it('TDD 6.2 allows 120 authenticated requests a minute', function () {
    expect(AppServiceProvider::AUTHENTICATED_REQUESTS_PER_MINUTE)->toBe(120);

    $limit = limitFor('authenticated');

    expect($limit->maxAttempts)->toBe(120)
        ->and($limit->decaySeconds)->toBe(60);
});

it('TDD 6.2 applies the general limit across the whole signed-in surface', function () {
    /*
     | Asserted over the router rather than over a handful of routes, because
     | the value of a backstop is that it has no gaps. This is the limit that
     | makes scraping expensive after an account is taken over — the specific
     | limits above cover submitting payments and asking for a reset, not
     | walking every ledger row and every document at two a second.
    */
    $unlimited = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('auth', $route->gatherMiddleware(), true))
        ->reject(fn ($route) => collect($route->gatherMiddleware())
            ->contains(fn ($m) => str_starts_with((string) $m, 'throttle:')))
        ->map(fn ($route) => $route->uri())
        ->values()
        ->all();

    expect($unlimited)->toBe([], 'Authenticated routes carry no rate limit: '.implode(', ', $unlimited));
});

it('TDD 6.2 keys the general limit on the user, so one busy tenant cannot spend another allowance', function () {
    $one = User::factory()->create(['role' => 'tenant']);
    $two = User::factory()->create(['role' => 'tenant']);

    $request = fn (User $user) => tap(Request::create('/portal', 'GET'))
        ->setUserResolver(fn () => $user);

    expect(limitFor('authenticated', $request($one))->key)->toBe('user:'.$one->id)
        ->and(limitFor('authenticated', $request($two))->key)->toBe('user:'.$two->id);
});

/*
 |--------------------------------------------------------------------------
 | And no sixth
 |--------------------------------------------------------------------------
 */

it('WP-34 registers no rate limiter the specification does not name', function () {
    /*
     | The reverse direction. A limiter added later without a line in TDD §6.2
     | is a policy decision made in a service provider, and the number in it
     | was chosen by whoever was in a hurry. This fails when that happens, and
     | the fix is to write it down.
    */
    // The resolved instance, not the facade: the facade is a static proxy and
    // holds no state of its own.
    $limiter = app(Illuminate\Cache\RateLimiter::class);
    $property = new ReflectionProperty($limiter, 'limiters');
    $property->setAccessible(true);
    $registered = array_keys($property->getValue($limiter));

    sort($registered);

    expect($registered)->toBe(['authenticated', 'contact', 'password-reset', 'payments']);
});
