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

## Proposed on 2026-11-05: the table of contents from the headings (Eric, after the first test of slice 227)

Coded in part on 2026-11-05 (slice 227): the manifest (level, text, `toc`, depth, written by the merge, read by the command) and the checks of the levels, read only. **Not coded**: the exclusion of the headings of `header`, `footer` and `nav`, the table of contents itself, the second render. Eric, the same day: **the block that lists the units cannot make a table of contents** (its entries are units, with the title and the excerpt of each, and its titles are headings that a table of contents would take for its own): the table of contents will be **a block of its own**, made from the manifest and the pages of the PDF. What slice 227 gave when this was written: a marker in every heading `h1` to `h6` of every merged unit (`heading/<unit>/<n>`) and the page of each, read in the PDF (`wp otherguise books pagination --headings`); several headings can be on one page. What it does **not** give: the **level** and the **text** of a heading, a way to leave out the headings that are not part of the structure (the headings of the template of a unit, such as "Plus de publications"), and a check of the **discipline of the levels** that a hierarchical table of contents needs.

- **A manifest written by the merge.** The merge has the HTML of each unit when it puts the markers. For each marker it also notes the level, the text (plain text: the tags, a link or an emphasis, are dropped), the unit, the number of the heading and the **depth of the unit** in the section; the list is kept with the PDF job (not in the post, which stays stable). The table of contents is then made by WordPress from the manifest and from the pages read in the PDF (`PdfMarkers`), without reading the text of the PDF.
- **Which headings count.** No marker for a heading inside `header`, `footer` or `nav`, nor for a heading with the class `no-toc` (as `no-print` hides from the print: a heading to leave out of every table of contents, for instance "Plus de publications" in a template; to add to the templates of the theme). The table of contents chooses the levels it lists (1 to 3, say).
- **The level of an entry** = the depth of the unit in the section + the difference between the level of the heading and the level of the title of the unit (its first heading). An article whose title is an `h1` and whose subtitles are `h2` gives entries of level 1 and 2 under a section of depth 1; a unit that is a section is one level up.
- **A check of the discipline**, in `wp otherguise books pagination --check`: a level that is skipped (an `h4` right after an `h2`), a unit that starts lower than its title, a heading with no text. It reports; it does not repair: the levels are the author's.
- **Several headings per page** need nothing more: each entry has its own page.

Open: whether the text of a heading with markup (emphasis, link) should keep it in the table of contents (plain text proposed); where the manifest is kept (with the job state, like the pagination map); how the table of contents is shown in the web mode (a list of links with no page: the block `books/section-list` already lists the units, the headings of a unit would be a second level of the same list).

## Decided on 2026-11-05: the table of contents is a block, made in two passes (Eric)

**The block is coded (2026-11-05)**, with numbers that are placeholders: the second pass of the job, the check box of the preview and the map of the pages are **not coded**. Decisions of Eric, on the proposal above and on the questions I put to him:

- **A block of its own**, `books/section-toc` (name to confirm), in the template or the content of the section; not the list of the units (`books/section-list`), whose entries are units with a title and an excerpt and whose titles are headings that a table of contents would take for its own. Like the two other blocks it has the attribute `modes` (every mode by default).
- **A parameter that limits the number of levels** (1 = the units only). The **level of an entry** is the depth of its unit plus the difference between the level of the heading and that of the title of the unit (see above); the block lists the entries up to that level, and leaves out the headings with `no-toc`.
- **The section itself**: the headings of its page are entries like the others (a preface or an afterword is a chapter of the book), so the author puts `no-toc` on "Table des matières" and on the title of the book. (The first wording of this slice said "none by default", which cannot be: a heading is left out by a class, and the afterword must stay.)
- **The look**: an entry per line, indented by level, the number of the page on the right with a dotted leader. Chromium has no `target-counter` and no `leader()` (tried on 2026-11-05: it accepts the syntax and prints nothing): the leader is made with CSS (a dotted line that fills the space between the text and the number), to try on a real PDF. On the web the same entries are links with no number.
- **Two passes, since the page of an entry is only known once the PDF is made**: a first pass makes the PDF with numbers of a fixed width in their place (so that the table of contents takes the room it will have), the job reads the pages with `PdfMarkers` and the manifest, then makes the PDF again with the numbers, and **checks that the pagination did not move** (the page of every heading is the same): if it did, it goes again, three passes at most, then it says so. Eric: "we have no choice". **In the preview window a check box** says whether the preview is **one pass** (a real preview, the numbers are placeholders) or goes on with the **numbering of the tables of contents** (and, later, of the indexes, which work the same way); one pass by default.

How the block can be made at all: the template is rendered before the units, so the table of contents cannot read the headings of the units when the block is rendered. Like the merge block it **leaves a marker**, and the output buffer that places the units (`MergedPrint::place()`) replaces it by the table of contents once it has the manifest rows of the merge; in the second pass the page of each heading is given by the map that the job saved after the first (an argument of the address, signed like the token of the merge: a visitor cannot ask for numbers that were not computed).

**Coded**: `books/section-toc` (attributes `levels`, default 2, 0 for all, and `modes`), `Merge\\TableOfContents` (the entries and the HTML: an entry is a `li` with the class `books-toc__level-<n>`, in print a text, a leader and a page, on the web a link), `MergedPrint::toc_slot()` (the marker `<!-- books-section-toc:<n> -->`, replaced in `place()` by the table made from the headings of the page of the section and of the units, so a table before the units knows the headings after them), `Merge\\Headings` (the markers of the headings and the manifest, moved out of `MergedPrint`), the style `blocks/section-toc/style.css` (flex line, dotted leader, padding by level, `tabular-nums`). A print mode is one of `books_preview_frame_modes`; the entries of the other modes are links to the post of the heading (no anchor in the post yet). The headings of the template of a unit ("Commentaires", "Plus de publications" on the scratch) are entries until they are left out: **the exclusion of `header`, `footer`, `nav` is not coded**, `no-toc` in the templates is the way for now. Tried on a scratch WordPress with Chromium: the table of an 8 article book in print (levels 1 and 2, leaders, `000` at the right, the title of the book and "Table des matieres" left out with `no-toc`, "Postface" listed), on the web (links); a PDF made from the page shows it.

Still to decide: the name of the block (it is `books/section-toc` for now); how the entries of a unit whose first heading is not printed are shown (no number, a dash); whether the first pass is kept as the PDF of a section that has no table of contents (no second pass at all); the width of the placeholder; where the map of the pages is kept (with the job state, like the manifest).

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
