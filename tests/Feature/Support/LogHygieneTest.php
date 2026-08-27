<?php

use App\Domain\Payments\AuthorizeNetGateway;
use App\Exceptions\GatewayUnavailableException;
use App\Jobs\SendSystemAlerts;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Nothing personal reaches the log  [WP-31, TDD §10, I-5]
|--------------------------------------------------------------------------
|
| Two halves, and the second is the one that matters.
|
| The static half reads the source and refuses the shapes that leak by accident
| — a whole model handed to the logger, a request bag, a key named `password`.
|
| The sweep half drives the paths that actually log, on a resident with
| distinctive details, and then **reads the file that was written**. That is the
| difference between believing the call sites are careful and knowing what ended
| up on disk. A framework, a package or an exception handler can write a line
| nobody in this codebase asked for.
|
| Why it matters here rather than in general: these logs sit on shared hosting
| for fourteen days, in a directory whose protection is a `.htaccess` file, on an
| account the client's other sites share.
|
*/

uses(RefreshDatabase::class);

/** Details distinctive enough that finding them in a log is unambiguous. */
const SWEEP_SURNAME = 'Zolnerowich';
const SWEEP_EMAIL = 'zolnerowich@example.test';
const SWEEP_PHONE = '4045550731';
const SWEEP_ROUTING = '061000052';
const SWEEP_ACCOUNT = '900012345678';

beforeEach(function () {
    // A log of this test's own, so the assertions are about what these paths
    // wrote rather than whatever else is in storage/logs.
    $this->logPath = storage_path('logs/sweep-'.Str::uuid().'.log');

    config([
        'logging.default' => 'sweep',
        'logging.channels.sweep' => [
            'driver' => 'single',
            'path' => $this->logPath,
            'level' => 'debug',
        ],
    ]);

    // Forget any channel already built, so the config above is what gets used
    // rather than a driver resolved earlier in the request.
    app('log')->forgetChannel('stack');
    app('log')->forgetChannel('single');

    // Credentials, or the gateway refuses before it ever calls out and the code
    // that does the logging is never reached — which is how the first version of
    // this file passed while the leak it was written to catch was live.
    config([
        'services.authorize_net.environment' => 'sandbox',
        'services.authorize_net.login_id' => 'sweep-login',
        'services.authorize_net.transaction_key' => 'sweep-key',
    ]);

    $this->tenant = Tenant::factory()->create([
        'first_name' => 'Marguerite',
        'last_name' => SWEEP_SURNAME,
        'email' => SWEEP_EMAIL,
        'phone' => SWEEP_PHONE,
    ]);

    $this->resident = User::factory()->create([
        'role' => 'tenant',
        'tenant_id' => $this->tenant->id,
        'email' => SWEEP_EMAIL,
    ]);

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create([
            'property_id' => Property::factory()->create()->id,
        ])->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => '500.00', 'tenant_portion' => '500.00', 'ha_portion' => '0.00',
        'rent_due_day' => 1, 'grace_period_days' => 5, 'status' => 'active',
    ])->save();
});

afterEach(function () {
    if (isset($this->logPath) && file_exists($this->logPath)) {
        @unlink($this->logPath);
    }
});

/** Everything written to this test's log so far. */
function sweptLog(): string
{
    return file_exists(test()->logPath) ? file_get_contents(test()->logPath) : '';
}

/*
 |--------------------------------------------------------------------------
 | The static half — shapes that leak by accident
 |--------------------------------------------------------------------------
 */

it('WP-31 hands the logger no whole object, request bag or model', function () {
    // One careless argument defeats every careful one. A model interpolated
    // into a log line carries every column it has, including the ones added
    // after the line was written.
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        preg_match_all('/Log::(?:info|warning|error|debug|critical|notice|alert|emergency)\((.*?)\);/s',
            $source, $matches);

        foreach ($matches[1] as $call) {
            foreach ([
                '$request->all()' => 'the whole request bag',
                '->toArray()' => 'a model as an array',
                '->attributesToArray()' => 'a model as an array',
                'getTraceAsString' => 'a stack trace',
            ] as $needle => $why) {
                if (str_contains($call, $needle)) {
                    $offenders[] = $file->getFilename().' logs '.$why;
                }
            }
        }
    }

    expect($offenders)->toBe([], implode(PHP_EOL, $offenders));
});

