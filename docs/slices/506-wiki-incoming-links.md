<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 506 — Wiki: the incoming links and the wanted pages

Status: **proposed (2026-11-06), not coded**. Dependencies: slices 501 (the names), 505 (the shortcode, the one way a link is written), Triples. Context: [`../design/wiki.md`](../design/wiki.md): "which pages cite this one" is the piece that WordPress does not have, and the equivalent of `Special:WantedPages` that Eric uses to write the autobiography.

## What it does

An **index of the links** kept up to date when a page is saved, in statements of `triples`, and two readings of it.

### The statements

| Predicate | Subject | Object | Meaning |
|---|---|---|---|
| `wiki/cites` | a `wiki_page` | a `wiki_page` | the page has a link by name to that page; `inverse_label` "Cited by"; no limit |
| `wiki/wants` | a `wiki_page` | `string`: a qualified name | the page has a link by name to a name that **no page has yet** |

A link by name is written by id (`wiki/cites`), so it **survives a rename or a move** (slice 505).

### Kept up to date

- **When a page is saved**: its content is read for `[page …]` (`get_shortcode_regex()`), each name is resolved; the statements of the page are **brought in line** (the missing ones added, the ones that are no longer in the text removed: one transaction, nothing is written when nothing changed); a link to itself is left out; a name that the rules refuse is not an index entry (it is an error, slice 505).
- **When a name is claimed** (a page created, renamed, moved): the `wiki/wants` statements that hold that name become `wiki/cites` to the new page (`subjects_of()` on the literal: **one indexed query**).
- **When a page is deleted**: the cleanup of Triples takes both directions.
- `wp otherguise wiki links --rebuild [--dry-run]`: reads every page again (can be run twice) and reports what changed.

### Readings

- **A block `wiki/cited-by`**: the pages that cite this one, with the label of their space, in the order of their titles; only the pages that the reader may see; nothing when there is none.
- **The wanted pages**: a screen under **Wiki** (and `wp otherguise wiki wanted`): each missing qualified name with **how many pages want it** and which, and a link to the offer of slice 504 (a user who may create makes the page from the list); sorted by the number of pages, then by name.

## Tests (planned)

Predicates (registered, no limit); saving a page (a link added, removed, resolved, missing, to itself; nothing written when nothing changed); the claim of a name that turns wants into cites; deletion; `--rebuild` and its report; the block (the order, the visibility, the empty case); the wanted list (the counts, the order, a name that was created in the meantime is no longer there); the screen (capability, as the other screens of the menu).

## Not done

Links written in other ways than the shortcode (an ordinary link to the address of a page: the address is the name, so a later version could read it); links from the blog (another instance, see the design note); the link in the printed book: a link to an encyclopaedic page becoming a note (a subject of Books, not analysed).
