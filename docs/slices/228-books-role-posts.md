<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 228 — Books: posts with a role (table of contents, index, blank page)

Status: **proposed (2026-10-26), not coded**. Dependencies: slices 223 (sections), 227 (the pagination map). Context: [`../design/books.md`](../design/books.md).

## Goal

Let the front matter, the index and the pages that the printer requires be **units of a section like any other**: a post that has a **role**, placed where the author wants, generated from the content and styled by templates of the theme (as the category pages are), instead of a spreadsheet and manual assembly.

## Proposed

A role is a value of a protected post meta (`_books_role`) on a post that is a unit of a section; the editor panel of slice 224 offers it. Roles:

- **`toc`**: the content is generated from the section (order, titles, depth, from the manifest of the section), each line a link. In the print mode each line also shows the page given by the pagination map (slice 227); in the web mode there is no page number.
- **`index`**: the content is generated from the passages marked in the units (names of places, people, themes), sorted, each with its pages; a section may have several indexes (by theme).
- **`blank`**: an empty page, **with no header, footer or folio**, and optionally a text such as "achevé d'imprimer" (see below).

Their look is the one of a template of the theme (`wp-twenty-twenty-five-printable`) chosen by the role, like the category templates; Books provides the data (a block or a function, not the markup).

**Two renders, no more**: the map comes from the body, so the toc and the index are rendered after it. The length of a toc does not depend on its digits, but the page offset depends on the number of pages of the front matter: render it, count its pages, render it again with the offset, and check that the count has not changed. They are small PDFs placed in front of and after the body (a merged body is **not** rendered again); the folios are stamped on the final file.

## What a printer asks (CoolLibri FAQ, https://www.coollibri.com/faq, read 2026-10-26)

- one **single PDF** for the inside, pages in print order; **one sheet has two pages**, so the number of pages should be even (the FAQ gives no other multiple);
- **minimum and maximum number of pages depend on the binding** (perfect-bound: 60 or 80 minimum, 500 to 700 maximum by paper; hardcover: 24 to 100 or 150; stapled: 8 to 60; spiral: 1 to 290 or 500); the spine thickness depends on the number of pages;
- **if the last page is blank, the printer prints its "achevé d'imprimer" notice there, otherwise it adds pages**: a final blank page is therefore wanted, and its text is the author's choice;
- a **combined cover file** needs a blank page 2 and a blank second-to-last page (a separate cover file does not);
- margins of 2 cm, bleed of 3 mm when the pages carry images to the edge; images at 300 ppi at least; CMYK recommended, with an ICC profile by paper; the file at most **700 MB** (a 184-unit book was 292 MB).

## The role `blank`, as the printer's constraints

A **layout rule** of the book, not just a role to place by hand: the section may declare `printer` constraints (even page count, minimum and maximum, a final blank page, blank pages before a chapter that must start on the right-hand page) and Books **adds the `blank` pages that are missing** when it assembles, and says so in the log. Chromium already adds the blank pages needed by `break-before: right`; the rule concerns the ones it cannot know (the total, the end).

## Not decided (open)

- Where the printer constraints are declared (a post meta of the section, a settings page, a preset by printer such as "coollibri-perfect-bound").
- Whether a role post is a real WordPress post (visible on the site, to hide with slice 402) or a virtual unit produced by Books from a template without a post.
- How a passage is marked for the index (a block, a Triples statement, a CSS class).
- Whether a role changes the manifest of the section and the web mode (a toc in the web mode is a list of links).

## Tests (planned)

The generation of the toc and of the index from a fixture section, the offset and the check of the page count, the blank pages added by the constraints (even total, final page), a book over the maximum of a binding reported, the editor control of the role.

## Verification (planned)

On a scratch WordPress with the stand-in of Gotenberg and Chromium; **never tried** on a real printer's proofing, nor on the real blog.
