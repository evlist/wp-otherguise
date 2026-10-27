<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 230 — Books: printer profiles and book projects

Status: **coded (2026-10-26)** except the theme's part and what slices 228 and 229 do with the rules, see "What was coded" and "Verified". Dependencies: slice 223 (sections). Needed by slices 228 (roles, blank pages) and 229 (checks); slice 227 (the pagination map) is computed per project. Context: [`../design/books.md`](../design/books.md).

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
- what belongs to this book only: the title and the author, the folios on or off (a chapter on a right-hand page is a rule of the profile), the pages of the output (the PDF jobs and their pagination map are kept by project).

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

**First try with the child theme (scratch WordPress, 2026-10-26; Playwright Chromium 141, `@page` added to the page by hand, theme repository untouched; margins read in the PDF with `pdftotext -bbox`; one text-only post with the template `journee-print`; not Gotenberg, not the real blog)**:
- **Without `@page`** (the options of the call, 10 mm): the text sits at 9.8 mm left, 9.7 mm right and 10 mm at the top: `print.css` already clears the padding of `#post-content` and the margin of `main`, so **the vertical padding `spacing|60` of the template does not show** in the print mode. Point 2 above is not confirmed for this template.
- **`@page { size: A5; margin: 20mm 8mm 20mm 20mm }`**: 20.1 mm left, 7.9 mm right, 20.2 mm at the top, exactly the profile, with **no change to the theme**; adding rules that remove the padding and `max-width` of the groups changed nothing on A5.
- **A4 with margins of 20 mm** (printable width 170 mm, about the `contentSize` of 645 px): 20.1 / 19.6 / 20.0 mm, as asked. **A4 with margins of 10 mm** (printable width 190 mm): the text stops at 19.3 mm from the right edge instead of 10: **`contentSize` limits the width as soon as the printable width exceeds about 170 mm**; with `max-width: none` on the content blocks it is 9.6 mm. So point 1 matters for large formats (A4 with small margins, square or landscape books), not for A5 with 20 mm margins.
- **Limits**: the right edge is the end of the longest line (ragged), so the right margin is only checked within a few millimetres; nothing tried with images, galleries, GPX maps, the QR code, or several units; the merged HTML was not used.

**What it changes**: for A5, Books alone (`@page`) is enough with this theme and this template; the theme work is **one rule (`max-width: none` on the content blocks in `print.css`) for formats wider than about 170 mm**, plus the roles (point 6). Points 2 to 5 stay to be checked on pages with images and maps.

## What was coded (2026-10-26)

