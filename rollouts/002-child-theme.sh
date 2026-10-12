#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Rollout 002: the child theme. The templates of the blog move from the database into the child theme
# evlist/wp-twenty-twenty-five-printable (directory wp-twenty-twenty-five-printable), which becomes the active theme, and the variants of the
# modes are moved to it. No visible change is expected. The story, the risks and what was observed are in docs/rollouts/002-child-theme.md.
#
# Usage: rollouts/002-child-theme.sh --site=staging [--no-validate] [--dry-run]
#        rollouts/002-child-theme.sh --site=prod --rollout=N [--no-validate] [--yes] [--force]
#   N is the number of the rollout (the tag rollout-N) that `rollout.sh create --site=staging` made after the rehearsal. On staging and on
#   testing the clones are brought to the latest commits instead.
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

# 1. The versions: the same on every site, since they come from the tag.
if [ "$SITE" = prod ]; then
  step "Put the repositories on rollout-$ROLLOUT_NUMBER" rollout_sh apply "$ROLLOUT_NUMBER" --site=prod --yes
else
  step "Bring the repositories to the latest commits" rollout_sh update --site="$SITE"
fi
note "The repositories" rollout_sh status --site="$SITE"

# 2. What must be true before the switch.
check "the child theme is installed ($THEME)" wp theme is-installed "$THEME"
check_output "the reusable block $REUSABLE_BLOCK of the lists of posts exists" "wp_block" wp post get "$REUSABLE_BLOCK" --field=post_type

# 3. Where we start from.
note "The templates of the database, before" templates_sh check --site="$SITE"
note "The variants declared, before" wp otherguise modes variant list
note "The global styles of the site editor, before (they are stored per theme)" wp post list --post_type=wp_global_styles --fields=ID,post_name,post_status
HOME_URL=$(wp option get home 2>/dev/null || echo "(the address of the site)")
validate "BEFORE the switch, open on $HOME_URL and keep a screenshot of each: the home page; an archive (a category or a month); the search page ($HOME_URL/?s=test); a post that uses publication-randonnee, one that uses journee, one that uses 'Résumé mensuel randonnée', and one with the default template; and each of them with ?print and with ?mode=print. Note the header, the missing footer, the menus, the colours and the fonts."

# 4. The switch.
if [ "$(wp theme list --status=active --field=name 2>/dev/null)" = "$THEME" ]; then
  say "The theme $THEME is already active"
else
  step "Activate the theme $THEME" wp theme activate "$THEME"
fi
step "Move the variants of the modes from $PARENT to $THEME" wp otherguise modes variant retheme "$PARENT" "$THEME"

# 5. What must be true afterwards.
check_output "the active theme is $THEME" "$THEME" wp theme list --status=active --field=name
check "no database copy hides a file of the theme" templates_sh check --site="$SITE"
check_output "no variant is left on $PARENT" "" wp otherguise modes variant list --theme="$PARENT"
check "the variants are declared for $THEME" test -n "$(wp otherguise modes variant list --theme="$THEME" 2>/dev/null)"
note "The templates of the database, after" templates_sh check --site="$SITE"
note "The variants declared, after" wp otherguise modes variant list
note "The global styles of the site editor, after" wp post list --post_type=wp_global_styles --fields=ID,post_name,post_status

validate "AFTER the switch, open the same pages on $HOME_URL and compare them with the screenshots: they must look the same (lists of posts, header, no footer, colours, fonts), also with ?print and ?mode=print."
validate "The PHP error log has nothing new (wp-content/debug.log)."
manual "If the colours or the fonts of the site changed: the global styles saved in the site editor were attached to $PARENT and did not follow. Go back (wp theme activate $PARENT, then wp otherguise modes variant retheme $THEME $PARENT) and tell Claude: the content of the global styles post has to be given to the new theme. If nothing changed, there is nothing to do." "the pages look the same as before."

rollout_end
