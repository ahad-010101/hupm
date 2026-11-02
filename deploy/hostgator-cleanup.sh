#!/bin/bash
#
# One-off malware clean-up for the HostGator account `jabrilgino`.
# Written 2 November 2026. Not part of the application — delete once done.
#
# The account was compromised on 25 October 2026. HostGator's scan found
# 298,856 infections across fifteen WordPress installations and disabled PHP
# account-wide on 28 October, which is why headsuppm.com returns 403.
#
# HUPM ITSELF IS NOT INFECTED. Every flagged path in its directories is a
# dropped `wp-cron.php` / `wp-blog-header.php`, a leftover folder from the
# site-builder product that used its document root before it, or one of
# HostGator's own blocking .htaccess files. No HUPM source file is modified
# and the database is intact.
#
# WHAT THIS DOES NOT TOUCH, BY DESIGN:
#   ~/mail/**            every mailbox, on every domain (200+ MB of client email)
#   ~/releases ~/shared  HUPM's code, .env and tenant documents
#   ~/hupm               the release symlink
#   the addon domains themselves, so mail and DNS keep working
#
# USAGE
#   bash hostgator-cleanup.sh          # dry run — prints, deletes nothing
#   bash hostgator-cleanup.sh --go     # actually deletes
#
set -uo pipefail

GO=0
[ "${1:-}" = "--go" ] && GO=1

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
note() { printf '  %s\n' "$*"; }

# Count, then delete only with --go. Everything destructive goes through here
# so the dry run genuinely covers every path.
act() {
  local label="$1"; shift
  local n
  n=$("$@" -print 2>/dev/null | wc -l)
  if [ "$GO" = "1" ]; then
    "$@" -delete 2>/dev/null || "$@" -exec rm -rf {} + 2>/dev/null
    note "$label: removed $n"
  else
    note "$label: would remove $n"
  fi
}

[ "$GO" = "1" ] && say "LIVE RUN — deleting" || say "DRY RUN — nothing will be deleted. Re-run with --go to apply."

# ---------------------------------------------------------------------------
say "0. Safety check — HUPM must be present before we touch anything"
for p in "$HOME/hupm" "$HOME/shared/.env" "$HOME/releases"; do
  if [ ! -e "$p" ]; then echo "ABORT: $p is missing. Not proceeding."; exit 1; fi
done
note "hupm symlink, shared/.env and releases all present"
note "release in use: $(readlink -f "$HOME/hupm")"

# ---------------------------------------------------------------------------
say "1. Infected WordPress sites — empty the content, keep the folder"
# The folder and the addon domain stay so that mail and DNS are untouched.
# Each of these is a separate WordPress install, all of them infected.
SITES="
in-need.org
hhcbdz.com
mckeefamilytrust.com
privaterightsinstitute.com
jabrilmckee.com
hmfashioncorp.com
hhfitnezz.com
hhbitcoin.com
hhtechz.com
hhgamez.com
axisexperts.com
girltalkz.com
blkscorpionent.com
_wildcard_.hhrumurz.com
"
for s in $SITES; do
  d="$HOME/$s"
  [ -d "$d" ] || { note "$s: not present, skipping"; continue; }
  if [ "$GO" = "1" ]; then
    find "$d" -mindepth 1 -delete 2>/dev/null || rm -rf "${d:?}"/* "${d:?}"/.[!.]* 2>/dev/null
    note "$s: emptied"
  else
    note "$s: would empty ($(find "$d" -mindepth 1 2>/dev/null | wc -l) entries)"
  fi
done

# ---------------------------------------------------------------------------
say "2. public_html — remove its WordPress, KEEP HUPM's document root"
# public_html is itself an infected WordPress install AND the parent of
# website_0f94b77e, which is HUPM's live document root. It cannot be deleted
# wholesale. Remove only WordPress and the known-bad extras.
PH="$HOME/public_html"
if [ -d "$PH" ]; then
  for item in wp-admin wp-includes wp-content \
              wp-activate.php wp-blog-header.php wp-comments-post.php \
              wp-config.php wp-config-sample.php wp-cron.php wp-links-opml.php \
              wp-load.php wp-login.php wp-mail.php wp-settings.php \
              wp-signup.php wp-trackback.php xmlrpc.php \
              license.txt readme.html \
              wp-active.php wp-admin.php wp-comment.php test.php 794278; do
    t="$PH/$item"
    [ -e "$t" ] || continue
    if [ "$GO" = "1" ]; then rm -rf "$t"; note "removed public_html/$item"
    else note "would remove public_html/$item"; fi
  done

  # The other site-builder document roots. OURS IS EXCLUDED EXPLICITLY.
  for w in "$PH"/website_*; do
    [ -d "$w" ] || continue
    case "$(basename "$w")" in
      website_0f94b77e) note "KEEPING $(basename "$w") — HUPM's document root"; continue ;;
    esac
    if [ "$GO" = "1" ]; then rm -rf "$w"; note "removed $(basename "$w")"
    else note "would remove $(basename "$w")"; fi
  done
fi

# ---------------------------------------------------------------------------
say "3. HUPM's own document root — strip the foreign leftovers"
# These folders belong to the site-builder product that used this docroot
# before HUPM, and the scan flagged their index.php files as injectors.
# Everything NOT listed here is left alone: build/, index.php, favicon.ico,
# robots.txt, cgi-bin and .well-known are ours and must survive.
OURS="$PH/website_0f94b77e"
if [ -d "$OURS" ]; then
  for item in audits embeds list-grid photos requests comment-editor \
              galleries query-content statics i18ns upgrades nextjs \
              post-catalog search-api cssjs cloudflare test.php; do
    t="$OURS/$item"
    [ -e "$t" ] || continue
    if [ "$GO" = "1" ]; then rm -rf "$t"; note "removed docroot/$item"
    else note "would remove docroot/$item"; fi
  done
  note "KEEPING: build/ index.php favicon.ico robots.txt cgi-bin .well-known"
fi

# ---------------------------------------------------------------------------
say "4. Webshells dropped in the home directory and hidden folders"
# Random eight-character .php files at the top level, plus the named shells
# the scan found in cPanel's own directories. The oldest dates from Nov 2022.
act "home-root random .php shells" \
  find "$HOME" -maxdepth 1 -type f -regextype posix-extended \
       -regex ".*/[a-z0-9]{8}\.php"

