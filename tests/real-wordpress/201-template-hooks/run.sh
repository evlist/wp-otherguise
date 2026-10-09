#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Slice 201: tries four ways of replacing a template or a template part by its variant in a given mode, on a real WordPress site.
#
# Usage: run.sh /path/to/wordpress http://127.0.0.1:8090
#
# The site must be a scratch site (the script creates and deletes templates, a template part, a classic theme and options, and
# switches themes), served at the given URL, with WP-CLI available as `wp` (or in $WP), a block theme as active theme with the templates
# `single` and `page` and the template parts `header` and `footer` (Twenty Twenty-Five), and permalinks set to "plain".
# It is NOT part of the PHPUnit suite: it needs a WordPress site, a database and a web server.
set -u

WP_PATH=${1:?path of the WordPress site}
BASE=${2:?URL of the site}
WP=${WP:-wp}
# `command` skips this function, which has the same name as the program.
wp() { command $WP --path="$WP_PATH" --allow-root "$@"; }
command -v "$WP" >/dev/null 2>&1 || [ -x "$WP" ] || { echo "WP-CLI not found: set WP to the command" >&2; exit 2; }

THEME=$(wp theme list --status=active --field=name)
THEME_DIR="$WP_PATH/wp-content/themes/$THEME"
MU="$WP_PATH/wp-content/mu-plugins"
HERE=$(cd "$(dirname "$0")" && pwd)
PASS=0
FAIL=0

