#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# The templates and template parts that the site editor stored in the database of a site, which hide the files of the theme (a
# database copy is used instead of the file) and which are not in git. Meant to run on the Docker host. Used when the templates move
# into the child theme (evlist/wp-twenty-twenty-five-printable): export them, then remove the copies that would shadow the files.
#
# Usage: templates.sh <command> --site=<prod|staging|testing> [options]
#   check                      list the templates of the database and what each is to the active theme:
#                              SHADOWS (the active theme has a file for it: the copy hides the file), DATABASE-ONLY (no file),
#                              UNUSED (attached to another theme, for example the parent). Exit code 1 if something SHADOWS.
#   export <dir>               write every template and part of the database as files under <dir> (templates/<slug>.html,
#                              parts/<slug>.html) with customTemplates.json, the fragment of theme.json for the custom templates
#                              (post type "post" is a guess: check it). The "theme" attribute of the template parts is removed.
#   delete --backup-dir <dir>  after writing a backup of them (export of the copies that shadow a file), delete those copies.
#                              Asks you to type the name of the container; --yes skips it, --dry-run only lists them.
#   --yes, --dry-run           as above.
# Read-only except delete. Never writes to production without the confirmation.
#
# Settings (environment variables, as in rollout.sh): PROD_CONTAINER, STAGING_CONTAINER, TESTING_CONTAINER...
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=target.sh
. "$HERE/target.sh"
PROD_CONTAINER=${PROD_CONTAINER:-docker-e-vli-st_wordpress_1}
STAGING_CONTAINER=${STAGING_CONTAINER:-docker-sb-vli-st_wordpress-sb_1}
STAGING_URL=${STAGING_URL:-https://sb.vli.st}

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
quiet_warnings() { grep -v -e 'Constant WP_DEBUG already defined' -e 'Function _load_textdomain_just_in_time was called' || true; }

COMMAND=${1:-}
[ -z "$COMMAND" ] || shift
SITE="" YES=0 DRY_RUN=0 TARGET_DIR="" BACKUP_DIR="" POSITIONAL=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --site=prod | --site=staging | --site=testing) SITE=${1#--site=} ;;
    --site=*) die "Unknown site: ${1#--site=} (prod, staging or testing)." ;;
    --yes) YES=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --backup-dir) [ "$#" -ge 2 ] || die "--backup-dir needs a directory."; BACKUP_DIR=$2; shift ;;
    --backup-dir=*) BACKUP_DIR=${1#--backup-dir=} ;;
    -h | --help) COMMAND=help ;;
    -*) die "Unknown option: $1" ;;
    *) [ -z "$POSITIONAL" ] || die "Too many arguments."; POSITIONAL=$1 ;;
  esac
  shift
done
case "$COMMAND" in
  help) sed -n '2,/^set -euo/{/^set -euo/!p}' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
  check | export | delete) ;;
  *) die "Say what to do: check, export <dir> or delete --backup-dir <dir> (see --help)." ;;
esac
[ -n "$SITE" ] || die "Say which site: --site=prod, --site=staging or --site=testing."
[ "$COMMAND" = export ] && { [ -n "$POSITIONAL" ] || die "export needs a directory."; TARGET_DIR=$POSITIONAL; }
[ "$COMMAND" = export ] || [ -z "$POSITIONAL" ] || die "Too many arguments."
[ "$COMMAND" != delete ] || [ -n "$BACKUP_DIR" ] || die "delete needs --backup-dir <dir>: the copies are backed up first."

if [ "$SITE" = prod ]; then
  CONTAINER=$PROD_CONTAINER
else
  select_target "$SITE" || exit 2
  CONTAINER=$STAGING_CONTAINER
fi
[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" = "true" ] || die "Container $CONTAINER is not running."

# Runs templates.php on the site with the given action: the code goes through the command line of `wp eval`.
run_php() { # action
  local code
  code=$(sed '1d' "$HERE/templates.php")
  docker exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache "$CONTAINER" wp eval "\$otherguise_action = '$1'; $code" 2> >(quiet_warnings >&2)
}

# Writes the "@@FILE path ... @@END" stream under a directory.
write_files() { # directory
  local dir=$1 count
  mkdir -p "$dir"
  awk -v dir="$dir" '
    /^@@FILE / { path = dir "/" substr($0, 8); system("mkdir -p \"$(dirname \"" path "\")\""); out = path; printf "" > out; next }
    /^@@END$/ { close(out); out = ""; next }
    out != "" { print >> out }
  '
  count=$(find "$dir" -type f -name '*.html' | wc -l)
  echo "$count file(s) under $dir"
}

case "$COMMAND" in
check)
  out=$(run_php check)
  echo "$out"
  ! grep -q '^SHADOWS' <<<"$out" || { echo; echo "Some database copies hide the files of the theme: see delete."; exit 1; }
  ;;
export)
  run_php export-all | write_files "$TARGET_DIR"
  echo "Check $TARGET_DIR/customTemplates.json (post types) before putting it into theme.json."
  ;;
delete)
  shadows=$(run_php check | grep '^SHADOWS' || true)
  if [ -z "$shadows" ]; then echo "No database copy hides a file of the theme: nothing to delete."; exit 0; fi
  echo "These database copies hide the files of the theme:"
  echo "$shadows" | sed 's/^/  /'
  if [ "$DRY_RUN" -eq 1 ]; then echo "Dry run: nothing deleted."; exit 0; fi
  run_php export-shadowing | write_files "$BACKUP_DIR"
  [ "$YES" -eq 1 ] || {
    echo
    echo "The copies are backed up in $BACKUP_DIR. Deleting them makes $CONTAINER use the files of the theme."
    read -r -p "Type the name of the container to go on [$CONTAINER]: " answer
    [ "$answer" = "$CONTAINER" ] || die "Not confirmed."
  }
  run_php delete-shadowing
  ;;
esac