- **Two post types**, `books_printer` and `books_project` (`Project\PostTypes`): not public (no page, no rewrite, not in the search or the REST API), edited under **Settings** by users who can `edit_others_posts` (all their capabilities map to it), exported with the site, deleted by the uninstall of the module. They are edited with **a meta box** (`Project\MetaBoxes`: nonce, `edit_post` checked again, everything posted goes through `PrinterProfile::normalize()` / `Projects::normalize()` before it is stored in one protected meta: `_books_profile`, `_books_project`), so the block editor is not used.
- **The profile** (`Project\PrinterProfile`): the width and height of the page, the margin, the gutter, the safety zone, the bleed, the position of the folios (none, centred, outside), the fewest, the most and the multiple of pages, a blank last page, a chapter on a right-hand page, the cover (none, separate, first and last pages, combined), the fewest ppi, the colours, the largest file, the address of the documentation and the date it was read. A value that is not valid is replaced by its default; a missing gutter is the margin. **A project does not override a field of its profile** (decided: keep one source). The rules of the blank pages and of the images are stored and shown but **nothing reads them yet** (slices 228 and 229).
- **The presets** (`Project\Presets`, in PHP, not JSON files): `coollibri` and `flexilivre`, from their documentation read on 2026-10-26, **to check against the printers' sites**; created by `wp otherguise books profile create <title> --preset=<slug>`. A section without a project keeps the global page format of the settings.
- **The project** (`Project\Projects`): the title of the post, the author and the folios on or off (in its meta), and **two relations of the Triples module** (`Project\ProjectLinks`, registered by Books): **`books/root`** (project → its top-level section: the sections of the book are its children, the "hat" are units of it) and **`books/profile`** (project → its printer profile), one object each. They are found by the index of the statements and taken away by Triples when a post is deleted; the form and the commands set them (setting replaces, an empty choice takes it away). The first version of the slice kept the two ids in the meta; `wp otherguise books project migrate` moves them (it can be run twice).
- **Which projects cover a post**: those whose root is the post or a section **above** it, directly or not (`Sections::ancestors()`, then the index `projects_of( section )`): a section, a day or a whole book can belong to several projects. A project whose profile is missing is left out. **The one that applies**: the one asked for (`books_project` in the address) when it covers the post, else the lowest id; the filter `books_project_for_post` can choose among those that cover it.
- **The rules in the page** (`Project\PageRules`, `Project\PrintStyles`): in a print mode (the first of `books_preview_frame_modes`) and for a post a project covers, `wp_add_inline_style()` at priority 20 of `wp_enqueue_scripts` adds `@page` (size, margin, gutter with `:right` and `:left`, the bleed that enlarges the page and every margin), the folios in the margin boxes (unless the project turns them off) and the variables `--books-margin`, `--books-gutter` and `--books-safe` (the profile's values, without the bleed) and **`--books-k`**, the factor of the page (below).
- **A scalable page: the factor `--books-k`** (Eric, 2026-10-27, instead of a scale of the PDF): the **global format of the settings is the page the templates are composed for** (an A4 with margins of 10 mm on the blog: the image sizes of slice 221 follow it); `--books-k` is the **usable width of the project's page over the usable width of the global format** (a quarter to four, three decimals; 1 when the global format is not known), 0.674 for an A5 with margins of 10 mm. A theme multiplies by it what does not follow the width of the page: `calc( 200px * var( --books-k, 1 ) )`. It is the only CSS that Books generates for this; the page itself stays the theme's. A day of the blog, which took two or three pages in an A5 because the map plugin writes fixed heights (`200px`, `150px`), the gallery wrapped to one column and the figures wrapped in a narrow column, is **one page for 9 days of 12 in an A5, as many as in an A4**, with the CSS that the child theme now has (see its README); text down to 85 %. The preview of the panel needs nothing: the page of a project is shown at its own usable width and the same CSS applies.
- **The PDF job**: the job asks the page with `books_project=<id>`, sends the format of the profile and `preferCssPageSize` (new option of `GotenbergClient`, field `preferCssPageSize`), and the key of the cache depends on the project and on its CSS (added only for a post that has a project, so the PDF files already made keep their key). The project asked for travels with the request: the routes `pdf/<id>` (POST and GET), `/file` and `/download` take `project`, the cron event carries it as its fourth argument, and `request()`, `status()` and `download()` use it; the state kept for a post and a mode is the one of the **last request**.
- **The choice in the preview panel**: when **several** projects cover the post, the title bar has a **Book project** menu next to the mode (with one project nothing is shown and that project applies; with none the global format applies). The page of the iframe asks for `books_project=<id>`, the PDF requests carry it, and the page frame and the verdict (fits / overflows) use the page of the **chosen** project.
- **WP-CLI** (see `docs/wp-cli.md`): `books profile list|show|create`, `books project list|show|create|set-profile`; `create` and `set-profile` can be run twice, `--dry-run` writes nothing.
- **Not done**: the theme's part (below, nothing changed in `wp-twenty-twenty-five-printable`); the screen's link from a section to its projects; the use of the other fields (slices 228 and 229).

## Decided while coding, and still open

- Whether `preferCssPageSize` is always sent (a project) or only when the project has a profile (a section without a project keeps the options of the settings).
- Where the blank pages are made for a PDF made unit by unit (the parity of each unit starts at a recto).
- A check "the active theme follows the contract" on the settings screen of Books (the variables used, `modes/print.css` present), as an indication.

- The names of the post types and of the fields; a table of settings or post meta (meta, to be exported with the posts).
- The presets: shipped as JSON files read to create the first posts, or only documentation.
- How a margin reaches the templates (a CSS variable set on the page by Books, to be shared with the child theme `wp-twenty-twenty-five-printable`, which today fixes its own margins).
- What a project of a section **without** a top-level section means (a book that is a single section).
- Whether a project can override a field of its profile.

### The reference of the factor `--books-k` (open, discussed with Eric on 2026-10-27; nothing here is decided)

- **The reference is a usable area, not a paper size.** What the templates assume is the width (190 mm today) and height of the content area, not "A4". It is computed from the global format of the settings and its margin, so a change of the global margin moves the factor of every project while the templates did not change. **Open: declare the reference separately** (the usable width and height the theme is composed for, in a setting of Books or of the theme), so that the global settings stay free to change.
- **Margins alone change the factor**: an A4 with margins of 20 mm against a reference of A4 with 5 mm gives 170/200 = 0.85 on the width (257/287 = 0.895 on the height) although the paper is the same. The current code already does this for the width.
- **The limiting dimension**: the factor is today the **width** ratio only. Proposed, not coded: `min( usable width ratio, usable height ratio )` when a unit must fit one page (the height then matters), the width alone for text that flows over pages. The square root of the area ratio is not proposed: it is right only when the two usable areas have the same proportions.
- **Enlarging** (factor above 1, smaller margins than the reference): allowed by the clamp (a quarter to four) but never tried; open whether to enlarge or only to leave more room.
- **The text floor** (85 % today, `--books-t` in the theme) is a point of a spectrum: at 85 % the layout reflows to the page, at 0 % the page is the reference composition reduced as a whole (what Eric did last year by reducing an A4 to A5, text near 70 %). Open: make the floor a field of the project, and compare `zoom` of the content with `scale` of Gotenberg (how Leaflet and Chart.js measure their container, whether `@page` margins stay in mm with `scale`): **not tried**. Formats with another proportion than the reference (square, A6) would still need adaptation.

## Tests

`BooksProjectLinksDbTest` (4 tests with the real statements: the two relations, the projects that cover a post through a book, a part and a day, the choice among them, the clean-up when a post is deleted), `BooksProjectTest` (13 tests: normalization of a profile, the CSS for equal margins, a gutter, a bleed and each position of the folios, the presets, the projects and which one covers a post, the filter, the print styles in and out of the print mode, the forms and their escaping, saving with and without capability, nonce or form, the registration of the two types, the commands on profiles and on projects with their refusals and runs twice), `BooksPdfTest` (the format, the address, the option and the key with a project; the project asked for in the request, the event, the key and the job), `BooksRenderTest` (`preferCssPageSize` sent only with the rules), `BooksModuleTest` (the hooks). 596 PHPUnit tests and 145 jsdom tests pass, `phpcs` is clean.

## Verified

- **Chromium** (Playwright 141, `pdftotext -bbox`, scratch WordPress 7.1.3): a printer profile and a project created with the commands (a second run changes nothing), the page of a post of the section in the print mode carries the style and the page in the web mode does not; the PDF made from it has the size of the profile (150.5 mm), **18.0 mm** on the left of a right-hand page and **7.9 mm** on a left-hand page (profile: gutter 18, margin 8), 8.2 mm at the top.
- **Relations and choice** (scratch WordPress): the project made before the relations moved by `migrate` (twice: nothing the second time); two projects on a book and on the section above it cover the same post, the page asks for the first by default, for the other with `books_project=` and falls back to the first for an id that does not cover it; in the editor (Chromium, logged in) the panel shows the menu with the two projects, the address of the iframe and the size of the page follow the choice; the form of a project sets, clears and restores its root and its profile.
- **The admin in Chromium (logged in as admin)**: the two menu entries under Settings, the form of a profile (values, saving of a comma-less decimal, a select and a check box), the form of a project (the sections and the profiles as selects, the author and the folios), the values read again after saving; no error in the page. A subscriber cannot edit a profile or add a project (`user_can`).
- **Never tried**: the real Gotenberg with `preferCssPageSize` (the field name is from its documentation; the PDF made by it was not looked at), the blog's sites, a PDF made for the second of two projects with a real Gotenberg, a book of hundreds of units, the uninstall on a real site, the `Settings` menu entries with several users, the merged HTML branch with a project.
