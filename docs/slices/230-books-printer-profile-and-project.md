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

## Not decided (open)

- The names of the post types and of the fields; a table of settings or post meta (meta, to be exported with the posts).
- The presets: shipped as JSON files read to create the first posts, or only documentation.
- How a margin reaches the templates (a CSS variable set on the page by Books, to be shared with the child theme `wp-twenty-twenty-five-printable`, which today fixes its own margins).
- What a project of a section **without** a top-level section means (a book that is a single section).
- Whether a project can override a field of its profile.

## Tests (planned)

Registration of the two types (not public, capabilities), the fields and their sanitising, the profile of a project, the format given to the renderer, a section without a project unchanged, uninstall, WP-CLI `books project list|show|set-profile`.

## Verification (planned)

On a scratch WordPress; **never tried** with a printer's proof, nor on the real blog.
