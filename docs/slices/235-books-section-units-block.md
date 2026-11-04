<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 235 — Books: the blocks that list and merge the units of a section

Status: **coded (2026-11-03)**, tried on a scratch WordPress only. Dependencies: slice 231 (the merge of the units of a section, who may ask for it), 223 (sections), the Modes module (the active mode and the variants of templates). Asked by Eric on 2026-11-02: a small book made of a section, whose **cover** comes from the section itself (title, featured image, excerpt) and whose pages are the units, with a template that does not replace the existing ones; then, the same day, **two blocks** that the editor of the section places where he wants, each with the modes it concerns.

The file keeps its first name (`235-books-section-units-block`).

## The problem

Since slice 231 the pages of the units were put **after the whole page of the section, before the scripts of the footer**, for every section, and nothing listed the units. A template or a content could not say where, and a section could not have a list (a table of contents) or no merge at all.

## Two blocks (decided by Eric, 2026-11-03)

Both have an attribute **`modes`**: the slugs of the modes in which the block acts; **empty (the default) means every mode**. Both are placed by the editor of the section, in the content of the section or in a template of the theme.

1. **`books/section-list`: lists the units** of the section, with links (a table of contents in print, a list on the web). The block has **no inner blocks**: the server builds a Query Loop of WordPress (`core/query`, variation "Units of the section") each time, and puts in its post template the **template part chosen** in the attribute **`item`**, or, without a choice, the title (level 3, linked) and the excerpt. A loop whose `query` attribute holds `books_units: true` lists **the units of the section of the page**, in the order of the section, published and without a password (`Block\UnitsQuery`, filter `query_loop_block_query_vars`; categories, author, search and sticky posts of the loop are dropped; an unlisted unit (slice 402) is shown: a section wants them). A page of the loop holds 100 units (the filter `books_section_list_per_page`); the pagination is the theme's. The variation "Units of the section" of the Query Loop also works alone, in the inserter, without the block (then it has no `modes` and no `item`).
   - **The items** are template parts of the theme, so a part can have a **variant in a mode** like any template part (`wp otherguise modes variant`, with `Variants::part`): the list can show the title and the excerpt on the web, and the title alone in print. The parts an editor may choose are set on the screen **Otherguise → Books** ("Items of the lists of units", one box per part of the active theme; option `books_list_items`, removed at uninstall) and completed by the filter `books_list_items`. The block uses the part only if it is **on that list and the theme still has it**, else the default: a block alone, in a template file, shows a list.
2. **`books/section-merge`: merges the units.** It leaves a marker where the pages of the units go, **in its modes**, for who may have the section merged (slice 231: an editor, or the PDF job with its token; **nobody else, in any mode**: the web too). **It is the only thing that causes a merge**: a section whose page has no such block is not merged (the footer does nothing), in print or in any mode. It shows nothing elsewhere, nothing while a merge is going on (a unit that is a section renders the same template: the merge follows the sub-sections itself, six levels, slice 231; the list of that unit, on the other hand, is shown: a table of contents of the chapter), and nothing when its post is not the section of the page. **One merge per page**: the first block asks, the others of the page ask for nothing more (the units are the same).

The editor puts them where he wants: a cover, a list (table of contents) in print only, the merge, a closing page. The list can be shown in print and on the web, or in one of them; the merge can be asked for in the web mode too (for an editor, as a preview).

How the units reach the marker: the template (and the content) are rendered **before `wp_head`**, so the marker is known when `wp_head` ends (priority 999): an output buffer starts there; the units are rendered by the footer as before (priority 1) and the callback of the buffer replaces the marker when the page is sent. A marker not seen before `wp_head` keeps the behaviour of slice 231: the units go in front of the footer. A marker not found in the page: the units go before `</body>`; no units: the marker is removed.

## Why two blocks (decided by Eric, 2026-11-04)

The list and the merge are both "the units of the section, each shown in some way, in some modes", and a merge is a list whose item is the **whole page of the unit**. They stay **two blocks** with a **common vocabulary** (the attribute `modes`, the notion of an item, the order of the section) and, where it helps, common code:

- the item of the list is a template part of the theme with the unit as the current post; the item of the merge is the template that WordPress finds for the unit, rendered with the unit asked from the main query (slice 231), so that the blocks and shortcodes that read the main query (QR codes, maps) see the unit;
- the list is light and open to everybody, paginated by the loop, and shows the direct units; the merge costs the rendering of all the units, is reserved to an editor or the PDF job, has its limits and its log, follows the sub-sections, and is placed after the fact. A block that anybody sees must not become, from a menu, a block that costs a minute of rendering and shows for some people only.

What is shared today: `ModeFilter` (the modes of a block, the mode of the request, the choices of the editor), the registration of the block with its inline data. Not done: a common base for the two blocks and for the editor scripts (the check boxes of the modes are written twice).

## Detecting the mode

`modes_active_mode()` of the Modes module; Books already depends on Modes. Without Modes the slug is empty: a block with no `modes` acts, a block that names modes shows nothing. The editor offers the modes of the registry (`window.booksModes`, an inline script), as check boxes: none checked is every mode.

