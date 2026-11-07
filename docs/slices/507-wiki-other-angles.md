<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 507 — Wiki: the other angles of a subject

Status: **rewritten for the hierarchical model and coded on the branch `wiki` (2026-11-07)**, not merged into `main`. Dependencies: slices 501 (`Keys::elsewhere`, the index of the exact title), 502, 505. Context: [`../design/wiki.md`](../design/wiki.md): Eric treats **the same subject from several angles**; in the tree the same title lives under several branches (`Biography/Paris`, `Opinion/Paris`).

## What it does

- **The other angles of a page** (`Tree\Angles::of( $id )`): **the pages that have the same title elsewhere in the hierarchy, at any level** (decided 2026-11-07), by address, then **the pages declared as the same subject** under another title, by address; each page once, the page itself never.
- **Declaring a same subject** (`Tree\Angles::relate()`, `unrelate()`, `related()`): the predicate **`wiki/same-subject`**, **symmetric**, page to page, no limit (`Paris` and `Paris (ville)`). Said twice, it is one statement; a page that has no key, or a page with itself, is refused. `wp otherguise tree relate <a> <b> [--remove]`.
- **The block `wiki/other-angles`** (`Tree\AnglesBlock`, server-rendered, `autoRegister`): "Other angles:" and a list of the addresses (`wiki-angles`, `wiki-angle`, no style), **only pages the reader may see**, nothing when there are none. The page that does not exist (slice 504) already shows the same-title list with the same class.
- **What went with the spaces**: there is no `wiki/space-label` block. The role of the label is played by the address itself, and by the ancestors given to the results of the link tool (slice 505).

## Tests

`WikiTreeAnglesDbTest` (3, real statements): the same title at any level (order by address, the page excluded, unknown page); the same subject (symmetric, said twice, listed once and after the same title, refusals, removed); the block (links, order, visibility, empty). `WikiModuleTest` (predicate, hooks). Suite OK (836), `phpcs` clean.

## Verification

- **Tried** on the scratch WordPress: two `Rome` pages and a `Rome (ville)` declared the same subject; `/Biography/Rome` printed the band with `/Opinion/Rome` then `/Opinion/Rome (ville)`, as links.
- **Never tried**: `wp otherguise tree relate` from a real WP-CLI (the service was called); the block in the Site Editor or in a template of a theme; a draft or private angle for a logged-in user; many angles at once (`elsewhere` is bounded to 50, no paging).

## Not done

- **A box or panel in the editor** to declare a same subject (one title or address per line, resolved like a link): only the command exists for now.
- The address of the **title alone** (`/Paris`) as a page that lists the angles (a disambiguation page): not designed; a single-segment path is a first-level title.
- The unlisted flag of the module `access` (slice 402) is not looked at.
