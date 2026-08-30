# Deploying HUPM from GitHub Actions

Two servers, two workflows, both run by hand:

| Workflow | Site | Host | SSH | Environment |
|---|---|---|---|---|
| **Deploy — HostGator** | `headsuppm.com` | HostGator, cPanel | `192.185.52.206:2222` | `production` |
| **Deploy — Hostinger** | `demo.saremcotech.com` | Hostinger, hPanel | port `65002` | `demo` |

Both call `.github/workflows/_deploy.yml`, which holds the actual work. One core rather
than two copies, because the hosts differ in six values and nothing else — a duplicated
450-line workflow drifts, and this one already did once when a fix landed on one copy.

The six values live in the caller files, in plain sight and diffable:

| | HostGator | Hostinger |
|---|---|---|
| `home_dir` | `/home5/jabrilgino` | `/home/u529996195` |
| `deploy_path` | `/home5/jabrilgino/hupm` | `/home/u529996195/hupm` |
| `docroot` | `/home5/jabrilgino/public_html/website_0f94b77e` | `/home/u529996195/domains/saremcotech.com/public_html/demo` |
| `php_cli` | `/usr/local/bin/php` | `/usr/bin/php` |
| `app_url` | `https://headsuppm.com` | `https://demo.saremcotech.com` |
| `ssh_port` | `2222` | `65002` |

The by-hand procedure is [../docs/deployment.md](../docs/deployment.md). This is the
automated path, and it is the one to use once an account is set up.

### What Hostinger does differently

Beyond those six values, three things are worth knowing before you assume a HostGator
habit transfers:

- **A subdomain sits under the PARENT domain's `public_html`**, so the document root is
  `domains/saremcotech.com/public_html/demo` — not `domains/demo.saremcotech.com/`.
  Confirmed on the server rather than inferred; getting it wrong points the release's
  `public` symlink at a directory nothing serves, and that shows up as a blank page
  rather than an error.
- **Composer is installed** (`/usr/local/bin/composer`). HostGator has none. So
  `hupm:demo-production` can seed the demo portfolio directly on Hostinger once dev
  dependencies are present, instead of the dump-and-import detour production needed —
  Faker is a dev dependency and a `--no-dev` install does not have it.
- **No malware-remediation `.htaccess`.** The `Deny from all` rule that 403'd every PHP
  file on HostGator is a property of that account, not of shared hosting. The re-allow
  block in `public/.htaccess` is inert here.

DNS for `saremcotech.com` is already on Hostinger's nameservers (`ns1/ns2.dns-parking.com`),
so a subdomain's A record is created by hPanel — nothing to do at the registrar.

---

## 1. Server layout

```text
/home5/jabrilgino/
├── hupm -> releases/<id>                 symlink — swapped atomically
├── releases/
│   ├── 20260827143000-aa4c015/           the live release
│   └── …                                  the last five, for rollback
├── shared/
│   ├── .env                              chmod 600, written by hand, never deployed
│   └── storage/                          documents, logs, sessions, cache
└── public_html/website_0f94b77e/         cPanel Document Root — a REAL directory
    ├── index.php                         paths rewritten to /home5/jabrilgino/hupm
    ├── .htaccess
    ├── build/                            Vite output
    ├── .well-known/                      AutoSSL — never deleted
    └── cgi-bin/                          cPanel — never deleted
```

Each release symlinks three things back out:

| In the release | Points at | Why |
|---|---|---|
| `.env` | `shared/.env` | production credentials outlive releases and never come from the repo |
| `storage` | `shared/storage` | tenant documents and logs must survive a deploy |
| `public` | the Document Root | **this is the one that is not obvious — see below** |

### Why `public` is a symlink

Laravel's `public_path()` is `base_path('public')`. With the application in `~/hupm` and
the Document Root elsewhere, it resolves to a directory nothing serves — and
`Vite::manifest()` reads `public_path('build/manifest.json')`, so `@vite` throws and
**every page returns 500**. Two other things in this codebase read it as well: the
`SecurityHeaders` middleware (`public_path('hot')`) and `hupm:preflight`'s asset check.

Symlinking makes `public_path()` land on the directory Apache actually serves, with no
code change.

Overriding it in `bootstrap/app.php` is **not** an option: that file runs before `.env`
is loaded, and once `config:cache` exists Laravel skips `LoadEnvironmentVariables`
entirely — so an `env()`-driven override returns null in production and fails in exactly
the environment it was written for.

### Why the Document Root is *not* a symlink

cPanel owns that directory and may recreate it, and Apache `FollowSymLinks` is not
guaranteed on shared hosting. So assets are extracted into it **before** the release
symlink swaps. Vite filenames are content-hashed, so the outgoing and incoming builds
coexist safely for the seconds in between.

---

## 2. Secrets

