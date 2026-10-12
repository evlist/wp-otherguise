<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rollout 002: the child theme

Status: **written, not run**. Tag: `rollout-N` (given by `rollout.sh create --site=staging` after the rehearsal).

## Goal and visible effect

Move the templates of the blog from the database into the child theme [`evlist/wp-twenty-twenty-five-printable`](https://github.com/evlist/wp-twenty-twenty-five-printable) (directory `wp-twenty-twenty-five-printable`), and make it the active theme. **No visible change is expected**: the same templates, now as files. What a visitor must not see is a difference; what Eric sees is the theme name in Appearance, and that the templates of the site editor are now those of the files.

The templates exported from staging on 2026-10-12: 6 custom ones (`journee*`, `publication-randonnee*`, `wp-custom-template-r-sum-mensuel-randonn-e*`), 4 customised standard ones (`archive`, `home`, `search`, `single`) and 2 parts (`header`, which delegates to `header-large-title`, and an **empty** `footer`).

**Why it can be undone at once:** nothing is deleted. The copies in the database stay attached to Twenty Twenty-Five; going back is `wp theme activate twentytwentyfive`.

## Preconditions

- [ ] `rollout-1` exists (the baseline, [001](001-baseline.md)).
- [ ] The clone of the theme is mounted on the site as `wp-content/themes/wp-twenty-twenty-five-printable`, and the site sees it: `wp theme list` shows `wp-twenty-twenty-five-printable` (inactive). Staging: done when it was created; production: already mounted (Eric, 2026-10-12).
- [ ] **The reusable block of the lists of posts exists:** `wp post get 32919 --field=post_type` says `wp_block` (the templates `archive`, `home` and `search` refer to it by number).
- [ ] Staging is a fresh copy of production (the date in the label of its frame), and WP-CLI is in the container.
- [ ] The theme repository is at the commit to rehearse (`rollout.sh update --site=staging` brings it there).

## Versions

On staging, after the rehearsal below, `rollout.sh create --site=staging` tags the four repositories; paste its output here and put `rollout-N` in the header. On production the rollout is `rollout.sh apply N --site=prod`.

## Backup (production only)

```sh
docker exec -u www-data docker-e-vli-st_wordpress_1 wp db export /tmp/before-rollout-N.sql
```

and the templates of the database, which `rollout.sh` does not touch but which are the way back: `templates.sh export ~/templates-prod-before --site=prod` (read-only).

## Before: what to compare afterwards

Open each of these on the site, **before** and **after**, and keep the screenshots (or the saved pages): the home page of the blog; an archive (a category or a month); the search page (`/?s=...`); a post that uses `publication-randonnee`, one that uses `journee`, one that uses "Résumé mensuel randonnée", and a post with the default template (`single`); and each of them with `?print` and `?mode=print`. Also note: the header (the title block), the absence of a footer, the menus, the colours and the typography.

## Commands

On staging first, **from this text**, then on production (`<c>` is the container of the site; `$SITE` is `staging` or `prod`):

1. `rollout.sh status --site=$SITE`: the four repositories; on production after `apply N`, `wp-twenty-twenty-five-printable` is on the commit of the rollout.
2. `docker exec -u www-data <c> wp theme list --fields=name,status,version`: the child theme is listed, not active.
3. `docker exec -u www-data <c> wp post get 32919 --field=post_type`: `wp_block`.
4. `templates.sh check --site=$SITE`: record the output (before). Expected: `SHADOWS` and `DATABASE-ONLY` for the templates attached to Twenty Twenty-Five.
5. `docker exec -u www-data <c> wp theme activate wp-twenty-twenty-five-printable`: `Success: Switched to 'Twenty Twenty-Five Printable' theme.`
6. `templates.sh check --site=$SITE`: expected: **no** `SHADOWS` (exit code 0), and the former templates now `UNUSED` (attached to Twenty Twenty-Five, not used by the child).

## Manual steps

There is no command yet for these (see the list in `../design/rollout.md`). Each one with what to check afterwards.

1. **The variants of the modes, for the new theme.** In Settings, Otherguise modes, the variants refer to the templates **with their theme** (`twentytwentyfive//journee` and so on): after the switch they point at templates of the old theme, so `?mode=print` finds no variant. For each of the three pairs, **remove** the old variant and **add** it again with the templates of the new theme:
   - `journee` has the variant `journee-print` in the mode print;
   - `publication-randonnee` has the variant `publication-randonnee-print` in the mode print;
   - `wp-custom-template-r-sum-mensuel-randonn-e` has the variant `wp-custom-template-r-sum-mensuel-randonn-e-print` in the mode print.
   Check: the screen lists the three pairs with `wp-twenty-twenty-five-printable//...` identifiers, and `?mode=print` on a post of each kind shows the print version. (The command `variant retheme` that would do this in one go is in the list of the commands to write.)
2. **The global styles of the site editor** (colours, typography saved in Appearance, Editor, Styles) are stored **per theme** and may not follow the switch. Before the switch note `wp post list --post_type=wp_global_styles --fields=ID,post_name,post_status`; after the switch compare the appearance of the pages. If the styles were customised and are lost, **stop**: go back (rollback below) and say so, the content of that post has to be given to the new theme.

## Verification

Run on staging, then on production; every line must agree.

| Check | How | Expected |
|---|---|---|
| the theme is active | `wp theme list --status=active --field=name` | `wp-twenty-twenty-five-printable` |
| no database copy hides a file | `templates.sh check --site=$SITE` | exit 0, no `SHADOWS` |
| the pages look the same | the pages of "Before", after | the same, including `?print` and `?mode=print` |
| the lists of posts are there | home, archive, search | the posts listed (the reusable block 32919 is found) |
| no new error | the PHP error log (`wp-content/debug.log`) | nothing new |
| the print variants work | `?mode=print` on a post of each of the three kinds | the print template |

## Rollback

1. `docker exec -u www-data <c> wp theme activate twentytwentyfive`: back to the templates of the database, which were never touched. The variants of the manual step 1 must be put back too (the old identifiers) if they were changed.
2. On production, `rollout.sh rollback --site=prod` puts the clone of the theme back where it was (it is only read while the theme is not active); the backup is needed only if something unexpected wrote to the database.

## Log

(Dates, sites, who ran it, what differed from the text and the correction made to it.)
