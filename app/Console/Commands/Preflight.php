<?php

namespace App\Console\Commands;

use App\Domain\Payments\AuthorizeNetGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WP-00L / WP-00H — the TDD §12.4 environment checklist, executable.
 *
 * Run this on HostGator the day credentials arrive. It reports observed values
 * rather than ticks, because "PHP OK" is what hides a 5.6 install. Exits
 * non-zero if any required check fails, so it can gate a deploy script.
 *
 * The checks are ordered by how expensive the failure is to discover late:
 * outbound HTTPS first (a block there invalidates the payment architecture,
 * R-14), then the PHP CLI path (a wrong one silently kills every scheduled
 * job, R-5), then everything else.
 */
class Preflight extends Command
{
    protected $signature = 'hupm:preflight {--write= : Write a markdown report to this path}';

    protected $description = 'Validate the runtime environment against the TDD §12.4 checklist';

    /** Extensions the application genuinely uses. Keep this list honest. */
    private const REQUIRED_EXTENSIONS = [
        'bcmath', 'ctype', 'curl', 'fileinfo', 'intl', 'mbstring',
        'openssl', 'pdo_mysql', 'tokenizer', 'xml', 'zip',
    ];

    /**
     * Hosts the application must reach. A block has no shared-hosting workaround,
     * so severity is graded by what the failure costs: losing the gateway or email
     * kills the product, losing weather.gov disables one Track B feature (WP-21).
     *
     * @var array<string, array{why:string, required:bool}>
     */
    private const OUTBOUND_HOSTS = [
        'api.authorize.net' => [
            'why' => 'Payment gateway (WP-13) — no workaround if blocked',
            'required' => true,
        ],
        // [D-17] Resend, not Postmark/SendGrid. The constraint the specs were
        // expressing is "HTTPS API, not SMTP" — this host blocks the SMTP ports.
        'api.resend.com' => [
            'why' => 'Transactional email (WP-03) — SMTP ports are blocked on this host',
            'required' => true,
        ],
        'api.weather.gov' => [
            'why' => 'Weather alerts (WP-21 only) — degrades that feature, does not block go-live',
            'required' => false,
        ],
    ];

    /** @var list<array{name:string,observed:string,ok:bool,required:bool}> */
    private array $results = [];

