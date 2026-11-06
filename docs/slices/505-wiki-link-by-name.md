<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 505 — Wiki: the link by name, the move of a page, the rename of a space

Status: **proposed (2026-11-06), not coded**. Dependencies: slices 501 (the names), 502 (the pages), 503 (the addresses), 504 (the red link leads to its offer). Context: [`../design/wiki.md`](../design/wiki.md): **qualified names only** (decided by Eric on 2026-11-06), the syntax read leniently (the colon or the slash).

## What it does

### The shortcode

`[page "Biography:1958-1968/fr"]`, `[page "Biography:Paris" label="the city"]`, resolved **when the page is rendered** (not when it is saved: a rename or a move does not break a link, which an ordinary link of WordPress would).

- **The name** is read as in the design note: **a colon** marks the space (`Biography:Paris`); **the slash form** (`Biography/Paris`) is read too, its first segment being a registered space. Since the unqualified names are refused there is no doubt between a space and a page.
- **Resolved** (`Names::resolve()`): a **current name**: a link to the address of the page (`<a class="wiki-link" href="…">`), the text being `label` or the title as it is written; an **alias**: a link to the **current** address of the page (no redirect to follow); the page must be visible to the reader (a draft or a private page of the type is a red link for who cannot see it).
- **Missing**: a **red link** `<a class="wiki-link wiki-missing" href="<the address the page would have>">` that leads to the offer of slice 504 (title attribute "This page does not exist").
- **Unqualified or malformed** (`[page "Paris"]`, an unknown space, a title that the rules refuse): **refused** (decided): where it is rendered, a visible `<span class="wiki-error">[page "Paris": the space is missing]</span>` for who can edit the page, the text alone for a visitor; **when the page is saved**, a message (the block editor: an error on the save with the list; the classic screen: a notice) and the page is not published until it is fixed (a draft may keep it). The check is made **for the pages of the type `wiki_page` only**; elsewhere only the render tells.
- **One query per name** (an indexed lookup) with a cache for the request; a page with hundreds of links is a case for `resolve_many()` (**to measure**, not to write before).
- The name of the shortcode (`page`) is short: **to check** that no plugin of the instance already uses it; the block with a search in the editor comes later (a shortcode to type is less comfortable there).

### Move a page to another space

`wp otherguise wiki move <post> --space=<name> [--title=<title>] [--dry-run]`: claims the name in the target space (**refused** if a page of that name is there: the move does not rename in silence, which WordPress would), the former name becomes an alias (so the old address **redirects** and the links by name written with it **still resolve**), the meta `wiki_space` changes (the only way to change it, slice 502). Can be run twice. A row action on the list of the pages calls the same service.

### Rename a space

`wp otherguise wiki rename-space <old> <new> [--dry-run]`: for every page of the space the name is claimed in the new space with the old one as an alias, then the record of the space (slice 500) changes its name; **refused** if the new name is taken, **all or nothing** (a transaction per page and a report; run again after a failure it finishes the work).

## Tests (planned)

The reading of a name (the colon, the slash form, a title with a colon, a refused title, an unknown space); the shortcode (current, alias, missing, error for an editor and for a visitor, `label`, a page that the reader cannot see); the check at saving (REST error, the classic notice, a draft that may keep an error); `move` (free target, taken target, the alias, the old address redirects, the links still resolve, run twice, `--dry-run`); `rename-space` (the aliases of every page, a failure in the middle and a second run); the cache of the request.

## Not done

The incoming links (506); a block to insert a link with a search; `resolve_many()`.
