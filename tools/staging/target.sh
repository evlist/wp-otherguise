# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Sourced by sync-site.sh, set-environment.sh and rollout.sh. There are three sites: production, staging and testing. The scripts that
# work on "the copy" know it by the STAGING_* variables; select_target makes them describe the testing site when asked.
#
# Settings for the testing site (no defaults: they depend on where Eric puts it): TESTING_CONTAINER, TESTING_VOLUME, TESTING_URL and,
# when its WordPress container has no mysql client, TESTING_DB_CONTAINER. The staging site has the defaults of the old "sandbox" (sb.vli.st).

# select_target <staging|testing>: leaves STAGING_CONTAINER, STAGING_VOLUME, STAGING_URL and STAGING_DB_CONTAINER describing the site.
select_target() {
  case "$1" in
    staging) ;;
    testing)
      : "${TESTING_CONTAINER:?Give TESTING_CONTAINER (the WordPress container of the testing site)}"
      : "${TESTING_VOLUME:?Give TESTING_VOLUME (its volume of /var/www/html on the host)}"
      : "${TESTING_URL:?Give TESTING_URL (its address, without a trailing slash)}"
      STAGING_CONTAINER=$TESTING_CONTAINER STAGING_VOLUME=$TESTING_VOLUME STAGING_URL=$TESTING_URL STAGING_DB_CONTAINER=${TESTING_DB_CONTAINER:-}
      ;;
    *) echo "Unknown target \"$1\": staging or testing." >&2; return 2 ;;
  esac
}
