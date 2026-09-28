#!/usr/bin/env bash
#
# Deploy the Obitleague plugin from a local checkout to obitleague.co.uk.
#
#   ./deploy-live.sh              # deploy HEAD of main, then verify
#   ./deploy-live.sh --dry-run    # show what would ship, change nothing
#   ./deploy-live.sh --no-verify  # skip the post-deploy route probe
#
# The repository root IS the plugin, so "deploy" means: archive HEAD, push it
# over SSH, swap it into wp-content/plugins/obitleague, and fix up the three
# things a plain file copy gets wrong on this Plesk host:
#
#   1. .htaccess   — wp-cli's `rewrite flush` never writes it under CLI (no
#                    Apache detected in that context), and the vhost is
#                    nginx -> Apache, so every permalink 404s without it.
#   2. theme       — hello-elementor is not installable from WP.org under that
#                    slug (WP.org serves it as `hello`), so it ships as a
#                    tarball and is only touched when missing.
#   3. table_prefix— the host originally used a Plesk-random prefix
#                    (FTgPKOteL_); the migrated DB uses wp_. Verified, never
#                    silently rewritten: changing a prefix under a live site
#                    is a data operation, not a deploy step.
#
# Requirements: git, ssh, scp, and an SSH key with root access to the host
# (default ~/.ssh/plesk_root). Run from anywhere inside the repo.
#
# Safety: refuses to run with a dirty tree, keeps timestamped backups of the
# live plugin dir and wp-config in the host's backup root, and probes real
# league/team routes afterwards instead of /league/1/ (which 302s from the
# unknown-league redirect and hides template fatals).

set -euo pipefail

HOST="${OBITLEAGUE_HOST:-server.threewalls.co.uk}"
KEY="${OBITLEAGUE_SSH_KEY:-$HOME/.ssh/plesk_root}"
DOMAIN="obitleague.co.uk"
DOCROOT="/var/www/vhosts/$DOMAIN/httpdocs"
PLUGIN_DIR="$DOCROOT/wp-content/plugins/obitleague"
THEME_DIR="$DOCROOT/wp-content/themes/hello-elementor"
BACKUP_ROOT="/root/obitleague-backups"
PHP_BIN="/opt/plesk/php/8.4/bin/php"
WP_CLI="$PHP_BIN /usr/local/bin/wp --path=$DOCROOT --allow-root"
SITE_USER="obitleague"
SITE_GROUP="psaserv"

DRY_RUN=0
DO_VERIFY=1
STAGE_DIR=""

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mxx\033[0m %s\n' "$*" >&2; exit 1; }

for arg in "$@"; do
	case "$arg" in
		--dry-run)   DRY_RUN=1 ;;
		--no-verify) DO_VERIFY=0 ;;
		-h|--help)   sed -n '3,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*)           die "unknown option: $arg (try --help)" ;;
	esac
done

cleanup() { [ -n "$STAGE_DIR" ] && [ -d "$STAGE_DIR" ] && rm -rf "$STAGE_DIR"; return 0; }
trap cleanup EXIT

# ---------------------------------------------------------------- preflight --

cd "$(git rev-parse --show-toplevel 2>/dev/null || echo .)" || die "not inside a git repository"
git rev-parse --verify HEAD >/dev/null 2>&1 || die "no HEAD commit"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$BRANCH" = "main" ] || die "refusing to deploy from '$BRANCH' — check out main first (or deploy a tag deliberately)"

# Only tracked modifications matter: untracked files (local tooling, the
# worktree's own .freebuff/, AI_PLUGIN_GUIDE.md) are never in the archive.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
	git status --short --untracked-files=no >&2
	die "tracked files are modified; commit or stash before deploying"
fi

# Only ship what is already pushed, so the live site never runs code that is
# not recoverable from the remote.
git fetch --quiet origin
LOCAL_SHA="$(git rev-parse --short HEAD)"
REMOTE_SHA="$(git rev-parse --short origin/main 2>/dev/null || echo none)"
[ "$LOCAL_SHA" = "$REMOTE_SHA" ] || die "local main ($LOCAL_SHA) differs from origin/main ($REMOTE_SHA); push first"

