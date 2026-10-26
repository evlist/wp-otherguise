<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 228 — Books: posts with a role (table of contents, index, blank page)

Status: **proposed (2026-10-26, revised the same day), not coded**. Dependencies: slices 223 (sections), 227 (the pagination map), 230 (printer profiles and book projects: the rules). Context: [`../design/books.md`](../design/books.md).

## Goal

Let the front matter, the index and the pages that a printer requires be **units of a section like any other**: a post that has a **role**, placed where the author wants, generated from the content and styled by templates of the theme (as the category pages are), instead of a spreadsheet and manual assembly.

## Proposed

A role is a value of a protected post meta (`_books_role`) on a post that is a unit of a section; the editor panel of slice 224 offers it. Roles:

- **`toc`**: the content is generated from the section (order, titles, depth, from the manifest), each line a link. In the print mode each line also shows the page given by the pagination map (slice 227); in the web mode there is no page number.
- **`index`**: the content is generated from the passages marked in the units (names of places, people, themes), sorted, each with its pages; a section may have several indexes (by theme).
- **`blank`** (generic, Eric's definition): a post with this role, added to a section, **produces as many page breaks as needed to satisfy the rules of the printer profile of the project (slice 230) at that place**, and nothing if none is needed. It prints no header, footer or folio. Its rules, from the profile: the next unit starts on a right-hand page; at the end, the total is a multiple of the profile's multiple (an even number at least), is not under its minimum, and the last page is blank when the printer wants it (CoolLibri prints its "achevé d'imprimer" there, otherwise it adds pages); a combined cover file wants a blank second page and second-to-last page. The post may carry a text (the notice of the end) that is printed on the last blank page.

Their look is the one of a template of the theme (`wp-twenty-twenty-five-printable`) chosen by the role, like the category templates; Books provides the data (a block or a function, not the markup).

**Order of the computation** (proposed): the pagination map comes from the body (slice 227), so the toc and the index are rendered after it; the **blank** posts are resolved **from the start to the end** (a blank page before changes the parity of what follows), each one from the pages already counted; the front matter (the toc) is rendered, its pages counted and rendered again with the offset, then the check that the count did not change. They are small PDFs placed in front of and after the body (a merged body is **not** rendered again); the folios are stamped on the final file. **Chromium does not add them** (checked 2026-10-26, version 141: `break-before: right`, `left`, `recto` and `page-break-before: right` all leave the units on consecutive pages, no blank page), so **every** blank page, including the one before a chapter that must start on a right-hand page, is the work of the `blank` role. See slice 230, "How Books gives the rules to the page", for the order that keeps the margins right.

## What the printers ask

Kept in the profiles of slice 230. For the record, read on 2026-10-26:

- **CoolLibri** (https://www.coollibri.com/faq): one single PDF for the inside, pages in print order; a sheet has two pages; minimum and maximum of pages by binding (perfect-bound 60 or 80 to 500 or 700, hardcover 24 to 100 or 150, stapled 8 to 60, spiral 1 to 290 or 500), the spine thickness depends on the number of pages; a final blank page receives the "achevé d'imprimer", otherwise pages are added; a combined cover file needs a blank page 2 and a blank second-to-last page; **safety zone** of 2 cm "all around" (a recommendation for every format, no figure by format, the printer does not check the layout; Eric's book of last year had its folios 1 cm from the bottom in A5 and was printed), bleed of 3 mm; images 300 ppi at least; CMYK recommended; file at most 700 MB (184 units: 292 MB).
- **Flexilivre** (https://www.flexilivre.com/fichier/): six fixed formats (small and large landscape, portrait and square), important content at least 5 mm from the edges, no cut marks, no spine, a cover as first and last page, converted to RGB (CMYK as an option), 3 GB at most; no number of pages, resolution or blank-page rule on that page.

## Not decided (open)

- Whether a role post is a real WordPress post (visible on the site, to hide with slice 402) or a virtual unit produced by Books from a template without a post.
- How a passage is marked for the index (a block, a Triples statement, a CSS class).
- Whether a role changes the manifest of the section and the web mode (a toc in the web mode is a list of links).
- Whether a `blank` post chooses its target (the next unit on a right-hand page, or the end of the book) or finds it from its place (the last unit of the book means the end).

## Tests (planned)

The generation of the toc and of the index from a fixture section, the offset and the check of the page count, the blank pages for each rule (right-hand page, multiple, minimum, final page, combined cover) with a fixture profile, several blank posts resolved in order, the editor control of the role.

## Verification (planned)

On a scratch WordPress with the stand-in of Gotenberg and Chromium; **never tried** on a printer's proof, nor on the real blog.
