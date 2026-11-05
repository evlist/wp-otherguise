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

- **The marker of a unit** (`MergedPrint::marker()`): the first child of each `div.books-merge-unit` of the merged HTML (slice 231, 235) is an invisible link of one pixel to `https://marker.invalid/unit/<post id>` (`.invalid` is reserved by RFC 2606: it leads nowhere), out of the tab order and hidden from screen readers. External URI links were chosen over internal ones: nothing has to be targeted, the annotation sits on the page where the link is, and it survives a rewrite of the named destinations. The link is a **block** with `break-after: avoid`, which keeps it with the first block of the unit: as an inline box it formed a line of its own that could be the last line of a page while the title went to the next (found by comparing with the text of the pages: unit 3 was reported on page 3 and printed on page 4).
- **`Pdf\PdfMarkers::read( $path )`**: reads the file in pieces (like `PdfPages`), keeps only the page objects, the nodes of the page tree and the link annotations whose address is a marker, never the streams; orders the pages by the page tree (the order of the file when the tree is not whole); returns `pages` and, for each marker, the pages where it was found. `null` for a file without a page; an empty list for a file whose objects were rewritten (an object stream: not read).
- **`wp otherguise books pagination <section> --file=<pdf> [--check] [--format=table|json]`**: prints the page of each unit (the id, the page, the title; the page of its first heading, else of its marker); `--headings` also prints the page of every heading (`<unit>#<n>`). `--check` reports a unit of the section without a marker (a draft, a post with a password, a limit), a marker found on several pages, a marker of a post that is not in the section. It reads and writes nothing else: it can be run twice.

## Not decided (open)

- Whether the markers are internal links, external URI links, or both (internal links can be rewritten by a PDF merge; URI markers survive better but add an external-looking link to the printed PDF, to be removed by the final pass).
- Whether Gotenberg passes the annotations and the destinations through its options and through `gs` (to try).
- Whether the markers are removed from the final PDF (they are links of one pixel: harmless on paper, visible in a PDF viewer).

## Tests (planned)

PHPUnit on small fixture PDFs made by Chromium (a unit over 3 pages, a unit with a blank page after it, a marker found twice, a PDF without annotations), a chunk boundary inside an annotation, the WP-CLI command.

## Verification

Chromium 141 (Playwright, `page.pdf`), a scratch WordPress 7.1.3: the merged page of a section of 8 posts of 2 to 45 paragraphs (A5), 10 pages; the page given for each unit is the page where its title is printed (checked with `pdftotext`, page by page), and the command with `--check` finds the 8 markers, each once. A small Chromium document of 4 units, and a read in pieces of 700 bytes, give the same.

**Through the real Gotenberg (2026-11-05, Eric, testing)**: on a PDF made by the job of the section 58840 (the Pyrenees, 74 MB, the files `books-pdf/<key>.pdf` of the uploads), the command found the **26 markers, each once**, with the page of each unit (page 2 to the end): Gotenberg, with the options of the job, **keeps the link annotations**. Eric ran it; the pages were not compared with the text of the PDF, and the titles came out with HTML entities (`&#8211;`), fixed the same day (`PaginationCommand::plain()`).

**Headings, on the scratch (2026-11-05)**: the 8 posts again, with the template of the units forcing a page break before its main group (as `journee-print` does), 15 pages: the unit markers were one page too early for the units that follow a long one (unit 48 on page 5, printed on page 6), the **heading markers gave the page of the title for the 8 units** (compared with `pdftotext`, page by page); `--headings` lists them. Not tried through Gotenberg since this change.

**Never tried**: the options of Gotenberg that rewrite the objects (`pdfa`, `metadata`, `flatten`, the merge, the post-processing of its PDF engine: a PDF that has lost its link annotations gives no marker, and the command says so), the 184 units of the first book, `gs`.
