<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rollout 003: the print format comes from the modes

Status: **proposed, not written** (the prerequisites below do not exist yet; no script). Tag: `rollout-N` (filled in by `rollout.sh create`). Depends on [002](002-child-theme.md) being applied: the child theme is active and the variants are declared for it.

## Goal and visible effect

`?mode=print` gives the same page as `?print`, and the plugin `wp-pdf-helper` is switched off. What changes for the administrator: the "Print" column of the media library and the flag `wpdfh.print` are gone as an interface. What a visitor sees: the print icon of the web templates now leads to `?mode=print` (the links are blocks `modes/link`), and a "Web" link next to the QR code of the print templates leads back. The pages `?print` stop working (no link leads to them any more; the PDF are made by opening the page in the print mode in Samsung Internet).

## Prerequisites: what must exist before the rollout

Decided by Eric (2026-10-13). Each line is to be implemented and tried on **testing** before staging. Nothing is ticked: none of it is known to be done.

| Done | Feature | Repository and commit | Tried on testing |
|---|---|---|---|
| [x] | The gallery block in Media Helper: **`wp-media-helper/gallery`**, wrapper class `wp-block-wp-media-helper-gallery`, the same output as `wp-printable-gallery` (`columns=2`, `size=gallery`, `link=file`), the order of today (`menu_order`, then ID), the images from the links of the active mode (method `otherguise`) or from `get_attached_media()` (`native`); with no image it prints nothing (the old print mode then showed every attached image). The order by date taken is later and separate | `evlist/wp-media-helper`, `b497b67`, `docs/slices/043-gallery-block.md` | [ ] PHPUnit with stubs only; nothing on a real site, and its editor script never loaded in a real editor |
| [x] | Media Helper lets the methods `native` and `otherguise` live together, so that the migration can run at the end of the rollout (and, with the setting on, returns the images attached the WordPress way when no context is asked, or when the filter `wp_media_helper_native_links_in_context` says so) | `evlist/wp-media-helper` (slice 042 step 7, tag `rollout-2` at `8ab96c5`, then `b497b67`) | [ ] |
| [x] | Otherguise: the filter `modes_active_mode` (the slug of the active mode, which the gallery block of Media Helper uses as its context) and the answer to `wp_media_helper_native_links_in_context` (before the migration the native links count in the default mode, not in print) | `evlist/wp-otherguise`, slice 214 | PHPUnit only |
| [ ] | Attached GPX: the attributes `mapHeight`, `graphHeight`, `showAttachments`, `showDownload` | `vdv/wp-attached-gpx`, commit `e6d0bbe` | [ ] |
| [x] | Theme: in the three web templates (`journee`, `publication-randonnee`, résumé mensuel) the JavaScript link of the hack is replaced by a block `modes/link` to the print mode (icon print, accessible label "Print"); in the three print templates a block `modes/link` to the web mode (label "Web") next to the QR code | `evlist/wp-twenty-twenty-five-printable` | [ ] |
| [x] | Theme: the GPX block of the print templates carries the sizes that `wp-pdf-helper` imposed: `publication-randonnee-print` `mapHeight` 200px, `graphHeight` 150px; résumé mensuel print 600px and 300px; both `showAttachments` and `showDownload` false | theme | [ ] |
| [x] | Theme: the six templates that use the gallery (`journee`, `publication-randonnee`, `wp-custom-template-r-sum-mensuel-randonn-e`, each with its `-print`) call `wp-media-helper/gallery` and the rule of `modes/print.css` follows its class (`wp-block-wp-media-helper-gallery`) | theme (`953d784`) | [ ] |
| [x] | Theme: the rules `.print-link` and the font Dashicons (nothing uses them once the links are blocks), and the class wrongly put on the paragraph "Écrit par", are removed | theme (`1c0ce35`) | [ ] |
| [x] | Otherguise: the stylesheet `modes/print.css` of the theme (slice 213), `wp otherguise media migrate` (slice 211), the block `modes/link` (slice 205) | `evlist/wp-otherguise` | PHPUnit only; never tried on a real site |

**Plugins that are not git clones** (copied from Gitea into the containers, so not covered by the tag and put back to the state of production by every synchronisation): Attached GPX, the gallery plugin, and any other block plugin. They are updated by hand on staging, then on production, before the script. The script checks them by what they do, not by a version: the block `create-wp-attached-gpx/wp-attached-gpx` has the four attributes, and `WP_Block_Type_Registry::get_instance()->is_registered( 'wp-media-helper/gallery' )` is true. After a new synchronisation of staging they must be updated again.

## The script (to write once the prerequisites are met)

Order proposed, to be confirmed when it is written. **On production nothing is asked** (the validations are listed at the end), so the checks are the safety net, and what must be true of the container and the plugins is checked **first**, before the backup is made:

1. the versions, and the state before (`wp plugin list` with versions, since the plugins copied from Gitea are not tagged);
2. a manual step: update the plugins copied from Gitea; then the functional checks above (the script stops if they fail);
3. deactivate `wp-printable-gallery` (Eric: at the start of the rollout; it stays installed);
4. compare `?print` (production) with `?mode=print` (the site being rolled out), page by page, while `wp-pdf-helper` still works;
5. `wp otherguise media migrate --dry-run`, read the report, then run it; the migration comes last because Media Helper lets both methods coexist;
6. choose the method `otherguise` in Media Helper (to be done in the script once the name of the option is checked);
7. compare again, and the list of photos of a sample of posts before and after (the same list, in the same order);
8. deactivate `wp-pdf-helper` (last), and compare the pages in the print mode one more time.

## What to watch

- **Print galleries are empty until the migration has run.** Print shows only the links that carry the mode print; the web keeps showing the images attached the WordPress way (the filter of slice 214). The window is the time between the update of the theme and the end of the migration, which is the last step but one; the migration must not be skipped.

- **The same photos in print.** The old gallery showed, with `?print`, the images whose `wpdfh.print` was not empty; the migration gives them every mode and gives the others every mode except print. Compare the lists on a sample of posts, flagged or not.
- **The sizes of the maps in print** (200/150 or 600/300 by template), with no attachments and no download link.
- **The QR code** is the permalink of the post (no query string): it leads to the web version from a printed page.
- **The icon** of the print link is now an SVG of the block `modes/link`; the theme no longer loads the font Dashicons (wp-pdf-helper did, for every visitor).
- **The link "Web"** next to the QR code of the print pages is for the screen: `@media print` hides it on paper (`.mode-link`, the one rule added to `modes/print.css`).
- **`?print` addresses** written by hand in posts or menus, if any, will no longer do anything.
- **Going back:** reactivate `wp-pdf-helper` and `wp-printable-gallery`; the migration did not change `post_parent` or the flag.

## Not in this rollout

The order of the photos by date taken (a visible change, made later in Media Helper); the classes `author-line` and `title-part` (Eric: not a concern); `noindex` and `canonical` of the print mode (later slices of the Modes module).

## Versions

(Pasted from `rollout.sh create --site=staging` when the rollout is created.)

## Log

(Date, site, who ran it, what differed from the text and the correction made to it.)
