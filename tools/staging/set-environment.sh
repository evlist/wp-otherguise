#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Declares what a site is, which is what the coloured frame and the label of site-environment.php say: sets the constant
# WP_ENVIRONMENT_TYPE of the staging or of the testing site and (re)installs the must-use plugin there. sync-site.sh does it at the end
# of every copy; use this script on a new server, before its first copy, or after an update of site-environment.php. It changes nothing
# else, refuses a site that does not say it is the one expected, and never writes to production.
#
# Usage: set-environment.sh <staging|testing>     set the environment of that site and install the plugin
#        set-environment.sh --show <staging|testing>   say what the site thinks it is
#
# Settings (environment variables, as in sync-site.sh and target.sh): STAGING_CONTAINER, STAGING_URL, TESTING_CONTAINER, TESTING_URL.
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=target.sh
. "$HERE/target.sh"
STAGING_CONTAINER=${STAGING_CONTAINER:-docker-sb-vli-st_wordpress-sb_1}
STAGING_URL=${STAGING_URL:-https://sb.vli.st}

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
quiet_warnings() { grep -v -e 'Constant WP_DEBUG already defined' -e 'Function _load_textdomain_just_in_time was called' || true; }
wp_in() { docker exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache "$STAGING_CONTAINER" wp "$@" 2> >(quiet_warnings >&2); }
wp_root() { docker exec -u root -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache "$STAGING_CONTAINER" wp --allow-root "$@" 2> >(quiet_warnings >&2); }

SHOW=0
if [ "${1:-}" = "--show" ]; then SHOW=1; shift; fi
case "${1:-}" in
  staging | testing) TARGET=$1 ;;
  -h | --help) sed -n '2,/^set -euo/{/^set -euo/!p}' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
  *) die "Say which site: staging or testing (see --help)." ;;
esac
[ "$#" -eq 1 ] || die "Too many arguments."
select_target "$TARGET" || exit 2

[ "$(docker inspect -f '{{.State.Running}}' "$STAGING_CONTAINER" 2>/dev/null)" = "true" ] || die "Container $STAGING_CONTAINER is not running."
siteurl=$(wp_in option get siteurl) || die "Cannot read the address of the site in $STAGING_CONTAINER."
[ "$siteurl" = "$STAGING_URL" ] || die "The site in $STAGING_CONTAINER says it is $siteurl, not $STAGING_URL. Not touching it."

if [ "$SHOW" -eq 1 ]; then
  type=$(wp_in eval 'echo wp_get_environment_type();') || die "Cannot read the environment."
  case "$type" in
    staging) shown=staging ;;
    development | local) shown="testing ($type)" ;;
    *) shown="$type" ;;
  esac
  echo "$STAGING_URL ($TARGET site): $shown"
  exit 0
fi

case "$TARGET" in
  staging) type=staging ;;
  testing) type=development ;;
esac
wp_root config set WP_ENVIRONMENT_TYPE "$type" --type=constant
# Written through the container, as root: the volume is often not writable by the user who runs this script.
docker exec -i -u root "$STAGING_CONTAINER" sh -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cat >/var/www/html/wp-content/mu-plugins/site-environment.php && chown www-data:www-data /var/www/html/wp-content/mu-plugins/site-environment.php' <"$HERE/site-environment.php"
echo "$STAGING_URL is now the $TARGET site (WP_ENVIRONMENT_TYPE=$type)"
