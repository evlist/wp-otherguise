#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Where is the CSS that the templates of a theme need? Takes the classes that the templates and the parts of the theme use and says, for each,
# where it is styled: the additional CSS of each theme, the global styles, the snippets of the plugin Custom CSS & JS, the CSS files of the
# plugins, of the themes and of Custom CSS & JS. A class that is found nowhere is marked. Read-only. Meant to run on the Docker host.
#
# Usage: css-sources.sh --site=<prod|staging|testing> [--theme=<slug>] [--find=<term>]...
#   --theme   the theme whose templates are looked at (default: wp-twenty-twenty-five-printable).
#   --find    an extra term to look for in the same places, for example a font name (--find=Ultra); can be repeated.
#
# Settings (environment variables, as in rollout.sh): PROD_CONTAINER, STAGING_CONTAINER, TESTING_CONTAINER...
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=target.sh
. "$HERE/target.sh"
PROD_CONTAINER=${PROD_CONTAINER:-docker-e-vli-st_wordpress_1}
STAGING_CONTAINER=${STAGING_CONTAINER:-docker-sb-vli-st_wordpress-sb_1}

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
quiet_warnings() { grep -v -e 'Constant WP_DEBUG already defined' -e 'Function _load_textdomain_just_in_time was called' || true; }

SITE="" THEME=wp-twenty-twenty-five-printable FIND=()
for arg in "$@"; do
  case "$arg" in
    --site=prod | --site=staging | --site=testing) SITE=${arg#--site=} ;;
    --site=*) die "Unknown site: ${arg#--site=} (prod, staging or testing)." ;;
    --theme=*) THEME=${arg#--theme=} ;;
    --find=*) FIND+=("${arg#--find=}") ;;
    -h | --help) sed -n '2,/^set -euo/{/^set -euo/!p}' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "Unknown option: $arg" ;;
  esac
done
[ -n "$SITE" ] || die "Say which site: --site=prod, --site=staging or --site=testing."
[[ "$THEME" =~ ^[A-Za-z0-9_.-]+$ ]] || die "Unknown theme name: $THEME"
if [ "$SITE" = prod ]; then
  CONTAINER=$PROD_CONTAINER
else
  select_target "$SITE" || exit 2
  CONTAINER=$STAGING_CONTAINER
fi
[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" = "true" ] || die "Container $CONTAINER is not running."

# The terms go to PHP as a quoted array: single quotes escaped.
php_find="array("
for term in "${FIND[@]+"${FIND[@]}"}"; do php_find+="'${term//\'/\\\'}', "; done
php_find+=")"
code=$(sed '1d' "$HERE/css-sources.php")
docker exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache "$CONTAINER" wp eval "\$otherguise_theme = '$THEME'; \$otherguise_find = $php_find; $code" 2> >(quiet_warnings >&2)