**Settings → Environments.** Two environments, four secrets each. Nothing else needs
configuring in the GitHub UI — the paths and ports are in the caller workflows.

| Secret | `production` (HostGator) | `demo` (Hostinger) |
|---|---|---|
| `SSH_PRIVATE_KEY` | deploy key, private half, no passphrase | as HostGator, or its own key |
| `SSH_KNOWN_HOSTS` | `ssh-keyscan -p 2222 192.185.52.206` | `ssh-keyscan -p 65002 <host>` |
| `SSH_HOST` | `192.185.52.206` | from hPanel → Advanced → SSH Access |
| `SSH_USER` | `jabrilgino` | `u529996195` |

`HEALTH_TOKEN` is no longer read by the workflow — it stays in the server's `.env`,
because `/health` still needs it to return its reasons to a monitor.

Add yourself as a **required reviewer** on `production` so a deploy there waits for an
approval. `demo` does not need one.

---

## 3. First-time setup

**3.1 Deploy key** — on your machine:

```bash
ssh-keygen -t ed25519 -C hupm-deploy -f hupm-deploy -N ""
```

Put the **public** half on the server:

```bash
ssh-copy-id -i hupm-deploy.pub -p 2222 jabrilgino@192.185.52.206
```

Paste the **private** half into `SSH_PRIVATE_KEY`. Then pin the host key:

```bash
ssh-keyscan -p 2222 192.185.52.206
```

That output goes into `SSH_KNOWN_HOSTS`. The workflow uses `StrictHostKeyChecking=yes`
against it — disabling that check to make a deploy work is how a pipeline becomes the
thing that ships to whoever answers on that address.

**3.2 Test connectivity** before touching GitHub:

```bash
ssh -i hupm-deploy -p 2222 jabrilgino@192.185.52.206 'whoami; /usr/local/bin/php -v; which tar'
```

All three must answer. If `/usr/local/bin/php` is not executable, run `which php` and
correct the `PHP_CLI_PATH` variable — **a wrong PHP path is the single most common
shared-hosting failure and it fails silently** (R-5).

**3.3 Bootstrap the server** — once:

```bash
scp -P 2222 deploy/hostgator-setup.sh jabrilgino@192.185.52.206:~/
```

```bash
ssh -p 2222 jabrilgino@192.185.52.206 'bash ~/hostgator-setup.sh'
```

**3.4 Create the database** in cPanel → MySQL Databases. Disable remote access.

**3.5 Fill in `~/shared/.env`** — the script writes a template with every key. Nothing
deploys until `DB_*`, `RESEND_API_KEY` and `HEALTH_TOKEN` are set.

---

## 4. The first deployment

Run **Actions → Deploy — HostGator → Run workflow** (or **Deploy — Hostinger**, for the
demo) with `run_migrations` set to **true**.

Then, on the server:

```bash
ssh -p 2222 jabrilgino@192.185.52.206 '/usr/local/bin/php /home5/jabrilgino/hupm/artisan key:generate'
```

Seed — both are required, and neither is demo data:

```bash
ssh -p 2222 jabrilgino@192.185.52.206 'cd /home5/jabrilgino/hupm && /usr/local/bin/php artisan db:seed --force'
```

```bash
ssh -p 2222 jabrilgino@192.185.52.206 'cd /home5/jabrilgino/hupm && /usr/local/bin/php artisan db:seed --class="Database\Seeders\WorldSeeder" --force'
```

`db:seed` carries your settings and the public site's copy. `WorldSeeder` fills the
country/state/city tables — **without it the Country dropdown is empty and no property
can be created.** It is the slowest step by far (~155,000 rows); re-run it if it times
out, since it truncates and reloads rather than appending.

Finally add the cron entry in cPanel → Cron Jobs, every minute:

```text
* * * * * /usr/local/bin/php /home5/jabrilgino/hupm/artisan schedule:run >> /dev/null 2>&1
```

**On `/health` after the first deploy:** it returns **503** when degraded, and it reports
the scheduler heartbeat. Before the cron entry above exists the heartbeat has never been
written, so degraded is the honest answer — not a fault. Once cron has run a couple of
times, `curl -H "X-Health-Token: …" https://headsuppm.com/health` should say `ok`. From
then on a 503 means something genuinely needs attention.

---

## 5. Every deploy after that

Actions → **Deploy — HostGator** or **Deploy — Hostinger** → Run workflow. Leave
`run_migrations` on unless you know this release has none.

The workflow, in order: build `vendor/` and the Vite assets → rewrite `index.php` for
the split root → tar → SSH → unpack to a new release → symlink shared state →
`artisan down` → migrate → **swap the symlink** → clear and warm caches → `artisan up` →
prune to the last five releases.

### It verifies nothing

**No test suite, and no post-deploy checks either.** A green run means the files arrived
and the migration ran. It does not mean the site works.

