<?php

use App\Support\BusinessCalendar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

// Architecture tests need the application booted too: the schema guarantees in
// SchemaTest query the live database through the DB facade. Tests that only
// touch the filesystem (CaseSensitivityTest) are unaffected by this.
pest()->extend(TestCase::class)->in('Architecture');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Today in the COMPANY timezone, not UTC (D-07).
 *
 * `now()->toDateString()` is the UTC date, which between 8pm and midnight in
 * Georgia is *tomorrow* — so a "received today" payload built that way is
 * rejected as future-dated for four hours out of every twenty-four. The
 * product resolves every business date through BusinessCalendar; a test that
 * does not passes all afternoon and fails all evening.
 */
function businessToday(): string
{
    return app(BusinessCalendar::class)->today()->toDateString();
}

/**
 * One Authorize.Net JSON body, BOM and all.
 *
 * Lives here rather than in a test file because two of them need it, and a
 * Pest helper is a plain global function: declaring it twice is a fatal
 * redeclaration the moment both files load in one process. That does not show
 * up when you run either file on its own -- only in the full suite.
 *
 * The BOM is the point. Authorize.Net prefixes every response with one and
 * `AuthorizeNetGateway::decode()` strips it, so a fake without one exercises a
 * response shape the gateway never actually receives.
 */
function anetBody(array $payload): string
{
    return "\xEF\xBB\xBF".json_encode(array_merge([
        'messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'Successful.']]],
    ], $payload), JSON_THROW_ON_ERROR);
}