    public function handle(): int
    {
        $this->components->info('HUPM preflight — TDD §12.4');

        $this->checkPhp();
        $this->checkExtensions();
        $this->checkIni();
        $this->checkDatabase();
        $this->checkRuntimeDrivers();
        $this->checkFilesystem();
        $this->checkAssets();
        $this->checkOutbound();
        $this->checkGateway();
        $this->checkScheduler();

        $this->render();

        if ($path = $this->option('write')) {
            file_put_contents($path, $this->markdown());
            $this->components->info("Report written to {$path}");
        }

        $failed = array_filter($this->results, fn ($r) => $r['required'] && ! $r['ok']);

        if ($failed !== []) {
            $this->newLine();
            $this->components->error(count($failed).' required check(s) failed. Do not proceed to WP-01 / deploy.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('All required checks passed.');

        return self::SUCCESS;
    }

    private function record(string $name, string $observed, bool $ok, bool $required = true): void
    {
        $this->results[] = compact('name', 'observed', 'ok', 'required');
    }

    private function checkPhp(): void
    {
        $this->record(
            'PHP version',
            PHP_VERSION.' ('.PHP_SAPI.')',
            version_compare(PHP_VERSION, '8.2.0', '>='),
        );

        // The single most common shared-hosting failure: cron invokes bare `php`,
        // which resolves to a different (often ancient) binary than the web SAPI.
        $this->record(
            'PHP CLI binary path',
            PHP_BINARY ?: 'unknown',
            PHP_BINARY !== '',
        );

        $this->record('PHP CLI is absolute', PHP_BINARY, str_starts_with(PHP_BINARY, '/') || preg_match('/^[A-Za-z]:/', PHP_BINARY) === 1);
    }

    private function checkExtensions(): void
    {
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $version = $loaded ? (phpversion($ext) ?: 'loaded') : 'NOT LOADED';
            $this->record("ext-{$ext}", $version, $loaded);
        }
    }

    private function checkIni(): void
    {
        $this->record('proc_open available', function_exists('proc_open') ? 'yes' : 'no — Composer cannot run on this host', function_exists('proc_open'), required: false);

        $memory = ini_get('memory_limit');
        $bytes = $this->toBytes($memory);
        $this->record('memory_limit', (string) $memory, $bytes === -1 || $bytes >= 128 * 1024 * 1024);

        $this->record('max_execution_time', (string) ini_get('max_execution_time'), true, required: false);
        $this->record('upload_max_filesize', (string) ini_get('upload_max_filesize'), $this->toBytes(ini_get('upload_max_filesize')) >= 20 * 1024 * 1024);
        $this->record('post_max_size', (string) ini_get('post_max_size'), $this->toBytes(ini_get('post_max_size')) >= 20 * 1024 * 1024);
    }

    private function checkDatabase(): void
    {
        try {
            $version = DB::selectOne('select version() as v')->v ?? 'unknown';
            // MySQL 8.0+ is required: the leases.active_unit_key generated column
            // (D-03) and several JSON columns do not exist below it.
            $this->record('MySQL version', $version, version_compare($version, '8.0.0', '>='));

            DB::statement('create table if not exists hupm_preflight_probe (id int primary key)');
            DB::statement('drop table hupm_preflight_probe');
            $this->record('MySQL DDL privileges', 'create/drop table succeeded', true);
        } catch (Throwable $e) {
            $this->record('MySQL connectivity', 'FAILED: '.$e->getMessage(), false);
        }
    }

    /**
     * The mail transport, and the webhook secret it makes mandatory.
     *
     * resend/resend-laravel registers POST /resend/webhook unconditionally and
     * verifies signatures only when `resend.webhook.secret` is set — a blank
     * secret disables the check, not the route. That fails open, so an empty
     * secret is treated here as a hard failure rather than a warning.
     */
    private function checkRuntimeDrivers(): void
    {
        // AC-AUTH-07 deletes session rows to invalidate other sessions after a
        // password reset. That only works on the database driver — with `file`
        // there is nothing to delete and the guarantee silently disappears.
        $sessionDriver = (string) config('session.driver');
        $this->record('session driver', $sessionDriver, $sessionDriver === 'database');

        $queueDriver = (string) config('queue.default');
        $this->record('queue driver', $queueDriver, $queueDriver === 'database');

        $cacheDriver = (string) config('cache.default');
        $this->record('cache driver', $cacheDriver, $cacheDriver === 'database');

        $mailer = config('mail.default');
        $this->record('mail transport', (string) $mailer, true, required: false);

        if ($mailer !== 'resend') {
            return;
        }

        $apiKey = (string) config('services.resend.key');
        $this->record('RESEND_API_KEY set', $apiKey === '' ? 'MISSING' : 'present', $apiKey !== '');

        $secret = (string) config('resend.webhook.secret');
        $this->record(
            'RESEND_WEBHOOK_SECRET set',
            $secret === ''
                ? 'MISSING — the package leaves POST /resend/webhook unauthenticated'
                : 'present',
            $secret !== '',
        );
    }

    private function checkFilesystem(): void
    {
        foreach (['storage/framework', 'storage/logs', 'bootstrap/cache'] as $relative) {
            $path = base_path($relative);
            $this->record("writable {$relative}", is_writable($path) ? 'yes' : 'NO', is_writable($path));
        }

        // Documents live outside the web root and are served only through an
        // authenticated controller (TDD §2). Confirm the public dir is not the app root.
        $this->record(
            'public/ is a subdirectory of the app',
            public_path(),
            str_starts_with(public_path(), base_path()) && public_path() !== base_path(),
        );
    }

    /**
     * The compiled front end actually reached the server.  [D-14]
     *
     * `public/build` is gitignored -- committing build output makes every
     * branch conflict on a hashed filename -- and there is no Node here to
     * rebuild it. So the assets arrive by upload, as a separate act from
     * everything else, and a deploy that forgets them ships a site with no CSS
     * and no JavaScript.
     *
     * That failure is quiet in the worst way: the HTML is correct, every route
     * answers 200, the logs are clean, and the tenant portal is an unstyled
     * column of links. Nothing in the framework notices, because as far as
     * Laravel is concerned the page rendered.
     *
     * Checked here rather than trusted to a deploy checklist because a
     * checklist is a person remembering.
     */
    private function checkAssets(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->record('Vite build present', 'MISSING public/build/manifest.json', false);

            return;
        }

        /** @var array<string, array{file?: string}> $entries */
        $entries = json_decode((string) file_get_contents($manifest), true) ?: [];

        $missing = [];

        foreach ($entries as $entry) {
            if (isset($entry['file']) && ! is_file(public_path('build/'.$entry['file']))) {
                $missing[] = $entry['file'];
            }
        }

        // A manifest listing files that are not there is the signature of a
        // partial upload -- an FTP client that stopped, or a resumed transfer
        // that skipped the large chunks.
        $this->record(
            'Vite build present',
            $missing === []
                ? count($entries).' entries, all files present'
                : count($missing).' file(s) named in the manifest are missing: '.implode(', ', array_slice($missing, 0, 3)),
            $missing === [],
        );

        /*
         | Left behind by `npm run dev`. If this file reaches the server, every
         | @vite call points the browser at http://localhost:5173 -- so the site
         | has no assets at all, for everyone except whoever happens to be
         | running a dev server on their own machine, where it looks perfect.
         |
         | Required off the developer's machine only. Locally its presence just
         | means `npm run dev` is running, which is the normal state and not a
         | finding; on the host APP_ENV is production and it is a broken deploy.
        */
        $hot = is_file(public_path('hot'));

        $this->record(
            'no stale public/hot',
            $hot
                ? ($this->getLaravel()->isLocal() ? 'present (dev server running — fine locally)' : 'PRESENT — delete it')
                : 'absent',
            ! $hot,
            required: ! $this->getLaravel()->isLocal(),
        );
    }

