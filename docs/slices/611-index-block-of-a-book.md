<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 611 — Books: the block `books/section-index`, with page numbers

Status: **coded (2026-11-22), on `main`; see "What was built, and what differs from the plan" at the end.** Dependencies: 605 (the selection), 610 (the builder and the renderer), slices 227–228 and 231–235 of Books (the merge, the manifest, the passes, the table of contents as the model). **The module Books depends on Index** (declared in `dependencies()`, checked by `ArchitectureTest`; Index never knows Books). Context: [`../design/index.md`](../design/index.md), "Consumers" and "Vocabulary and the dimensions of an index".

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

## What was built, and what differs from the plan (2026-11-22)

Built: the block `books/section-index` (`Block\SectionIndexBlock`, `blocks/section-index/` with `block.json`, `edit.js`, `style.css`), `Merge\IndexSlots` (the markers and their replacement), `Merge\IndexTable` (the HTML), `MergedPrint::index_slot()`, the count of numbered indexes in the manifest (added to `tocs`, so the job's second pass needs no change), and, on the side of Index, the filter `index_build` and the attribution flags of `IndexModel`.

What differs from the plan:

- **No declared dependency of Books on Index.** The slice said `Books` would declare `dependencies() = [ …, 'index' ]`. **That would have switched the module Index on wherever Books is on** (`ModuleSwitches` enables what an enabled module needs, and Index is off by default). Instead the coupling is **by a filter in both directions**: Index answers **`index_build( null, $post_ids, $options )`** (a plain array: `groups` by letter, each line with its label, kind, classes and locators, and `attribution`), and Books asks it; **no class of one is named in the other** (`ArchitectureTest` is untouched). **A site without the module of the index gets no index: the marker goes away.** The design note ("the module depends on Index") is corrected.
- **The layout is Books'**: the HTML is made by `Merge\IndexTable` from the data, not by `IndexRenderer` of Index (which stays the renderer of the command and of the future web block). **`attribution`**: the notice is made in Books from the flags `osm` and `wikimedia` that the builder computes (a topic with a Wikidata or Wikipedia key owes to Wikimedia; a mention from `image/*` or `trace/*` owes to OpenStreetMap); **the wording is the filter `books_index_attribution`, there is no settings screen**.
- **The locator of a mention is the page of the first heading of its post** (as planned). The pages in order, consecutive ones collapsed, a **main** page in bold, **a run broken where the weight changes**; a post that is not in the map has no number (**a line with none shows a dash**); a placeholder (`000`) until the map is known.
- **Outside print** the locators are links with the **title of the post**.
- **The titles of the index and of its letters carry `no-toc`**, so that a table of contents on the same page does not list them.
- **The merge was too long**: `MergedPrint.php` was at the limit of 700 lines of the repository's rule, so **the manifest and the map of the pages moved to `Merge\MergeRecord`** (no change of behaviour) and the guard of the slots (`slot_section()`) is shared by the tables of contents and the indexes.
- **Not done**: a settings screen for the notice; the tree (sub-topics); non-consecutive ranges; the exact page of an element (612).

### Verification

- **Under automated tests** (PHPUnit, 918 tests in the suite): the pages (runs, weights, escaping, classes), the unknown (a dash, the placeholder), the links and the title, the notice (`auto`, `yes`, `no`, one notice, the filter), the slots (markers, the data asked only for the slot in the piece, no units, no data), the block (the options, a mode it is not shown in, a notice that is not known), **the merge on a real database** (the posts of the merge in order, the draft and the password left out, the map of the pages, the manifest counting one numbered table of contents and one numbered index, two indexes with their own options, no module of the index, no right to merge), and the attribution flags of the builder.
- **On a scratch WordPress 7.1.4** (both modules on): the block is registered, the filter `index_build` answers with the real module (a selection `kind:place source:post`: three letters), refuses a malformed token (null), and the table is made with page numbers and with links and real titles. **No PDF was made**: the second pass, the real page numbers and the layout in Chromium and Gotenberg were **never tried**.
