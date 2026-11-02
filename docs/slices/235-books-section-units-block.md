<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 235 — Books: the units of a section in a template (print marker and Query Loop)

Status: **coded (2026-11-02)**, tried on a scratch WordPress only. Dependencies: slice 231 (the merge of the units of a section, who may ask for it), 223 (sections), the Modes module (the active mode and the variants of templates). Asked by Eric on 2026-11-02: a small book made of a section, whose **cover** comes from the section itself (title, featured image, excerpt) and whose pages are the units, with a template that does not replace the existing ones.

## The problem

Since slice 231 the pages of the units are put **after the whole page of the section, before the scripts of the footer**. A template cannot say where (no back cover, no page of contents after the cover), and nothing lists the units on the web.

## Two pieces

1. **Print: the block `books/section-units`**, a marker. Put it **at the root** of the print template of a section (a group in a column would squeeze the pages of the units). In the print mode, for who may have the section merged (an editor, or the PDF job with its token: slice 231), it leaves `<!-- books-section-units -->` and the merge puts the pages of the units **there**; what follows the block in the template (a back cover) comes after them. It shows nothing in any other case: the web mode, a visitor, a merge going on (a unit that is a section renders the same template; the merge follows the sub-sections itself, six levels, slice 231), a block whose post is not the section of the page.
2. **Web: the Query Loop of WordPress** (`core/query`). A loop whose `query` attribute holds `books_units: true` lists **the units of the section of the page**, in the order of the section, published and without a password (`Block\UnitsQuery`, filter `query_loop_block_query_vars`, default priority; the other settings of the loop that would filter posts — categories, author, search, sticky — are dropped). The size of a page of the loop (`perPage`) and its pagination block stay the loop's own: the theme decides. The theme puts what it wants in the loop (title, featured image, excerpt, list style, grid), and **the list style is the theme's**, not the plugin's. The editor offers it as the variation **"Units of the section"** of the Query Loop (inserter; `edit.js` of the block, `perPage` 100, a title and an excerpt inside). An **unlisted** unit (slice 402) is shown: a query by `post__in` is left alone by the filters of the unlisted state, and a section wants them.

A first version of the block listed the units itself (its own `<ul>`, inner blocks repeated for each unit); Eric asked on 2026-11-02 whether a block of the theme could be reused, and the Query Loop is: nothing of that first version is kept.

How the units reach the marker: the template is rendered **before `wp_head`**, so the marker is known when `wp_head` ends (priority 999): an output buffer starts there; the units are rendered by the footer as before (priority 1) and the callback of the buffer replaces the marker when the page is sent. A marker not seen before `wp_head` (a template not rendered first) keeps the behaviour of slice 231: the units go in front of the footer. A marker that is not found in the page: the units go before `</body>`; no units: the marker is removed.

## Detecting the mode

`MergedPrint::slot()` uses the request of slice 231 (`MergeRequest`: the active mode of the Modes module, `modes_active_mode()`, is one of `books_preview_frame_modes`, `print` by default). Books already depends on Modes. Without Modes the block shows nothing.

## For the theme

A template "section with a cover" for the web (cover, content of the section, then the Query Loop "Units of the section" with inside what each unit shows) and its print variant (declared with `wp otherguise modes variant`: the variants are relations, not a naming convention): the cover on a page of its own, the content of the section, then the block at the root of the template. The units keep their own templates (for instance "publication randonnée print"). A visitor who may not merge sees nothing where the block is in print.

## Not done

- A page of contents with page numbers (the markers of slice 227 would give them).
- Taking the folio off the cover (`@page :first`): the page rules of slice 230 put the folio on every page. Left aside by Eric on 2026-11-02.
- The numbers of units of the preview and of the PDF as two settings (a slice of its own, to do later).
- The editor shows, in the loop, the posts that its other settings would give (a sample), not the units: the server replaces them on the page.
- Units that are not published, for someone who holds the token of the section (slice 403, see `docs/design/books.md`).

## Tests

`BooksMergedPrintDbTest`: the marker and the placement of the units (the rest of the page untouched, once), nothing elsewhere (a visitor, the web mode), no marker for another post or a post that is not a section, silence during a merge, the units before `</body>` without a marker, the marker removed without units; the filter of the loop (untouched without the key, the order of the section, the settings dropped, the page size kept, nothing for a post that is not a section, the page when the context has no post). `BooksModuleTest`: the hooks (`wp_head` 999, `init`, `query_loop_block_query_vars`).

## Verified

On a scratch WordPress 7.1.3 with Chromium (an administrator; a section with three posts; the two templates of the child theme, the web one with the Query Loop in place of the block): in print the three units are right after the content of the section; for a visitor in print nothing is listed; on the web the loop lists the three units.

**Never tried**: the variation in the block editor (the script's syntax was checked, it was not loaded), a real Gotenberg with this template, the blog (testing, staging, production), the cover of the theme as rendered, the pagination of the loop, a classic theme.