it('WP-31 names no field in a log context that should never be in one', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        preg_match_all('/Log::(?:info|warning|error|debug|critical|notice|alert|emergency)\((.*?)\);/s',
            $source, $matches);

        foreach ($matches[1] as $call) {
            foreach ([
                'password', 'routing', 'account_number', 'accountNumber', 'card_number',
                'cvv', 'ssn', 'social_security', 'api_key', 'transaction_key', 'secret',
            ] as $forbidden) {
                if (str_contains(strtolower($call), $forbidden)) {
                    $offenders[] = $file->getFilename().' logs a `'.$forbidden.'`';
                }
            }
        }
    }

    expect($offenders)->toBe([], implode(PHP_EOL, $offenders));
});

/*
 |--------------------------------------------------------------------------
 | The sweep — what actually reached the file
 |--------------------------------------------------------------------------
 */

it('WP-31 writes nothing of a resident when a payment cannot be started', function () {
    // The richest source of accidental disclosure in the system: an exception
    // from a third party, logged with its own message and context, on a request
    // made by a signed-in resident.
    Http::fake(['apitest.authorize.net/*' => Http::response(
        "\xEF\xBB\xBF".json_encode([
            'messages' => ['resultCode' => 'Error', 'message' => [[
                'code' => 'E00027',
                // The gateway quoting details back at us is exactly how this
                // leaks. Found for real: a rejected API login id came back
                // inside an error message.
                'text' => 'The transaction was unsuccessful for account '.SWEEP_ACCOUNT
                    .' routing '.SWEEP_ROUTING.'.',
            ]]],
        ])
    )]);

    app(Settings::class)->set('company.name', 'Heads Up Enterprises');

    $this->actingAs($this->resident)->postJson('/portal/pay', [
        'lease_id' => $this->lease->id,
        'amount' => '500.00',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $log = sweptLog();

    expect($log)->not->toBe('', 'Nothing was logged, so this test proved nothing.');

    // str_contains + toBeFalse rather than not->toContain, because toContain
    // takes needles rather than a message: a second argument is a second thing
    // searched for, not an explanation of the failure. In a sweep the
    // explanation is the whole value of the test -- "something leaked" is not
    // an actionable report.
    expect(str_contains($log, SWEEP_ACCOUNT))->toBeFalse('A bank account number reached the log.')
        ->and(str_contains($log, SWEEP_ROUTING))->toBeFalse('A routing number reached the log.')
        ->and(str_contains($log, SWEEP_SURNAME))->toBeFalse('A resident surname reached the log.')
        ->and(str_contains($log, SWEEP_EMAIL))->toBeFalse('A resident email address reached the log.');
});

it('I-5 redacts a bank number the gateway quotes back at us', function () {
    /*
     | The one this sweep actually caught.
     |
     | Authorize.Net names the value it objected to, so a rejected debit comes
     | back as "unsuccessful for account 900012345678 routing 061000052" — and
     | that sentence went into the log verbatim. The call site looked careful:
     | it passed the text through a redactor. The redactor only removed *our*
     | credentials.
     |
     | Driven at the gateway rather than through the portal, because the portal
     | has validation in front of it and an earlier version of this test passed
     | while the leak was live — the request never reached the code that logs.
    */
    Http::fake(['apitest.authorize.net/*' => Http::response(
        // The escape, not the characters: Authorize.Net prefixes every response
        // with a UTF-8 BOM, and three literal bytes are what `decode()` strips.
        // Typing ï»¿ into a UTF-8 file gives six bytes, json_decode fails, and
        // the request dies as "unreadable response" before reaching the code
        // under test.
        "\xEF\xBB\xBF".json_encode(['messages' => ['resultCode' => 'Error', 'message' => [[
            'code' => 'E00027',
            'text' => 'Unsuccessful for account '.SWEEP_ACCOUNT.' routing '.SWEEP_ROUTING.'.',
        ]]]])
    )]);

    $gateway = app(AuthorizeNetGateway::class);

    $send = new ReflectionMethod($gateway, 'send');
    $send->setAccessible(true);
    $auth = new ReflectionMethod($gateway, 'authentication');
    $auth->setAccessible(true);

    try {
        $send->invoke($gateway, [
            'getHostedPaymentPageRequest' => ['merchantAuthentication' => $auth->invoke($gateway)],
        ], false);
    } catch (GatewayUnavailableException) {
        // Expected. What matters is what it wrote on the way out.
    }

    $log = sweptLog();

    expect($log)->not->toBe('', 'The rejection was not logged, so this proves nothing.');
    expect(str_contains($log, 'E00027'))
        ->toBeTrue('The reason code is what makes the line useful; keep it.');

    expect(str_contains($log, SWEEP_ACCOUNT))->toBeFalse('A bank account number reached the log.')
        ->and(str_contains($log, SWEEP_ROUTING))->toBeFalse('A routing number reached the log.');
});

it('WP-31 writes nothing of a stranger when the contact form is used', function () {
    app(Settings::class)->set('company.email', 'office@example.test');

    $this->post('/contact', [
        'name' => 'Marguerite '.SWEEP_SURNAME,
        'email' => SWEEP_EMAIL,
        'phone' => SWEEP_PHONE,
        'subject' => 'My account is '.SWEEP_ACCOUNT,
        'message' => 'Please call me on '.SWEEP_PHONE.', it is about my rent.',
        'website' => '',
        'started_at' => (string) (time() - 30),
    ])->assertSessionHasNoErrors();

    $log = sweptLog();

    // Somebody will put their own name, number and worse into a free-text
    // subject. None of it belongs in a file kept for a fortnight.
    expect($log)->not->toContain(SWEEP_SURNAME)
        ->and($log)->not->toContain(SWEEP_EMAIL)
        ->and($log)->not->toContain(SWEEP_PHONE)
        ->and($log)->not->toContain(SWEEP_ACCOUNT);
});

it('WP-31 writes no address of a resident when a webhook is refused', function () {
    $this->postJson('/webhooks/authorize-net', ['eventType' => 'net.authorize.payment.authcapture.created'])
        ->assertStatus(401);

    $log = sweptLog();

    expect($log)->not->toBe('', 'The refusal was not logged at all, which is its own problem.');
    expect($log)->not->toContain(SWEEP_SURNAME)->and($log)->not->toContain(SWEEP_EMAIL);
});

it('WP-31 writes nothing of a resident when a system alert is raised', function () {
    User::query()->delete();

    // A payment nobody matched — the alert names a payment reference, and the
    // temptation is to name the resident it belongs to as well.
    Payment::factory()->create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->tenant->id,
        'status' => Payment::STATUS_PENDING,
        'gateway' => 'authorize_net',
        'gateway_transaction_id' => '120089164113',
        'submitted_at' => now()->subDays(20),
    ]);

    app()->call([new SendSystemAlerts, 'handle']);

    $log = sweptLog();

    expect($log)->not->toBe('', 'No alert was logged, so this proved nothing.');
    expect($log)->not->toContain(SWEEP_SURNAME)
        ->and($log)->not->toContain(SWEEP_EMAIL)
        ->and($log)->not->toContain(SWEEP_PHONE);
});

/*
 |--------------------------------------------------------------------------
 | The log has to end, and it has to rotate
 |--------------------------------------------------------------------------
 */

it('TDD §10 rotates daily and keeps a fortnight', function () {
    expect(config('logging.channels.daily.driver'))->toBe('daily')
        ->and(config('logging.channels.daily.days'))->toBe(14);
});

it('TDD §10 ships an environment that actually uses the rotating channel', function () {
    // The channel being configured is not the same as it being used. `single`
    // never rotates and never prunes: laravel.log grows until the shared account
    // runs out of disk, at which point every write on the site fails, not only
    // the logging.
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('LOG_STACK=daily')
        ->and($env)->not->toContain('LOG_STACK=single');
});