VERSION="$(grep -m1 -oE "define\( *'OBITLEAGUE_VERSION', *'[^']+'" obitleague.php | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" || echo unknown)"
DB_VERSION="$(grep -m1 -oE "define\( *'OBITLEAGUE_DB_VERSION', *'[^']+'" obitleague.php | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" || echo unknown)"
log "deploying $VERSION (db $DB_VERSION) from $LOCAL_SHA on $BRANCH -> $DOMAIN"

# ------------------------------------------------------------------ archive --

STAGE_DIR="$(mktemp -d)"
ARCHIVE="$STAGE_DIR/obitleague.tar.gz"
git archive --format=tar.gz --output="$ARCHIVE" HEAD

# The archive is what lands in the web root, so refuse to ship dev tooling.
# .gitattributes marks work/ export-ignore; this is the belt to that braces.
# Read the listing once: piping tar into `grep -q` under `set -o pipefail` fails
# even on a match, because grep exits early and tar dies of SIGPIPE.
ARCHIVE_LISTING="$(tar -tzf "$ARCHIVE")"
if grep -qE '^(work|\.freebuff)/' <<<"$ARCHIVE_LISTING"; then
	die "archive contains dev-only paths — check the export-ignore rules in .gitattributes"
fi
grep -qx 'obitleague.php' <<<"$ARCHIVE_LISTING" || die "archive is missing obitleague.php; repo root must be the plugin"

FILE_COUNT="$(grep -cv '/$' <<<"$ARCHIVE_LISTING" || true)"
log "archive verified: $FILE_COUNT files, no dev-only paths"

[ "$DRY_RUN" -eq 1 ] && { log "dry run: stopping before any change to $DOMAIN"; exit 0; }

# ------------------------------------------------------------------- upload --

ssh -o BatchMode=yes -i "$KEY" "root@$HOST" true 2>/dev/null || die "cannot ssh to root@$HOST with $KEY"

log "uploading to $HOST"
scp -q -o BatchMode=yes -i "$KEY" "$ARCHIVE" "root@$HOST:/root/obitleague-deploy.tar.gz"
REMOTE_MD5="$(ssh -o BatchMode=yes -i "$KEY" "root@$HOST" 'md5sum /root/obitleague-deploy.tar.gz | cut -d" " -f1')"
LOCAL_MD5="$(md5sum "$ARCHIVE" | cut -d' ' -f1)"
[ "$REMOTE_MD5" = "$LOCAL_MD5" ] || die "checksum mismatch after upload (local $LOCAL_MD5, remote $REMOTE_MD5)"

# ------------------------------------------------------------------- deploy --

log "deploying on $HOST"
ssh -o BatchMode=yes -i "$KEY" "root@$HOST" bash -s <<REMOTE
set -euo pipefail
DOCROOT="$DOCROOT"
PLUGIN_DIR="$PLUGIN_DIR"
THEME_DIR="$THEME_DIR"
BACKUP_ROOT="$BACKUP_ROOT"
WP_CLI="$WP_CLI"
SITE_USER="$SITE_USER"
SITE_GROUP="$SITE_GROUP"
STAMP="\$(date +%Y%m%d-%H%M%S)"

mkdir -p "\$BACKUP_ROOT"

echo "-- backing up current plugin dir"
if [ -d "\$PLUGIN_DIR" ]; then
	tar -czf "\$BACKUP_ROOT/plugin-\$STAMP.tar.gz" -C "\$(dirname "\$PLUGIN_DIR")" "\$(basename "\$PLUGIN_DIR")"
	echo "   backup: \$BACKUP_ROOT/plugin-\$STAMP.tar.gz"
fi
cp -p "\$DOCROOT/wp-config.php" "\$BACKUP_ROOT/wp-config-\$STAMP.php" 2>/dev/null || true

echo "-- extracting plugin (staged, then swapped)"
STAGE="\$(mktemp -d)"
tar -xzf /root/obitleague-deploy.tar.gz -C "\$STAGE"
[ -f "\$STAGE/obitleague.php" ] || { echo "staged extract looks wrong"; exit 1; }
# Replace rather than merge: a file deleted upstream must not linger live.
rm -rf "\$PLUGIN_DIR.old"
if [ -d "\$PLUGIN_DIR" ]; then mv "\$PLUGIN_DIR" "\$PLUGIN_DIR.old"; fi
mv "\$STAGE" "\$PLUGIN_DIR"
rm -rf "\$PLUGIN_DIR.old" "\$STAGE"
rm -f /root/obitleague-deploy.tar.gz

