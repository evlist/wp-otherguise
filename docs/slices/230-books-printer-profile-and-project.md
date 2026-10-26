<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 230 — Books: printer profiles and book projects

Status: **proposed (2026-10-26), not coded**. Dependencies: slice 223 (sections). Needed by slices 228 (roles, blank pages) and 229 (checks); slice 227 (the pagination map) is computed per project. Context: [`../design/books.md`](../design/books.md).

## Why

What a printer asks (format, margins, number of pages, blank pages, cover, resolution, weight) is **not a property of a section**. The same section was published last year at Flexilivre, one section after the other, then gathered with the others into one book at CoolLibri; the two printers do not have the same rules (Flexilivre: six fixed formats, important content at 5 mm from the edges at least, no cut marks, no spine, covers as first and last page, converted to RGB; CoolLibri: 2 cm "safety zone" recommended, 3 mm bleed, a number of pages by binding, 700 MB at most). The rules therefore belong to a **printer profile**, and a **book project** ties a profile to a section.

## Proposed: two post types (Eric's choice, 2026-10-26)

**`books_printer`: a printer profile** (one post per printer and format, editable in the admin, with no code to add a printer; presets for CoolLibri and Flexilivre could be offered as starting points). Fields, all optional except the format:
- the **page format** (width and height, a name), the **safety margin**, the **gutter** (inner margin), the **bleed**, the **position of the folios**;
- the **number of pages**: minimum, maximum, multiple (an even number: a sheet has two pages), by binding when the printer says so;
- the **rules of blank pages**: a final blank page wanted (CoolLibri prints its "achevé d'imprimer" notice on it), the blank pages of a combined cover file, the first page of a chapter on a right-hand page;
- the **cover**: in the file or separate, first and last page, no spine;
- the **images**: minimum resolution (300 ppi at CoolLibri), colour space (RGB, CMYK), the profile ICC;
- the **weight** of the file at most (700 MB at CoolLibri, 3 GB at Flexilivre) and a link to the printer's documentation, with the date it was read.

