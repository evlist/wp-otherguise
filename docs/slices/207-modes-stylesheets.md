<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 207 — Modes: a stylesheet per mode

Status: **done** (the choices below were confirmed by Eric). Dependencies: slices 200 (modes), 203 (applying the variants), 204 (the screen); the Triples module. Module: `Modes`.

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

## What was built

- The predicate **`modes/stylesheet`** (subject `mode`, object `attachment`), registered with the module; `Stylesheet\Stylesheets` (add, remove, list in the order of the ids), `Stylesheet\StylesheetFiles` (the only class that asks WordPress about attachments: describes a CSS file, lists them, receives an upload), `Stylesheet\StylesheetLoader` (enqueues on `wp_enqueue_scripts`, priority 100 by default, registered on `init` priority 20 so that the filter `modes_stylesheet_priority` can be added by a theme; handle `modes-{mode}-{id}`, version = modification time of the file).
- The section **Stylesheets** of **Settings → Otherguise modes** (`Admin\StylesheetsScreen`) and the handlers `modes_add_stylesheet` and `modes_remove_stylesheet` (`Admin\StylesheetActions`). Capability `edit_theme_options` and, to add, `upload_files`; own nonces; the form is read before anything is written; refusals are notices (`invalid_request`, `not_a_stylesheet`, `upload_failed`, `too_large`).
- **Uploads accept `.css` only**, whatever the site allows (the filter `upload_mimes` is narrowed to `css` during the upload), the check of the type by content (which may call a stylesheet `text/plain`) is overridden for the extension `css`, and the size limit is 512 KB (filter `modes_stylesheet_max_bytes`); a file over the limit is deleted from the Media Library.
- The file stays in the Media Library when it is taken away from a mode; when the file is deleted, Triples removes the statement.

## Not changed

Templates and variants, the way a mode is found, the setting that enables the modes. Nothing happens for a mode without stylesheet.

## Tests

PHPUnit (no database for the pure parts): the list of the stylesheets of a mode, the enqueue (handles, addresses, versions, priority, filter), nothing when the modes are disabled or in the administration; with a database: the handler (capability, nonce, refusals, the relation stored, the cleanup when the attachment is deleted), the screen. On a real WordPress: upload of a CSS file, the print page of the test copy styled, the web page unchanged, the stylesheet removed.

## Verified

- PHPUnit (12 tests in `ModesStylesheetsDbTest`, plus the hook tests of the module and of the screen): the service (idempotent add, order of the ids, a `ModeDefinition` as well as a reference, refusals that store nothing, removal), the loader (handles, addresses, versions, nothing for another mode, with the modes disabled, without the service or for a file that is gone), the handlers (capability, upload capability, nonce of each action and of the other one, every refusal as a notice with its code, upload and existing file), the section (rows, missing files, escaping, nonces, the form, only the upload when the library has no stylesheet) and the priority of the loader.
- **Real WordPress 7.1.3 in Chromium** ([`tests/real-wordpress/stylesheets/`](../../tests/real-wordpress/stylesheets/flow.js), 27 checks, no PHP error and no failed request): uploading a CSS file from the screen; it is linked once in print mode (`?print` and `?mode=print`), after the styles of the theme, not in web mode, and served as `text/css`; a `.php` file, a file over the limit and a form without file refused with a message and nothing kept; adding a file of the Media Library to the default mode and removing it; disabled modes; a file deleted behind the screen's back (the relation is cleaned up and the screen still opens).

## Not verified

An upload by a user who has `edit_theme_options` but not `upload_files` (only tested with doubles); multisite (where the allowed file types are a network setting); the look of the screen on a narrow screen; very many stylesheets (the list of the library is limited to 200 files); the print CSS of Eric's site itself (tried with a scratch file, not with the CSS of his hack).

## To confirm (answered)

1. The stylesheet is a file of the Media Library, referenced by `modes/stylesheet`, rather than a longer literal in the statements.
2. Several stylesheets per mode, in the order of their ids.
3. Loaded after the theme (priority 100), for every mode that has some, on the front end only.
4. Upload `.css` only, from the screen, by users who can edit the theme options and upload files.
5. ~~How the hack's own CSS is switched off~~ — settled by Eric: the CSS stops being injected as soon as the plugin that carries the hack (PDF helper) is deactivated; nothing to do here.

## Possible improvements (not planned)

- **A stylesheet per variant.** The hack injects the same CSS whatever the variant, so one stylesheet per mode is enough for Eric. The model would hardly grow if it were needed: the same predicate `modes/stylesheet`, with a *template* (the variant) as the subject instead of a mode, loaded when that variant is the one served. Nothing in the first slice prevents it.
- An explicit order between the stylesheets of a mode (a qualifier).
- A file of the theme or of a plugin as a second kind of source.
- Loading the stylesheets in the block editor, so that the editor shows the mode.
