<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rolling out to the production blog

How the work of this plugin reaches Eric's production blog: small steps, each one rehearsed on a staging site first. The goal behind it is to use the `Books` module to produce a first book; everything below is a means to move the blog towards it without breaking it. Points are marked **Decided** (Eric accepted it), **Proposed** (suggested by Claude, not confirmed) or **Open**.

## Three sites, three kinds of verification

**Decided** (Eric, 2026-10-12): there are **three sites**, and the former sandbox (`sb.vli.st`) is now **staging**.

| Site | Role | Versions of the plugins | Cleanliness |
|---|---|---|---|
| **Production** (`e.vli.st`) | the blog | a rollout tag | n/a |
| **Staging** | rehearse the procedure of an iteration | the commits that are about to be tagged, then the tag | must be a fresh copy of production, no free experiments |
| **Testing** | integration tests with the real articles and photos | follows the development branches | may be dirty and broken |

Functional tests of the plugins stay where they are cheap (automated tests, Codespaces, scratch sites): a throwaway WordPress cannot hold the real volume, which is why testing is a copy of production. Each site shows what it is with a coloured frame ([`tools/staging/`](../../tools/staging/README.md): red `production`, orange `staging`, green `testing`).

## The loop

**Decided** (Eric, 2026-10-12, adapted to three sites). One iteration:

1. **Synchronise production onto staging** (and onto testing when the integration tests need fresh data) with `sync-site.sh`.
2. **On staging, bring the plugins to the commits to rehearse** (`rollout.sh update`) **and configure them.**
3. **Test and rehearse** the written procedure on staging.
4. **Create the rollout** on staging: `rollout.sh create` tags the commits that were rehearsed, `rollout-N`, in every plugin repository, and pushes the tags to GitHub.
5. **On production: back up, then `rollout.sh apply N --site=prod`, then reproduce by hand the manipulations of step 2.** There is **no automatic synchronisation from staging or testing to production**: the tests may have changed things that must not reach it.
6. Go back to 1.

For step 5 to stay manageable, **the iterations must stay small.** A rollout is the same set of versions everywhere: what runs on staging when the tag is created is what production gets, whatever the other clones were doing in between.

## Rules that keep step 5 safe

**Proposed** (Claude, 2026-10-12), to confirm.

- **Write the procedure before running it.** For each iteration, the exact steps of step 2 (settings, commands) are written down first, then run **from that text** on staging right after the synchronisation, then replayed as written on production. Anything done outside the text is not reproduced, or the text is corrected and rehearsed again.
- **Prefer commands that can be run twice** to settings made by hand: `wp otherguise media migrate` creates only what is missing, leaves existing links alone, and has a `--dry-run`. If reproducing the settings of the modes screen (modes, variants, stylesheets, default templates) by hand becomes error-prone, a command or an export/import of these settings (candidate slice) would remove the risk. Not before it hurts.
- **Back up production just before step 5:** `wp db export` (and a copy of the templates edited). Cheap, and the only simple way back for steps that write to the database; `rollout.sh rollback` only moves the code.
- **The synchronisation overwrites staging**, including the links created by the rehearsal. This is intended; the procedure must recreate what it needs each time (the migration command does).
- **One step per iteration**, or two when they are independent. The media migration and the change of the blocks must not share an iteration.

## Migrations are scripts (decision of 2026-10-12)

**Decided** (Eric): what is done on staging is carried to production by a **migration script**, not by hand and not by copying the database.

