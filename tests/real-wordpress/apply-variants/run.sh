#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Slice 203: checks on a real WordPress site that the variants declared with the Modes module are what the front end displays.
#
# Usage: WP="wp --path=/path/to/wordpress --allow-root" run.sh http://127.0.0.1:8090
#
# Needs the scratch site of the other scripts of this directory (the plugin active, Twenty Twenty-Five, plain permalinks, the admin
# account admin/admin, and WP_ENVIRONMENT_TYPE set to local so that application passwords work over HTTP). It creates and removes templates, a classic theme, an application password, and switches themes.
# It is NOT part of the PHPUnit suite.
set -u

BASE=${1:?URL of the site}
WP=${WP:-wp}
HERE=$(cd "$(dirname "$0")" && pwd)
PASS=0
FAIL=0

check() { if [ "$2" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAIL=$((FAIL + 1)); echo "FAIL  $1"; fi; }
get() { curl -sS -m 30 "$BASE/?$1"; }
has() { grep -q -- "$2" <<<"$1"; }
absent() { ! grep -q -- "$2" <<<"$1"; }
w() { $WP "$@"; }

THEME=$(w theme list --status=active --field=name)
THEME_DIR=$(w theme path "$THEME" --dir)
POST=$(w post list --post_type=post --field=ID | head -1)
PAGE=$(w post create --post_type=page --post_status=publish --post_title="og203 page" --porcelain)
CAT=$(w term list category --field=term_id | head -1)

cleanup() {
	w eval-file "$HERE/setup.php" cleanup >/dev/null 2>&1
	w theme activate "$THEME" >/dev/null 2>&1
	rm -rf "$(dirname "$THEME_DIR")/og203-classic"
	w post delete "$PAGE" --force >/dev/null 2>&1
	[ -n "${APPPW:-}" ] && w user application-password delete admin "$APPPW" >/dev/null 2>&1
}
trap cleanup EXIT

echo "WordPress $(w core version), theme $THEME"
w eval-file "$HERE/setup.php" create
# The modes are disabled until they are enabled.
w option update modes_settings '{"enabled":true}' --format=json >/dev/null

echo "--- Templates: the variant of single in print mode"
body=$(get "p=$POST"); absent "$body" 'OG203-SINGLE-PRINT'; check "default mode: the normal template" $?
has "$body" 'modes-mode-web'; check "default mode: class modes-mode-web on the body" $?
body=$(get "p=$POST&mode=print"); has "$body" 'OG203-SINGLE-PRINT'; check "?mode=print: the variant of single is displayed" $?
has "$body" 'modes-mode-print'; check "?mode=print: class modes-mode-print on the body" $?
body=$(get "p=$POST&print"); has "$body" 'OG203-SINGLE-PRINT'; check "?print (the alias of the hack): the variant" $?
body=$(get "p=$POST&mode=ghost"); absent "$body" 'OG203-SINGLE-PRINT'; check "?mode=ghost: ignored, the normal template" $?
body=$(get "p=$POST&mode[]=print"); absent "$body" 'OG203-SINGLE-PRINT'; check "?mode[]=print: ignored" $?
code=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/?p=$POST&mode=print"); [ "$code" = 200 ]; check "HTTP 200 in print mode" $?

echo "--- A variant that is a file of the theme, and a variant for the default mode"
body=$(get "page_id=$PAGE&print"); has "$body" 'OG203-PAGE-PRINT'; check "page: the variant provided by a theme file" $?
body=$(get "page_id=$PAGE"); absent "$body" 'OG203-PAGE-PRINT'; check "page without a mode: normal" $?
body=$(get "cat=$CAT"); has "$body" 'OG203-ARCHIVE-WEB'; check "a variant for the web (default) mode applies to a request without a mode" $?
body=$(get "cat=$CAT&print"); absent "$body" 'OG203-ARCHIVE-WEB'; check "and not in print mode" $?

echo "--- Template parts"
body=$(get "p=$POST&print"); absent "$body" 'OG203-HEADER-PRINT'; check "the variant of single (no parts in it) shows no part variant" $?
w eval '$post = wp_insert_post( array( "post_type" => "wp_template", "post_name" => "single-post", "post_title" => "single-post", "post_content" => "<!-- wp:template-part {\"slug\":\"header\",\"area\":\"header\",\"tagName\":\"header\"} /--><!-- wp:paragraph --><p>OG203-SINGLE-POST</p><!-- /wp:paragraph --><!-- wp:template-part {\"slug\":\"footer\",\"area\":\"footer\",\"tagName\":\"footer\"} /-->", "post_status" => "publish" ) ); wp_set_object_terms( $post, get_stylesheet(), "wp_theme" );' >/dev/null
body=$(get "p=$POST&print"); has "$body" 'OG203-SINGLE-POST'; check "a more specific template exists: it is used, as without a mode (the variant of single does not replace it)" $?
has "$body" 'OG203-HEADER-PRINT'; check "its header part is replaced by the variant (database part)" $?
has "$body" 'OG203-FOOTER-PRINT'; check "its footer part is replaced by the variant (theme file part)" $?
body=$(get "p=$POST"); absent "$body" 'OG203-HEADER-PRINT'; check "without a mode its parts are the normal ones" $?
w eval '$m = \Otherguise\Core\Modules::get( "modes" ); $v = $m->variants(); $v->declare( $v->template( "single-post" ), $m->modes()->get( "print" ), $v->template( "og203-with-parts" ) );' >/dev/null
body=$(get "p=$POST&print"); has "$body" 'OG203-WITH-PARTS'; check "a variant declared for single-post replaces single-post" $?
has "$body" 'OG203-HEADER-PRINT'; check "and its parts are replaced too" $?

echo "--- The variant disappears"
w eval 'foreach ( get_posts( array( "post_type" => "wp_template", "name" => "og203-single-print", "numberposts" => 1 ) ) as $p ) { wp_delete_post( $p->ID, true ); } $v = \Otherguise\Core\Modules::get( "modes" )->variants(); foreach ( triples_statements()->match( null, "modes/has-variant" ) as $s ) { if ( $s->subject()->id() === get_stylesheet() . "//single-post" ) { triples_statements()->delete( $s ); } }' >/dev/null
code=$(curl -sS -o /tmp/og203.html -w '%{http_code}' "$BASE/?p=$POST&print"); body=$(cat /tmp/og203.html)
[ "$code" = 200 ] && has "$body" 'OG203-SINGLE-POST'; check "variant deleted: HTTP 200 and the normal (specific) template" $?
w post list --post_type=wp_template --name=single-post --field=ID | while read -r id; do w post delete "$id" --force >/dev/null; done
body=$(get "p=$POST&print"); absent "$body" 'OG203-SINGLE-PRINT'; check "variant deleted, relation kept: the normal template, no error" $?
absent "$body" '<b>Warning'; check "no PHP warning in the page" $?

echo "--- The site editor and REST are not touched"
APPPW=$(w user application-password create admin og203 --porcelain 2>/dev/null)
if [ -n "$APPPW" ]; then
	PW=$APPPW
	APPPW=$(w user application-password list admin --fields=uuid,name --format=csv | grep og203 | cut -d, -f1)
	rest() { curl -sS -o /tmp/og203-rest.json -w '%{http_code}' -u "admin:$PW" "$BASE/?rest_route=$1"; }
	code=$(rest "/wp/v2/templates&context=edit&_fields=id"); plain=$(tr -d ' ' </tmp/og203-rest.json)
	[ "$code" = 200 ] && has "$plain" 'twentytwentyfive'; check "REST works with an application password (HTTP $code)" $?
	code=$(rest "/wp/v2/templates&context=edit&_fields=id&mode=print&print"); with=$(tr -d ' ' </tmp/og203-rest.json)
	[ "$code" = 200 ] && [ "$plain" = "$with" ]; check "the list of templates of REST is the same with ?mode=print" $?
	code=$(rest "/wp/v2/block-renderer/core/template-part&context=edit&attributes%5Bslug%5D=header&attributes%5Btheme%5D=$THEME"); part=$(cat /tmp/og203-rest.json)
	[ "$code" = 200 ] && has "$part" 'rendered'; check "the block renderer works (HTTP $code)" $?
	code=$(rest "/wp/v2/block-renderer/core/template-part&context=edit&attributes%5Bslug%5D=header&attributes%5Btheme%5D=$THEME&mode=print&print"); part_print=$(cat /tmp/og203-rest.json)
	[ "$code" = 200 ] && [ "$part" = "$part_print" ] && absent "$part_print" 'OG203-HEADER-PRINT'; check "the block renderer shows the normal header in print mode" $?
else
	echo "SKIP  application passwords are not available"
fi

echo "--- A classic theme"
C="$(dirname "$THEME_DIR")/og203-classic"
mkdir -p "$C"
printf '%s\n' '/*' 'Theme Name: og203 classic' 'Version: 1.0' '*/' >"$C/style.css"
for t in index single single-print; do printf '%s\n' "<?php wp_head(); ?><body><p>OG203-CLASSIC-$t</p><?php wp_footer(); ?></body>" >"$C/$t.php"; done
w theme activate og203-classic >/dev/null
w eval '$m = \Otherguise\Core\Modules::get( "modes" ); $v = $m->variants(); $v->declare( $v->template( "single" ), $m->modes()->get( "print" ), $v->template( "single-print" ) );' >/dev/null
body=$(get "p=$POST"); has "$body" 'OG203-CLASSIC-single<'; check "classic theme, no mode: single.php" $?
body=$(get "p=$POST&mode=print"); has "$body" 'OG203-CLASSIC-single-print'; check "classic theme, print mode: single-print.php" $?
w eval '$m = \Otherguise\Core\Modules::get( "modes" ); $v = $m->variants(); foreach ( triples_statements()->match( null, "modes/has-variant" ) as $s ) { triples_statements()->delete( $s ); }' >/dev/null
w theme activate "$THEME" >/dev/null

echo "--- The relations of another theme do not apply after a switch of theme"
body=$(get "p=$POST&print"); absent "$body" 'OG203-CLASSIC'; check "back on the block theme: no trace of the classic theme" $?

w option delete modes_settings >/dev/null 2>&1

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
