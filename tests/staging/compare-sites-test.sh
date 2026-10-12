#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Tests of tools/staging/compare-sites.sh with a stand-in for curl that serves two fixture pages. Nothing here touches a real site.
# Run: tests/staging/compare-sites-test.sh
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../../tools/staging/compare-sites.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
PASS=0 FAIL=0
check() { if [ "$2" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAIL=$((FAIL + 1)); echo "FAIL  $1"; fi; }

mkdir -p "$T/bin"
export T
cat >"$T/bin/curl" <<'STUB'
#!/usr/bin/env bash
url=${!#}
echo "curl $*" >>"$T/curl.log"
case "$*" in
  *" -I "*|*"-I "*)
    case "$url" in
      *missing*) printf '404' ;;
      *) printf '200' ;;
    esac
    exit 0 ;;
esac
case "$url" in
  https://e.test/*) cat "$T/prod.html" ;;
  https://sb.test/*) cat "$T/staging.html" ;;
  *) exit 22 ;;
esac
STUB
chmod +x "$T/bin/curl"
export PATH="$T/bin:$PATH"
export PROD_URL=https://e.test STAGING_URL=https://sb.test TESTING_URL=https://t.test TESTING_CONTAINER=x TESTING_VOLUME=y

cat >"$T/prod.html" <<'HTML'
<html><head><title>Voyages</title>
<link rel='stylesheet' id='a' href='https://e.test/wp-content/themes/tt5/style.min.css?ver=1.5' />
<link rel='stylesheet' id='b' href='https://e.test/wp-content/plugins/x/x.css?ver=2' />
<style id="global-styles-inline-css">@font-face{font-family:"Serif";src:url('https://e.test/wp-content/fonts/serif.woff2');} body{font-family: Serif, serif;}</style>
</head><body class="home blog wp-theme-tt5"></body></html>
HTML
cat >"$T/staging.html" <<'HTML'
<html><head><title>Voyages</title>
<link rel='stylesheet' id='a' href='https://sb.test/wp-content/themes/tt5/style.min.css?ver=1.5' />
<style id="global-styles-inline-css">@font-face{font-family:"Serif";src:url('https://e.test/wp-content/fonts/serif.woff2');} body{font-family: Sans, sans-serif;}</style>
<style id="extra-inline-css">a{}</style>
</head><body class="home blog wp-theme-child"></body></html>
HTML

ro() { bash "$SCRIPT" "$@"; }

ro --with=bogus >/dev/null 2>&1; check "refuses an unknown site" $((! $?))
ro --bogus >/dev/null 2>&1; check "refuses an unknown option" $((! $?))
ro >"$T/out" 2>&1; rc=$?; check "runs" $rc
grep -q "only production: .*plugins/x/x.css" "$T/out"; check "a stylesheet that only production loads is reported" $?
! grep -q "style.min.css" "$T/out"; check "the address of the copy is replaced, so the same stylesheet is not a difference" $?
grep -q "only staging:       extra-inline-css" "$T/out"; check "an inline style that only the copy has is reported" $?
grep -q "only production: font-family: Serif, serif" "$T/out" && grep -q "only staging:       font-family: Sans, sans-serif" "$T/out"; check "the font families that differ are reported" $?
grep -q "only production: wp-theme-tt5" "$T/out" && grep -q "only staging:       wp-theme-child" "$T/out"; check "the classes of the body that differ are reported" $?
grep -q "FROM ANOTHER ORIGIN" "$T/out"; check "a font file announced from production is flagged as another origin" $?
grep -q "Differences found" "$T/out"; check "the verdict says so" $?
ro --path=/missing --print >/dev/null 2>&1; grep -q "/missing?print" "$T/curl.log"; check "--print adds ?print to the page" $?
ro --path='/?p=3' --mode=print >/dev/null 2>&1; grep -q "/?p=3&mode=print" "$T/curl.log"; check "--mode adds &mode=… to a page that has a query" $?
ro --with=testing >/dev/null 2>&1; grep -q "https://t.test/" "$T/curl.log"; check "--with=testing compares with the testing site" $?

# Identical pages.
sed 's#https://e.test#https://sb.test#g' "$T/prod.html" >"$T/staging.html"
ro >"$T/out" 2>&1; grep -q "No difference found" "$T/out"; check "identical pages (apart from the address) show no difference" $?

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
