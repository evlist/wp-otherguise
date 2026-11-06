<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 501 — Wiki: the names of the pages (rules, table, index)

Status: **proposed (2026-11-06), not coded**. Dependencies: slice 500 (the spaces). Context: [`../design/wiki.md`](../design/wiki.md): the level 3 (an address of our own), the identity of a page is the pair **(space, exact title)**.

## What it does

The **rules** that make a title a name, and the **index** that tells which page has a name. Pure logic and one table, no content type yet (502) and no address (503).

### The rules (`Wiki\Name`, no WordPress)

The starting point is MediaWiki's, **not its code**: the name of a page is its title after these steps, and two titles are the same name when they give the same result.

1. Unicode **NFC** (an accent written as one letter or as a letter and a combining mark is the same title). Needs the PHP extension `intl` (`Normalizer`); without it the step is skipped and the settings screen says so (**to look at**: how often `intl` is missing).
2. **A space and an underscore are the same**: runs of whitespace and underscores become one space; the ends are trimmed.
3. **The first letter is in capitals** (`mb_strtoupper` of the first character); **nothing else is changed**: the case and the accents tell two titles apart.
4. **Refused**: an empty title, a title longer than 190 characters (the limit of an indexed column in `utf8mb4`), the characters `# ? % < > [ ] { } |` and the control characters, a title that starts with `/`, a title with `//` (a subpage has a name). The colon is allowed (only the first one of an address separates the space, slice 503).
5. **The key** of a name is the result in a binary form: the same string, compared byte for byte (the column has the collation `utf8mb4_bin`: **the collation of the database is not trusted**, see the design note). **The folded key** is the lower case without the accents (`remove_accents`), used **for the suggestions only**, never for an identity.

A slash in a title is a **subpage by convention** (`1958-1968/fr`), nothing more: there is no hierarchy in the data.

### The table `wiki_names` (`{prefix}wiki_names`, created by the module, version in the option `wiki_schema_version`, like the table of `triples`, slice 102)

| Column | |
|---|---|
| `id` | bigint, auto increment |
| `space` | varchar(60), the registered name of the space, binary |
| `name_key` | varchar(190), `utf8mb4_bin` |
| `title` | varchar(190), the title as it is shown |
| `folded` | varchar(190), `utf8mb4_unicode_ci` |
| `post_id` | bigint |
| `current` | tinyint: 1 for the current name of a page, 0 for a name it had (an **alias**: a redirect) |
| `created_at` | datetime (UTC) |

`UNIQUE ( space, name_key )`, `KEY ( post_id )`, `KEY ( space, folded )`. **One row is both the identity, the redirect and the history**: a rename adds a row and clears `current` on the old one; an import of the old addresses of the SelfPedia is rows with `current` at 0.

### The index (`Wiki\Names`, with the real database in the tests)

- `claim( $space, $title, $post_id )`: the name becomes the current one of the page; **refused** (an exception with the page that holds it) when another page has it as a current name or as an alias; the page's former current name becomes an alias.
- `resolve( $space, $title )`: `array( 'post' => id, 'current' => bool )` or null; the title is put through the rules first.
- `current_of( $post_id )`: the current name, `release( $post_id )`: forgets every name of a deleted page.
- `similar( $space, $title )`: the pages of the same space whose folded key is the same (a suggestion: "a similar page exists"); `elsewhere( $title, $except_space )`: the pages of the same title in the **other** spaces (the angles, slice 507).
- Everything inside a transaction (as `Statements::transaction`).

## Tests (planned)

The rules (each step; NFC with and without `intl`; the refused titles; the first letter in other scripts; a title that differs only by an accent is another name, the folded key is the same); the table (creation, the version, the unique key with the real statements, the collation: two titles that differ by an accent **both** fit); `claim` (free, taken, an alias, a rename), `resolve`, `similar`, `elsewhere`, `release`; uninstall removes the table and the option.

## Not done

The content type and the screens (502), the address (503), the 404 (504).

## Verification (planned)

PHPUnit with a real MariaDB (the collation `utf8mb4_bin` is checked on a column, not assumed); a scratch WordPress with `utf8mb4_unicode_520_ci` as the default collation of the tables. Never tried on a real site.
