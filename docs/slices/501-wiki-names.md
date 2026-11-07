<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 501 — Wiki: the titles and the index of the tree (rules, keys, service)

Status: **rewritten on 2026-11-07 for the hierarchical model and coded on the branch `wiki`** (not merged into `main`). This slice was first written and coded for the **first model** (spaces, `Space:Title`); that version is kept at the tag `wiki-model-1`. Dependencies: the module `triples` (the registry of the predicates, the statements, the cleanup of slices 100 to 104). Context: [`../design/wiki.md`](../design/wiki.md), "The model".

## What it does

The **rules** that make a title a name, and the **index** that tells **which page has a given title under a given parent** and, from that, resolves a path of titles. The pages are the pages of WordPress (the type `page`, `post_parent` is the tree, slice 502); this slice does not read posts: it is told the parent and the title, and keeps the index in **statements of `triples`**.

### The rules (`Wiki\Tree\Title`, no database)

The rules of the first model, with the decisions of 2026-11-07:

1. Unicode **normal form C** (needs the PHP extension `intl`; without it the step is skipped and the folded key keeps the accents).
2. **A space and an underscore are the same**: a run of spaces and underscores is one space, and the ends are trimmed.
3. **Nothing else is changed**: **the first letter is not made a capital**, the titles are **case-sensitive** (`Paris` and `paris` are two pages) and an accent tells two titles apart.
4. **Refused**: an empty title, the control characters, the characters `# ? % < > [ ] { }` and `|`, **any slash** (it separates the levels; the migration replaces it by `-`), a title that is `.` or `..` (a browser would rewrite the address), and a **key** of more than 191 bytes (below).
5. **At the first level only**: a title made **only of digits** is refused (`/2026` is the archive by year). The titles that a route of WordPress or the web server would serve first (`feed`, `wp-admin`…) are refused too, by a test that **slice 503 gives** (the service takes it as a callable and has none by default).

The colon is an ordinary character: there are no namespaces.

### The key

The identity of a page is **(its parent, its title after the rules)**, written as one string, **the key**: `<id of the parent>/<title>`, `0` for the first level (`0/Biography`, `625/Paris`). The slash cannot be in a title, so the key is not ambiguous. **At most 191 bytes** (the `string` of Triples): only **one title** counts, so **the depth is not limited**.

### The predicates (registered on `triples_register_predicates`)

| Predicate | Subject | Object | Limits | Reads |
|---|---|---|---|---|
| `wiki/key` | a post | `string`: the key | one per page, **one page per key** | the current key |
| `wiki/former-key` | a post | `string`: a key | many per page, **one page per key** | an alias: the old key of a renamed or moved page |
| `wiki/folded-key` | a post | `string`: the key with the title in lower case and without accents | one per page, **many pages per key** | the similar siblings |
| `wiki/title` | a post | `string`: the title alone | one per page, **many pages per title** | the same title elsewhere (the angles) |

The predicates of the first model (`wiki/name`, `wiki/former-name`, `wiki/folded-name`, `wiki/space`) are **not** registered by the new service: the first-model code that still uses them stays until slices 502 to 504 are reworked (**the two sets live side by side** until then).

### The service (`Wiki\Tree\Keys`, on the `Statements` of Triples)

- `claim( $post_id, $parent_id, $title )`: **in a transaction**; the title is put through the rules; **refused** (a `NameException` that carries the page that holds the key) when another page has the key **as a current key or as an alias** (both predicates are checked here: `max_subjects_per_object` only sees one at a time); a page cannot be its own parent; the former current key becomes an alias; a key that the page had as an alias comes back as its current one; the title and the folded key are kept up to date. A **move** or a **rename** is a `claim` with another parent or title: **only the key of the page changes**, the descendants keep theirs.
- `resolve( $parent_id, $title )`: `array( 'post' => id, 'current' => bool )` or null: **one indexed query**, then the aliases.
- `resolve_path( array $titles )`: walks the path **one level at a time** from the first level; answers `array( 'post' => id|null, 'exact' => bool, 'deepest' => id, 'rest' => titles that remain )`: `exact` is false when **an alias** was used at some level (the address has to be redirected to the current one: a node that was moved or renamed keeps all its descendants reachable under the old path); when a level is missing, `post` is null, `deepest` is the last node that exists and `rest` the titles that were not found (what the 404 needs).
- `current_of( $post_id )`: the current key cut into the parent and the title; `path_of( $post_id )`: the titles from the first level to the page, read **from the keys alone** (no call to WordPress), null when an ancestor has no key yet.
- `aliases_of( $post_id )`, `add_alias( $post_id, $parent_id, $title )` (what an import brings: refused when another page holds the key; nothing when the page has it already).
- `similar( $parent_id, $title )`: the siblings whose folded key is the same, **except the page that has exactly the key**; `elsewhere( $title, $except_post, $limit )`: the pages that have **the same title anywhere** in the tree (the angles), bounded.
- `rebuild( $pages )`: makes the current keys again from a list (`post`, `parent`, `title`) that the caller reads from the posts; **the aliases are kept**; reports the pages that were refused.
- **A page that is deleted** (not trashed) loses its statements through the cleanup of Triples; the module's uninstall does not delete them (as the other modules).

