<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 506 — Wiki: the incoming links and the wanted pages

Status: **rewritten for the hierarchical model and coded on the branch `wiki` (2026-11-07)**, not merged into `main`. Dependencies: slices 501, 502, 505 (the links), Triples. Context: [`../design/wiki.md`](../design/wiki.md): "which pages cite this one" is what WordPress does not have, and the equivalent of `Special:WantedPages` that Eric uses to write the autobiography.

## What it does

An **index of the ordinary links** (`<a href>` whose address stands for a path of the tree, slice 505), kept in `triples` (`Tree\Citations`), and its readings.

| Predicate | Subject | Object | Meaning |
|---|---|---|---|
| `wiki/cites` | a page | a page | the page links to that page; inverse "Cited by"; no limit |
| `wiki/wants` | a page | `string`: a path of titles joined by `/` (`Biography/Nobody`) | the page links to a path that **enters the tree and stops** (a red link); a path over 191 bytes is not recorded |

A link is kept **by id**: it survives a rename or a move, and an alias counts as the page that has it.

### Kept up to date

- **On save** (`wp_after_insert_post`, priority 20, any page that is not an auto-draft, trashed or a revision): the anchors are read with `WP_HTML_Tag_Processor`; the statements of the page are **brought in line** (missing ones added, ones no longer in the text removed, one transaction; `sync()` says whether anything changed). A link to the page itself is left out. Drafts are indexed too; **readers are filtered when they read**.
- **When a page is given a key** (created, renamed, moved, first saved: `Pages` calls back `Citations::claimed()`): the `wants` of its path become `cites` to it (one indexed query).
- **Deletion**: the cleanup of Triples takes both directions.
- **`wp otherguise tree links [--dry-run]`**: reads every page that is not in the trash again; reports pages read and changed; can be run twice.

### Readings

- **Block `wiki/cited-by`** (server-rendered, `autoRegister`, class `wiki-cited-by`): the pages that cite the current page, by address, **only those the reader may see**, nothing when there is none.
- **Wanted pages**: screen **Otherguise → Wanted pages** (read-only, capability `edit_pages`, filter `wiki_wanted_capability`, declared to the overview) and `wp otherguise tree wanted`: each wanted path with the pages that want it, **most wanted first, then by path**; the address leads to the offer of slice 504 where a user who may create makes the page or the chain.

## Tests

`WikiTreeCitationsDbTest` (6, real statements, tag processor from `OTHERGUISE_WP_CORE`, skipped without it): reading a content; sync (added, removed, nothing written when unchanged, itself left out); links surviving a move; creating a wanted page turns wants into cites and updates the count; rebuild (dry run, real, second run); the block (visibility, empty). `WikiModuleTest` (predicates, hooks). Suite OK (833), `phpcs` clean.

## Verification

- **Tried** on the scratch WordPress: saving a page with three links created the `cites` (through an alias of a renamed page) and two `wants`; `Tree\Pages::create` of one wanted page turned its want into a `cites` and left the other; the block printed "Cited by: /Opinion/Note 506" on the new page; the screen listed the remaining wanted path with its page, and the overview mentions it.
- **Never tried**: `wp otherguise tree wanted` and `tree links` from a real WP-CLI (the services were called); a page saved from the block editor (the hook is the same, but not driven); the block in the Site Editor; a large site (the wanted list reads every `wiki/wants` statement: not measured); deleting a page (relied on Triples); a wanted path whose ancestors are renamed afterwards (the string is kept as written and is not rewritten); the blog instance.

## Not done

- Links written in another form than an address of the site; links in the printed book (a subject of Books).
- Wanted paths whose first title is unknown (a first-level page that does not exist yet is not a red link, slice 505).
- A cache or a paging of the wanted list.
