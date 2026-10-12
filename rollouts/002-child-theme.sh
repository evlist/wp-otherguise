#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Rollout 002: the child theme. The templates of the blog move from the database into the child theme
# evlist/wp-twenty-twenty-five-printable (directory wp-twenty-twenty-five-printable), which becomes the active theme, the variants of the
# modes are moved to it, and the CSS that the plugin Custom CSS & JS injected (snippet 33286) now comes from the theme: the plugin is
# deactivated. No visible change is expected. The story, the risks and what was observed are in docs/rollouts/002-child-theme.md.
#
# Usage: rollouts/002-child-theme.sh --site=staging [--no-validate] [--dry-run]
#        rollouts/002-child-theme.sh --site=prod --rollout=N [--no-validate] [--yes] [--force]
#   The script does not run git: put the clones of the site on the state to try first (a tag, a branch, `rollout.sh update`). On staging it
#   notes that state and remembers it; `rollout.sh create --site=staging` then tags it rollout-N; on production put the clones on the tag
#   (`rollout.sh apply N --site=prod`) and the script checks that they are on it and that it is what was rehearsed.
#
# To go back, nothing is deleted by this script: `wp theme activate twentytwentyfive`, then
# `wp otherguise modes variant retheme wp-twenty-twenty-five-printable twentytwentyfive`.
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=../tools/staging/rollout-lib.sh
. "$HERE/../tools/staging/rollout-lib.sh"

THEME=wp-twenty-twenty-five-printable
PARENT=twentytwentyfive
REUSABLE_BLOCK=32919   # the list of posts that archive, home and search show

rollout_init "002-child-theme" "$@"
if [ "$SITE" = prod ]; then
  [[ "$ROLLOUT_NUMBER" =~ ^[0-9]+$ ]] || die "On production, say which rollout: --rollout=N (the tag made on staging)."
fi
rollout_begin

# 1. The versions. Git is managed outside this script: put the clones on the state to rehearse (a tag, a branch) first.
rollout_versions

# 2. What must be true before the switch.
check "the child theme is installed ($THEME)" wp theme is-installed "$THEME"
# `is-installed` only looks for the directory: a stale mount or a missing style.css would only show when the theme is activated.
check_output "WordPress can read the child theme (its style.css, its parent)" "ok" wp eval "\$t = wp_get_theme( '$THEME' ); echo \$t->errors() ? 'broken: ' . \$t->errors()->get_error_message() : 'ok';"
check_output "the reusable block $REUSABLE_BLOCK of the lists of posts exists" "wp_block" wp post get "$REUSABLE_BLOCK" --field=post_type