# (2) theme: ship-only-if-missing. WP.org cannot serve this slug, and
# overwriting a working theme on every deploy is how sites break at 2am.
if [ ! -d "\$THEME_DIR" ]; then
	echo "-- theme missing; cannot be installed from WP.org under this slug"
	echo "   copy it from the Local checkout:"
	echo "   scp -i \$KEY -r <local-site>/wp-content/themes/hello-element \$THEME_DIR"
	exit 3
fi
echo "-- theme present: hello-elementor"

chown -R "\$SITE_USER:\$SITE_GROUP" "\$PLUGIN_DIR"

echo "-- clearing opcache so the new code is what runs"
pkill -USR2 -f "php-fpm" 2>/dev/null || systemctl reload php8.4-fpm 2>/dev/null || echo "   (no opcache reload available; harmless if disabled)"

# (3) prefix: verify only. The live DB uses wp_ after the migration; if this
# ever disagrees the fix is a data operation, not something to do mid-deploy.
PREFIX="\$(\$WP_CLI config get table_prefix 2>/dev/null || echo unknown)"
echo "-- table_prefix: \$PREFIX"
case "\$PREFIX" in
	wp_) : ;;
	*) echo "   WARNING: expected wp_, found \$PREFIX — verify the DB before serving traffic" ;;
esac

echo "-- flushing caches and rewrite rules"
\$WP_CLI cache flush >/dev/null 2>&1 || true
\$WP_CLI transient delete --all >/dev/null 2>&1 || true
\$WP_CLI rewrite flush >/dev/null 2>&1 || true

# (1) .htaccess: the reason permalinks 404 after a plain deploy. wp-cli will
# not write this under CLI, so write it if it is missing or has no WP block.
HT="\$DOCROOT/.htaccess"
if ! grep -q "BEGIN WordPress" "\$HT" 2>/dev/null; then
	echo "-- writing .htaccess (wp-cli does not do this under CLI)"
	cat > "\$HT" <<'HTACCESS'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTACCESS
	chown "\$SITE_USER:\$SITE_GROUP" "\$HT"
	chmod 644 "\$HT"
else
	echo "-- .htaccess already has a WordPress block"
fi

echo "-- plugin state"
\$WP_CLI plugin list --fields=name,status,version --format=csv 2>/dev/null | grep -E "^(name|obitleague)," || true
echo "-- deploy complete"
REMOTE

# ------------------------------------------------------------------- verify --

if [ "$DO_VERIFY" -eq 0 ]; then
	log "skipping verification (--no-verify)"
	exit 0
fi

log "verifying $DOMAIN over HTTPS"
# Probe a league/team that actually exists. /league/1/ returns 302 from the
# unknown-league redirect even when the template is fatally broken, which is
# how the Standings_Service 500 shipped unnoticed.
LEAGUE_ID="$(ssh -o BatchMode=yes -i "$KEY" "root@$HOST" "$WP_CLI db query \"SELECT id FROM wp_obitleague_leagues WHERE is_main=1 LIMIT 1\" --skip-column-names" 2>/dev/null | tr -d '\r' | grep -E '^[0-9]+$' | head -1)"
[ -n "$LEAGUE_ID" ] || warn "could not read a league id; probing without one"

FAILED=0
probe() { # probe <expected> <path>
	local expected="$1" path="$2" code
	code="$(curl -sS -o /dev/null -w '%{http_code}' -m 30 "https://$DOMAIN/$path" 2>/dev/null || echo 000)"
	if [ "$code" = "$expected" ]; then
		printf '    ok   /%-28s %s\n' "$path" "$code"
	else
		printf '    FAIL /%-28s %s (expected %s)\n' "$path" "$code" "$expected"
		FAILED=1
	fi
}

probe 200 ""
probe 200 "catalogue/"
probe 200 "standings/"
probe 200 "stats/"
probe 200 "forum/"
probe 200 "register/"
probe 302 "wp-admin/"
[ -n "$LEAGUE_ID" ] && probe 200 "league/$LEAGUE_ID/"

if [ "$FAILED" -ne 0 ]; then
	warn "route probe failed — check $HOST before announcing anything"
	exit 1
fi

log "deploy verified: $VERSION live at https://$DOMAIN"