**`books_project`: a book project**: one post per book to print, with
- the **root section**, a **top-level** section (Eric's choice): the project does not list sections one by one. What last year was a "hat" (cover, title page, ...) is made of units of that root section, before the sections; the sections of the book are its children;
- the **printer profile**;
- what belongs to this book only: the title and the author, the options that are not the printer's (folios on or off, a chapter on a right-hand page), the pages of the output (the PDF jobs and their pagination map are kept by project).

The same section can have **several projects** (one per printer, or a book that gathers the sections of the year).

## Interactions

- The page format of a project sets the size of the rendering (the preview panel and Gotenberg) instead of the global setting; a section without a project keeps the current behaviour.
- The thresholds of slice 229, the blank pages of slice 228 and the margins of the print templates are read from the profile of the project.
- Capabilities: a profile and a project are edited with `edit_others_posts`; they are not public (no front-end page, not in sitemaps, not unlisted-visible) and they are exported with the site.

## How Books gives the rules to the page (proposed; Chromium checked on 2026-10-26)

Books **injects the rules into the page in the print mode**; the theme is asked for almost nothing.

- **`@page` carries the rules**: the format (`size`), the margin, the gutter (`@page :right` and `@page :left` with the inner margin on the binding side), the bleed. No class of the theme is involved, and `margin`, `padding` of the main container remain the theme's concern (the child theme already clears them in `modes/print.css`).
- **Margin boxes carry the folios**: `@bottom-center`, or `@bottom-right` on right-hand pages and `@bottom-left` on left-hand pages for folios on the outside, with `counter(page)`.
- **The theme's contract** is reduced to what is not a page: Books defines CSS variables on the root (`--books-margin`, `--books-gutter`, `--books-safe`) that a theme can use for its own spacing, and a theme whose main container has padding adds one line to its `print.css`. A class contract (a name for the main container) is a fallback, not the principle.

**What was checked** (Playwright Chromium 141, `pdftotext -bbox` on the PDF, an A5 page; **not through Gotenberg**):
- `@page :right { margin-left: 20mm; margin-right: 8mm }` and `@page :left` with the sides swapped give **exactly** 20.1 and 7.9 mm on the recto and the opposite on the verso; the first page of a document is a right-hand page. The rules also win when the paper size and the margins are given as options of the call, but the page height then differs by one point (595.9 instead of 595.0), so **`preferCssPageSize`** (a field of Gotenberg, not sent today by `GotenbergClient`) is to be used and the margins of the settings dropped from the call, to have one source.
- **Margin boxes work**: `@bottom-center { content: counter(page) }` and the folios on the outside (right on odd pages, left on even pages) are drawn at the expected places. Their font and position are the CSS's, so the stamp of pdfcpu is only needed for folios that Chromium cannot number (the offset of the front matter, slice 228).
- **Chromium does not insert blank pages**: `break-before: right`, `left`, `recto` and `page-break-before: right` leave three units on three consecutive pages. The role `blank` of slice 228 therefore has to **produce** them.

**Consequence for the blank pages** (to check): an odd number of pages inserted after the render would swap recto and verso for everything after it, and with it the gutter. The blank pages must therefore be **in the HTML**, counted from a first render (the map of slice 227) and the body rendered again. That is two renders of the body for a book with blank pages; they give the same pagination **if the sum of the inner and outer margin is the same on both sides** (the text then wraps the same on a recto and on a verso), which is what a gutter does, to check. A book with no blank page needs one render.

## The contract with the theme (proposed; read in `wp-twenty-twenty-five-printable` on 2026-10-26, nothing changed there yet)

**One direction: Books injects, the theme consumes.** A theme depending on a plugin is not a problem in itself here (the child theme is Eric's, for his site, and already uses `[modes_url]`, `[kaya_qrcode_dynamic]`, `wp-media-helper/gallery`, `wp-whobird` and `wp-attached-gpx`, and its `modes/print.css` is loaded by the Modes module); it becomes one when the theme breaks without the plugin, or the plugin without the theme. Hence:

1. **Books works without the theme.** It writes `@page` (format, margins, gutter, bleed) and the margin boxes of the folios itself, and gives a default template for each role (`toc`, `index`, `blank`); a site with another theme gets a correct, plain result.
2. **The theme works without Books.** It uses CSS variables **with a fallback** (`var(--books-margin, 10mm)`), so nothing changes when the plugin is inactive; **no call to a function of the plugin** in its PHP (`functions.php`), only shortcodes, blocks and variables, which WordPress ignores or shows as plain text when the plugin is missing.
3. **The contract is documented in the README of the theme**: the variables (`--books-margin`, `--books-gutter`, `--books-safe`), the file `modes/print.css`, the names of the roles and of the classes. A theme that respects it works with Books. WordPress has no declaration of a theme's dependency on a plugin (`Requires Plugins` is for plugins), so it is documentation and a check (see "Not decided").
4. **The templates of the roles are the one real dependency**: they need the data of Books (the manifest, the pagination map) and mean nothing without it, which is acceptable; the theme only overrides the default template of Books, as it overrides a category template.

What exists in the child theme today: the print templates (for example `journee-print.html`) are a `main` group with a **constrained** layout, a full-width group `#post-content` with a vertical padding (`spacing|60`), and the content in a constrained layout; `modes/print.css` clears `padding` and `margin` of `html`, `main` and `#post-content` (it depends on those names); there is **no `@page` rule, no page break per unit, no folio** in the theme.

To do in the theme, once Books gives the rules:

1. **Width.** The constrained layouts are limited by `contentSize` of `theme.json`, a width of the screen. In the print mode the groups must take the width of the page: `max-width: none` in `modes/print.css` for those groups, the width being given by `@page`.
2. **Vertical padding.** The groups with `spacing|60` add empty space above and below each page on top of the margin of the page, which makes the real margins differ from the profile (and the checks of slice 229 report it). Replace it with the variables of Books (with a fallback), or remove it in the print mode.
3. **Variables.** Where the theme fixes a spacing for the print, use the variables of Books with their fallback, not its own numbers.
4. **Page breaks.** In the merged HTML Books adds its own wrapper (`.books-merge-unit`); unit by unit nothing is needed. To check: `page-break-inside: avoid` of `print.css` (galleries, GPX maps) must not leave a large image alone on a page or push it over the bottom margin.
5. **Folios.** Books writes the `@page` margin boxes. If the theme wants to style them (font, size), it provides the rule; otherwise the default of Books applies.
6. **Roles.** The overrides of the default templates of Books for `toc`, `index` and `blank`, and the classes of the lines of the toc and of the index. New work; none of it exists.

**To check before relying on it**: whether the full-width groups stop at the margin of the page or overflow, and whether `contentSize` reduces the render under Gotenberg. Both can be measured with the checks of slice 229. A first try on testing: set `@page` by hand with the child theme, then measure the margins in the PDF.

## Not decided (open)

- Whether `preferCssPageSize` is always sent (a project) or only when the project has a profile (a section without a project keeps the options of the settings).
- Where the blank pages are made for a PDF made unit by unit (the parity of each unit starts at a recto).
- A check "the active theme follows the contract" on the settings screen of Books (the variables used, `modes/print.css` present), as an indication.

- The names of the post types and of the fields; a table of settings or post meta (meta, to be exported with the posts).
- The presets: shipped as JSON files read to create the first posts, or only documentation.
- How a margin reaches the templates (a CSS variable set on the page by Books, to be shared with the child theme `wp-twenty-twenty-five-printable`, which today fixes its own margins).
- What a project of a section **without** a top-level section means (a book that is a single section).
- Whether a project can override a field of its profile.

## Tests (planned)

Registration of the two types (not public, capabilities), the fields and their sanitising, the profile of a project, the format given to the renderer, a section without a project unchanged, uninstall, WP-CLI `books project list|show|set-profile`.

## Verification (planned)

On a scratch WordPress; **never tried** with a printer's proof, nor on the real blog.
