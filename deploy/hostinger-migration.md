# Migrating HUPM from HostGator to Hostinger

Written 2 November 2026, during the HostGator account compromise.

## Why

The HostGator account `jabrilgino` was compromised on 25 October 2026. HostGator
disabled PHP execution account-wide on 28 October, which is why headsuppm.com
returns 403. The final scan found **298,856 infections across fifteen WordPress
installations** on that account. The attacker was still creating spam mail
accounts on **1 November**, four days after the block.

**HUPM itself is clean.** Every flagged path in its directories is either a
dropped `wp-cron.php` / `wp-blog-header.php`, a leftover folder from a previous
site in the shared document root, or one of HostGator's own blocking
`.htaccess` files. No HUPM source file is modified and the database is intact.

So this is not a recovery — it is a relocation of healthy code and data away
from an account that has been reinfected repeatedly since 2022.

## What moves

| Thing | Size | How |
|---|---|---|
| Database `jabrilgi_hupm` | 26.1 MB, 51 tables | `mysqldump` → import |
| Tenant documents `shared/storage/app` | 7.4 MB | `tar` → extract |
| `shared/.env` | — | Hand-copied, **APP_KEY preserved** |
| Application code | — | Deploy workflow from git, nothing copied |
| `info@headsuppm.com` mailbox | 31 MB | Decision required — see Stage 6 |

---

## ⚠ Four things that will bite

**1. `APP_KEY` must be copied, never regenerated.** Every runbook we have says
to run `key:generate` on a new install. **Do not do that here.** Laravel
encrypts data with it; a fresh key against the migrated database silently
breaks every encrypted value, and nothing errors at the time. Copy the existing
line from the old `.env` verbatim.

**2. `public/.htaccess` lines 90–93 can 500 the whole site.** That block
(`Order allow,deny` / `Allow from all`) is a HostGator malware workaround and
is **not** wrapped in `<IfModule>`, unlike every other block in the file. On a
host without `mod_access_compat` Apache rejects the directive and returns 500
for every request. It has never fired because Hostinger loads that module — but
verify, or guard it, before cutover.

**3. The build runs on PHP 8.3.** Building on a *lower* PHP than the host is
fine; building on a *higher* one makes Composer's `platform_check.php` abort
every request. If Hostinger runs anything below 8.3, `_deploy.yml` needs
editing, not just the wrapper.

**4. Do not copy the dropped malware files across.** `wp-cron.php` and
`wp-blog-header.php` were littered into `releases/`, `shared/` and
`shared/storage/`. Exclude them explicitly when tarring — see Stage 2.

---

## Stage 0 — Prove Hostinger works before paying for a year

Outbound HTTPS is a go/no-go with no workaround: without it the system cannot
take payments or send email.

```bash
ssh -p 65002 <user>@<host>
```

```bash
php -v; which php; readlink -f $(which php)
curl -sS -o /dev/null -w "authorize.net %{http_code}\n" https://api.authorize.net/xml/v1/request.api
curl -sS -o /dev/null -w "resend %{http_code}\n" https://api.resend.com/
mysql --version; which tar; which mysqldump
```

Any HTTP response at all from the two APIs means egress works. A hang or
connection refused means **stop** — that host cannot run this application.

Record these four values; every later stage needs them:

- `HOME_DIR` — output of `echo $HOME`
- `DOCROOT` — the document root hPanel reports for headsuppm.com. On Hostinger
  a subdomain's docroot sits under the **parent** domain's `public_html`. Wrong
  path gives a blank page, not an error.
- `PHP_CLI` — absolute path from `readlink -f $(which php)`
- SSH port — `65002` on Hostinger

## Stage 1 — Provision

1. Hostinger plan **with SSH access** (not the cheapest tier)
2. Add `headsuppm.com` as a website — do **not** change nameservers yet
3. hPanel → Databases → create a MySQL database and user, note the credentials
4. Confirm the MySQL version is 8.0+ (D-03 needs a stored generated column)

## Stage 2 — Export from HostGator

CLI PHP still works there — the 403 only affects Apache — so this can be done
while the site is down.

```bash
ssh -o IdentitiesOnly=yes -i ~/.ssh/headsuppm_deploy -p 2222 jabrilgino@192.185.52.206
```

```bash
cd $HOME && export MYSQL_PWD=$(grep '^DB_PASSWORD=' shared/.env | cut -d= -f2-)
mysqldump --no-tablespaces --single-transaction --quick \
  -u$(grep '^DB_USERNAME=' shared/.env | cut -d= -f2) \
  $(grep '^DB_DATABASE=' shared/.env | cut -d= -f2) > ~/hupm-migrate.sql
tail -1 ~/hupm-migrate.sql
```

The last line must read `-- Dump completed on ...`. Anything else means a
truncated dump — do not proceed.

Tenant documents, excluding the dropped malware:

```bash
tar --exclude='wp-cron.php' --exclude='wp-blog-header.php' \
    -czf ~/hupm-storage.tar.gz -C $HOME/shared storage/app
```

