#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Compares a page of production with the same page of staging (or testing), to see what differs without screenshots: the stylesheets that the
# page loads, the inline styles, the font files (@font-face) and whether they can be fetched, the font families, the classes of the body and
# the title. The addresses of the other site are replaced by those of production before comparing, so only real differences show. Read-only.
# Meant to run anywhere that can reach both sites (the Docker host, your computer).
#
# Usage: compare-sites.sh [--with=staging|testing] [--path=/] [--print] [--mode=<mode>]
#   --with    the site to compare with production (default: staging).
#   --path    the page, from the root of the site (default: /).
#   --print   the same page with ?print (and --mode=<mode> with ?mode=<mode>).
#
# Settings (environment variables, as in sync-site.sh): PROD_URL, STAGING_URL, TESTING_URL. COMPARE_CURL_OPTS: more options for curl, for
# example '-u user:password' when the copy is behind HTTP authentication (never put the password in a file of the repository).
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=target.sh
. "$HERE/target.sh"
PROD_URL=${PROD_URL:-https://e.vli.st}
STAGING_URL=${STAGING_URL:-https://sb.vli.st}

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

WITH=staging PATH_ARG=/ QUERY=""
for arg in "$@"; do
  case "$arg" in
    --with=staging | --with=testing) WITH=${arg#--with=} ;;
    --with=*) die "Unknown site: ${arg#--with=} (staging or testing)." ;;
    --path=*) PATH_ARG=${arg#--path=} ;;
    --print) QUERY="print" ;;
    --mode=*) QUERY="mode=${arg#--mode=}" ;;
    -h | --help) sed -n '2,/^set -euo/{/^set -euo/!p}' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "Unknown option: $arg" ;;
  esac
done
select_target "$WITH" || exit 2
OTHER_URL=$STAGING_URL
[[ "$PATH_ARG" == /* ]] || PATH_ARG=/$PATH_ARG
suffix=""
if [ -n "$QUERY" ]; then
  case "$PATH_ARG" in *\?*) suffix="&$QUERY" ;; *) suffix="?$QUERY" ;; esac
fi
prod_host=${PROD_URL#*://}
other_host=${OTHER_URL#*://}
[ "$prod_host" != "$other_host" ] || die "Both addresses are the same."

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
# shellcheck disable=SC2086
fetch() { curl -sL --max-time 60 ${COMPARE_CURL_OPTS:-} "$1$PATH_ARG$suffix"; }
fetch "$PROD_URL" >"$WORK/a.html" || die "Cannot fetch $PROD_URL$PATH_ARG$suffix"
fetch "$OTHER_URL" >"$WORK/b.raw.html" || die "Cannot fetch $OTHER_URL$PATH_ARG$suffix"
[ -s "$WORK/a.html" ] || die "$PROD_URL$PATH_ARG$suffix gave an empty page."
[ -s "$WORK/b.raw.html" ] || die "$OTHER_URL$PATH_ARG$suffix gave an empty page."
# The other site's own address becomes production's, in its plain and JSON-escaped forms.
sed -e "s#https\\?://$other_host#https://$prod_host#g" -e "s#https:\\\\/\\\\/$other_host#https:\\\\/\\\\/$prod_host#g" -e "s#//$other_host#//$prod_host#g" "$WORK/b.raw.html" >"$WORK/b.html"

stylesheets() { grep -o '<link[^>]*rel=.stylesheet.[^>]*>' "$1" | sed -E "s/.*href=[\"']([^\"']*)[\"'].*/\\1/; s/\\?ver=[^&\"']*//" | sort -u; }
inline_styles() { grep -o '<style[^>]*id=[^>]*>' "$1" | sed -E "s/.*id=[\"']([^\"']*)[\"'].*/\\1/" | sort -u; }
font_urls() { grep -oE "url\\([\"']?[^)\"']*\\.(woff2|woff|ttf|otf)[^)\"']*[\"']?\\)" "$1" | sed -E "s/^url\\([\"']?//; s/[\"']?\\)\$//; s/\\?.*//" | sort -u; }
font_families() { grep -oE 'font-family:[^;}"]*' "$1" | sed 's/[[:space:]]\+/ /g' | sort -u; }
body_classes() { grep -o '<body[^>]*class="[^"]*"' "$1" | sed -E 's/.*class="([^"]*)"/\1/' | tr ' ' '\n' | sort -u; }
title() { grep -o '<title>[^<]*</title>' "$1" | head -1; }

differences=0
section() { # title function
  local title=$1 fn=$2
  "$fn" "$WORK/a.html" >"$WORK/a.txt" || true
  "$fn" "$WORK/b.html" >"$WORK/b.txt" || true
  printf '\n== %s (production: %s, %s: %s)\n' "$title" "$(wc -l <"$WORK/a.txt")" "$WITH" "$(wc -l <"$WORK/b.txt")"
  if diff -q "$WORK/a.txt" "$WORK/b.txt" >/dev/null; then
    echo "   the same"
  else
    differences=1
    { diff "$WORK/a.txt" "$WORK/b.txt" || true; } | grep '^[<>]' | sed "s/^< /   only production: /; s/^> /   only $WITH:       /"
  fi
}

printf 'Comparing %s%s%s (production) with %s%s%s (%s)\n' "$PROD_URL" "$PATH_ARG" "$suffix" "$OTHER_URL" "$PATH_ARG" "$suffix" "$WITH"
section "Title" title
section "Stylesheets loaded" stylesheets
section "Inline styles (ids)" inline_styles
section "Font files (@font-face)" font_urls
section "Font families used" font_families
section "Classes of the body" body_classes

# Can the font files that the copy announces be fetched, and from where?
printf '\n== Font files announced by %s: can they be fetched?\n' "$WITH"
font_urls "$WORK/b.raw.html" >"$WORK/fonts.txt" || true
if [ ! -s "$WORK/fonts.txt" ]; then
  echo "   none in the page itself (fonts of the theme are in its stylesheets: look at Stylesheets loaded)"
else
  while read -r url; do
    case "$url" in
      //*) full="https:$url" ;;
      /*) full="$OTHER_URL$url" ;;
      *) full=$url ;;
    esac
    # shellcheck disable=SC2086
    code=$(curl -s -o /dev/null -w '%{http_code}' -I --max-time 30 ${COMPARE_CURL_OPTS:-} "$full" || echo "000")
    origin=""
    case "$full" in "$OTHER_URL"*) ;; *) origin="  FROM ANOTHER ORIGIN (the browser needs CORS headers to use it)" ;; esac
    printf '   %s  %s%s\n' "$code" "$full" "$origin"
    if [ -n "$origin" ] || [ "$code" != 200 ]; then differences=1; fi
  done <"$WORK/fonts.txt"
fi

echo
if [ "$differences" -eq 0 ]; then echo "No difference found in what was compared."; else echo "Differences found (see above)."; fi