What still protects the deploy itself: `php -l` over everything that will execute on the
host, a check that Vite produced a manifest, and the release symlink — which does not
move until the unpack and the migration have both succeeded. So a failed deploy leaves
the previous release serving.

Run these yourself afterwards. They are all still built:

```bash
ssh -p 2222 jabrilgino@192.185.52.206 'cd /home5/jabrilgino/hupm && /usr/local/bin/php artisan hupm:preflight'
```

```bash
ssh -p 2222 jabrilgino@192.185.52.206 'cd /home5/jabrilgino/hupm && /usr/local/bin/php artisan hupm:bank-data-sweep'
```

```bash
curl -sI https://headsuppm.com/ | grep -i -E 'strict|x-|referrer|content-security'
```

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://headsuppm.com/.env
```

That last one must not print `200`.

`ci.yml` no longer runs on push either. It is manual: **Actions -> CI -> Run workflow**,
whenever you want the suite run against MySQL 8 on a clean machine.

---

## 6. Rollback

The last five releases stay on disk, and the swap is one symlink.

```bash
ssh -p 2222 jabrilgino@192.185.52.206 'ls -1dt /home5/jabrilgino/releases/*/'
```

```bash
ssh -p 2222 jabrilgino@192.185.52.206 'ln -sfn /home5/jabrilgino/releases/<previous-id> /home5/jabrilgino/.hupm-next && mv -Tf /home5/jabrilgino/.hupm-next /home5/jabrilgino/hupm && cd /home5/jabrilgino/hupm && /usr/local/bin/php artisan optimize:clear && /usr/local/bin/php artisan config:cache && /usr/local/bin/php artisan route:cache && /usr/local/bin/php artisan view:cache'
```

Two things that rollback does **not** undo:

- **Migrations.** Reversing a schema change is a deliberate act, the same way a ledger
  correction is a reversing entry rather than an edit (I-3). Do not reach for
  `migrate:rollback` on a live financial system without reading what it will run.
- **The Document Root assets.** They are not versioned. Re-running the workflow from the
  previous tag is the clean way to restore them — but because Vite filenames are hashed,
  the previous build's files are usually still sitting there and the old `index.php`
  will reference them correctly.

---

## 7. Shared-hosting failure cases, and what the workflow does about each

| Failure | How it usually shows | Handled by |
|---|---|---|
| **Wrong PHP CLI path** | Cron runs and writes nothing. Charges never post, reconciliation never runs, balances quietly drift (R-5) | `PHP_CLI_PATH` is a variable, never bare `php`; `hostgator-setup.sh` checks it is executable; every remote command uses it explicitly |
| **Composer absent** | `composer: command not found` mid-deploy | `vendor/` is built on the runner and shipped in the tarball. Composer is never invoked on the host |
| **No Node on the server** | An unstyled site: correct HTML, every route 200, clean logs | Vite builds in CI and the job fails if `manifest.json` is missing (D-14) |
| **Stale `public/hot`** | Every visitor's browser sent to `localhost:5173`; no CSS at all | Deleted before packaging; `hupm:preflight` fails on it outside local |
| **`rsync` missing** | `rsync: command not found` | Not used. Transport is `tar` + `scp` |
| **Deploy dies mid-upload** | Half-written application | Unpacked into a *new* release directory. The live symlink only moves after a clean unpack and a successful migrate |
| **`.env` overwritten** | Production credentials replaced by defaults | `.env*` is excluded from the tarball and symlinked from `shared/`. The remote script aborts if `shared/.env` is missing |
| **`storage/` wiped** | Every tenant document gone | Excluded from the tarball, symlinked from `shared/` |
| **AutoSSL breaks ~60 days later** | HTTPS expires with no apparent cause | The Document Root is never `--delete`d, so `.well-known/` survives |
| **Cached config holds the old `.env`** | Deploy looks fine, uses yesterday's credentials | `optimize:clear` always runs *before* the three `:cache` commands |
| **`max_execution_time` on a long seed** | `WorldSeeder` dies part-way | Run from the shell, not a web request; it truncates and reloads, so re-running fixes it |
| **Site stuck in maintenance mode** | Residents see the maintenance page overnight | An `if: failure()` step runs `artisan up` |
| **Two deploys at once** | Corrupted tree | `concurrency` queues them and never cancels mid-flight |

---

## 8. What is deliberately not automated

- **`.env` is never created or modified by CI.** Production credentials belong on the
  host, not in a secret store that also has to be able to write them.
- **`key:generate` is manual and run once.** Regenerating `APP_KEY` on a live system
  invalidates every session and every encrypted value.
- **Migrations are opt-in per run**, and destructive commands (`migrate:fresh`,
  `db:wipe`) appear nowhere in the workflow.
- **The cron entry is added in cPanel by hand.** It is the one piece of configuration
  that has to survive independently of any deploy.