# 3. Where we start from (what to compare with is the other site: production, or staging when this is production).
note "The templates of the database, before" templates_sh check --site="$SITE"
note "The variants declared, before" wp otherguise modes variant list
note "The global styles of the site editor, before (they are stored per theme)" wp post list --post_type=wp_global_styles --fields=ID,post_name,post_status
HOME_URL=$(wp option get home 2>/dev/null || echo "(the address of the site)")
if [ "$SITE" = prod ]; then OTHER_URL=${STAGING_URL}; OTHER=staging; else OTHER_URL=${PROD_URL:-https://e.vli.st}; OTHER=production; fi
PAGES="the home page; an archive (a category or a month); the search page (/?s=test); a post that uses publication-randonnee, one that uses journee, one that uses 'Résumé mensuel randonnée', and one with the default template; and each of the posts with ?print and with ?mode=print"
COMPARE="Compare $HOME_URL with $OTHER_URL ($OTHER), page by page: $PAGES. (tools/staging/compare-sites.sh --path=... [--print|--mode=print] lists what differs: stylesheets, inline styles, fonts, body classes.)"

if [ "$SITE" != prod ]; then
  validate "BEFORE any change. $COMPARE Staging is a copy of production, so they must look the same: note every difference that is already there, so as not to blame the next steps for it."
fi

# 4. The switch, one change at a time, with a comparison after each so as to know which one made a difference.
if [ "$(wp theme list --status=active --field=name 2>/dev/null)" = "$THEME" ]; then
  say "The theme $THEME is already active"
else
  step "Activate the theme $THEME" wp theme activate "$THEME"
fi
validate "AFTER THE THEME SWITCH, before anything else. $COMPARE What differs now comes from the switch itself. Expected: the fonts (and perhaps the colours), which are global styles of the site editor, attached to the theme; they come back with the next step. Anything else (the header, the missing footer, the lists of posts) is a problem. Note what is different. Without a terminal: tools/staging/compare-sites.sh shows the fonts."

# The global styles of the site editor (the fonts, the colours chosen there) are stored per theme: the child theme starts without them.
GLOBAL_STYLES='$slug = "wp-global-styles-" . urlencode( $theme );
$find = static function ( $name, $theme ) { return get_posts( array( "post_type" => "wp_global_styles", "name" => $name, "post_status" => array( "publish", "draft" ), "numberposts" => 1, "tax_query" => array( array( "taxonomy" => "wp_theme", "field" => "name", "terms" => $theme ) ) ) ); };
$from = $find( "wp-global-styles-" . urlencode( $parent ), $parent );
if ( array() !== $find( $slug, $theme ) ) { echo "the child theme has its own global styles already"; return; }
if ( array() === $from ) { echo "nothing to copy: $parent has no global styles"; return; }
$id = wp_insert_post( array( "post_type" => "wp_global_styles", "post_status" => "publish", "post_title" => "Custom Styles", "post_name" => $slug, "post_content" => $from[0]->post_content ) );
if ( ! $id || is_wp_error( $id ) ) { echo "FAILED"; return; }
wp_set_object_terms( $id, $theme, "wp_theme" );
echo "copied from post " . $from[0]->ID . " to post " . $id;'
step "Give the global styles of $PARENT to $THEME (fonts and colours chosen in the site editor)" wp eval "\$theme = '$THEME'; \$parent = '$PARENT'; $GLOBAL_STYLES"
check_output "the child theme has global styles of its own" "yes" wp eval "\$p = get_posts( array( 'post_type' => 'wp_global_styles', 'name' => 'wp-global-styles-' . urlencode( '$THEME' ), 'post_status' => array( 'publish', 'draft' ), 'numberposts' => 1 ) ); echo \$p ? 'yes' : 'no';"
validate "AFTER THE GLOBAL STYLES WERE GIVEN TO THE CHILD THEME. $COMPARE The fonts and the colours must be those of $OTHER again (without this step, Twenty Twenty-Five's default fonts, Manrope, replace the ones chosen in the site editor). Note what is still different."

step "Move the variants of the modes from $PARENT to $THEME" wp otherguise modes variant retheme "$PARENT" "$THEME"
validate "AFTER THE VARIANTS MOVED. Open ?mode=print (and ?print) on a post of each of the three kinds on $HOME_URL and on $OTHER_URL ($OTHER): the print version must be there, the same. Note what is different."

# 5. The CSS of the blog comes from the theme, no longer from the plugin Custom CSS & JS.
SNIPPET=33286   # the only snippet of the plugin: frontend, header, priority 5, CSS
check_output "the CSS of the blog is in the style.css of the theme" "yes" wp eval 'echo false !== strpos( (string) file_get_contents( get_stylesheet_directory() . "/style.css" ), ".lettrine:first-letter" ) ? "yes" : "no";'
check_output "the stylesheet of the theme is loaded by the theme" "yes" wp eval 'do_action( "wp_enqueue_scripts" ); echo wp_style_is( "twentytwentyfive-printable-style", "enqueued" ) ? "yes" : "no";'
if wp plugin is-active custom-css-js >/dev/null 2>&1; then
  check_output "the only active snippet of Custom CSS & JS is $SNIPPET (the plugin would take the others with it)" "$SNIPPET" wp post list --post_type=custom-css-js --post_status=publish --field=ID
  step "Deactivate the plugin Custom CSS & JS" wp plugin deactivate custom-css-js
  validate "AFTER THE PLUGIN CUSTOM CSS & JS WAS DEACTIVATED (its CSS now comes from the theme). $COMPARE Look at the galleries, the images aligned left or right, the maps and the drop cap (the font Ultra); the theme's stylesheet now loads after the parent's, the plugin's CSS used to come very early. Note what is different."
else
  say "The plugin Custom CSS & JS is already inactive"
fi

# 6. What must be true afterwards.
check_output "the active theme is $THEME" "$THEME" wp theme list --status=active --field=name
check "no database copy hides a file of the theme" templates_sh check --site="$SITE"
check_output "no variant is left on $PARENT" "" wp otherguise modes variant list --theme="$PARENT"
check "the variants are declared for $THEME" test -n "$(wp otherguise modes variant list --theme="$THEME" 2>/dev/null)"
note "The templates of the database, after" templates_sh check --site="$SITE"
note "The variants declared, after" wp otherguise modes variant list
note "The global styles of the site editor, after" wp post list --post_type=wp_global_styles --fields=ID,post_name,post_status

validate "AT THE END. $COMPARE They must look the same. Whatever you noted after one of the steps above and that is still different is what the rollout has changed."
validate "The PHP error log has nothing new (wp-content/debug.log)."
rollout_end