### What the choice costs

- **No atomic unicity**: Triples counts before it inserts, so two saves of the same key at the very same moment could both pass; the transaction narrows it, it does not close it (one author: accepted; a named lock would close it).
- **Four statements for a page.** A lookup is still one indexed query.
- **The path is walked, not stored**: one query per level. The cache of the addresses of the design note ("a cached map") is an optimisation that comes **after** this index and never replaces it.

## Coded on 2026-11-07 (branch `wiki`)

- `Tree\Title` (the rules, the key, the folded form), `Tree\Keys` (the service) in `plugin/modules/wiki/src/Tree/`; five codes added to `NameException` (`SLASH`, `DOTS`, `DIGITS`, `RESERVED`, `BAD_PARENT`); the four predicates registered by `Module::register_predicates()` (**next to** those of the first model, which stay until slices 502 to 504 are reworked); `Module::keys()` builds the service on the statements of Triples.
- The first-level test for the routes (`feed`, `wp-admin`…) is a **callable** of the constructor of `Keys`, with none by default; slice 503 provides it. A title made only of digits is refused at the first level by the rules themselves.
- **A first-model service and the new one live side by side**: `Names`, `Name`, `Spaces`, the pages, the router and the 404 are untouched and still work as before.

## Tests

`WikiTitleTest` (6, no database: the form of a title, the normal form C, each refused title, the key, the limit in bytes, the cut and the folded form) and `WikiKeysDbTest` (15, on a real MariaDB with the real statements: a title under a parent, case and accent sensitivity, a taken key, a rename and its held alias, a path walked, a path that stops, a **move that carries the descendants**, the path of a page read from the keys, the other refusals, the absence of a route test, the suggestions, the same title elsewhere, `add_alias`, the limits of the four predicates, a deleted page, the rebuild); `WikiModuleTest` expects the eight predicates. The whole suite passes with the database (778 tests, none skipped), `phpcs` is clean.

## Verification

- **Tried** on the scratch WordPress 7.1.x with real hierarchical pages (`wp_insert_post` with a `post_parent`), the real table of Triples (`varbinary(191)`): `Paris`, `paris`, `Ansò` and `Anso` under one parent are **four pages** found by their exact titles (their slugs of WordPress are `biography/anso` and `biography/anso-2`, never used); a path of three levels resolves, with the underscore for a space; a path that stops gives the deepest page that exists and the titles that remain; **a move** under another parent (`wp_update_post` and a `claim`) changes the path of the descendant **with no write on the descendant**, the path read from the keys **agrees with `get_post_ancestors()`**, and the old path still resolves, flagged not exact; the same title elsewhere finds the two `Paris` pages; a title typed with another accent finds both accent siblings as similar; a duplicate is refused with its holder; **deleting the pages leaves no statement**. It was a throw-away script, not a test kept in the repository.
- **Never tried**: the index at scale (thousands of pages: one indexed query per level, **not measured**), two saves of the same key at the very same moment (the known limit above), the notice about `intl` on a PHP without it, a site that moves a page by the editor (slice 502 will call `claim` from the saving hooks).

## Not done

The posts and the type `page` (502), the routing and the canonical address (503), the 404 and the approaching paths (504); the test of the first-level titles that a route would serve first (503); the cache of the addresses.
