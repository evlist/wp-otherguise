<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

Moved here from `evlist/shared-room` on 2026-10-09. Points are marked **Decided** (Eric accepted it), **Proposed** (suggested by Claude, not confirmed) or **Open**. The delivered state is in [`../IA.md`](../IA.md) and [`../slices/`](../slices/README.md); this document keeps the reasoning.

# 3. Books

Only sketched so far; nothing decided.

- **Proposed**: a book is a selection of posts (by date, category, tag or trip) with an order and sections (month, stage), stored as an ordered `contains` relation.
- **Proposed**: `book` and `cover` are modes of domain 2, each with its own target templates. Each post is rendered in the `print`/`book` mode. A photo can then be attached to a post for the web only, for print only, or for both, through statements about the `mode`.
- **PDF production, proposed order**:
  1. A single "book" page: all posts concatenated with a table of contents, CSS `@page` rules and page breaks, turned into a PDF by the browser or a paged-media tool. This needs no external binary and keeps the "no `shell_exec`" rule used in `wp-i18nly`.
  2. Later, optionally, server-side rendering (headless Chrome or a service) to produce PDFs automatically.
- Final assembly (cover, continuous pagination, binding margins) could be done in PHP with a library or stay in an external tool.

Format today: A5, printed at coollibri.com (a detail for now; page size must stay configurable per book).

## The manual finishing chain (from Eric's how-to notes)

Eric shared a short LibreOffice document ("howto", November 2025) describing how the first book, six months on the Camino de Santiago, was finished. It supersedes the earlier mention of PDF Arranger for the final assembly. The steps, all command-line except the table of contents and index:

1. **Merge.** The monthly PDFs are merged with `pdfcpu merge`. Each monthly file is named `<start-date>_<end-date>_<title>.pdf` (for example `20250404_20250503_compostelle_1er_mois.pdf`). A book is therefore a sequence of **parts** defined by a date range and a title, each part printed to one PDF from the browser.
2. **Resize to A5.** `pdfcpu resize "formsize:A5"`. The pages are therefore *not* printed at A5 from the browser: the layout is designed at another size and scaled down afterwards (A4 to A5 is a factor of about 0.71), so the fonts and the layout shrink with it.
3. **Reduce the photos.** Ghostscript (`gs -sDEVICE=pdfwrite -dPDFSETTINGS=/printer ...`) downsamples the images to shrink the file, presumably for the printer's upload limit.
4. **Table of contents and index.** Built in a spreadsheet, exported as tab-separated CSV, inserted into Writer ("Insert / Text from File") and formatted with styles; the result becomes part of the PDF (the intermediate file is called `compostelle_wip.pdf`).
5. **Page numbers.** Stamped on the final PDF with `pdfcpu stamp add -mode text -- "%p" ...` (bottom centre, small grey label with a rounded border). The numbers are the **physical page numbers of the PDF**, front matter included, not CSS counters. This is why the table of contents and index could be computed from the position of each post.

Consequences for the design (**proposed**):

- Everything after the browser step is command-line and automatable. A **finishing pipeline** with replaceable steps (merge, resize or scale to the book format, reduce images, stamp folios) fits behind the renderer interface; a sidecar could ship a Chromium together with `pdfcpu` and Ghostscript, which are Eric's current tools.
- Stamping folios on the final PDF is robust: it does not depend on CSS page-margin support, and the physical page number matches what the table of contents and index cite. Roman numerals for the front matter would need stamping a page range (to verify in `pdfcpu`).
- The book format should be set where the page is laid out (`@page` size in the book template) rather than by scaling afterwards; the scaling step can stay as a compatibility option.
- A part is defined by a date range and a title: composing a book from "a period", as Eric did with database queries, should be a first-class way to build the list of posts, with parts as sections of the ordered `contains` statements.
- Eric's caveat: this chain describes how the **first book** was made; it is a record of needs and constraints, not necessarily what he wants to reproduce.
- Answers: the pages were printed from the browser at **A4** (then scaled to A5); in the first book the **table of contents is at the beginning and the index at the end**; the index was formatted in Writer (ODT) and is too large to share.

Data from the table of contents of the first book (a spreadsheet of 215 rows: title and page, analyzed on 2026-10-09):

