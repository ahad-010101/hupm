#!/usr/bin/env bash
#
# One-time HostGator bootstrap.  Run ONCE, by hand, over SSH, before the first
# GitHub Actions deploy.  Safe to re-run: every step is idempotent.
#
#   ssh -p 2222 jabrilgino@192.185.52.206
#   bash ~/hostgator-setup.sh
#
# What it does NOT do: create .env (you write that by hand, once — production
# credentials do not belong in a CI secret store), install Composer (the
# workflow builds vendor/ on the runner), or add the cron entry (cPanel).

set -euo pipefail

HOME_DIR="/home5/jabrilgino"
APP_LINK="$HOME_DIR/hupm"                                   # symlink -> current release
RELEASES="$HOME_DIR/releases"
SHARED="$HOME_DIR/shared"
DOCROOT="$HOME_DIR/public_html/website_0f94b77e"            # cPanel Document Root
PHP="/usr/local/bin/php"

echo "==> Checking the things the architecture assumes"

[ -x "$PHP" ] || { echo "FATAL: $PHP is not executable. Run 'which php' and fix PHP_CLI_PATH."; exit 1; }
"$PHP" -v | head -n 1

# The extensions TDD §12.4 requires. A missing one is better found now than
# halfway through the first migration.
for ext in bcmath ctype curl fileinfo gd intl mbstring openssl pdo_mysql tokenizer xml zip; do
    "$PHP" -m | grep -qi "^${ext}$" || echo "  WARNING: PHP extension '$ext' is missing"
done

command -v tar >/dev/null || { echo "FATAL: tar is missing; the deploy transport needs it."; exit 1; }
command -v mysqldump >/dev/null || echo "  WARNING: mysqldump not found — WP-32 backups will need another route"

echo "==> Creating the directory layout"

mkdir -p "$RELEASES"
mkdir -p "$SHARED/storage/app/private"
mkdir -p "$SHARED/storage/app/public"
mkdir -p "$SHARED/storage/framework/cache/data"
mkdir -p "$SHARED/storage/framework/sessions"
mkdir -p "$SHARED/storage/framework/views"
mkdir -p "$SHARED/storage/framework/testing"
mkdir -p "$SHARED/storage/logs"
mkdir -p "$DOCROOT"

# storage/ holds every tenant document and every log. It lives in shared/ so a
# release swap cannot take it with it.
chmod -R 755 "$SHARED/storage"

echo "==> Writing a placeholder .env if there is none"

if [ ! -f "$SHARED/.env" ]; then
    cat > "$SHARED/.env" <<'ENVEOF'
# HUPM production. Written by hand, once. NEVER deployed from the repository.
#
# Fill every value below, then run:
#     /usr/local/bin/php /home5/jabrilgino/hupm/artisan key:generate
#
APP_NAME="Heads Up Enterprises"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://headsuppm.com

# Storage is UTC by design (D-07). Every business date — due day, grace expiry,
# day-5 delinquency, proration — resolves through App\Support\BusinessCalendar
# in America/New_York. Do not change this to a local timezone: a job running at
# 01:00 UTC would evaluate the wrong Georgia calendar day.
APP_TIMEZONE=UTC

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

# No Redis, no daemons on shared hosting. All three use the database driver.
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

# `daily`, not `single`: a single channel never rotates and never prunes, so
# laravel.log grows until the account runs out of disk — which fails every
# write on the site, not just the logging.
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=warning

# Email over HTTPS API, never SMTP — the ports are blocked here.
MAIL_MAILER=resend
RESEND_API_KEY=
RESEND_WEBHOOK_SECRET=
MAIL_FROM_ADDRESS="no-reply@headsuppm.com"
MAIL_FROM_NAME="${APP_NAME}"

# Sandbox until a sandbox payment has settled and reconciled ON THIS HOST.
AUTHORIZE_NET_ENVIRONMENT=sandbox
AUTHORIZE_NET_LOGIN_ID=
AUTHORIZE_NET_TRANSACTION_KEY=
AUTHORIZE_NET_SIGNATURE_KEY=

# Must match the HEALTH_TOKEN GitHub secret, or the deploy health check gets
# the bare verdict with no reasons.
HEALTH_TOKEN=
ENVEOF
    echo "    Wrote $SHARED/.env — FILL IT IN before deploying."
else
    echo "    $SHARED/.env already exists; left untouched."
fi

chmod 600 "$SHARED/.env"

echo "==> Protecting the document root's cPanel-owned entries"

# .well-known is how AutoSSL proves domain control at renewal. Losing it does
# not break anything today — it breaks HTTPS in about sixty days, long after
# anyone would connect it to a deploy. The workflow never deletes here; this
# just makes sure they exist.
mkdir -p "$DOCROOT/.well-known"

echo "==> Result"
echo "    releases : $RELEASES"
echo "    shared   : $SHARED"
echo "    docroot  : $DOCROOT"
echo "    app link : $APP_LINK -> (created by the first deploy)"
echo ""
echo "Next:"
echo "  1. Edit $SHARED/.env and fill in every blank."
echo "  2. Create the MySQL database and user in cPanel; disable remote access."
echo "  3. Run the GitHub Actions 'Deploy to HostGator' workflow."
echo "  4. After it succeeds, add the cron entry in cPanel (every minute):"
echo "     * * * * * $PHP $APP_LINK/artisan schedule:run >> /dev/null 2>&1"