    private function checkOutbound(): void
    {
        foreach (self::OUTBOUND_HOSTS as $host => $spec) {
            try {
                $response = Http::timeout(10)->connectTimeout(10)
                    ->withHeaders(['User-Agent' => 'HUPM-preflight/1.0'])
                    ->get("https://{$host}");
                // Any HTTP status proves the TLS connection succeeded; 403/404 is fine.
                $this->record("outbound https://{$host}", 'HTTP '.$response->status().' — '.$spec['why'], true, $spec['required']);
            } catch (Throwable $e) {
                $this->record("outbound https://{$host}", 'UNREACHABLE — '.$spec['why'], false, $spec['required']);
            }
        }
    }

    /**
     * Are the Authorize.Net credentials real?  [WP-13]
     *
     * Reachability is not the same as usable. `authenticateTestRequest` is the
     * cheapest call that proves the API Login ID and Transaction Key actually
     * work — it moves no money and creates nothing.
     *
     * The Signature Key is checked separately because it is a different value
     * that people routinely assume is the Transaction Key, and without it the
     * webhook endpoint refuses everything.
     */
    private function checkGateway(): void
    {
        $gateway = app(AuthorizeNetGateway::class);

        if (! $gateway->isConfigured()) {
            $this->record(
                'Authorize.Net credentials',
                'NOT SET - online payments are off until AUTHORIZE_NET_LOGIN_ID and _TRANSACTION_KEY are present',
                false,
            );

            return;
        }

        try {
            $ok = $gateway->authenticates();
            $this->record(
                'Authorize.Net credentials ('.$gateway->environment().')',
                $ok ? 'accepted' : 'REJECTED - check the API Login ID and Transaction Key',
                $ok,
            );
        } catch (Throwable $e) {
            $this->record('Authorize.Net credentials', 'UNREACHABLE - '.$e->getMessage(), false);
        }

        $this->record(
            'Authorize.Net signature key',
            config('services.authorize_net.signature_key')
                ? 'set - the webhook endpoint can verify events'
                : 'NOT SET - the webhook endpoint will refuse every request',
            (bool) config('services.authorize_net.signature_key'),
        );

        // Go-live gate, not a local one: sandbox credentials on the live host
        // means tenants pay into a test account and no money ever arrives.
        $this->record(
            'Authorize.Net environment',
            $gateway->environment().(app()->environment('production') && $gateway->environment() === 'sandbox'
                ? ' - SANDBOX ON A PRODUCTION HOST'
                : ''),
            ! (app()->environment('production') && $gateway->environment() === 'sandbox'),
        );
    }

    private function checkScheduler(): void
    {
        // Cannot prove cron from inside PHP. Report the exact line to install and
        // let WP-00H verify by observed effect.
        $this->line('');
        $this->components->twoColumnDetail(
            '<fg=yellow>cron (verify by observed effect, not by crontab listing)</>',
            '',
        );
        $this->line('  * * * * * '.PHP_BINARY.' '.base_path('artisan').' schedule:run >> /dev/null 2>&1');
    }

    private function render(): void
    {
        $this->newLine();

        foreach ($this->results as $r) {
            $status = $r['ok']
                ? '<fg=green>PASS</>'
                : ($r['required'] ? '<fg=red>FAIL</>' : '<fg=yellow>WARN</>');

            $this->components->twoColumnDetail($r['name'], $r['observed'].' '.$status);
        }
    }

    private function markdown(): string
    {
        $lines = [
            '# Environment validation — TDD §12.4',
            '',
            'Generated by `php artisan hupm:preflight`.',
            '',
            '| Check | Observed | Result |',
            '|---|---|---|',
        ];

        foreach ($this->results as $r) {
            $status = $r['ok'] ? 'PASS' : ($r['required'] ? '**FAIL**' : 'WARN');
            $lines[] = "| {$r['name']} | `{$r['observed']}` | {$status} |";
        }

        $lines[] = '';
        $lines[] = '## Cron entry to install';
        $lines[] = '';
        $lines[] = '```';
        $lines[] = '* * * * * '.PHP_BINARY.' '.base_path('artisan').' schedule:run >> /dev/null 2>&1';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'Cron must be proven by observed effect (a job_runs row), not by a crontab listing.';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function toBytes(string|false $value): int
    {
        if ($value === false || $value === '') {
            return 0;
        }

        $value = trim($value);

        if ($value === '-1') {
            return -1;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