Then pull both down, and read the old `.env` so you can transcribe `APP_KEY`:

```bash
scp -o IdentitiesOnly=yes -i ~/.ssh/headsuppm_deploy -P 2222 \
  jabrilgino@192.185.52.206:'~/hupm-migrate.sql ~/hupm-storage.tar.gz' .
```

## Stage 3 — Bootstrap the new account

```bash
scp -P 65002 deploy/server-setup.sh <user>@<host>:~/
ssh -p 65002 <user>@<host>
DOCROOT=/path/from/stage/0 PHP=/path/from/stage/0 bash ~/server-setup.sh
```

It creates `releases/`, `shared/storage/...`, the docroot, `.well-known`, and a
placeholder `shared/.env`. It deliberately does not create a real `.env`, run
`key:generate`, or add the cron.

Now fill in `~/shared/.env`. Copy every value from the old file, changing only
the database credentials to the new ones. **Keep `APP_KEY` byte-for-byte.**
Also confirm:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://headsuppm.com
APP_TIMEZONE=UTC
MAIL_MAILER=resend
AUTHORIZE_NET_ENVIRONMENT=production
```

Import the data:

```bash
mysql -u <newuser> -p <newdb> < hupm-migrate.sql
tar -xzf hupm-storage.tar.gz -C ~/shared
```

## Stage 4 — Wire up deployment

```bash
ssh-keygen -t ed25519 -C "github-actions-hupm-hostinger" -f ~/.ssh/hupm_hostinger -N ""
ssh-copy-id -i ~/.ssh/hupm_hostinger.pub -p 65002 <user>@<host>
ssh-keyscan -p 65002 <host>
```

In GitHub → Settings → Environments, create **`production-hostinger`** with
four secrets: `SSH_PRIVATE_KEY` (the private half), `SSH_KNOWN_HOSTS` (the
keyscan output), `SSH_HOST`, `SSH_USER`.

Add `.github/workflows/deploy-hostinger-production.yml`, copying
`deploy-hostgator.yml` and changing six values: `environment`, `home_dir`,
`deploy_path`, `docroot`, `php_cli`, `ssh_port: '65002'`.

Run it with `run_migrations: true`. The workflow asks the server for `$HOME`
and refuses if it disagrees with `home_dir`, so a wrong path fails loudly
rather than half-deploying.

## Stage 5 — Verify before touching DNS

Point your own machine at the new server by adding a line to your hosts file
(`C:\Windows\System32\drivers\etc\hosts`), then browse the real domain:

```
<hostinger-ip>   headsuppm.com
```

Then on the server:

```bash
cd ~/hupm && <php> artisan hupm:preflight
cd ~/hupm && <php> artisan hupm:bank-data-sweep
```

Expect one failure only — the MySQL version check if Hostinger is not on 8.x.
Everything else must pass. Confirm by hand: the public site loads, login works,
the admin dashboard opens, a tenant ledger shows the right balance.

Remove the hosts entry afterwards.

## Stage 6 — Decide about email

`info@headsuppm.com` holds **31 MB** on HostGator.

- **Keep mail where it is** — point only the A record at Hostinger and leave the
  MX records on HostGator. Simplest, and nothing is lost. But it keeps a
  dependency on the compromised account.
- **Move it** — create the mailbox on Hostinger, then migrate with IMAP sync
  (Thunderbird or `imapsync`) before cutover. Cleaner end state, more work.

Decide before Stage 7, because the DNS change is where it takes effect.

## Stage 7 — DNS cutover

1. **24 hours before:** lower the TTL on the A record to 300 seconds
2. Point the A record for `headsuppm.com` and `www` at the Hostinger IP
3. If moving mail, update MX at the same time; if not, leave MX alone
4. Issue SSL in hPanel (Let's Encrypt) and confirm `https://` serves a valid cert
5. Watch until the old host stops receiving traffic

## Stage 8 — Finish

**The cron is the one that fails silently.** Without it charges never post and
balances drift, with no error anywhere:

```
* * * * * <absolute-php> /home/<user>/hupm/artisan schedule:run >> /dev/null 2>&1
```

Verify by effect, not by listing it — the heartbeat should be under a minute
old:

```bash
cd ~/hupm && <php> artisan tinker --execute="echo cache()->get(App\Support\SystemHealth::HEARTBEAT_KEY);"
```

Then:

- Confirm the Authorize.Net keys work from the new host (`hupm:preflight`)
- Add `RESEND_WEBHOOK_SECRET`, still missing
- Check Resend's sending domain is still verified
- Take a first backup

## Stage 9 — Only then, deal with HostGator

Once headsuppm.com is live on Hostinger, the old account stops being urgent.
The client still has fifteen infected WordPress sites and spam mail accounts to
resolve, but it no longer holds rent collection hostage.

Do not cancel that account until the `info@headsuppm.com` mail is migrated or
confirmed unnecessary.
