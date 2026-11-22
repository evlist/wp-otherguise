<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 612 — Books: PDF markers for the mentions that are visible in the text

Status: **proposed (2026-11-22), not coded.** Dependencies: 611 (the block), 605 (`index/via`), slice 227 of Books (the markers of the headings, the manifest, `PdfMarkers`, the pagination map). Context: [`../design/index.md`](../design/index.md), "Vocabulary and the dimensions of an index".

## Decided (Eric, 2026-11-22)

- **The markers of the PDF are generalised from the headings to every topic that is visible in the text**, so that **the index of the photographs gives the page of the photograph**, and every index gives **the page of the element that shows the topic**.
- **No new QR code**: the reader who wants to read more **goes to the article on the web with the QR code that the book already has**.

## Proposed

### What is marked

In the **print mode only** (like the markers of the headings), the unit's HTML is read by **`PageReader`** (slice 601) and an **invisible marker of one pixel**, `https://marker.invalid/mention/<post>/<n>`, is put **in front of every element that stands for a mention**: a **photograph** (`<img>`, or the link to its original), a **link to Wikipedia or Wikidata**, an element with a **`data-index` mark**, and later a **GPX file named in the text**. **Only the elements that have a stored mention with a matching `index/via`** are marked (a link that made no mention gets none), which keeps the number of markers to what an index can use. `n` numbers the marks of the post in the order of the page.

### What the manifest and the map hold

- The **manifest** (slice 227) gets the list of the marks: `post`, `n`, `type`, `value` (the `via` of the mention, as the page shows it, **resolved to the address of the original for an image**), and the **mode** (`print`).
- The **pagination map** (the one of `books_pages`) gets the keys `m:<post>#<n>` next to `<post>#<n>` (the headings); `PdfMarkers` reads the pattern `mention/<post>/<n>`; **the passes are the same** (the map of a pass is the one the next pass prints).
- **A mention is visible in print iff its marker is in the PDF**: **the print pass measures the visibility** that slice 605 left declared.

### What the block does with it

- **The locator of a mention is the page of its marker** (`index-locator-element`); **when it has no marker** (a tag, a category, a mark that the print template hides, a mention whose `via` is not in the page) **it is the page of the post** (`index-locator-post`, **the page that carries the QR code of the article**): a book can set it in italics or explain it in its colophon, **without any QR code made for the index**.
- **An index of the photographs** is `include="source:image"` (slice 605): every line is a topic (a place, a species) and **its locators are the pages of the photographs**; ranges are collapsed; a **photograph with a place but no printed marker** is not a locator (the index is of what is printed).
- **The report** (`wp otherguise books pagination --check`, slice 227) lists the **mentions that fell back to the post** and the **marks found twice or missing**.

## What to measure before it is trusted

- **The number of markers**: a day has about 66 photographs and a book about 184 units, so **some 12,000 marks**; the **size of the manifest** (about 1 MB) and **the time of `PdfMarkers`** to read as many annotations; **whether Chromium and Gotenberg keep them all** (slice 227 only proved 26 markers through Gotenberg).
- **A marker in front of an `<img>` inside a figure** must land on the page of the image (the lesson of the markers at the start of a unit: **the marker of an element is the element, not the start of its block**).

## Tests (planned)

The marks (an image, a link, a `data-index` mark; an element with no mention gets none; the numbering in the order of the page; print only); the manifest and the map keys; `PdfMarkers` and the pattern; the locators of the block (element, fallback post, ranges); the passes. **On a scratch WordPress with a real PDF** (Chromium), then **Eric's testing blog through the real Gotenberg**.

## Not done

**Photographs as a source** (the photographs slice must exist: until then this slice marks links and marks); a marker for a **region** of the page (a map); the **web** index with anchors; the renaming of the code.
