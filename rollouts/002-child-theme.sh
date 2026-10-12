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

# 4. The switch.
if [ "$(wp theme list --status=active --field=name 2>/dev/null)" = "$THEME" ]; then
  say "The theme $THEME is already active"
else
  step "Activate the theme $THEME" wp theme activate "$THEME"
fi
step "Move the variants of the modes from $PARENT to $THEME" wp otherguise modes variant retheme "$PARENT" "$THEME"

# 5. The CSS of the blog comes from the theme, no longer from the plugin Custom CSS & JS.
SNIPPET=33286   # the only snippet of the plugin: frontend, header, priority 5, CSS
check_output "the CSS of the blog is in the style.css of the theme" "yes" wp eval 'echo false !== strpos( (string) file_get_contents( get_stylesheet_directory() . "/style.css" ), ".lettrine:first-letter" ) ? "yes" : "no";'
check_output "the stylesheet of the theme is loaded by the theme" "yes" wp eval 'do_action( "wp_enqueue_scripts" ); echo wp_style_is( "twentytwentyfive-printable-style", "enqueued" ) ? "yes" : "no";'
if wp plugin is-active custom-css-js >/dev/null 2>&1; then
  check_output "the only active snippet of Custom CSS & JS is $SNIPPET (the plugin would take the others with it)" "$SNIPPET" wp post list --post_type=custom-css-js --post_status=publish --field=ID
  step "Deactivate the plugin Custom CSS & JS" wp plugin deactivate custom-css-js
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

validate "Compare $HOME_URL with $OTHER_URL ($OTHER), page by page: the home page; an archive (a category or a month); the search page (/?s=test); a post that uses publication-randonnee, one that uses journee, one that uses 'Résumé mensuel randonnée', and one with the default template; and each of them with ?print and with ?mode=print. They must look the same: lists of posts, header, no footer, menus, colours, fonts."
validate "The PHP error log has nothing new (wp-content/debug.log)."
manual "If the colours or the fonts of the site changed: the global styles saved in the site editor were attached to $PARENT and did not follow. Go back (wp theme activate $PARENT, then wp otherguise modes variant retheme $THEME $PARENT) and tell Claude: the content of the global styles post has to be given to the new theme. If nothing changed, there is nothing to do." "the pages look the same as before."

rollout_end
