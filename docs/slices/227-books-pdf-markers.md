<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 227 — Books: markers in the PDF and the pagination map

Status: **coded in part (2026-11-05)**: the markers in the merged HTML, the reader `PdfMarkers` and the command `wp otherguise books pagination`, tried on a scratch WordPress with the PDF of Chromium. **Not coded**: the markers of the passages to index, the pagination map stored with the PDF job, the use of the map to print page numbers in the table of contents (second render of the job, slice 228). See "Coded on 2026-11-05" below. Dependencies: slices 220 (the PDF job), 223 (sections), 226 (large files, `PdfPages`). Needed by slice 228. Context: [`../design/books.md`](../design/books.md), "Page numbers, table of contents and index with a merged HTML".

## Goal

Know **on which page of a PDF each unit and each passage to index starts**, without extracting text, so that a second pass can print the table of contents and the index with true page numbers, and the folios can be stamped.

## What is known (Chromium only, 2026-10-26)

A link in the HTML (`<a href="#u-3">`, or an external marker `https://marker.invalid/unit/3`, even of one transparent pixel) becomes a `/Link` annotation carried by the page where it sits; every `id` targeted by an internal link becomes a named destination; `outline: true` gives bookmarks. The PDFs of Chromium have no object streams, which `PdfPages` already relies on.

## Proposed

- **Markers in the print mode.** In the print mode only, each unit of a section starts with a marker link (`#u-<post id>`) and the passages to index (the elements the Triples module or a block mark) get an `id` and a marker (`#p-<post id>-<n>`). In a merged HTML they are emitted by the merge; in the per-unit way, by the print mode of each post.
- **A reader** `Otherguise\Books\Pdf\PdfMarkers`: reads the page tree and the `/Link` annotations of a PDF file (in pieces, like `PdfPages`, no `shell_exec`, no library) and returns `marker => page`. Named destinations are read as a second source, when the annotations are not enough.
- **The pagination map**: for a book project (slice 230: the format, hence the pagination, depends on the project), `unit or passage => page in the body`, stored with the PDF job (not in the post, which stays stable); a page offset is added when the front matter is known (slice 228). In the per-unit way the starts are the cumulative sum of `PdfPages::count()`; only the passages need the reader.
- **WP-CLI** `wp otherguise books pagination <section-id>` prints the map (JSON or table) and `--check` reports a marker found twice or missing.

## Coded on 2026-11-05

- **A marker in every heading** (`MergedPrint::mark_headings()`): in the merged HTML (slices 231, 235) the opening tag of each heading `h1` to `h6` of a unit, **and of the page of the section itself**, is followed by an invisible link of one pixel to `https://marker.invalid/heading/<post id>/<n>` (`.invalid` is reserved by RFC 2606: it leads nowhere; out of the tab order and hidden from screen readers), numbered in the post. Chromium writes it as a link annotation on the page where the heading is printed. External URI links were chosen over internal ones: nothing has to be targeted, and the annotation survives a rewrite of the named destinations. Scripts, styles, comments and text areas are left alone. The headings of the page of the section are marked by the output buffer that places the units (slice 235), so only on a page that is merged, never on an ordinary page of the site.
- **No marker at the start of a unit** (decided with Eric on 2026-11-05, after a first version had one): that marker, an invisible link as the first child of each `div.books-merge-unit`, was left on the page before when the template of the unit forces a page break before its first block (`starts-page`, slice 235): the first unit of the first book of testing was reported on page 2 and printed on page 3 (reproduced on a small Chromium document). **The page of a unit is the page of its first heading.**
- **The manifest** (read only): the merge writes, in the directory of the PDF files, a file `<key>.json` (the key is the hash of its contents; `PdfStore::write_manifest()`, cleaned like the PDF files, removed with the directory) with the **units that were merged** (id and depth) and the **headings** of the page of the section and of the units, in the order of the document: the post, the number, the **level**, the **plain text**, whether a table of contents lists it (`toc`, false for a heading with the class `no-toc`) and the depth. A marker `manifest/<key>` in front of the units names it in the PDF, so that the manifest of a PDF is found whatever was merged since (a preview, say).
- **`Pdf\PdfMarkers::read( $path )`**: reads the file in pieces (like `PdfPages`), keeps only the page objects, the nodes of the page tree and the link annotations whose address is a marker, never the streams; orders the pages by the page tree (the order of the file when the tree is not whole); returns the number of pages and, for each marker, the pages where it was found. `null` for a file without a page; an empty list for a file whose objects were rewritten (an object stream is not read).
- **`wp otherguise books pagination <section> --file=<pdf> [--check] [--headings] [--format=table|json]`**: prints the page of each unit (the id, the page of its first heading, the title; the section itself first); `--headings` also prints every heading (`<post>#<n>`, the page, `h<level>`, the text; `(no-toc)` when a table of contents leaves it out); a PDF made before the manifests has none: the command says so and shows the pages only. `--check` reports **a unit with no page**: *merged but none of its headings was found* (no heading, or the headings are not printed) or *not in the PDF* (a draft, a post with a password, a limit); **a first heading that is not in the PDF** (hidden by the print stylesheet, say: the page given is then that of a later heading); a heading marker on several pages; the headings of a post that is not in the section; and the levels: **a level that is skipped** (an `h4` right after an `h2`), **a heading above the first heading of its post**, **a heading with no text** (a heading with `no-toc` is not looked at). Reads the file and writes nothing: it can be run twice. No PDF made before this change is to be read (Eric: there were none to keep).

