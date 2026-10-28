<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slices

One document per slice of work, named `NNN-name.md`. A slice is documented before it is coded, lists its dependencies, and is marked done when its tests pass.

Numbering: `0xx` foundation, `1xx` Triples, `2xx` Modes (and the first slices of Books, 218 to 222), `3xx` Books, `4xx` Shares.

## Done

| Slice | Title |
|---|---|
| [000](000-repository-scaffold.md) | Repository scaffold: structure, tooling, module loader, architecture tests |
| [100](100-triples-registry.md) | Triples: entity types and predicate registry (qualifier part superseded by 101) |
| [101](101-triples-registry-revision.md) | Triples: registry revision for statements about statements |
| [102](102-triples-storage.md) | Triples: storage of statements (one table) |
| [103](103-triples-statements.md) | Triples: creating and reading statements |
| [104](104-triples-lifecycle.md) | Triples: cleanup, actions and cache |
| [105](105-triples-admin.md) | Triples: administration screen |
| [200](200-modes-active-mode.md) | Modes: declared modes, the mode of the request, access to the services |
| [201](201-modes-template-hooks.md) | Modes: where to hook the choice of a template (verification, no product code) |
| [202](202-modes-variants.md) | Modes: variants of templates and template parts |
| [203](203-modes-apply-variants.md) | Modes: applying the variants |
| [204](204-modes-admin.md) | Modes: the screen of the variants |
| [205](205-modes-link-block.md) | Modes: the link to another mode (block `modes/link`) |
| [207](207-modes-stylesheets.md) | Modes: a stylesheet per mode |
| [210](210-media-method.md) | Media: the attachment method of Otherguise for Media Helper (several posts, modes, ranks) |
| [212](212-variant-retheme.md) | Modes: moving the variants to another theme (WP-CLI `variant retheme` and `variant list`) |
| [213](213-modes-theme-stylesheet.md) | Modes: the stylesheet `modes/<mode>.css` of the theme, loaded when the mode is active |
| [214](214-modes-active-mode-filter.md) | Modes: the filter `modes_active_mode` (slug), and the native links of Media Helper before the migration |
| [215](215-media-migrate-criterion.md) | Media: a generic criterion (meta key and value, restricted mode, MIME family) for `wp otherguise media migrate` |
| [216](216-modes-url-shortcode.md) | Modes: the shortcode `[modes_url mode="…"]`, the address of a page in a mode, for the QR code of Kaya |
| [217](217-media-migrate-all-types-and-posts.md) | Media: `media migrate` for every type of media (`--mime=all`) and for a list of posts (`--post-ids`) |
| [218](218-media-forget-removed-attachments.md) | Media: forget the links of the attachments that Media Helper removes (`wp_media_helper_attachments_removed`) |
| [211](211-media-migration.md) | Media: migration of the native attachments and of the `wpdfh.print` flag (WP-CLI) |
| [209](209-modes-default-template.md) | Modes: the default template of new posts (WordPress has no such setting; independent of the modes) |
| [204](204-modes-admin.md) | Modes: disabled by default (a change of slice 204: see its last note) |

## Planned

None at the moment.

## Candidates (not planned yet)

Titles only. Each one is to be planned with Eric, from the decisions recorded in [`../design/`](../design/README.md), before any code is written.