- Six parts ("Premier mois" to "Sixième mois") starting on pages 8, 42, 79, 116, 153 and 190; the last entries are "Carte" (page 230) and "Index" (page 231). The body starts on physical page 8, so seven pages precede it (cover, title and the table of contents).
- Entries are post titles (not dates). Besides the daily posts, each part has recurring sections: an opener (the month), often a "Résumé", and at the end a "whoBIRD" section (bird detections) and a "Carte" (map). Other repeated titles: "Le camino" (4 times), "Gastronomie".
- **207 of 214 units take exactly one page.** Seven take more: five of two pages and two of three pages, almost all of them "whoBIRD" sections (their length depends on the number of detections), plus one "Gastronomie". With a default of one page per unit, only a handful of exceptions would have to be declared or measured, and they belong to a recognizable kind of content.
- The table of contents has about 5 pages at the front, and **its length does not depend on the page numbers it contains** (digits do not change the number of lines): rendering it once with placeholder numbers gives its page count, then the real numbers can be filled in. With the table of contents at the front, two steps are enough; the index at the end shifts nothing.
- The structure of a part is regular: opener, optional summary, daily posts, whoBIRD, map. This suggests a **kind** on each unit of the ordered `contains` statements (opener, summary, day, appendix...).

## Table of contents and index

Eric builds both by hand today and finds them useful features; the index "needs real thinking". **Proposed**, nothing decided.

- **The core difficulty is page numbers.** They exist only after pagination, which happens in the renderer, not in WordPress. A table of contents or index *with page numbers* therefore needs a renderer that can resolve them: a paged-media polyfill such as Paged.js (`target-counter()`), or a two-pass process (render, read the page of each anchor, inject the numbers, render again). Plain browser printing cannot do it. A table of contents *without* page numbers (sections, post titles, dates) is independent of the layout and can come first.
- **Table of contents.** Derived from the structure of the book: sections and ordered posts (the ordered `contains` statements and the statements about them that give their section). The simpler of the two features.
- **Where index entries come from** (three sources, which can be combined):
  1. **Taxonomy terms** (tags, categories such as places or species): each term used by the posts of the book becomes an entry, with the posts as locators. Automatic, but coarse.
  2. **Explicit marks in the text**: an inline "index entry" mark with an optional sub-entry, a sort key and a locator anchored in the passage (the idea of `\index` in LaTeX or `indexterm` in DocBook). Precise, but costly to author by hand; the step that generates the post HTML could also emit the marks.
  3. **Statements**: an entry is the object of a statement such as `(post:12, mentions, term:45)`; sub-entries come from a "broader" relation between terms, "see also" from a relation between entries. Statements about the mention carry what an index needs: the passage anchor, whether the mention is main or passing (bold page number), and the mode (print only). This ties the index to the triples domain.
