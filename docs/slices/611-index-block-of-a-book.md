<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 611 — Books: the block `books/section-index`, with page numbers

Status: **proposed (2026-11-22), not coded.** Dependencies: 605 (the selection), 610 (the builder and the renderer), slices 227–228 and 231–235 of Books (the merge, the manifest, the passes, the table of contents as the model). **The module Books depends on Index** (declared in `dependencies()`, checked by `ArchitectureTest`; Index never knows Books). Context: [`../design/index.md`](../design/index.md), "Consumers" and "Vocabulary and the dimensions of an index".

## What it does

A block **`books/section-index`** (a block only, no shortcode: Eric) that stands for **an index of the section**, placed in a template of the book like the table of contents. It works **as `books/section-toc` does**: it leaves a marker (`<!-- books-section-index:N -->`) where the index goes, the merge replaces it **once the units are known**, and in the print modes the page numbers are **placeholders in the first pass and the real numbers in the next** (the passes of slice 228, three at most).

### Attributes

- **`include`** and **`exclude`**: the tokens of slice 605 (`kind:place`, `source:image`, `visibility:print`…); **by default every kind, source and visibility** (Eric). Several blocks on one template give **several indexes of one book**.
- **`context`** (the broader topic in parentheses, on by default), **`modes`** (the modes in which the block is shown, as the table of contents), **`title`**.
- **`attribution`**: `auto` (default), `yes`, `no`. **One notice for the index** (Eric), after the last line, crediting **OpenStreetMap and the Wikimedia Foundation**, **shown in `auto` only when a listed topic comes from them** (a topic with a Wikidata key, or a place read from coordinates). The wording is a setting with a default; the CSV and the JSON of the command carry it as a field.

### What it lists, and the pages

- The posts are **the units of the merge, in order** (the same list as the table of contents: `MergedPrint` already has it); the lines are those of the builder of slice 610 with the selection; the typography follows the **classes per kind** (`index-kind-…`, of the kind and its ancestors).
- **In this slice the locator of a mention is the page of its post**: the page of the **first heading** of the unit, given by the map of the pages of the job (`books_pages`, slice 227). **Consecutive pages are collapsed (12–14)**, a **main** mention is in bold (`index-locator-main`), and the locators of a line are in the order of the book. **Where the passage itself is printed, the exact page comes with slice 612.**
- **Outside the print modes** (the preview, the web) the locators are **links to the posts**, as the entries of the table of contents.
- **The length of the index does not depend on the numbers** except for the collapse of ranges (a placeholder is one number): **an index at the end of the book shifts nothing**, the one at the start would need the second pass, as the table of contents does.
- **Access**: the same as the merge (an editor, or the PDF job with its token): the block **needs `books/section-merge` on the same page** and reads **only the posts of the merge** (published, no password), so the index of a book never lists a draft.

### Where the code goes

`Books\Block\SectionIndexBlock` (as `SectionTocBlock`), `MergedPrint::index_slot()` (as `toc_slot()`), a `Books\Merge\IndexTable` that calls `IndexBuilder` and `IndexRenderer` with the locator function of the book, the **manifest** gets a count of the **numbered indexes** next to `tocs` so that the job knows a second pass is needed, and `block.json` with the attributes.

## Tests (planned)

The block (the marker, a page that is not the section, a mode that is not allowed, the attributes); the merge (the index replaced at the marker, the units in order, a draft left out); the locators (placeholders, ranges collapsed, a main mention in bold, links outside print); the passes (a book with an index and a table of contents settles in the same passes); the selection (two blocks, two indexes); the attribution (`auto`, `yes`, `no`); `ArchitectureTest` for Books → Index. **A scratch WordPress with a real section and a PDF made by Chromium**, as for slice 227.

## Not done

**The exact pages of the printed elements (612)**; the tree (sub-topics under their parents); "see also"; ranges of non-consecutive pages; the renaming of the code (*entry* → *topic*), **to be done right after this slice**.