- `1xx` Triples, after 105: 106 JSON export and import; later REST, then RDF export.
- `2xx` Modes, after 205: propagation of the mode in internal links, a QR code to another mode, per-mode assets and options, side effects (`noindex`, `canonical`).
- `3xx` Books: composition of a book (ordered `contains` statements, parts by date range); single book page; table of contents; page numbers (declared strategy); index; export; renderer backends.
- `208` Modes, a contract for other plugins: the filters `modes_active_mode` and `modes_option` and per-mode options set on the screen ([planned in detail](208-modes-plugin-contract.md), **not needed for now**: parameters of a block are better set in its call, in each template; useful for plugins without a block).
- `206` Modes, edit the variants in place and save with a button ([planned in detail](206-modes-screen-save.md), **not a priority**): change the template, the variant and the modes of a row, one **Save changes** button, all or nothing.
- `2xx` Modes, create a variant by copying: in the form **Add a variant** of **Settings → Otherguise modes**, an option "create the variant as a copy of the template" (with a name), so that a print version can be started from the web template without going through the site editor. Eric's request after finding that the list of templates of the editor offers only *Edit* and *Reset* (the **Add Template** button is at the top right of that list, and the template can then be filled through the code editor). Open: what the copy keeps (the whole content and the parts it uses), and whether it also declares the relation.
- `219` Books, a print preview panel in the post editor ([first version coded and tried by Eric on testing](219-modes-print-preview-panel.md)): the post in an iframe in print mode, at the size of the page, with a "fits / overflows" indication.
- `220` Books, a PDF preview through a Gotenberg server ([coded, tried against a stand-in of Gotenberg](220-books-gotenberg-pdf-preview.md)): the address of the server in the settings, a PDF mode in the panel with download and open in a new tab.
- `221` Books, the sizes of the images for the print ([coded](221-books-print-image-sizes.md)): names declared by the theme as fractions of the usable width, dimensions computed by Books from the page format for 300 ppi, no `srcset` in the print mode, `wp otherguise books sizes`.
- `222` Books, the resolution of the altitude profile in the print mode ([coded and tried on testing](222-books-print-chart-resolution.md)): a pixel ratio of 3 for the chart in the print modes; the tiles of the map are left alone (zoom+1 rejected).
- `223` Books, a section as a container ([coded and tried on a scratch WordPress](223-books-section-container.md)): any post or page marked as a section holds an ordered list of posts (`books/contains`); a panel in the editor attaches posts from the section (search, dates, selection) and from a post (search among the sections); WP-CLI `books section`.
- `224` Books, the section panel in a floating window ([coded and tried on a scratch WordPress](224-books-section-window.md)): the title "Book section", a summary only in the sidebar of a section, a window like Media Helper's with the featured image as the background of each line, arrows, "add before / after", remove with confirmation, view and edit.
- `225` Books, the address by which the PDF server reaches the site ([coded and tried on a scratch WordPress](225-books-pdf-internal-address.md)): an optional setting that replaces the public address of the page sent to the server by the internal one (`http://wordpress`), to avoid the proxy and its time limits.
- `226` Books, large PDF files ([coded and tried on a scratch WordPress](226-books-large-pdf.md)): the answer of the server is written to a file, the pages are counted in the file, and the PDF is sent to the panel as a file (byte ranges), not as base64 in JSON.
- `227` Books, markers in the PDF and the pagination map ([proposed](227-books-pdf-markers.md)): marker links and ids in the print mode, a reader of the annotations of the PDF, the page of each unit and passage.
- `228` Books, posts with a role ([proposed](228-books-role-posts.md)): table of contents, index and blank pages as units of a section, generated and styled by templates of the theme; the role `blank` adds the page breaks the rules of the printer profile require.
- `229` Books, checks before printing ([proposed](229-books-print-checks.md)): margins against the safety zone, weight of the file and of the pages, effective resolution of the images, reported as indications by WP-CLI and in the panel.
- `230` Books, printer profiles and book projects ([coded and tried on a scratch WordPress](230-books-printer-profile-and-project.md)): two post types, the rules of a printer (format, margins, pages, blank pages, images, weight) and a project that ties a profile to a top-level section.
- `231` Books, the print page of a section with all its units ([coded and tried on a scratch WordPress](231-books-merged-section-print.md)): from the test branch `merge-all-html`, for an editor or the PDF job only (a signed token), the units rendered one after the other in front of the footer, a log that is off by default; `wp otherguise books merge-log`.
- `232` Books, the format of the site follows the theme ([proposed](232-books-site-format-from-theme.md)): the values of the theme's reference are the defaults of the setting "format of the page", saved values are kept when the theme changes with a warning to confirm (keep or adopt), the theme's values are shown with a restore button, and the preview offers the "Site format" next to the projects.
- `400` Access, a private link with a secret token ([coded and tried on a scratch WordPress](400-shares-private-link.md)): the module `Access`: one token per post, permanent until revoked, to see a private or unpublished post without logging in (`?shared=<token>`); the use by the PDF job of Books, and the unlisted state, come in later slices.
- `401` Access, a settings page ([coded and tried on a scratch WordPress](401-access-settings.md)): whether the module exposes its functioning (private links, who may create one), read before any hook is added.
- `402` Access, published but hidden ([coded and tried on a scratch WordPress](402-access-unlisted.md)): an "unlisted" flag that removes a post from the lists, feeds, search and sitemaps while its address works.
- `403` Access and Books, previewing unpublished posts ([coded in part, tried with a stand-in for Gotenberg](403-access-section-preview.md)): the PDF job converts a post that is not published through its private link, as a preview (flagged, not for the final print); the links of a section page wait for the loop block.
- Integration with Media Helper (attachments qualified by mode, one image attached to several posts).
