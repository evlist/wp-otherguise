<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 235 — Books: the block "units of the section"

Status: **coded (2026-11-02)**, tried on a scratch WordPress only. Dependencies: slice 231 (the merge of the units of a section, who may ask for it), 223 (sections), the Modes module (the active mode and the variants of templates). Asked by Eric on 2026-11-02: a small book made of a section, whose **cover** comes from the section itself (title, featured image, excerpt) and whose pages are the units, with a template that does not replace the existing ones.

## The problem

Since slice 231 the pages of the units are put **after the whole page of the section, before the scripts of the footer**. A template cannot say where: no back cover, no page of contents after the cover, and nothing is shown in the web mode.

## The block `books/section-units`

Put it **at the root** of the template of a section (a group in a column would squeeze the pages of the units).

- **Print mode, for who may have the section merged** (an editor, or the PDF job with its token: slice 231): the block leaves a marker (`<!-- books-section-units -->`) where the pages of the units go. `MergedPrint` puts the units **there** instead of in front of the footer. The pages that follow the block in the template (a back cover) come after the units.
- **Everywhere else** (the web mode, a visitor, the editor): the **list of the units** of the section, in the order of the section, the published ones without a password (a list, like the page of a category). For each unit, the **inner blocks** of the block, with the unit as their post (as a post template does: title, featured image, excerpt...); without inner blocks, the title with a link. The attribute `limit` bounds this list (0: all of them, the default); it does not bound the print.
- **While a merge is going on** (a unit that is a section renders the same template) the block shows nothing: the merge follows the sub-sections itself (six levels, slice 231).
- A block whose post is not the section of the page (the block in the template of another post) gives the list, never the marker.

How the units reach the marker: the template is rendered **before `wp_head`**, so the block's marker is known when `wp_head` ends (priority 999): an output buffer starts there; the units are rendered by the footer as before (priority 1), and the callback of the buffer replaces the marker when the page is sent. A page whose marker was not seen before `wp_head` (a template that is not rendered first) keeps the behaviour of slice 231: the units are put in front of the footer. A marker that is not found in the page: the units go before `</body>`; no units: the marker is removed.

## Detecting the mode

The block does not read the mode itself: `MergedPrint::slot()` uses the request of slice 231 (`MergeRequest`: the active mode of the Modes module, `modes_active_mode()`, is one of `books_preview_frame_modes`, `print` by default). Books already depends on Modes. Without Modes the block shows the list.

## For the theme

A template "section with a cover" and its print variant (declared with `wp otherguise modes variant`: the variants are relations, not a naming convention). In print: the cover (title, featured image, excerpt of the section, a page of its own), then the block, then what should follow the pages. In web: the same blocks, then the block with its inner blocks for the list. The units keep their own templates (for instance "publication randonnée print").

## Not done

- A page of contents with page numbers (the markers of slice 227 would give them).
- Taking the folio off the cover (`@page :first`): the page rules of slice 230 put the folio on every page.
- The numbers of units of the preview and of the PDF as two settings (a slice of its own, to do later).
- The pagination of the web list.

## Tests

`BooksMergedPrintDbTest`: the marker and the placement of the units (the rest of the page untouched, once), the list elsewhere (a visitor, the web mode, the limit), no marker for another post or a post that is not a section, silence during a merge, the units before `</body>` without a marker, the marker removed without units. `BooksModuleTest`: the hooks (`wp_head` 999, `init`).

## Verified

On a scratch WordPress 7.1.3 with Chromium (an administrator; a section with three posts; a template and its print variant): in print, the three units are between the cover and the back cover; the same page for a visitor shows the list; the web mode shows the list with title (of the unit, not of the section) and excerpt.

**Never tried**: a real Gotenberg with this template, the editor script of the block in the block editor (its syntax was checked, it was not loaded), a section of the real blog, the cover of the theme (it does not exist yet), a classic theme.