## Not decided (open)

- Whether the markers are internal links, external URI links, or both (internal links can be rewritten by a PDF merge; URI markers survive better but add an external-looking link to the printed PDF, to be removed by the final pass).
- Whether Gotenberg passes the annotations and the destinations through its options and through `gs` (to try).
- Whether the markers are removed from the final PDF (they are links of one pixel: harmless on paper, visible in a PDF viewer).

## Tests (planned)

PHPUnit on small fixture PDFs made by Chromium (a unit over 3 pages, a unit with a blank page after it, a marker found twice, a PDF without annotations), a chunk boundary inside an annotation, the WP-CLI command.

## Verification

Chromium 141 (Playwright, `page.pdf`), a scratch WordPress 7.1.3: the merged page of a section of 8 posts of 2 to 45 paragraphs (A5), 10 pages; the page given for each unit is the page where its title is printed (checked with `pdftotext`, page by page). A small Chromium document, and a read in pieces of 700 bytes, give the same.

**Through the real Gotenberg (2026-11-05, Eric, testing)**: on a PDF made by the job of the section 58840 (the Pyrenees, 74 MB, the files `books-pdf/<key>.pdf` of the uploads), the command found the **26 markers, each once**, with the page of each unit (page 2 to the end): Gotenberg, with the options of the job, **keeps the link annotations**. Eric ran it; the pages were not compared with the text of the PDF, and the titles came out with HTML entities (`&#8211;`), fixed the same day (`PaginationCommand::plain()`).

**Headings, on the scratch (2026-11-05)**: the 8 posts again, with the template of the units forcing a page break before its main group (as `journee-print` does), 15 pages: the markers at the start of the units were one page too early for the units that follow a long one (unit 48 on page 5, printed on page 6), the **heading markers gave the page of the title for the 8 units** (compared with `pdftotext`, page by page). **Through the real Gotenberg (Eric, testing, 2026-11-05)**, with the headings: the first unit on page 3, as printed; the 26 units each with one `h1` on its page; the section's own `h2` of the table of contents on page 2.

**The page of the section (scratch, 2026-11-05)**: a section whose content is a cover paragraph, a heading "Table des matieres", the list of its units, the merge block and a heading "Postface": the markers of the 10 headings of the page of the section give page 1 for the cover and the table of contents, page 12 (the last) for "Postface" (checked with `pdftotext`). The 8 titles of the list of the units are headings too (`h3`): they are numbered like the others; leaving them out of a table of contents is the `no-toc` of slice 228.

**The manifest (scratch, 2026-11-05)**: the 8 posts, one of them with a heading `h2`, an `h4` and an `h2` with `no-toc`: the command shows the level and the text of the 10 headings of the section and of those of the units, flags the `h4` after the `h2` and does not look at the heading with `no-toc`. The headings of the template of every unit ("Plus de publications", an `h2`) are in it too: leaving the headings of `header`, `footer` and `nav` out of the markers is **not done** (only `no-toc` is), see slice 228.

**Never tried**: the options of Gotenberg that rewrite the objects (`pdfa`, `metadata`, `flatten`, the merge, the post-processing of its PDF engine: a PDF that has lost its link annotations gives no marker, and the command says so), the 184 units of the first book, `gs`.
