<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rollout 002: the child theme

Status: **applied on production on 2026-10-13**, after a rehearsal on staging the same day, to the end and with the validations. Tag `rollout-2`.

## Goal and visible effect

Move the templates of the blog from the database into the child theme [`evlist/wp-twenty-twenty-five-printable`](https://github.com/evlist/wp-twenty-twenty-five-printable) (directory `wp-twenty-twenty-five-printable`), and make it the active theme. **No visible change is expected**: the same templates, now as files. What a visitor must not see is a difference; what Eric sees is the theme name in Appearance, and that the templates of the site editor are now those of the files.

The templates exported from staging on 2026-10-12: 6 custom ones (`journee*`, `publication-randonnee*`, `wp-custom-template-r-sum-mensuel-randonn-e*`), 4 customised standard ones (`archive`, `home`, `search`, `single`) and 2 parts (`header`, which delegates to `header-large-title`, and an **empty** `footer`).

**Nothing is deleted:** the copies in the database stay attached to Twenty Twenty-Five, so the old state is still there.

## The script

The procedure is the script [`rollouts/002-child-theme.sh`](../../rollouts/002-child-theme.sh); this file says why and what to watch. The script is the same on every site, it can be run as often as needed on staging (synchronised again when something goes wrong), and it refuses production until **this text of it** was rehearsed to the end on staging with the validations.

```sh
# staging: put the clones on the state to try (a tag, a branch, rollout.sh update), then rehearse
rollouts/002-child-theme.sh --site=staging
# when it is right, tag what was rehearsed
tools/staging/rollout.sh create --site=staging
# production: the clones on the tag, then the script (it checks the tag and the rehearsal, backs up the database first)
tools/staging/rollout.sh apply N --site=prod
rollouts/002-child-theme.sh --site=prod --rollout=N
```

What it does, in this order: notes the versions of the repositories (it runs no git; on production it checks that they are on the tag and are what was rehearsed); checks that the plugin has the command `variant retheme`, that WordPress can read the theme, that it holds the fonts (`theme.json`) and the print format (`modes/print.css`), and that the reusable block `32919` of the lists of posts exists; notes the state before (the templates of the database, the variants, the global styles, the state of `wp-pdf-helper`, which this rollout does not touch); activates the theme; moves the variants to it (`wp otherguise modes variant retheme`); checks that the mode print loads `modes/print.css` of the theme (and the web mode does not) and that Dashicons is loaded for every visitor; checks that the CSS of the blog is in the `style.css` of the theme and that the theme loads it, then deactivates the plugin Custom CSS & JS (after checking that snippet 33286 is its only active one); checks that no database copy hides a file of the theme and that no variant is left on Twenty Twenty-Five; asks you to compare the pages with the other site (production, or staging when this is production) **after each change** (before any change, after the theme switch, after the variants moved, after the plugin was deactivated, and at the end), so that you know which step made a difference, and to look at the error log. There is no manual gesture left.

`--no-validate` skips the questions about the pages once Eric trusts the rehearsal; the question about the global styles is always asked.

## What to watch

- **The CSS now comes from the theme.** The snippet 33286 of Custom CSS & JS (galleries, alignment of images, maps, the drop cap; frontend, header, priority 5) is in the `style.css` of the theme, and the plugin is deactivated. The plugin printed it very early in the page; the theme's stylesheet loads after the parent's, so a rule of the theme that the old order let win could now lose or the other way round: look at galleries, images aligned left or right, maps and the drop cap. The drop cap uses the font `Ultra`, which has to be loaded by something else than this CSS. **The print format is not under `@media print`** (Eric: the page to print can be seen in HTML in the browser in print mode), so its CSS must load only in the print mode: that is the convention of [slice 213](../slices/213-modes-theme-stylesheet.md), `modes/<mode>.css` of the theme is loaded by the Modes module when that mode is active. The theme now carries `modes/print.css` (taken from wp-pdf-helper, which keeps serving `?print` and its own copy) and the rules of `.print-link` are in `style.css`. Since the theme is delivered by the tag, this rollout delivers them too: the script checks that the file exists and that the mode print loads it; whether the mode `print` also answers to `?print` (an alias) is worth a look on staging, because both stylesheets would then load. Dashicons (the print icon) was loaded by wp-pdf-helper for every visitor; the theme now does it. Slice 213 had never been tried on a real WordPress: this rehearsal is the first time.

- **The fonts and the sizes of the blog are in the `theme.json` of the theme.** Observed on staging (`compare-sites.sh`, 2026-10-12): without them the page used Manrope and Fira Code (the defaults of Twenty Twenty-Five) where production uses **Fira Sans** and **Literata**, chosen in the site editor and stored as global styles (the post `wp-global-styles-twentytwentyfive`), which are attached to a theme and do not follow a switch. The content of that post (Eric, 2026-10-12) is now the `settings` (the two font families and the five sizes) and the `styles` (typography of the body, headings, buttons, site title, post title, quotes, query pagination, and some block variations) of the `theme.json` of the child theme, versioned with it. Checked on a scratch WordPress: the global stylesheet is **byte for byte** the one of Twenty Twenty-Five with that post, and the 34 font files are the same ones, served from the parent theme. The script checks that the fonts are in the `theme.json` before it switches.
- **A difference found after a step is that step's.** The script pauses after each change for a comparison with the other site; note what differs where it first appears (the fonts, for instance, are most likely the global styles of the site editor, attached to the theme, at the theme switch). `--no-validate` removes the pauses.
- **The pages, compared with production** (staging, testing) or with staging (production): the home page; an archive; the search page; a post of each of the three custom kinds and one with the default template; each of them with `?print` and with `?mode=print`. `wp-pdf-helper` is active on both sites (it keeps what 002 leaves), so `?print` must look the same on both; `?mode=print` differs **on purpose**, because the theme loads `modes/print.css` there and the other site does not have it yet.
- **Going back** is `wp theme activate twentytwentyfive` and `wp otherguise modes variant retheme wp-twenty-twenty-five-printable twentytwentyfive`: nothing is deleted.
- **Preconditions the script checks for itself:** the theme installed (the clone mounted as `wp-content/themes/wp-twenty-twenty-five-printable`) and the reusable block. The tag `rollout-1` exists (the baseline, [001](001-baseline.md)).

## Versions

`rollout.sh create --site=staging` (2026-10-13), tag `rollout-2` in four repositories; production is brought to it with `rollout.sh apply 2 --site=prod`:

| Repository | Commit |
|---|---|
| `wp-media-helper` | `8ab96c5` |
| `wp-twenty-twenty-five-printable` | `d549c77` |
| `wp-scatter-elsewhere` | `60439b9` |
| `wp-otherguise` | `a547565` |

The script rehearsed is the one of `wp-otherguise` at that commit; a later change of the script or of the library makes production refuse it until it is rehearsed again. Plugins that are copies from Gitea (not git clones) are not in the tag.

## Log

- **2026-10-13, staging** (Eric). A first run stopped at the check that the mode print loads `modes/print.css`: the check simulated `?mode=print` inside WP-CLI, but the Modes module reads the real query string (`filter_input_array`), so it could only answer "no". It was a fault of the script, not of the site (the page `?print=print` of staging did load the stylesheet). The check now asks the public address of the site over HTTP. Observed on the way: after the theme switch and before the variants moved, `?print=print` showed the web template (the print variants were still declared for Twenty Twenty-Five), so the column of the GPX map had the wrong width; once the variants were moved the print template and its two columns were there. `?print=print` also activates the mode `print` of Otherguise (an alias), so with `wp-pdf-helper` active both `wp-pdf-helper-print.css` and `modes/print.css` load on a `?print` page. The second run went to the end with the validations.
- **2026-10-13, production** (Eric), `rollout.sh apply 2 --site=prod` then `rollouts/002-child-theme.sh --site=prod --rollout=2`. Two stops before any change, both caused by the container and not by the script: the image of WordPress had **no MySQL client** (the backup with `wp db export` failed; the package `mariadb-client` was added to the image, which was rebuilt), then the theme **mount was wrong again** ("the stylesheet is missing": the check that WordPress can read the theme stopped the script, on production as on testing). Each time the script stopped at the backup or at the check, before the first change. The third run went to the end. Lesson for the next rollouts: on production the validations are no longer asked (see `tools/staging/README.md`), so the checks of the script are the safety net, and what must be true of the container (MySQL client, WP-CLI, the mounts) is checked at the start, before the backup.
- **Note for later:** the script of 002 checks that the theme loads Dashicons, which is true of the theme at `rollout-2` only; the theme no longer loads it once the print links are blocks (rollout 003). Do not run 002 again on a later state of the theme: it has been applied.
