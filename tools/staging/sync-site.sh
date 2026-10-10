#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Copies a WordPress site running in Docker (production) onto its test copy: files, database, addresses. Meant to run on the Docker host.
#
# What it does, in this order:
#   1. checks (containers, volumes, addresses) and refuses to run when the "test" site looks like the production one;
#   2. makes sure WP-CLI is available in the test container (copied from the production one);
#   3. rsyncs the files of the production volume onto the test volume (wp-config.php, caches and anything in EXCLUDES are left alone);
#   4. dumps the database of the production site and loads it into the test database;
#   5. replaces the address of the production site by the address of the test site, with `wp search-replace`, which handles serialized data;
#   6. makes the copy safe: no mail, no indexing, no scheduled tasks, environment type "staging".
#
# It never writes to the production site: it only reads its files and dumps its database.
#
# Usage: sync-site.sh [--dry-run] [--yes] [--skip-uploads] [--keep-dump] [--keep-cron]
#   --dry-run        show the files rsync would change and stop. The only thing written is WP-CLI in the test container, if it is missing.
#   --yes            do not ask for confirmation.
#   --skip-uploads   do not copy wp-content/uploads (the test site keeps its own).
#   --keep-dump      keep the SQL dump (it holds personal data: mode 600, in $WORKDIR).
#   --keep-cron      leave WP-Cron enabled on the test site (it is disabled by default: scheduled tasks of the production plugins
#                    would run on the copy, and may publish, mail or call external services).
#
# Settings (environment variables, defaults for Eric's setup):
#   PROD_CONTAINER, STAGING_CONTAINER   WordPress containers.
#   PROD_VOLUME, STAGING_VOLUME         The volumes of /var/www/html on the host.
#   PROD_URL, STAGING_URL               Addresses, without a trailing slash.
#   RSYNC                               The rsync command ("sudo rsync" when the volumes are not readable by your user).
#   EXCLUDES                            More rsync exclusions, space separated (patterns relative to the volume).
#   PROD_DB_CONTAINER, STAGING_DB_CONTAINER
#                                       Containers of the database servers, only when the WordPress containers have no mysql client
#                                       (the official wordpress image has none): the dump and the load are then run in them.
#   WORKDIR                             Where the dump is put (default: a private temporary directory).
set -euo pipefail

PROD_CONTAINER=${PROD_CONTAINER:-docker-e-vli-st_wordpress_1}
STAGING_CONTAINER=${STAGING_CONTAINER:-docker-sb-vli-st_wordpress-sb_1}
PROD_VOLUME=${PROD_VOLUME:-/volumes/e-vli-st/wordpress}
STAGING_VOLUME=${STAGING_VOLUME:-/volumes/sb-vli-st/wordpress}
PROD_URL=${PROD_URL:-https://e.vli.st}
STAGING_URL=${STAGING_URL:-https://sb.vli.st}
RSYNC=${RSYNC:-rsync}
EXCLUDES=${EXCLUDES:-}
PROD_DB_CONTAINER=${PROD_DB_CONTAINER:-}
STAGING_DB_CONTAINER=${STAGING_DB_CONTAINER:-}

DRY_RUN=0 YES=0 SKIP_UPLOADS=0 KEEP_DUMP=0 KEEP_CRON=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --yes) YES=1 ;;
    --skip-uploads) SKIP_UPLOADS=1 ;;
    --keep-dump) KEEP_DUMP=1 ;;
    --keep-cron) KEEP_CRON=1 ;;
    -h | --help) sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

