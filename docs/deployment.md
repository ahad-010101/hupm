# Deploying HUPM to HostGator shared hosting

**Spec:** TDD §12. **Work packages:** WP-00H (validate), WP-35 (go-live).

This is the runbook. It assumes nothing has been deployed yet and that nobody has
logged into the hosting account.

> **Read this first.** The hosting account has never been validated. WP-00 was split
> at project start precisely because HostGator access was unavailable: WP-00L ran
> locally, WP-00H — the checklist below — was deferred, **and it gates M1.** Two of
> its checks can change the architecture if they fail, so Stage 2 runs *before*
> anything is uploaded for real.
>
> **No real tenant data enters any environment until Stage 2 passes.** Not a
> spreadsheet of names, not one opening balance. Stage 3 onward runs on staging.

---

## What you need before starting

| Thing | Where it comes from | Blocks |
|---|---|---|
| cPanel login (URL, user, password) | HostGator welcome email | everything |
| SSH access enabled | HostGator support ticket, or cPanel → Terminal | Stage 2 |
| The domain, pointed at the account | client | Stage 4 |
| Resend API key + verified sending domain | [resend.com](https://resend.com) — needs DNS records on the client's domain | WP-03 |
| Authorize.Net **production** credentials + signature key | client's merchant account | WP-35 only — staging uses sandbox |
| A value for `HEALTH_TOKEN` | you: `php -r "echo bin2hex(random_bytes(32));"` | Stage 4 |

**Do not ask for the Authorize.Net production keys yet.** Staging runs on sandbox and
must reconcile a sandbox settlement successfully first (TDD §12.5).

---

## Stage 1 — Build locally

There is no Node on the server. This is not a preference; shared cPanel has no Node
runtime, so the front end is compiled here and uploaded as files.

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

Then **delete `public/hot` if it exists.** It is written by `npm run dev` and, if it
reaches the server, every `@vite` call points visitors' browsers at
`http://localhost:5173`. The site then has no CSS and no JavaScript for everyone
except whoever is running a dev server on their own machine — where it looks perfect.

```bash
rm -f public/hot
```

`public/build/` is gitignored on purpose (committing hashed filenames makes every
branch conflict), so **it will not arrive with a `git pull`.** It has to be uploaded
as its own step, every time the front end changes. `hupm:preflight` checks for it in
Stage 2, because a checklist is a person remembering.

---

## Stage 2 — Validate the host  ← WP-00H, and the gate

Nothing below this line matters if this stage fails.

**2.1** In cPanel → **MultiPHP Manager**, set PHP to **8.2 or newer** for the domain.
In **MultiPHP INI Editor** set:

```ini
memory_limit = 256M
upload_max_filesize = 25M
post_max_size = 30M
max_execution_time = 120
```

**2.2** Find the **absolute PHP CLI path**. This is the single most common
shared-hosting failure, and it fails silently — cron runs, exits, writes nothing.

```bash
which php
readlink -f $(which php)
```

Typically `/opt/cpanel/ea-php82/root/usr/bin/php`. **Write down what it actually
says.** Never use bare `php` in a cron entry.

**2.3** Create the database in cPanel → **MySQL Databases**. Create a user, grant it
`ALL PRIVILEGES` *for now* (migrations need DDL), and **disable remote MySQL access**.
Names are prefixed with the account: `cpaneluser_hupm`.

**2.4** Upload the tree once — this can be a throwaway copy — and run:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php artisan hupm:preflight --write=preflight.md
```

This reproduces the whole TDD §12.4 checklist and reports **observed values, not
ticks** — "PHP OK" is what hides a 5.6 install. It exits non-zero on any required
failure.

**Two results change the architecture rather than the configuration:**

- **Outbound HTTPS to `api.authorize.net` blocked** → there is no shared-hosting
  workaround. Online rent collection is not possible on this account (R-14). Stop and
  escalate.
- **Outbound HTTPS to `api.resend.com` blocked** → no email. Every notification, every
  set-password invitation. Stop and escalate.

`api.weather.gov` failing costs one Track B feature (WP-21) and is not a blocker.

**2.5** Confirm the document root can be repointed (cPanel → **Domains** → the domain
→ Document Root). If it can, point it at `/home/user/hupm/public`. If HostGator will
not let you repoint the primary domain — which happens — use the fallback in Stage 3.

**2.6** Check `mysqldump` exists (`which mysqldump`) and that disk quota covers
documents plus 30 days of backups.

**Retain `preflight.md`.** It is the WP-00H evidence, and the DoD asks for observed
values recorded, not a tick.

---

## Stage 3 — Lay out the files

Target layout (TDD §12.1) — **the application root is outside the web root**:

```text
/home/user/
├── hupm/
│   ├── app/ bootstrap/ config/ database/ resources/ routes/
│   ├── storage/          ← documents and logs, never web-accessible
│   ├── vendor/           ← uploaded, built locally
│   ├── .env              ← chmod 600
│   ├── artisan
│   └── public/           ← DocumentRoot points HERE
│       └── build/        ← the Vite output from Stage 1
└── backups/
```

Upload: `app bootstrap config database public resources routes vendor artisan
composer.json composer.lock`.

**Do not upload:** `node_modules`, `tests`, `.git`, `.env` (write it on the server),
`storage/logs/*`, `public/hot`.

### If the document root cannot be repointed

Put the *contents* of `public/` into `public_html/`, keep everything else in
`/home/user/hupm/`, and change the two paths in `public_html/index.php`:

```php
require __DIR__.'/../hupm/vendor/autoload.php';
$app = require_once __DIR__.'/../hupm/bootstrap/app.php';
```

The maintenance-mode check near the top needs the same treatment. This is a supported
fallback, not a hack — but the repointed root is cleaner, so try that first.

### Permissions

```bash
chmod 600 /home/user/hupm/.env
chmod -R 755 /home/user/hupm/storage /home/user/hupm/bootstrap/cache
```

`public/.htaccess` already denies dotfiles, `*.md`, `*.log`, `*.sql`, `*.example` and
directory listings, in both Apache 2.2 and 2.4 syntax (cPanel hosts vary, and a
directive the running Apache does not understand is a 500 for the whole site). That is
the second lock; the first is that `.env` is not under the document root at all.

---

## Stage 4 — Configure

Write `/home/user/hupm/.env` from `.env.example`. The values that must change:

```dotenv
APP_ENV=production
APP_DEBUG=false                    # a trace carries queries, and our queries carry money
APP_URL=https://hupm.example.com   # https, no trailing slash
APP_KEY=                           # generated on the server, see below
APP_TIMEZONE=UTC                   # storage is UTC; business dates resolve in New York (D-07)

DB_DATABASE=cpaneluser_hupm
DB_USERNAME=cpaneluser_hupm
DB_PASSWORD=...

LOG_STACK=daily                    # `single` never rotates and fills the disk
LOG_DAILY_DAYS=14
LOG_LEVEL=warning                  # debug writes a line for uneventful work all day

MAIL_MAILER=resend                 # never smtp — HostGator blocks the ports
RESEND_API_KEY=...
RESEND_WEBHOOK_SECRET=...

AUTHORIZE_NET_ENVIRONMENT=sandbox  # production only after staging reconciles a real batch
AUTHORIZE_NET_LOGIN_ID=...
AUTHORIZE_NET_TRANSACTION_KEY=...
AUTHORIZE_NET_SIGNATURE_KEY=...

HEALTH_TOKEN=...                   # without it /health answers ok/degraded and no detail
```

Generate the key **on the server** and never commit it:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php artisan key:generate
```

Then enable **AutoSSL** (cPanel → SSL/TLS Status) and confirm the certificate issues.
HSTS is only sent over TLS, so until the certificate is live that header is correctly
absent.

---

## Stage 5 — Migrate and cache

```bash
cd /home/user/hupm
PHP=/opt/cpanel/ea-php82/root/usr/bin/php

$PHP artisan down
$PHP artisan migrate --force
$PHP artisan db:seed --force
$PHP artisan db:seed --class="Database\Seeders\WorldSeeder" --force
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan up
```

**`db:seed --force` is safe in production and is not optional.** `DatabaseSeeder`
carries no demo data: it runs `SettingsSeeder` (every gated client decision, with its
documented default — the application reads these, so an unseeded install has no
configuration at all), `HousingAuthoritySeeder`, `ContentSeeder` (the public site's
copy — without it the marketing pages render with no words) and `AdminUserSeeder`,
which refuses to run outside local. `DemoDataSeeder` is **not** in that list; it is
reachable only through `php artisan hupm:demo-data`, which you must never run here.

**`WorldSeeder` is separate and is also not optional.** It fills `countries`, `states`
and `cities` — roughly 250, 5,000 and 150,000 rows — and the property form's address
cascade reads them directly. Without it the Country dropdown renders with no options
and **no property can be created**, which stops the build at its first data-entry step.
It is kept out of `DatabaseSeeder` because 155,000 rows on every `migrate:fresh` would
make the test suite unusable, so on a fresh install it is a deliberate second command.

Watch it on shared hosting: it is by far the longest-running step here, and it is the
one most likely to hit `max_execution_time`. Run it from the shell rather than a web
request, and if it dies part-way, re-run it — the package's seed action is a truncate
and reload, not an append, so a half-finished run is recoverable by repeating it.

After migrating, **revoke `DROP` and `GRANT` from the database user** (TDD §6.3). No
application code issues DDL — that is asserted by a test — so migrations are the only
thing that needs the privilege, and it can be granted again for the next deploy.

The first admin account is a hand-written `INSERT`: nothing in the product creates a
staff account, and `AdminUserSeeder` refuses to run outside local. That is deliberate
(the Users screen is out of scope), and it is also how a departing member of staff is
suspended.

---

## Stage 6 — Cron

**One entry.** Everything recurring hangs off it, including draining the queue.
cPanel → **Cron Jobs**, every minute:

```bash
* * * * * /opt/cpanel/ea-php82/root/usr/bin/php /home/user/hupm/artisan schedule:run >> /dev/null 2>&1
```

Substitute the path **you observed in Stage 2.2**. Bare `php` is the failure that
looks like nothing being wrong.

Verify by effect, not by reading the crontab back. Wait two minutes, then:

```bash
curl -H "X-Health-Token: <your token>" https://hupm.example.com/health
```

The scheduler heartbeat is written every minute. If `/health` says `degraded` with a
stale heartbeat after three minutes, the cron entry is not running — and the PHP path
is the first thing to check.

---

## Stage 7 — Verify before anyone uses it

```bash
$PHP artisan hupm:preflight              # every check, on the real host
$PHP artisan hupm:bank-data-sweep        # I-5, and the database is real now
```

By hand, over HTTPS:

- `https://hupm.example.com/` renders **with styling** — no CSS means Stage 1's build
  did not arrive.
- **Create one test property in the admin console.** This is the cheapest proof that
  `WorldSeeder` completed: an empty Country dropdown means it did not. Delete the
  property afterwards.
- `https://hupm.example.com/emergency-maintenance` renders with JavaScript disabled.
  This is the page D-05 exists for: it must work on a poor mobile connection, which is
  exactly when someone opens it.
- `https://hupm.example.com/.env` → **fails**. Then `/composer.json`, `/README.md`,
  `/storage/`. All must fail. *(WP-34 DoD, host half.)*
- Response headers carry `Strict-Transport-Security`, `X-Content-Type-Options`,
  `X-Frame-Options`, `Referrer-Policy` and `Content-Security-Policy`:
  `curl -sI https://hupm.example.com/ | grep -i -E 'strict|x-|referrer|content-security'`
  *(WP-34 DoD, host half — the application sets all five; this checks nothing in front
  of it strips them.)*
- Sign in, submit a sandbox eCheck, wait for the sandbox batch, confirm
  `ReconcilePayments` settles it the next morning. **This is the M2 evidence on real
  infrastructure.**

Tick the two open WP-34 boxes and all five WP-00H boxes only after this.

---

## Deploying from GitHub Actions instead

The workflows do the same thing as the stages above, in the same order, with the parts
that get forgotten made structural. **All of them are manual** — nothing runs on push.

- **Deploy — HostGator** (`deploy-hostgator.yml`) → `headsuppm.com`
- **Deploy — Hostinger** (`deploy-hostinger.yml`) → `demo.saremcotech.com`
- `_deploy.yml` — the shared core both call. Not run directly.
- **CI** (`ci.yml`) — the suite against **MySQL 8, not SQLite** (D-15: this schema cannot
  be built on SQLite), plus Pint and both audits. Not a gate on deploying.

Per-host setup, secrets and the differences between cPanel and hPanel are in
[../deploy/README.md](../deploy/README.md).

**Why it is worth doing.** The runner has Node, so `public/build` is compiled on every
deploy and cannot be forgotten — which is D-14 solved structurally rather than by a
line in a checklist. It also rsyncs with `--exclude .env --exclude storage/`, so the
deploy physically cannot remove the credentials or a tenant's documents.

**Why it is manual.** This system moves rent, and ledger rows are immutable (I-3). A
migration that runs because somebody merged a README change is not a trade this project
should make.

### Before it can run

1. **Push the repo to GitHub.** There is no remote today.
2. Generate a deploy key, put the public half in `~/.ssh/authorized_keys` on the host:
   `ssh-keygen -t ed25519 -C hupm-deploy -f hupm-deploy -N ""`
3. Pin the host key: `ssh-keyscan -p <port> <host>` — the workflow uses a known_hosts
   file rather than `StrictHostKeyChecking=no`, because disabling that check to make a
   deploy work is how a pipeline becomes the thing that ships to whoever answers.

| Secret | |
|---|---|
| `SSH_PRIVATE_KEY` | the private half, no passphrase |
| `SSH_KNOWN_HOSTS` | the `ssh-keyscan` output |
| `SSH_HOST`, `SSH_USER` | cPanel account |
| `HEALTH_TOKEN` | same value as the server's `.env` |

| Variable | |
|---|---|
| `SSH_PORT` | **HostGator shared is commonly 2222, not 22** |
| `DEPLOY_PATH` | `/home/cpaneluser/hupm` |
| `PHP_CLI_PATH` | the absolute path from Stage 2.2 — never bare `php` |
| `APP_URL` | `https://hupm.example.com` |

### What it does not do

- **It does not create `.env`, and it never touches it.** Stage 4 stays a manual,
  once-only act on the server. Production credentials do not belong in a CI secret
  store when they are already on the host.
- **It does not roll back.** There are no release directories: on shared hosting the
  document root is a fixed path, so this is an in-place rsync inside `artisan down`,
  exactly as TDD §12.2 specifies. **A rollback is re-running the workflow from the
  previous tag.** If a migration was applied, that is not enough — reverse it
  deliberately, the same way a ledger correction is a reversing entry.
- **It does not replace Stage 2.** The workflow assumes SSH exists, the port is known,
  and `rsync` is installed. None of that is known until WP-00H has been run by hand.

### After it succeeds

It has already run `hupm:preflight`, `hupm:bank-data-sweep`, and checked the five
security headers and that `/.env` is not served — the host half of the WP-34 DoD, on
every deploy rather than once at go-live.

---

## Redeploying later

```bash
# LOCAL
composer install --no-dev --optimize-autoloader
npm ci && npm run build && rm -f public/hot
# upload app bootstrap config database public resources routes vendor artisan
# — public/build included, every time the front end changed

# SERVER
cd /home/user/hupm && PHP=/opt/cpanel/ea-php82/root/usr/bin/php
$PHP artisan down
$PHP artisan migrate --force
$PHP artisan optimize:clear
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
$PHP artisan up
$PHP artisan hupm:preflight
```

`optimize:clear` before the three `:cache` commands, always. A cached config holding
the previous `.env` is a deploy that appears to work and quietly uses yesterday's
credentials.

---

## What is still open

Deploying does not close these:

- **WP-32 backup and restore** is not built. Nightly encrypted `mysqldump` off-server,
  and a restore performed into staging and verified. An untested backup is not a
  backup, and a silent backup failure is a critical risk (TDD §8).
- **Opening balances (WP-08 / M1).** Twenty-six hand-entered ledger adjustments, from
  Rent Manager figures the client has not supplied, with tenant and Housing Authority
  portions separate (I-4). Then a reconciliation report signed in writing (Q-5). Until
  that is done every account reads zero and the first statement is wrong for all
  twenty-six.
- **Five gated client decisions** are still unanswered and ship as `settings` defaults.
  **Go-live cannot complete while a gated row is unconfirmed** — `/admin/settings`
  stamps `confirmed_at` when the client confirms.
- **MFA is not built, and there is no encryption at rest** on shared hosting (R-12,
  accepted and documented). Both belong in the handover pack's known limitations.
