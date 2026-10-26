<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 229 — Books: checks before printing (margins, weight, resolution)

Status: **proposed (2026-10-26), not coded**. Dependencies: slices 226 (large PDFs, `PdfPages`), 227 (the reader of the PDF), 230 (the printer profile and the book project: the thresholds). Context: [`../design/books.md`](../design/books.md).

## Goal

The printer checks nothing of the layout (its FAQ: no check of centring, font sizes or margins), so **Books gives the author the means to check** a page, a unit, a section or the whole PDF before it is sent: where the content is, how heavy the pages and the images are, at what resolution they will be printed. Results are **indications**, never blocks.

## Proposed checks

- **Margins**: content (text, images) inside the safety zone, the gutter or the bleed of the printer profile (slice 230), per page. Two ways to measure, to compare: from the HTML in Chromium (the boxes of the elements in the print layout, which tells which element is concerned) or from the PDF itself (the drawn area of each page). The first is more useful to fix a page, the second checks the real file.
- **Weight**: size of the whole file against the limit of the printer (700 MB at CoolLibri), size by unit and, if it can be read, **by page** (the bytes of the streams a page uses), to find the heavy pages and optimise them first (the condition of the merged HTML).
- **Images**: for each image of a page, its pixels and the size at which it is printed, so its **effective resolution** in ppi against the minimum of the printer (300 ppi). In the HTML: `naturalWidth` against the displayed width in the print layout; in the PDF: the image objects and the transformation that places them (readable with the reader of slice 227, no `shell_exec`). Also reported: images much larger than needed (weight), and the colour space (RGB, CMYK: the PDFs of Chromium are RGB, so a conversion to CMYK is done after, to be decided).
- **Fonts**: whether they are embedded (not mentioned by the FAQ, to check on a proof).
- **Pages**: the number of pages against the minimum, the maximum and the multiple of the binding, blank pages added by slice 228, the position of the folios against the safety zone.

## Output

- **WP-CLI** `wp otherguise books check <section-id> [--pdf=<id>] [--format=table|json]`, a report by unit and page, and a total; the same data through a REST route for the panel of the editor (a "checks" tab next to the preview, slice 219/220), where a page of the preview can show its zones.
- A threshold is a field of the printer profile (slice 230); nothing is ever refused.

## Not decided (open)

- HTML measure, PDF measure, or both (the second is the only one that checks the file actually sent).
- How far the reading of the PDF goes without a library (the bytes by page are the hardest: streams shared between pages).
- Whether the conversion to CMYK and the ICC profile are a step of the chain of Books (Ghostscript is already part of the first book's chain) or stay outside.

## Tests (planned)

Fixture PDFs made by Chromium: an image placed at a known size (effective ppi), a text box in the margin, a heavy page; the report in table and JSON.

## Verification (planned)

On a scratch WordPress with Chromium; **never tried** on a printer's proof, nor on the real blog.
