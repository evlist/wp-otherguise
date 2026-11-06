<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 508 — Wiki: the old addresses of the SelfPedia (a semi-manual migration)

Status: **proposed (2026-11-06), not coded**. Dependencies: slices 501 (aliases are `wiki/former-name`), 502 (`wiki create --file`), 503 (the redirects). Context: [`../design/wiki.md`](../design/wiki.md). **Eric, 2026-11-06: the wiki has few pages, the migration can be semi-manual and stays as simple as possible**: there is no import tool in this slice, only what keeps the old addresses working.

## What it does

### A command that makes the aliases from a file

`wp otherguise wiki alias <file> [--dry-run]`: the file has **one line per old address**, two columns separated by a tab (or a comma), `old<TAB>new`:

- `old`: the **qualified name of the old page** as MediaWiki wrote it (`Biography:1958-1968/fr`, with underscores or spaces), put through the rules of slice 501;
- `new`: the **qualified name of a page that exists** here, or its id.

Each line adds `old` as a **`wiki/former-name` of the page** (slice 501): `/Biography:1958-1968/fr` then redirects (a 301) to the current address (slice 503). **Can be run twice** (a line already done is reported as such), `--dry-run` writes nothing, and the report says:

- how many were **done**, **already there**, **already the current address** (an old address that is the same as the new one needs no alias: the same title in the same space is the same address, which is the point of the level 3);
- the **errors**, by line: an `old` that the rules refuse, a `new` that does not exist, an `old` held by **another** page (as a current name or as an alias).

`--check` reads the file again and checks that **every `old` resolves** (`Names::resolve()`), for the end of the migration.

### The links of an imported page (a function of this slice, used by `wiki create --file`, slice 502)

`Address::from_mediawiki( $href )`: the links of the content that comes from MediaWiki (Pandoc keeps them as relative addresses: `Biography:1958-1968/fr`, `/wiki/Biography:1958-1968/fr`, `../Biography:Paris`, `index.php?title=Biography:Paris`) become the **addresses of this site** (`/Biography:1958-1968/fr`, the builder of slice 503), so that they are the links of slice 505 from the first day; the links with the class `new` or an `action=edit&redlink=1` (a page that did not exist) become ordinary links to the address, which is **a red link** here until the page is made. A link to another site is left alone. The rule of the **old host** is the one of "What is not the module's business" below.

### The procedure (written here, done by hand)

1. List the pages of the SelfPedia worth keeping (MediaWiki: *Special:AllPages*, by hand).
2. For each: **convert** it (Pandoc, from the export of the wiki, or from the page itself) to HTML or Markdown; check it by eye (the quotations, the notes, the categories: what is lost is to be written down); **create** the page (its links are rewritten by `Address::from_mediawiki()`): `wp otherguise wiki create --space=<name> --title=<title> --file=<file>` (slice 502). Pages that keep their title in the same space have the same address: nothing more to do.
3. **The media**: the panel of Media Helper inserts the photos from Nextcloud (not part of this slice).
4. A file of the pages that **changed their name or their space**, the command above, then `--check`.

### What is not the module's business

The redirect **from the old host** (`wiki.vli.st`, a MediaWiki that has its own address form: `/wiki/Title`, `index.php?title=`...) **to the new site** is a rule of the old server or of its front proxy, written once; the module only answers the addresses of the form `/<Space>:<Title>`. Whether the new wiki keeps the old host is **open** (Eric).

## Tests (planned)

The reading of the file (tab or comma, blank lines, a comment line, an `old` with underscores or spaces, an id or a name for `new`); `Address::from_mediawiki()` (each form above, a red link of MediaWiki, a link to another site); the aliases (done, again, the same address, a refused `old`, a `new` that does not exist, an `old` held by another page); `--dry-run` writes nothing; `--check`; a run twice.

## Not done

Any import of content (Pandoc stays by hand: few pages); the categories of MediaWiki (a taxonomy of the content, a subject of its own); the history of the revisions.
