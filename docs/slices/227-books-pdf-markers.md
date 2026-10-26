<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 227 — Books: markers in the PDF and the pagination map

Status: **proposed (2026-10-26), not coded**. Dependencies: slices 220 (the PDF job), 223 (sections), 226 (large files, `PdfPages`). Needed by slice 228. Context: [`../design/books.md`](../design/books.md), "Page numbers, table of contents and index with a merged HTML".

## Goal

Know **on which page of a PDF each unit and each passage to index starts**, without extracting text, so that a second pass can print the table of contents and the index with true page numbers, and the folios can be stamped.

## What is known (Chromium only, 2026-10-26)

A link in the HTML (`<a href="#u-3">`, or an external marker `https://marker.invalid/unit/3`, even of one transparent pixel) becomes a `/Link` annotation carried by the page where it sits; every `id` targeted by an internal link becomes a named destination; `outline: true` gives bookmarks. The PDFs of Chromium have no object streams, which `PdfPages` already relies on.

## Proposed

- **Markers in the print mode.** In the print mode only, each unit of a section starts with a marker link (`#u-<post id>`) and the passages to index (the elements the Triples module or a block mark) get an `id` and a marker (`#p-<post id>-<n>`). In a merged HTML they are emitted by the merge; in the per-unit way, by the print mode of each post.
- **A reader** `Otherguise\Books\Pdf\PdfMarkers`: reads the page tree and the `/Link` annotations of a PDF file (in pieces, like `PdfPages`, no `shell_exec`, no library) and returns `marker => page`. Named destinations are read as a second source, when the annotations are not enough.
- **The pagination map**: for a book project (slice 230: the format, hence the pagination, depends on the project), `unit or passage => page in the body`, stored with the PDF job (not in the post, which stays stable); a page offset is added when the front matter is known (slice 228). In the per-unit way the starts are the cumulative sum of `PdfPages::count()`; only the passages need the reader.
- **WP-CLI** `wp otherguise books pagination <section-id>` prints the map (JSON or table) and `--check` reports a marker found twice or missing.

## Not decided (open)

- Whether the markers are internal links, external URI links, or both (internal links can be rewritten by a PDF merge; URI markers survive better but add an external-looking link to the printed PDF, to be removed by the final pass).
- Whether Gotenberg passes the annotations and the destinations through its options and through `gs` (to try).
- Whether the markers are removed from the final PDF (they are links of one pixel: harmless on paper, visible in a PDF viewer).

## Tests (planned)

PHPUnit on small fixture PDFs made by Chromium (a unit over 3 pages, a unit with a blank page after it, a marker found twice, a PDF without annotations), a chunk boundary inside an annotation, the WP-CLI command.

## Verification (planned)

Chromium: done on a document of 4 units. **To do**: through the real Gotenberg, on the 184 units of the first book, after `gs`.