- **Not by synchronising the database:** production lives on between the copy and the rollout (comments, edited posts, sessions, scheduled tasks); any table replaced loses that, and the relations of Otherguise hold identifiers of posts and media that differ as soon as production has new ones.
- **Not by hand, as a rule:** by hand only what has no command yet, listed as such in the file of the rollout (see below).
- **Rule for the plugins:** every step of a migration that touches what a plugin is responsible for is offered as a **WP-CLI command that can be run twice** (it creates only what is missing and leaves the rest alone), with a **`--dry-run`**, a **report** of what it did, and a way to **check** the result. `wp otherguise media migrate` is the model. This rule is in the `CLAUDE.md` of the repository; the commands still missing are listed in [What the plugin still has to offer](#what-the-plugin-still-has-to-offer-in-wp-cli).
- **A rollout has a file** ([`../rollouts/`](../rollouts/README.md)): the versions (the tag `rollout-N`), the preconditions, the backup, the commands, the manual steps with what to check after each, the verification and the rollback. It is written before it is run, run on staging **from the text**, then replayed on production as written.

## The templates live in a child theme (decision of 2026-10-12)

**Decided** (Eric): the templates of the blog move from the database into files, in a **child theme of Twenty Twenty-Five** kept in its own repository, [`evlist/wp-twenty-twenty-five-printable`](https://github.com/evlist/wp-twenty-twenty-five-printable). Twenty Twenty-Five stays untouched (its updates cannot overwrite anything), the templates are versioned and travel with the plugins as part of a rollout, and no tool for exporting and importing templates is needed. The templates of the blog are three web/print pairs (`journee`, `publication-randonnee`, "Résumé mensuel randonnée") plus a few others (for example one for a category); `publication-randonnee` is the most used.

What was **tried** on a scratch WordPress 7.1.3 (and written down in the README of the theme):

- A template saved in the site editor is stored in the database and **shadows the file** of the theme with the same slug.
- A database copy attached to the **parent** theme is **ignored** by the child: after the switch, the customised templates stop applying until they are in the theme.
- A `wp:template-part` that carries `"theme":"twentytwentyfive"` renders as **nothing, silently**, in the child theme: the `theme` attribute must be left out of the files.
- The identifiers kept by the modes screen for the variants contain the theme (`twentytwentyfive//slug`), so **the variants must be declared again** for the new theme: a command that rewrites them is on the list below.

**Not tried:** the global styles saved in the site editor (stored per theme, they may not follow the switch), and everything on the blog itself.

The scripts for this are [`tools/staging/templates.sh`](../../tools/staging/README.md): `check` lists the templates of the database and says which ones shadow a file, `export` writes them as files (with the fragment of `theme.json` for the custom templates), `delete` removes the shadowing copies after a backup (only needed if a template is saved in the site editor after the switch).

## Proposed order of the steps

From the least to the most risky. Each step is one iteration (or two independent ones). A rollout file is written for each ([`../rollouts/`](../rollouts/README.md)); the first one, [001](../rollouts/001-baseline.md), is written.

| # | Step | Visible effect on production |
|---|---|---|
| 0 | **Done by hand before the rollouts** (state reported by Eric on 2026-10-12): Otherguise, Media Helper and Scatter Elsewhere installed from git clones; the modes enabled; the modes and the variants declared on the templates of Twenty Twenty-Five. No stylesheet, no default template, the templates untouched. | The `?mode=` addresses work; `?print` is still served by the old plugin. |
| 1 | **Baseline** ([001](../rollouts/001-baseline.md)): `rollout.sh create --baseline --site=prod` records what runs on production now as `rollout-1`. | None. |
| 2 | **The child theme**: export the templates and parts that the database holds for Twenty Twenty-Five (the customised standard ones and the custom ones), put them in the theme, switch the theme (the copies attached to the parent become unused, nothing to delete), declare the variants again for the new theme; compare the two sites before and after (styles, menus). | None expected; this is the step to look at closely. |
| 3 | Make the templates use the block `modes/link`. | The links between the modes. |
| 4 | Stylesheet per mode; default template of new posts. | The styles of the modes; new posts start from `publication-randonnee`. |
| 5 | Media: `wp otherguise media migrate --dry-run`, read the report, run it; then choose the method `otherguise` in Media Helper. | None on the front end (the blocks still read `post_parent`, which is never changed); the panel offers several posts and modes. |
| 6 | Change the two blocks (`wp-attached-gpx`, `wp-printable-gallery`) to call `wp_media_helper_get_attached_media()`. | The galleries and the tracks come from the links; this is the step that can change the rendering. |
| 7 | Retire `wp-pdf-helper` (checklist in [modes.md](modes.md)). | The old `?print` hack and the "Print" column disappear. |

Between steps 5 and 6 the galleries keep working because `post_parent` is untouched; this was checked on a scratch site, **not yet** on a copy of the blog.

## Moving a template from staging to production

Superseded by the child theme (above): a template is changed in the repository of the theme, tried on testing, rehearsed on staging, and reaches production with the tag of the rollout. Until the switch (step 2), the templates of the database are exported with `tools/staging/templates.sh export`.

## What the plugin still has to offer in WP-CLI

**Proposed.** The list of what the migrations will need and that has no command yet. **Each one is written only when an iteration needs it**, with a `--dry-run` and a report, and tested like `media migrate`.

| Needed by step | Command (proposed name) | Today |
|---|---|---|
| 3 | `wp otherguise modes variant list \| add <template> <variant> --mode=<mode> \| remove` (and the same for template parts) | the modes screen only |
| 2 and 3 | `wp otherguise modes variant retheme <from-theme> <to-theme>`: rewrites the identifiers of the variants (`twentytwentyfive//slug` to `wp-twenty-twenty-five-printable//slug`, the name of the directory of the theme) | does not exist |
| 3 | `wp otherguise modes enable` / `disable` | `wp option update modes_settings` works but is not a gesture one remembers |
| 4 | `wp otherguise modes stylesheet list \| add <mode> <file or attachment> \| remove` (a file is first imported with `wp media import`) | the modes screen only |
| 4 | `wp otherguise modes default-template set <post type> <template>` | the modes screen, option `modes_default_templates` |
| all | `wp otherguise modes status`: modes, variants, stylesheets, default templates, enabled or not, in a form that a script can compare with what was expected (the verification at the end of a rollout file) | does not exist |
| 5 | `wp otherguise media migrate` | **exists** (slice 211) |
| 5 | `wp otherguise media list <post>` (the links of a post with their modes), to look at the result | the panel of Media Helper |
| any | `wp otherguise triples list \| add \| remove`, generic, if the commands above turn out to be too many | the screen "Otherguise relations" |

## Open

- **Open**: whether the rollout files stay in this repository (`docs/rollouts/`, where they are now) or move to a private repository of the blog: they name the containers and the settings of the site.
- **Open**: the names of the other templates and of their slugs. "Résumé mensuel randonnée" has the slug `wp-custom-template-r-sum-mensuel-randonn-e` (made from the title when it was created); keeping it keeps the posts that use it working, renaming it needs a command that rewrites the template of those posts.
- **Open**: how long a rollout is kept as a set (are old tags ever cleaned up?).
- **Open**: what a "small" iteration is for the blocks of step 6 (both at once, or one block per iteration).

## Related

[`../../tools/staging/README.md`](../../tools/staging/README.md) (the synchronisation, the environments and the rollouts), [slice 211](../slices/211-media-migration.md) (the migration command), [`media-helper.md`](media-helper.md) (the integration), [`modes.md`](modes.md) (retiring `wp-pdf-helper`).
