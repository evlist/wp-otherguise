<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 507 — Wiki: the other angles of a subject

Status: **proposed (2026-11-06), not coded**. Dependencies: slices 501 (`elsewhere`), 502 (the pages), 505 (the reading of a name). Context: [`../design/wiki.md`](../design/wiki.md): one of the interests of the spaces (Eric) is to treat **the same subject from several angles**; the same title lives in several spaces.

## What it does

A **block `wiki/other-angles`** for the template of a page: the band "Other angles: Encyclopaedia · Opinion · Biography".

- **The pages of the same title** in the other spaces (`Names::elsewhere( $title, $space )`), each as a link with the **label of its space** (slice 500), in the order of the spaces; only the pages that the reader may see (published, not unlisted: slice 402); nothing is printed when there are none.
- **The pages declared as the same subject with another title** ("Paris" and "Paris (ville)"): a predicate **`wiki/same-subject`**, **symmetric** (Triples already has that kind of predicate), page to page. It is written in a **box of the edit screen** ("Same subject as": one qualified name per line, resolved as the link by name of slice 505; a name that does not resolve is an error shown at saving), and by `wp otherguise wiki relate <post> <post>`. The block lists these too, after the pages of the same title; a page is listed once.
- **A block `wiki/space-label`**: the label of the space of a page, for a template of search results or of an archive (the same title in several spaces would otherwise look the same); with the status text of the space (slice 502: the data is there) as an option. A function `wiki_space_label( $post )` for a template that is not blocks.
- The markup carries classes (`wiki-angles`, `wiki-angle`, `wiki-space-label`); the look is the theme's.

## Tests (planned)

The block (none, one, several; the order of the spaces; a page that the reader cannot see; an unlisted page; a page of the same space is not listed; a declared page that has the same title is listed once); the predicate (symmetric, no limit; `relate` run twice); the box (a name that resolves, one that does not, a removal); the label (the block, the function, the status text).

## Not done

The address of the title alone (`/Paris`: a page that lists the angles, like a disambiguation page): a request with no colon is an ordinary request of WordPress and the rule of slice 503 does not take it; it would be a second rule, **to decide** when the first angles are written.