for f in "$HOME/.cpanel/2uzum3.php7" "$HOME/.pki/2uzum3.php7" \
         "$HOME/.sqmaildata/2uzum3.php7" "$HOME/.pki/indeex.php" \
         "$HOME/.sqmaildata/indeex.php"; do
  [ -e "$f" ] || continue
  if [ "$GO" = "1" ]; then rm -f "$f"; note "removed ${f#"$HOME"/}"
  else note "would remove ${f#"$HOME"/}"; fi
done

# ---------------------------------------------------------------------------
say "5. The dropped payload, everywhere it landed"
# The worm walked the whole tree writing this pair into every directory it
# could, including HUPM's app/, config/, routes/, shared/ and storage/.
# They are additions, never modifications — no real file is altered by removing
# them. ~/mail is excluded so nothing can reach a mailbox.
# One pass over everything, ~/mail included. No -prune here on purpose:
# `-delete` implies `-depth`, which silently disables `-prune`, so a pruned
# version would behave differently in the live run than in the dry run. These
# two filenames are only ever the dropped payload — HUPM has no file by either
# name — so matching by name alone is exact, and inside ~/mail it removes the
# dropped files without touching a single mailbox directory.
act "wp-cron.php / wp-blog-header.php (whole account, mailboxes untouched)" \
  find "$HOME" -type f \( -name wp-cron.php -o -name wp-blog-header.php \)

# ---------------------------------------------------------------------------
say "6. What is left for you to do by hand, in cPanel"
cat <<'MANUAL'
  These cannot be done safely from a shell script.

  a) DELETE THE ATTACKER'S MAIL ACCOUNTS — cPanel > Email Accounts.
     Created 2026-11-01 03:48, four days AFTER HostGator's block, which means
     the attacker still had access. Names carry random numeric suffixes:

       in-need.org   mailer334, newsletter226, noreply478, security357
       hhcbdz.com    account453, admin890, service358, webmaster520

     Keep the real ones: info, director, manager, webmaster, inayah, thefam.
     Check every domain, not just these two.

  b) CHANGE THE cPANEL PASSWORD, and check cPanel > FTP Accounts for any
     account you do not recognise. SSH keys are clean — authorized_keys has
     not changed since August — so the way back in is most likely one of these.

  c) DROP THE WORDPRESS DATABASES for the emptied sites — cPanel > MySQL
     Databases. WordPress malware frequently lives in wp_options and wp_posts,
     so leaving the databases behind leaves a reinfection route.
     DO NOT DROP `jabrilgi_hupm`. That is the property management system.

  d) ASK HOSTGATOR TO RE-SCAN, then to remove the ~1,271 blocking .htaccess
     files. A clean scan alone does not bring the sites back — those files are
     what serve the 403, and they are read-only by design.
MANUAL

say "Done."
[ "$GO" = "1" ] || note "This was a dry run. Nothing changed."