- **What a real index needs:** entries and sub-entries; "see" and "see also"; sorting that follows the language (accents, ignored leading articles, explicit sort keys; PHP's `intl` `Collator` is optional, so availability is to verify); letter headings; page ranges collapsed (12-14); main references highlighted; display forms that differ from the sort form.
- **Eric's experience (one book printed so far).** He checked by hand that each daily post fits on one page. Database queries then extracted a CSV (without page numbers) of the index entries, and the page numbers were computed and added in a second step, since a post's page follows from its position. He indexed mainly place names, then itinerary names, and, occasionally, notable incidents. Page numbers in the table of contents and index are really useful if they can be included.
- **Page numbers without a layout engine (proposed).** Eric's trick generalizes: if every post of a book starts on a new page (CSS page break before each post) and each post declares how many pages it takes (default 1, a statement about the `contains` statement), then the first page of each post is the cumulative sum of the previous page counts plus the front matter. No renderer is needed. A verification step must catch a post that overflows its declared count: the renderer, when present, reports the real page count of each post and the plugin warns on a mismatch; without a renderer, the print preview is the check. Blank pages needed so that sections start on a right-hand page can be modeled the same way.
- **Page resolution as a replaceable strategy (proposed).** Two strategies behind one interface, so that the table of contents and the index do not care which one is used: *declared* (the counting above, no dependency, post-level locators) and *measured* (Paged.js or a two-pass render, which handles posts of arbitrary length and passage-level anchors).
- **What gets indexed, and where it lives today (Eric).** Itineraries (GR10, Via Tolosana...), countries, regions and incidents are all **tags** (`post_tag`). In the printed book an itinerary tag only says that the route was followed or crossed that day, so a route is *not* a richer entity (an earlier idea of stages `part-of` a route is dropped). Cities and villages were typed in by hand, not tagged. The printed book has a single combined index.
- **Consequences for the index (proposed).**
  - Entries come from **index sources**, each one a taxonomy (any taxonomy, not only tags). The first sources are taxonomy terms; statements and inline marks come later.
  - Cities and villages: a non-public **hierarchical taxonomy** (country, region, place) used from the normal editor box, with autocomplete on names already entered, is the cheapest way to capture them at writing time; its hierarchy gives sub-entries for free. A migration helper could fill it from existing posts.
  - Tags are not hierarchical and mix several meanings, so each term needs a **kind** (place, route, incident) to be indexed and presented, and terms with no kind stay out of the index. The kind can be a statement `(term:45, has-kind, kind:route)` or term meta. If country > region sub-entries matter for tags, a "broader" statement between terms provides them.
  - One combined index is the default; the kind can drive typography or a per-kind index later.
  - **Eric's answers (to be refined later):** the selection of tags for the first book's index was done by hand, and the types of tags (place, route, incident) were told apart by **typography** in the formatted index, not by data. A flat index was enough (no country > region > city hierarchy). Cities and villages could simply be added as tags too, which avoids a dedicated taxonomy. So the kind of a term and its inclusion in the index become explicit data (a term with no kind is excluded) and typography follows from the kind; a hierarchy stays an option.
- **Parts of variable length (Eric's objection).** The table of contents, the index and chapter openings have no fixed page count, so declaring counts for them by hand would burden the user. Proposed ways to remove most of that burden:
  - **Numbering design (optional, no longer needed).** An earlier idea was roman numerals for the front matter and the table of contents after the body. Eric's first book shows it is not needed: the table of contents is at the front, its length is independent of its page numbers and is found by a first pass, and the folios are the physical pages of the PDF. Roman numerals and a table of contents at the back stay possible as layout options.
  - **Chapter openings:** give each opening a declared page count (default 1), as for posts, and **calibrate instead of asking the user to count**: a renderer measures the real count and the plugin stores it; without a renderer, a "Pages" screen lists each unit with its status (declared, verified, mismatch) and lets the user type the count seen in the print preview once. An idea to evaluate: import the produced PDF and find the page of each post title to fill the counts.
  - With a measuring renderer none of this is needed; the declared strategy is the dependency-free baseline.
- **Export.** The table of contents and the index can also be exported as CSV or JSON (with page numbers when known), which replaces Eric's database queries and keeps external steps possible.
- **Delivery order (proposed):** table of contents without page numbers first; then page numbers by the declared strategy (page break per post, page counts, overflow check); then the index (places and routes from terms and statements, incidents from marks), as its own slice after a design note; the measured strategy later, when posts longer than a page or passage-level anchors are needed.


## PDF production: automation options

**Proposed**, nothing decided.

- **Constraint.** A PHP PDF library (Dompdf, mPDF, TCPDF) is a poor fit: block themes rely on modern CSS (flex, grid, CSS variables), and the pages contain JavaScript-rendered maps (Leaflet, WP GPX Maps) and lazily loaded images. A real browser engine is needed. The plugin itself stays PHP-only (no `shell_exec`), so rendering happens outside WordPress.
- **What the plugin provides.** The `print`/`book` modes and their templates; a **single book page** (all posts of a book in order, table of contents, `@page` size and margins, continuous page numbers); a **manifest** of the book (ordered list of URLs with mode and options) through the REST API and WP-CLI.
- **What an external renderer does.** Opens the book page, or each URL of the manifest, in headless Chromium (for example Playwright, `page.pdf()` with header and footer disabled and the CSS page size preferred), waits for maps and images to finish loading, and merges the PDFs if there are several. With one book page, page numbering, table of contents and running headers become continuous, which per-post PDFs merged afterwards cannot give.
- **Eric's preference:** ideally on the server, but that means installing extra software, which could put off other users of the plugin. His own site runs under Docker, so it is not a problem for him. Serving the assembled result as HTML + CSS and printing from a browser (Samsung Internet while it adds no header, or a small dedicated Android app) is also considered.
- **Renderer backends** (**proposed** design): optional and pluggable behind one interface, all consuming the same book page and manifest.
  1. **Browser, the default, no dependency.** The plugin serves the book as HTML + CSS and the user prints it from a browser. `@page { margin: 0 }` with padding on the content normally hides the browser's own header and footer in Chromium-based browsers (to verify on Samsung Internet), which would remove the dependence on one browser. A small Android app (a WebView and the Android print framework) is possible but is a separate project and is not planned.
  2. **Sidecar HTTP renderer, optional.** A headless Chromium service in a container next to WordPress, called over HTTP by the plugin: no `shell_exec`, nothing installed in the WordPress container. It fits Eric's Docker setup and stays an opt-in for other users. Gotenberg is a known candidate (HTML or URL to PDF, and PDF merging); its current API and how to wait for the maps are to verify. The service must be able to reach the site, and the plugin only ever sends URLs of its own site.
  3. **External tool consuming the manifest, optional:** a command-line tool on a laptop or in CI.
- **Limitation to check:** Chromium does not support the paged-media features needed for a table of contents with page numbers and running headers (`target-counter()`, running elements). Paged.js polyfills them in the browser; a renderer based on Chromium shares the limitation unless it also runs such a polyfill.
- **Print link in the views** (**decided**): a block that builds the link to the current page in a given mode, shown only when a relation exists for the current template and that mode, replacing hand-written links in the templates.
- **Open for Books:** whether any renderer backend lives in this project or in a separate tool, and how far "Books" goes beyond composing the book and exposing the manifest.
