<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 501 — Wiki: the names of the pages (rules, relations, service)

Status: **proposed (2026-11-06; rewritten the same day after Eric's question "why a new table, and not the table of the relations?"), not coded**. Dependencies: slices 500 (the spaces), 100 to 104 (**Triples**: the registry of the predicates, the statements, the cleanup). Context: [`../design/wiki.md`](../design/wiki.md): the level 3 (an address of our own), the identity of a page is the pair **(space, exact title)**.

## What it does

The **rules** that make a title a name, and the **index** that tells which page has a name. A first version of this slice had a table of its own (`wiki_names`); **there is none any more**: the names are **statements of the module `triples`**, which already stores literal objects in binary columns (`VARBINARY(191)`: compared byte for byte, whatever the collation of the database, the reason for the table), has an index on (type of the object, value, predicate), has the cardinality limits `max_objects_per_subject` and `max_subjects_per_object`, and deletes the statements of a post when it is deleted (`deleted_post`, slice 104). The module `wiki` depends on `triples` from this slice.

### The rules (`Wiki\Name`, no WordPress)

The starting point is MediaWiki's, **not its code**: the name of a page is its title after these steps, and two titles are the same name when they give the same result.

1. Unicode **NFC** (an accent written as one letter or as a letter and a combining mark is the same title). Needs the PHP extension `intl` (`Normalizer`); without it the step is skipped and the settings screen says so (**to look at**: how often `intl` is missing).
2. **A space and an underscore are the same**: runs of whitespace and underscores become one space; the ends are trimmed.
3. **The first letter is in capitals** (`mb_strtoupper` of the first character); **nothing else is changed**: the case and the accents tell two titles apart.
4. **Refused**: an empty title, the characters `# ? % < > [ ] { } |` and the control characters, a title that starts with `/` or has `//` (a subpage has a name), and a **qualified name** `Space:Title` of more than **191 bytes in UTF-8** (the limit of a `string` of Triples, slice 101; it is bytes, not characters: a title written in an alphabet with two or three bytes a letter is shorter). The colon is allowed in a title (only the first one of an address separates the space, slice 503).
5. **The qualified name** is `Space:Title` (the space as registered, slice 500). **The folded name** is the same with the lower case and no accents (`remove_accents`) in both parts: it serves **the suggestions only**, never an identity.

A slash in a title is a **subpage by convention** (`1958-1968/fr`), nothing more: there is no hierarchy in the data.

### The predicates (registered on `triples_register_predicates`, as the module `books` does for its own)

| Predicate | Subject | Object | Limits | Reads |
|---|---|---|---|---|
| `wiki/name` | a `wiki_page` post | `string`: the qualified name | one per page, **one page per name** | the current name |
| `wiki/former-name` | a post | `string`: a qualified name | many per page, **one page per name** | an alias: its address redirects (slice 503) |
| `wiki/folded-name` | a post | `string`: the folded name | one per page, **many pages per name** | the similar pages |
| `wiki/space` | a post | `string`: the name of the space | one per page | the pages of a space |

A rename replaces `wiki/name` and adds `wiki/former-name` (`Statements::replace`); an import of the old addresses of the SelfPedia is `wiki/former-name` statements (slice 508).

### The service (`Wiki\Names`, on the API of `Statements`; the real database in the tests)

- `claim( $post_id, $space, $title )`: **in a transaction** (`Statements::transaction`): the name is put through the rules; it is **refused** (an exception that carries the page that holds it) when another page has it as a current name **or as an alias** (**the two predicates are checked here**, `max_subjects_per_object` only sees one predicate at a time); the page's former current name becomes an alias; a name that the page had as an alias comes back as its current one; `wiki/space` and `wiki/folded-name` are kept up to date.
- `resolve( $space, $title )`: `array( 'post' => id, 'current' => bool )` or null (`subjects_of()` on the literal: **one indexed query**, then the aliases).
- `add_alias( $post_id, $space, $title )`: adds a name that the page **had** (or that an import brings) as a `wiki/former-name`; **refused** when another page holds it; nothing when it is the page's own current name (slice 505 `move` and the script of slice 508 use it).
- `current_of( $post_id )`: the current name, split at its first colon.
- `similar( $space, $title )`: the pages of the same space whose folded name is the same; `elsewhere( $title, $except_space )`: the pages of the same title in the **other** spaces (the angles, slice 507: there are few spaces, one `resolve` each).
- `pages_of( $space )`: the pages of a space.
- `rebuild()`: makes the statements again from the space (a post meta, slice 502) and the title of every `wiki_page`; the **aliases are lost** (they exist only here).
- **A page that is deleted** (not trashed) loses its statements through the cleanup of Triples: **nothing to write**; a page in the trash keeps its names.
- **The uninstall** of the module deletes the statements of its predicates (the index, not the pages: it can be rebuilt, except the aliases).

### What the choice costs (said to Eric on 2026-11-06)

- **The limit of 191 bytes** instead of 190 characters (rule 4).
- **No atomic unicity**: Triples checks a limit by counting before it inserts, so two saves of the same name at the very same moment could both pass. The transaction narrows it, it does not close it; **for a wiki that has one author this is accepted**, and a named lock (`GET_LOCK`) around `claim()` closes it if it ever matters.
- **Three or four statements for a page** instead of a row; a lookup is still one indexed query.
- **The dependency on `triples` starts here**, not at slice 506; an instance that is a wiki enables Triples anyway.

## Tests (planned)

The rules (each step; NFC with and without `intl`; the refused titles; a qualified name of 191 and 192 bytes; the first letter in other scripts; a title that differs only by an accent is another name, its folded name is the same); the predicates (registered, the limits as in the table); `claim` (free, taken as a current name, taken as an alias, a rename and its alias, a name that comes back, the folded name and the space kept up to date, the transaction rolled back when a check fails), `resolve`, `similar`, `elsewhere`, `pages_of`, `rebuild`; the deletion of a post (Triples' cleanup) and the uninstall; `ArchitectureTest` (the dependency on `triples`).

## Not done

The content type and the screens (502), the address (503), the 404 (504); a lock for concurrent saves.

## Verification (planned)

PHPUnit with a real MariaDB and the real statements of Triples (as `BooksSectionDbTest`); a scratch WordPress with `utf8mb4_unicode_520_ci`: two titles that differ by an accent **both** fit and are told apart. Never tried on a real site.
