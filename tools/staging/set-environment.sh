#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Sets the environment of the sandbox site, which is what the coloured frame and the label of site-environment.php say: "staging" (a fresh
# copy of production, on which a procedure is rehearsed) or "testing" (free experiments). It changes nothing else: no file, no database,
# no setting but the constant WP_ENVIRONMENT_TYPE of the sandbox, and it (re)installs the must-use plugin site-environment.php there.
# It refuses to touch anything that does not say it is the sandbox, and never writes to production.
#
# Usage: set-environment.sh [staging|testing]
#   without argument  show the environment of the sandbox.
#
# Settings (environment variables, the same as sync-site.sh): STAGING_CONTAINER, STAGING_URL.
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
STAGING_CONTAINER=${STAGING_CONTAINER:-docker-sb-vli-st_wordpress-sb_1}
STAGING_URL=${STAGING_URL:-https://sb.vli.st}

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
quiet_warnings() { grep -v -e 'Constant WP_DEBUG already defined' -e 'Function _load_textdomain_just_in_time was called' || true; }
wp_in() { docker exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache "$STAGING_CONTAINER" wp "$@" 2> >(quiet_warnings >&2); }
wp_root() { docker exec -u root -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache "$STAGING_CONTAINER" wp --allow-root "$@" 2> >(quiet_warnings >&2); }

case "${1:-}" in
  "" | staging | testing) ;;
  -h | --help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
  *) die "Unknown environment \"$1\": staging or testing." ;;
esac
[ "$#" -le 1 ] || die "Too many arguments."

[ "$(docker inspect -f '{{.State.Running}}' "$STAGING_CONTAINER" 2>/dev/null)" = "true" ] || die "Container $STAGING_CONTAINER is not running."
siteurl=$(wp_in option get siteurl) || die "Cannot read the address of the site in $STAGING_CONTAINER."
[ "$siteurl" = "$STAGING_URL" ] || die "The site in $STAGING_CONTAINER says it is $siteurl, not $STAGING_URL. Not touching it."

if [ -z "${1:-}" ]; then
  type=$(wp_in eval 'echo wp_get_environment_type();') || die "Cannot read the environment."
  case "$type" in
    staging) echo "$STAGING_URL: staging" ;;
    development | local) echo "$STAGING_URL: testing ($type)" ;;
    *) echo "$STAGING_URL: $type (neither staging nor testing: run this script with staging or testing)" ;;
  esac
  exit 0
fi

case "$1" in
  staging) type=staging ;;
  testing) type=development ;;
esac
wp_root config set WP_ENVIRONMENT_TYPE "$type" --type=constant
# Written through the container, as root: the volume is often not writable by the user who runs this script.
docker exec -i -u root "$STAGING_CONTAINER" sh -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cat >/var/www/html/wp-content/mu-plugins/site-environment.php && chown www-data:www-data /var/www/html/wp-content/mu-plugins/site-environment.php' <"$HERE/site-environment.php"
echo "$STAGING_URL is now: $1 (WP_ENVIRONMENT_TYPE=$type)"