check() { # description, condition result (0 = ok)
	if [ "$2" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAIL=$((FAIL + 1)); echo "FAIL  $1"; fi
}

get() { curl -sS "$BASE/?$1"; }
has() { grep -q -- "$2" <<<"$1"; }
template_id() { grep -o '"template_id":"[^"]*"' <<<"$1" | sed 's/\\//g'; }

create() { # type slug content
	local id
	id=$(wp post create --post_type="$1" --post_status=publish --post_name="$2" --post_title="$2" --post_content="$3" --porcelain)
	wp post term set "$id" wp_theme "$THEME" >/dev/null
}

variants() { wp option update og201_variants "$1" >/dev/null; }

cleanup() {
	for type in wp_template wp_template_part; do
		for id in $(wp post list --post_type=$type --post_status=any --field=ID); do wp post delete "$id" --force >/dev/null; done
	done
	rm -f "$THEME_DIR/templates/og201-"*.html "$THEME_DIR/parts/og201-"*.html
	rm -rf "$WP_PATH/wp-content/themes/og201-classic"
	wp option delete og201_variants >/dev/null 2>&1
	wp theme activate "$THEME" >/dev/null 2>&1
	rm -f "$MU/og201.php"
}
trap cleanup EXIT

cleanup
ln -s "$HERE/og201.php" "$MU/og201.php"
wp term create wp_theme "$THEME" >/dev/null 2>&1
POST=$(wp post list --post_type=post --field=ID | head -1)
PAGE=$(wp post create --post_type=page --post_status=publish --post_title="og201 page" --porcelain)

echo "WordPress $(wp core version), theme $THEME, PHP $(php -r 'echo PHP_VERSION;')"

echo "--- Single post: a variant that is a database template"
create wp_template og201-single-print '<!-- wp:paragraph --><p>OG201-SINGLE-PRINT-DB</p><!-- /wp:paragraph -->'
variants '{"single":"og201-single-print"}'
body=$(get "p=$POST"); has "$body" 'OG201-SINGLE-PRINT-DB'; check "no mode: the normal template is used" $((! $?))
has "$(template_id "$body")" "$THEME//single\""; check "no mode: the template is single" $?
body=$(get "p=$POST&mode=print")
has "$(template_id "$body")" "$THEME//og201-single-print"; check "B: mode print: the variant is the current template" $?
has "$body" 'OG201-SINGLE-PRINT-DB'; check "B: mode print: the variant's content is rendered" $?
body=$(get "p=$POST&mode=print&og_strategy=A")
has "$body" 'OG201-SINGLE-PRINT-DB'; check "A (get_block_templates, the hack): the variant is rendered" $?
body=$(get "p=$POST&mode=print&og_strategy=C")
has "$body" 'OG201-SINGLE-PRINT-DB'; check "C (template_include and globals): the variant is rendered" $?

echo "--- A declared variant that does not exist"
variants '{"single":"og201-does-not-exist"}'
code=$(curl -sS -o /tmp/og201-body.html -w '%{http_code}' "$BASE/?p=$POST&mode=print")
has "$(template_id "$(cat /tmp/og201-body.html)")" "$THEME//single\""; check "B: a missing variant falls back to the template, with HTTP $code" $?

echo "--- A more specific template exists: the variant applies to the template that core would use"
create wp_template single-post '<!-- wp:paragraph --><p>OG201-SINGLE-POST-DB</p><!-- /wp:paragraph -->'
create wp_template og201-single-post-print '<!-- wp:paragraph --><p>OG201-SINGLE-POST-PRINT-DB</p><!-- /wp:paragraph -->'
variants '{"single":"og201-single-print"}'
body=$(get "p=$POST&mode=print")
has "$(template_id "$body")" "$THEME//single-post\""; check "B: variant declared for single only: single-post wins, as without a mode" $?
variants '{"single":"og201-single-print","single-post":"og201-single-post-print"}'
body=$(get "p=$POST&mode=print")
has "$(template_id "$body")" "$THEME//og201-single-post-print"; check "B: variant declared for single-post: it is used" $?

echo "--- A variant that is a file of the theme"
printf '%s' '<!-- wp:paragraph --><p>OG201-PAGE-PRINT-FILE</p><!-- /wp:paragraph -->' >"$THEME_DIR/templates/og201-page-print.html"
variants '{"page":"og201-page-print"}'
body=$(get "page_id=$PAGE&mode=print")
has "$body" 'OG201-PAGE-PRINT-FILE'; check "B: a variant provided by a theme file is rendered" $?

echo "--- Template parts"
create wp_template_part og201-header-print '<!-- wp:paragraph --><p>OG201-HEADER-PRINT-DB</p><!-- /wp:paragraph -->'
printf '%s' '<!-- wp:paragraph --><p>OG201-FOOTER-PRINT-FILE</p><!-- /wp:paragraph -->' >"$THEME_DIR/parts/og201-footer-print.html"
printf '%s' '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /--><!-- wp:paragraph --><p>OG201-WITH-PARTS</p><!-- /wp:paragraph --><!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->' >"$THEME_DIR/templates/og201-with-parts.html"
variants '{"single-post":"og201-with-parts","header":"og201-header-print","footer":"og201-footer-print"}'
body=$(get "p=$POST&mode=print&og_strategy=B")
has "$body" 'OG201-WITH-PARTS'; check "B: the variant template is rendered" $?
has "$body" 'OG201-HEADER-PRINT-DB'; check "D (render_block_data): a template part variant from the database is rendered" $?
has "$body" 'OG201-FOOTER-PRINT-FILE'; check "D (render_block_data): a template part variant from a theme file is rendered" $?
body=$(get "p=$POST&og_strategy=B")
has "$body" 'OG201-HEADER-PRINT-DB'; check "no mode: the template parts are unchanged" $((! $?))
body=$(get "p=$POST&mode=print&og_strategy=A")
has "$body" 'OG201-HEADER-PRINT-DB'; check "A (get_block_templates) cannot swap template parts: the block does not call it" $((! $?))

echo "--- Strategy A rewrites calls that are not the resolution of the front-end template"
first=$(wp eval '$t = get_block_templates(); echo $t[0]->slug;')
variants "{\"$first\":\"og201-single-print\"}"
out=$(wp eval '$_GET["mode"] = "print"; $_GET["og_strategy"] = "A"; $t = get_block_templates(); echo $t[0]->id; echo " | "; $_GET["og_strategy"] = "B"; $t = get_block_templates(); echo $t[0]->id;')
a=${out%% | *}; b=${out##* | }
[ "$a" = "$THEME//og201-single-print" ]; check "A: a plain get_block_templates() listing (editor, REST, other plugins) is rewritten" $?
[ "$b" = "$THEME//$first" ]; check "B: the same listing is untouched" $?

echo "--- Hierarchy filters are called with the types of the template loader"
variants '{}'
types=""
for q in "" "s=x" "cat=1" "p=99999" "author=1" "m=2026" "page_id=$PAGE"; do
	types="$types $(get "$q" | grep -o '"hierarchies":\[[^]]*\]' | grep -o '[a-z0-9]*: ' | tr -d ': ' | tr '\n' ',')"
done
echo "      types seen:$types"
for t in frontpage home search category archive 404 author date page; do has "$types" "$t"; check "type $t reaches {\$type}_template_hierarchy" $?; done

echo "--- A classic theme"
C="$WP_PATH/wp-content/themes/og201-classic"
mkdir -p "$C"
printf '%s\n' '/*' 'Theme Name: og201 classic' 'Version: 1.0' '*/' >"$C/style.css"
printf '%s\n' '<?php wp_head(); ?><body><p>OG201-CLASSIC-INDEX</p><?php wp_footer(); ?></body>' >"$C/index.php"
printf '%s\n' '<?php wp_head(); ?><body><p>OG201-CLASSIC-SINGLE</p><?php wp_footer(); ?></body>' >"$C/single.php"
printf '%s\n' '<?php wp_head(); ?><body><p>OG201-CLASSIC-SINGLE-PRINT</p><?php wp_footer(); ?></body>' >"$C/single-print.php"
wp theme activate og201-classic >/dev/null
variants '{"single":"single-print"}'
has "$(get "p=$POST")" 'OG201-CLASSIC-SINGLE<'; check "classic theme, no mode: single.php" $?
has "$(get "p=$POST&mode=print")" 'OG201-CLASSIC-SINGLE-PRINT'; check "B: classic theme, mode print: single-print.php" $?
has "$(get "p=$POST&mode=print&og_strategy=A")" 'OG201-CLASSIC-SINGLE-PRINT'; check "A: classic theme: not supported" $((! $?))
has "$(get "p=$POST&mode=print&og_strategy=C")" 'OG201-CLASSIC-SINGLE-PRINT'; check "C: classic theme: not supported" $((! $?))

echo "--- Canonical redirects keep the query string"
wp theme activate "$THEME" >/dev/null
wp rewrite structure '/index.php/%postname%/' >/dev/null 2>&1
slug=$(wp post get "$POST" --field=post_name)
location=$(curl -sS -o /dev/null -w '%{redirect_url}' "$BASE/index.php/$slug?mode=print")
has "$location" 'mode=print'; check "redirect_canonical keeps ?mode=print ($location)" $?
location=$(curl -sS -o /dev/null -w '%{redirect_url}' "$BASE/index.php/$slug?print")
has "$location" '?print'; check "redirect_canonical keeps the ?print alias ($location)" $?
wp rewrite structure '' >/dev/null 2>&1

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
