#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Test of tools/staging/css-sources.sh and css-sources.php on a scratch WordPress with WP-CLI (not the CI): a stand-in for docker hands the
# `wp eval` of the script to the real WP-CLI of the scratch site, which must have the child theme evlist/wp-twenty-twenty-five-printable
# installed under the name wp-twenty-twenty-five-printable (or THEME). It creates posts and removes them. Run:
# WPSH=/path/to/wp.sh THEME=<name> tests/staging/css-sources-test.sh. Never use a site that matters.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
WPSH=${WPSH:?WPSH must run WP-CLI on a scratch site}
THEME=${THEME:-twentytwentyfive-printable}
SCRIPT="$HERE/../../tools/staging/css-sources.sh"
T=$(mktemp -d)
PASS=0 FAIL=0
check() { if [ "$2" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAIL=$((FAIL + 1)); echo "FAIL  $1"; fi; }

mkdir -p "$T/bin"
cat >"$T/bin/docker" <<STUB
#!/usr/bin/env bash
case "\$1" in
  inspect) echo true; exit 0 ;;
esac
shift
while [[ "\${1:-}" == -* ]]; do case "\$1" in -u | -e) shift 2 ;; *) shift ;; esac; done
shift
[ "\$1" = wp ] && shift
exec $WPSH "\$@"
STUB
chmod +x "$T/bin/docker"
export PATH="$T/bin:$PATH"
export PROD_CONTAINER=prod STAGING_CONTAINER=stg STAGING_URL=https://sb.test

fixtures() { # create|remove
  $WPSH eval '
    foreach ( get_posts( array( "post_type" => array( "custom-css-js", "wp_global_styles", "custom_css" ), "post_status" => "any", "numberposts" => -1 ) ) as $p ) {
      if ( false !== strpos( $p->post_content . $p->post_title, "ogcss" ) ) { wp_delete_post( $p->ID, true ); }
    }
    if ( "'"$1"'" === "remove" ) { return; }
    wp_update_custom_css_post( "/* ogcss */ .author-line { color: red; }", array( "stylesheet" => "'"$THEME"'" ) );
    $id = wp_insert_post( array( "post_type" => "custom-css-js", "post_title" => "ogcss snippet", "post_status" => "publish", "post_content" => ".title-date-line { margin: 0; } @font-face { font-family: ogcssfont; }" ) );
    update_post_meta( $id, "options", array( "language" => "css", "side" => "frontend", "type" => "header" ) );
    wp_insert_post( array( "post_type" => "wp_global_styles", "post_title" => "ogcss global", "post_name" => "wp-global-styles-ogcss", "post_status" => "publish", "post_content" => wp_json_encode( array( "version" => 3, "styles" => array( "css" => ".print-link { display: none; }" ) ) ) ) );
  ' >/dev/null 2>&1
}
trap 'fixtures remove; rm -rf "$T"' EXIT
fixtures create

ro() { bash "$SCRIPT" "$@"; }

ro >/dev/null 2>&1; check "needs a site" $((! $?))
ro --site=nowhere >/dev/null 2>&1; check "refuses an unknown site" $((! $?))
ro --site=prod --theme='bad name' >/dev/null 2>&1; check "refuses a theme name that is not a name" $((! $?))
ro --site=prod --theme=no-such-theme >"$T/out" 2>&1; check "a theme that is not installed is reported" $((! $?))
grep -q "is not installed" "$T/out"; check "...with that reason" $?

ro --site=staging --theme="$THEME" --find=ogcssfont --find="nothing-like-this" >"$T/out" 2>&1; rc=$?; check "the report runs" $rc
[ "$rc" -eq 0 ] || head -20 "$T/out"
grep -q "classes in its templates" "$T/out"; check "it counts the classes of the templates and the places looked at" $?
grep -q "author-line => additional CSS of the theme" "$T/out"; check "a class styled by the additional CSS is found there" $?
grep -q "title-date-line => Custom CSS & JS snippet [0-9]* \"ogcss snippet\" (css, frontend, header, publish)" "$T/out"; check "a class styled by a snippet of Custom CSS & JS is found, with the settings of the snippet" $?
grep -q "print-link => global styles wp-global-styles-ogcss" "$T/out"; check "a class styled by the global styles is found there" $?
grep -q "ogcssfont => Custom CSS & JS snippet" "$T/out"; check "an extra term is found" $?
grep -q "nothing-like-this => -- NOT FOUND" "$T/out"; check "an extra term that is nowhere is marked" $?
grep -qE "^  [a-z-]+ => -- NOT FOUND" "$T/out"; check "a class that is styled nowhere is marked" $?
! grep -qE "^  (wp-|has-|is-)" "$T/out"; check "the classes of WordPress are left out" $?
grep -q "Fonts of the font library" "$T/out"; check "the fonts of the library are listed" $?
grep -q "ogcss snippet\": css, frontend, header, priority ?, publish" "$T/out"; check "every snippet is listed with its settings" $?
grep -q "dashicons-printer => file wp-includes/css/dashicons" "$T/out"; check "the CSS files of WordPress itself are looked at (the icon font)" $?

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
