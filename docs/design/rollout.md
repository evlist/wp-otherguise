<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rolling out to the production blog

How the work of this plugin reaches Eric's production blog: small steps, each one tried on the sandbox site (SB) first. The goal behind it is to use the `Books` module to produce a first book; everything below is a means to move the blog towards it without breaking it. Points are marked **Decided** (Eric accepted it), **Proposed** (suggested by Claude, not confirmed) or **Open**.

## The loop

**Decided** (Eric, 2026-10-12). One iteration:

1. **Synchronise production to SB** with the script of [`tools/staging/`](../../tools/staging/README.md) (files, database, addresses replaced, safety plugin).
2. **Install the updates of the plugins on SB and configure them.**
3. **Test** on SB.
4. **Reproduce by hand on production the manipulations of step 2.** There is **no automatic synchronisation in the other direction**: the tests may have changed things that must not reach production.
5. Go back to 1.

For step 4 to stay manageable, **the iterations must stay small.**

## Rules that keep step 4 safe

**Proposed** (Claude, 2026-10-12), to confirm.

- **Write the procedure before running it.** For each iteration, the exact steps of step 2 (plugins to update, settings, commands) are written down first, then run **from that text** on SB right after the synchronisation, then replayed as written on production. SB rehearses the procedure; free experiments remain possible but are not part of it. Anything done outside the text is not reproduced, or the text is corrected and rehearsed again.
- **Prefer commands that can be run twice** to settings made by hand: `wp otherguise media migrate` creates only what is missing, leaves existing links alone, and has a `--dry-run`. If reproducing the settings of the modes screen (modes, variants, stylesheets, default templates) by hand becomes error-prone, a command or an export/import of these settings (candidate slice) would remove the risk. Not before it hurts.
- **Back up production just before step 4:** `wp db export` (and a copy of the templates edited). Cheap, and the only simple way back for steps that write to the database.
- **The synchronisation overwrites SB**, including the links created by the tests on SB. This is intended; the procedure must therefore recreate what it needs on SB each time (the migration command does).
- **One step per iteration**, or two when they are independent. The media migration and the change of the blocks must not share an iteration.

## Proposed order of the steps

From the least to the most risky. Each step is one iteration (or two independent ones).

| # | Step | Visible effect on production |
|---|---|---|
| 1 | Install Otherguise and Media Helper, **modes disabled** (the default). | None. |
| 2 | Bring the two templates (`publication-randonnee`, `publication-randonnee-print`) up to date for the modes (the block `modes/link`), declare the modes and the variants, enable the modes. | The `?mode=` addresses work; `?print` is still served by the old plugin until it is retired. |
| 3 | Stylesheet per mode; default template of new posts. | The styles of the modes; new posts start from `publication-randonnee`. |
| 4 | Media: `wp otherguise media migrate --dry-run`, read the report, run it; then choose the method `otherguise` in Media Helper. | None on the front end (the blocks still read `post_parent`, which is never changed); the panel offers several posts and modes. |
| 5 | Change the two blocks (`wp-attached-gpx`, `wp-printable-gallery`) to call `wp_media_helper_get_attached_media()`. | The galleries and the tracks come from the links; this is the step that can change the rendering. |
| 6 | Retire `wp-pdf-helper` (checklist in [modes.md](modes.md)). | The old `?print` hack and the "Print" column disappear. |

Between steps 4 and 5 the galleries keep working because `post_parent` is untouched; this was checked on a scratch site, **not yet** on a copy of the blog.

## Moving a template from SB to production

**Proposed.** The templates exist on both sites; only their content is copied. A template is a post of type `wp_template`; WP-CLI has no command for it but the commands on posts are enough (`wp post get <ID> --field=post_content` on SB, `wp post update <ID> <file>` on production, after a backup and a `diff`). Replace the address of SB by that of production in the files first, and activate Otherguise on production **before** updating the templates (the block `modes/link` is unknown without it). Not tried on the blog.

## Open

- **Open**: whether to write the procedures in this repository (`docs/rollout/NNN-name.md`, one per iteration) or only in the conversation; the first would keep them for the next time.
- **Open**: an export/import of the settings of the modes (see above).
- **Open**: what a "small" iteration is for the blocks of step 5 (both at once, or one block per iteration).

## Related

[`../../tools/staging/README.md`](../../tools/staging/README.md) (the synchronisation), [slice 211](../slices/211-media-migration.md) (the migration command), [`media-helper.md`](media-helper.md) (the integration), [`modes.md`](modes.md) (retiring `wp-pdf-helper`).
