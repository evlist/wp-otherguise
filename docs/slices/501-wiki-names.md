<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 501 — Wiki: the names of the pages (rules, relations, service)

Status: **coded on the branch `wiki` (2026-11-06)**, not merged into `main` (proposed and rewritten the same day after Eric's question "why a new table, and not the table of the relations?"). Dependencies: slices 500 (the spaces), 100 to 104 (**Triples**: the registry of the predicates, the statements, the cleanup). Context: [`../design/wiki.md`](../design/wiki.md): the level 3 (an address of our own), the identity of a page is the pair **(space, exact title)**.

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

## Coded on 2026-11-06 (branch `wiki`)

`plugin/modules/wiki/src/`: **`Name`** (the rules, static, no database), **`NameException`** (codes `wiki_name_*`; `refuse()` throws with a translated message; `TAKEN` carries the page that holds the name and whether it is its current name), **`Names`** (the service, on the `Statements` of Triples, given by a callable like `Books\Section\Sections`), and the four predicates registered by `Module::register_predicates()` on `triples_register_predicates`, with the limits of the table above. `Module::names()` builds the service on the statements of the module Triples.

**Where the code differs from the text above** (decided while coding; Eric to confirm):

- **A trailing slash is refused** (`Paris/`), like a leading one: the address strips a trailing slash (slice 503), so `Paris` and `Paris/` would be the same address and two names. **A tab or a line break is refused** as a control character (it is not turned into a space).
- **`similar()` leaves out the page that has exactly the name** (it serves the page that does not exist, slice 504, and its suggestions): the other pages with the same folded name are the answer.
- **`rebuild( $pages )` takes the list of pages** (`post`, `space`, `title`) from the caller and **keeps the aliases** (the text said they are lost). The service does not read posts: the slice that stores the title and the space (502) gives the list. It deletes `wiki/name`, `wiki/space` and `wiki/folded-name`, claims each page, and **reports** (does not throw) the pages that were refused with the reason, such as a name that an alias of another page already holds.
- **No uninstall of the statements.** The module's uninstall only deletes the option of the spaces: the statements stay in the table of Triples, which keeps or deletes its data as its own setting says (`delete_data_on_uninstall`), as the other modules do; `rebuild()` makes the current names again.
- **A folded name of more than 191 bytes is not stored** (a few letters get longer in lower case): the page has no suggestions, and the name itself is not affected.
- **The folded name needs `intl`** (accents are removed through the decomposition of Unicode, not through `remove_accents`, so that the rules need no WordPress). Without `intl` the titles are not put in normal form C and the folded name keeps the accents; **the screen of the spaces warns** of it (`SettingsScreen`, a notice).
- **A bug of Triples was found and fixed on the way**: refusing a literal because of `max_subjects_per_object` built its message from the object, and a `Literal` has no string form, so the refusal ended in a PHP `Error` instead of the `InvalidStatementException` `too_many_subjects` (`StatementValidator::shown()`). No existing predicate had a literal with that limit; `wiki/name` does. Covered by `WikiNamesDbTest::test_the_predicates()`; **it is a change of the module Triples, to merge with the branch**.

## Tests

PHPUnit (the database tests need `OTHERGUISE_TEST_DB`): `WikiNameTest` (7: the form of a name, normal form C, each refused title, the limit in bytes, the cut at the first colon, the folded name, the refusal that carries the holder), `WikiNamesDbTest` (14, on a real MariaDB with the real statements: a free name, claiming twice, a name held as a current name and as an alias, a rename and its alias, a name that comes back, a move to another space, the other refusals, 191 bytes and the accents, the suggestions, the other angles, `add_alias`, the limits of the four predicates, a deleted page, `rebuild`), and two more in `WikiModuleTest` (the predicates and their limits, the notice about `intl`). The whole suite passes with the database (716 tests, none skipped) and `phpcs` is clean.

## Verification

- **Tried** on the scratch WordPress 7.1.x with the real table of Triples (`varbinary(191)`, no collation) and real posts: the module and its predicates are registered; `Cité` and `Cite` are two names for two pages; a move to another space leaves the old name as an alias; another page is refused with the holder and the fact that it is an alias; the suggestions find the page that differs by an accent; **deleting the posts** (`wp_delete_post`) leaves no `wiki/*` statement (the cleanup of Triples). This was a throw-away script run once, not a test kept in the repository.
- **Never tried**: a database whose collation is accent-insensitive for the index of the table of Triples (the column is binary, so it should not matter; not checked on another server version), two saves of the same name at the very same moment (the known limit above), the notice about `intl` on a PHP without it, a site with a large number of pages (the speed of `rebuild`), a multisite network.

## Not done

The content type and the screens (502: where the title and the space are stored, and the list that `rebuild()` takes), the address (503), the 404 (504); a lock for concurrent saves.
