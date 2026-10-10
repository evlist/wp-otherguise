<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 207 — Modes: a stylesheet per mode

Status: **planned**, to confirm with Eric before any code. Dependencies: slices 200 (modes), 203 (applying the variants), 204 (the screen); the Triples module. Module: `Modes`.

## Goal

Today, on the test copy of Eric's site, declaring `publication-randonnee-print` as the variant of `publication-randonnee` in the mode `print` gives the print **layout**, but not the print **CSS** that the hack injects (so the page is not styled for print). The hack's CSS must be replaced: a mode can have stylesheets, loaded on the front end when that mode is the mode of the request.

## Where the CSS lives: not in a statement

A statement's object is a **literal of at most 191 bytes** (`StringDatatype::MAX_BYTES`), because the column is part of the index (`object_id varbinary(191)`). A print stylesheet is several kilobytes. Raising the limit is not a number to change: a long text cannot be indexed, so it would need a second column or table, a migration, and statements whose object cannot be searched. It would also break the principle of the model: a statement links entities and carries small values; it does not hold documents.

So the statement holds a **reference** to where the CSS is. Three places were considered:

| Place | For | Against |
|---|---|---|
| **A file of the Media Library** (an attachment), chosen | `post:ID` is an entity the model already knows; no size limit; copied with the database and the uploads (the synchronization script handles both); fits the planned integration with Media Helper (attachments qualified by mode); removing the file removes the relation (Triples cleans up on `deleted_post`); served as a static file, with browser caching. | The file must be uploaded again to change it (or replaced in the Media Library). |
| A custom post type holding the CSS as text, edited in a textarea | Editable in place, revisions. | A new post type, an editing screen, escaping rules for text that ends up in the page. Much more code. |
| A file of the theme or of a plugin (a path in the statement) | Versioned in git; the path fits in 191 bytes. | Not for an administrator without file access; paths need an allow-list. May come later as a second kind of source. |

WordPress accepts `.css` in the Media Library on a single site (`text/css`; scripts and HTML are refused to users without `unfiltered_html`). That was checked on a scratch site 7.1.3, from the command line, not through an upload by a user, and not on a multisite (where the allowed types are a network setting).

## Model

- Subject: the mode (`mode:print`). Predicate: **`modes/stylesheet`** (module-owned name). Object: the attachment (`post:ID`).
- A mode can have **several** stylesheets (the default mode too). They are loaded in the order of their ids; an explicit order is a later refinement (a qualifier, as for the ranks of Books).
- A statement whose attachment is missing is ignored on the front end, and shown as missing on the screen, as for templates.

## Front end

- Only when the modes are enabled, on the front end, for the mode of the request: `wp_enqueue_style()` of each stylesheet, with the address of the attachment and the modification time of the file as version.
- **Priority 100 on `wp_enqueue_scripts`** (the theme's styles are printed at the default priority 10), so that the stylesheet of a mode overrides them without `!important`. Documented as a coexistence rule; the filter **`modes_stylesheet_priority`** changes it.
- `media="all"`: the print mode is also seen on a screen (`?print` in a browser), and the stylesheet may hold `@media print` rules itself.
- Not loaded in the administration or the block editor in this slice.

## The screen

A section **Stylesheets** under the tables of variants, on **Settings → Otherguise modes**:

- for each mode: its stylesheets (name, size, link to the file, **Remove from this mode**);
- a form **Add a stylesheet**: a mode and either a **file to upload** (`.css` only, size limit, stored in the Media Library through the standard upload functions) or an **existing CSS file** chosen in a list of the attachments of type `text/css`.

Handlers `admin_post_`: capability **`edit_theme_options` and `upload_files`**, own nonce, input read and validated before any write (a registered mode, an attachment that exists and is a stylesheet, file extension and detected type both `css`). No new handler of the screen is added if slice 206 comes first: it will be part of its single form.

## Not changed

Templates and variants, the way a mode is found, the setting that enables the modes. Nothing happens for a mode without stylesheet.

## Tests

PHPUnit (no database for the pure parts): the list of the stylesheets of a mode, the enqueue (handles, addresses, versions, priority, filter), nothing when the modes are disabled or in the administration; with a database: the handler (capability, nonce, refusals, the relation stored, the cleanup when the attachment is deleted), the screen. On a real WordPress: upload of a CSS file, the print page of the test copy styled, the web page unchanged, the stylesheet removed.

## To confirm

1. The stylesheet is a file of the Media Library, referenced by `modes/stylesheet`, rather than a longer literal in the statements.
2. Several stylesheets per mode, in the order of their ids.
3. Loaded after the theme (priority 100), for every mode that has some, on the front end only.
4. Upload `.css` only, from the screen, by users who can edit the theme options and upload files.
5. Open: how the hack's own CSS is switched off on the site (it is injected by the plugin that carries the hack, outside this repository).