## For the theme

The web template of a section shows the section and, where it wants a list, `books/section-list`; its print variant shows what print needs and, where the pages go, `books/section-merge`. The variants are relations declared with `wp otherguise modes variant`, not a naming convention. The units keep their own templates (for instance "publication randonnée print").

## No page break between the units (2026-11-04)

The merge no longer adds `break-before: page` to each unit (it was one rule written by the plugin in the merged HTML). The wrapper `div.books-merge-unit` stays (`data-post`, `data-depth`). **The templates of the theme add the page breaks** where a unit should start a page, so that a book of texts can run its chapters on, and a book of days keep one day per page. Until the templates do, the units follow one another without a page break (the note of a preview that stopped before the last unit still starts a page). The slice 230 note that a break cannot make a blank page (`left` / `right` do not insert one in Chromium) still holds.

## What changed from the first versions of this slice (2026-11-02)

- A first block `books/section-units` placed the marker and, on the web, listed the units itself; then it only placed the marker and a Query Loop listed the units. It is now **renamed `books/section-merge`** (nothing was in production) and the list is the wrapper `books/section-list`.
- The merge was **automatic** in a print mode (slice 231); it is now caused by the block only (Eric: "I have no sections in production").
- `MergeRequest` no longer says whether the mode is a print mode (`books_preview_frame_modes` stays for the PDF job and the preview): the mode is the block's business.

## Not done

- **A common base for the two blocks** (possible improvement, Eric 2026-11-04: not now): the registration of a block with its inline data for the editor and the filter by mode are written twice in PHP (`SectionMergeBlock`, `SectionListBlock`); a base class or a trait would share them. Worth doing when a third block comes. The check boxes of the modes are also written twice in JavaScript (`blocks/section-merge/edit.js`, `blocks/section-list/edit.js`), with no build step to share them.
- **Restricting the field of a merge or of a list** (a tag, a category, a date range...), which is what would make several merge blocks on one page useful. To do it: a marker per block, the units of a block computed from its own filter, a unit merged only once per page, the limits (1000 units, ten minutes) common to the page, and the same filter on the list so that the table of contents and the pages agree (the Query Loop already filters by taxonomy: which of the two governs is to decide).
- A table of contents **with page numbers** (Chromium has no `target-counter`, to our knowledge; the markers of slice 227 give the pages after the fact); links of the list that lead to the pages of the book, not to the web pages.
- Taking the folio off the cover (`@page :first`): the page rules of slice 230 put the folio on every page. Left aside by Eric on 2026-11-02.
- The numbers of units of the preview and of the PDF as two settings (a slice of its own, to do later).
- The editor shows a placeholder for the list (the server renders it), not the units.
- Units that are not published, for someone who holds the token of the section (slice 403, see `docs/design/books.md`).

## To know

- With the default modes (all of them), **an editor who opens the web page of a section that has the merge block waits for the whole merge** (the preview of the editor, `books_preview`, is bounded to ten units; the plain web page is not). The block can be limited to the print modes to avoid it.
- A section whose content has no merge block gives a PDF of one page.

## Tests

`BooksMergedPrintDbTest`: the marker and the placement of the units (the rest of the page untouched, once), nothing for a visitor in any mode, the modes of the merge block (default every mode, a list, a mode that is not named), only a block causes a merge and only the first, the list block (modes, wrapper, empty), the mode filter (and the choices of the editor), no marker for another post or a post that is not a section, silence during a merge, the units before `</body>` without a marker, the marker removed without units; the filter of the loop (untouched without the key, the order of the section, the settings dropped, the page size kept, nothing for a post that is not a section, the page when the context has no post). `BooksListItemsTest`: the saved slugs, the allowed and usable parts, the default item, the item chosen (allowed and present, else the default), the identifiers of the loops, the modes of the list, the screen of the settings. `BooksModuleTest`: the hooks (`wp_head` 999, `init`, `query_loop_block_query_vars`), the option of the items (registration, uninstall).

## Verified

On a scratch WordPress 7.1.3 with Chromium (an administrator and a visitor; a section whose content is a paragraph, the list block with its loop, the merge block, a paragraph; three posts): by default the list and the merge are in the web and the print page of the administrator, the units between the list and the last paragraph, and only the list for the visitor; a list limited to the web is absent in print; a merge limited to the web is absent in print; **a content with neither block has no merge** at all, in any mode. Before that, with the two templates of the child theme (the first version of the blocks): the cover, the content of the section and the three units in print.

On the same scratch with a part of the theme and its variant in print (declared with the Modes API): the list shows the part (title and excerpt) on the web and its print variant (title alone) in print, **inside the loop**, and a block with no choice shows the title and the excerpt.

**Never tried**: the blocks in the block editor (the scripts' syntax was checked, they were not loaded), a real Gotenberg with these blocks, the blog (testing, staging, production), the cover of the theme as rendered, the pagination of the loop, a classic theme, a section of several hundred units merged on the web.