say() { printf '\n== %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# Runs WP-CLI in a container, as the web server's user, without touching the home directory.
wp_in() {
  local container=$1
  shift
  docker exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache "$container" wp "$@"
}

# Same, as root: wp-config.php is often not writable by the web server's user.
wp_root() {
  local container=$1
  shift
  docker exec -u root -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache "$container" wp --allow-root "$@"
}

# Splits a DB_HOST (host, host:port) into the arguments of the mysql clients.
db_host_args() {
  case "$1" in
    *:/*) die "DB_HOST $1 is a socket path; give the database containers and run with TCP." ;;
    *:*) printf -- '-h %s -P %s' "${1%%:*}" "${1##*:}" ;;
    *) printf -- '-h %s' "$1" ;;
  esac
}

# --- 1. checks ---------------------------------------------------------------------------------------------------------------------
say "Checks"
[ "$PROD_CONTAINER" != "$STAGING_CONTAINER" ] || die "The production and the test containers are the same."
[ "$(readlink -f "$PROD_VOLUME")" != "$(readlink -f "$STAGING_VOLUME")" ] || die "The production and the test volumes are the same."
[ "$PROD_URL" != "$STAGING_URL" ] || die "The production and the test addresses are the same."
[ -d "$PROD_VOLUME/wp-content" ] || die "$PROD_VOLUME does not look like a WordPress volume (no wp-content)."
[ -d "$STAGING_VOLUME" ] || die "$STAGING_VOLUME does not exist."
for c in "$PROD_CONTAINER" "$STAGING_CONTAINER"; do
  [ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null)" = "true" ] || die "Container $c is not running."
done

# The command that installs WP-CLI in the test container: copied from the production container, no download.
if ! docker exec "$STAGING_CONTAINER" sh -c 'command -v wp >/dev/null 2>&1'; then
  say "Installing WP-CLI in $STAGING_CONTAINER (copied from $PROD_CONTAINER; lost if the container is recreated, the script reinstalls it)"
  prod_wp=$(docker exec "$PROD_CONTAINER" sh -c 'command -v wp') || die "WP-CLI was not found in $PROD_CONTAINER."
  tmp_wp=$(mktemp)
  docker cp "$PROD_CONTAINER:$prod_wp" "$tmp_wp"
  docker cp "$tmp_wp" "$STAGING_CONTAINER:/usr/local/bin/wp"
  rm -f "$tmp_wp"
  docker exec "$STAGING_CONTAINER" chmod 755 /usr/local/bin/wp
fi
wp_in "$STAGING_CONTAINER" --info >/dev/null || die "WP-CLI does not run in $STAGING_CONTAINER."

# What the production site says about itself must be the production address, and the test site must not be it.
prod_siteurl=$(wp_in "$PROD_CONTAINER" option get siteurl) || die "Cannot read the address of the production site."
[ "$prod_siteurl" = "$PROD_URL" ] || die "The production site says it is $prod_siteurl, not $PROD_URL."
if staging_siteurl=$(wp_in "$STAGING_CONTAINER" option get siteurl 2>/dev/null); then
  [ "$staging_siteurl" = "$STAGING_URL" ] || [ "$staging_siteurl" = "$PROD_URL" ] \
    || die "The test site says it is $staging_siteurl: neither $STAGING_URL nor (after an earlier copy) $PROD_URL. Not touching it."
  [ "$staging_siteurl" != "$PROD_URL" ] || echo "Note: the test database already holds the production address (earlier copy interrupted?)."
else
  echo "Note: the test site has no readable database yet; it will be created by the load."
fi

# The mysql clients: in the WordPress containers, or in the database containers when given.
client_in() { # container -> prints the client command, or fails
  docker exec "$1" sh -c 'command -v mariadb || command -v mysql' 2>/dev/null
}
dump_in() { docker exec "$1" sh -c 'command -v mariadb-dump || command -v mysqldump' 2>/dev/null; }
DUMP_CONTAINER=${PROD_DB_CONTAINER:-$PROD_CONTAINER}
LOAD_CONTAINER=${STAGING_DB_CONTAINER:-$STAGING_CONTAINER}
DUMP_CMD=$(dump_in "$DUMP_CONTAINER") || die "No mysqldump in $DUMP_CONTAINER. Give PROD_DB_CONTAINER (the database container of the production site)."
LOAD_CMD=$(client_in "$LOAD_CONTAINER") || die "No mysql client in $LOAD_CONTAINER. Give STAGING_DB_CONTAINER (the database container of the test site)."

# Credentials come from the wp-config.php of each site, and go through the environment of the command, never through its arguments.
cfg() { wp_in "$1" config get "$2"; }
P_NAME=$(cfg "$PROD_CONTAINER" DB_NAME) P_USER=$(cfg "$PROD_CONTAINER" DB_USER) P_PASS=$(cfg "$PROD_CONTAINER" DB_PASSWORD) P_HOST=$(cfg "$PROD_CONTAINER" DB_HOST)
S_NAME=$(cfg "$STAGING_CONTAINER" DB_NAME) S_USER=$(cfg "$STAGING_CONTAINER" DB_USER) S_PASS=$(cfg "$STAGING_CONTAINER" DB_PASSWORD) S_HOST=$(cfg "$STAGING_CONTAINER" DB_HOST)
[ "$P_HOST/$P_NAME" != "$S_HOST/$S_NAME" ] || die "Both sites use the same database ($S_HOST/$S_NAME)."

echo "Production : $PROD_URL  ($PROD_CONTAINER, $PROD_VOLUME, database $P_NAME)"
echo "Test copy  : $STAGING_URL  ($STAGING_CONTAINER, $STAGING_VOLUME, database $S_NAME)"

# --- 2. files ----------------------------------------------------------------------------------------------------------------------
rsync_args=(-a --delete --numeric-ids --exclude '/wp-config.php' --exclude '/wp-content/cache/' --exclude '/wp-content/upgrade/'
  --exclude '/wp-content/mu-plugins/staging-safety.php' --exclude '*.log' --exclude '/.maintenance')
[ "$SKIP_UPLOADS" -eq 0 ] || rsync_args+=(--exclude '/wp-content/uploads/')
for pattern in $EXCLUDES; do rsync_args+=(--exclude "$pattern"); done

if [ "$DRY_RUN" -eq 1 ]; then
  say "Dry run: files rsync would change in $STAGING_VOLUME"
  $RSYNC "${rsync_args[@]}" --dry-run --itemize-changes --stats "$PROD_VOLUME/" "$STAGING_VOLUME/" | tail -n 60
  echo "Dry run: stopping here. Nothing was written."
  exit 0
fi

if [ "$YES" -eq 0 ]; then
  echo
  echo "This REPLACES the files and the database of the test site ($STAGING_URL) with those of $PROD_URL."
  read -r -p "Type the name of the test container to go on [$STAGING_CONTAINER]: " answer
  [ "$answer" = "$STAGING_CONTAINER" ] || die "Not confirmed."
fi

say "Files"
$RSYNC "${rsync_args[@]}" "$PROD_VOLUME/" "$STAGING_VOLUME/"

# --- 3. database -------------------------------------------------------------------------------------------------------------------
say "Database: dump of the production site"
WORKDIR=${WORKDIR:-$(mktemp -d)}
chmod 700 "$WORKDIR"
DUMP_FILE="$WORKDIR/prod-$(date +%Y%m%d-%H%M%S).sql"
cleanup() { [ "$KEEP_DUMP" -eq 1 ] || { rm -f "$DUMP_FILE"; rmdir "$WORKDIR" 2>/dev/null || true; }; }
trap cleanup EXIT
umask 077
docker exec -e MYSQL_PWD="$P_PASS" "$DUMP_CONTAINER" "$DUMP_CMD" --single-transaction --add-drop-table --default-character-set=utf8mb4 \
  $(db_host_args "$P_HOST") -u "$P_USER" "$P_NAME" >"$DUMP_FILE"
tail -n 1 "$DUMP_FILE" | grep -q 'Dump completed' || die "The dump looks incomplete (no 'Dump completed' line): $DUMP_FILE"
echo "Dump: $(wc -c <"$DUMP_FILE") bytes"

say "Database: load into the test site"
docker exec -i -e MYSQL_PWD="$S_PASS" "$LOAD_CONTAINER" "$LOAD_CMD" --default-character-set=utf8mb4 $(db_host_args "$S_HOST") -u "$S_USER" "$S_NAME" <"$DUMP_FILE"

# --- 4. addresses ------------------------------------------------------------------------------------------------------------------
say "Addresses: $PROD_URL -> $STAGING_URL (serialized data handled by WP-CLI, guid left alone)"
prod_host=${PROD_URL#*://}
staging_host=${STAGING_URL#*://}
wp_in "$STAGING_CONTAINER" search-replace "$PROD_URL" "$STAGING_URL" --all-tables --skip-columns=guid --report-changed-only
wp_in "$STAGING_CONTAINER" search-replace "//$prod_host" "//$staging_host" --all-tables --skip-columns=guid --report-changed-only
leftovers=$(wp_in "$STAGING_CONTAINER" search-replace "$prod_host" "$staging_host" --all-tables --skip-columns=guid --dry-run --format=count)
echo "Other mentions of $prod_host left in the database (not changed: mail addresses, text...): $leftovers"

# --- 5. a safe copy ----------------------------------------------------------------------------------------------------------------
say "Making the copy safe"
wp_in "$STAGING_CONTAINER" option update blog_public 0
wp_root "$STAGING_CONTAINER" config set WP_ENVIRONMENT_TYPE staging --type=constant
if [ "$KEEP_CRON" -eq 0 ]; then
  wp_root "$STAGING_CONTAINER" config set DISABLE_WP_CRON true --raw --type=constant
  echo "WP-Cron disabled on the test site (use --keep-cron to leave it on)."
fi

mkdir -p "$STAGING_VOLUME/wp-content/mu-plugins"
cat >"$STAGING_VOLUME/wp-content/mu-plugins/staging-safety.php" <<PHP
<?php
/**
 * Plugin Name: Staging safety
 * Description: Written by tools/staging/sync-site.sh. This is a copy of $PROD_URL: no mail leaves it, search engines are asked to stay away, and the admin bar says so.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_wp_mail', '__return_false' );
add_filter( 'wp_robots', 'wp_robots_no_robots' );
add_action(
	'admin_bar_menu',
	static function ( \$bar ) {
		\$bar->add_node(
			array(
				'id'    => 'staging-copy',
				'title' => 'TEST COPY of $prod_host',
				'href'  => '$PROD_URL',
			)
		);
	},
	1
);
add_action(
	'admin_head',
	static function () {
		echo '<style>#wpadminbar{background:#7a2e0e}</style>';
	}
);
PHP
docker exec "$STAGING_CONTAINER" chown www-data:www-data /var/www/html/wp-content/mu-plugins/staging-safety.php 2>/dev/null || true

wp_in "$STAGING_CONTAINER" cache flush || true
wp_in "$STAGING_CONTAINER" rewrite flush || true

say "Done"
wp_in "$STAGING_CONTAINER" option get siteurl
wp_in "$STAGING_CONTAINER" plugin list --fields=name,status,version
echo
echo "Next: activate the plugin under development on the test site (wp plugin activate otherguise), then open $STAGING_URL/wp-admin/."
[ "$KEEP_DUMP" -eq 0 ] || echo "The dump was kept: $DUMP_FILE (personal data: delete it when done)."
