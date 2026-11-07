<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 508 — Wiki: the migration from the SelfPedia (a throw-away script outside the plugin)

Status: **rewritten for the hierarchical model and coded (2026-11-07)**; the script lives in [`evlist/shared-room`](https://github.com/evlist/shared-room), `tools/wiki-migration/` (branch `main`, decided by Eric), **not in this repository**. Dependencies: slices 501 to 503 (the public API `Tree\Pages::create()` and `find()`, the titles, the addresses). Context: [`../design/wiki.md`](../design/wiki.md).

## What the plugin gives, and nothing more

The service **`Tree\Pages`** (`create( $parent, $title, $html, $status )`, `find( $titles )`, slice 502) and the commands of slices 502 and 505. **No command that imports, no function that knows MediaWiki**: the foreign content is converted outside, only the writes go through the public API. (The rule of `CLAUDE.md` about migrations as WP-CLI commands is read as aimed at migrations of the production blog; Eric can decide otherwise.)

## The tool (`tools/wiki-migration/` of `shared-room`)

`wp eval-file tools/wiki-migration/migrate.php --json=<dir|file> [--dry-run] [--check] [--status=draft|publish] [--pandoc=<cmd>]`. Read its `README.md`.

- **Input**: the JSON answers of the MediaWiki API fetched **in bulk by page id** (`generator=allpages&gapnamespace=0&prop=revisions&rvprop=content&rvslots=main`, a file per batch). **The trap** (seen on the real wiki, 2026-11-06): the namespace `Biography` was declared **after** its pages, which are still in the main namespace under the literal title `Biography:...`; fetching by title gives empty pages and the API refuses `Biography:` as a prefix. Nothing in the script repairs the wiki.
- **Where the pages go** (**decided by Eric, 2026-11-07**, `config.php`): `Biography:X/fr` → `/Biographie/X` (French names, the wiki no longer being bilingual); `WikiVlist:Y/fr` → `/WikiVlist/Y`; the pages of the main namespace, which were the encyclopaedic pages, → `/Encyclopédie/Z`. The suffix `/fr` goes with **no alias**; a slash that is left becomes `-`; a space after the colon is trimmed. The first-level pages are created **as empty drafts** if missing, **with no rules of a branch** (set by hand).
- **Not migrated** (reported): the `/en` pages (language variants), the redirects, the root pages (only `{{Langues}}` and categories), `WikiVlist:I18n Hack`.
- **Templates** (before Pandoc, which drops a template without a word): `{{Langues}}` removed **only alone on its line** (its display titles are reported and ignored; inside running text it is an error), `{{Source privée|date}}` → `<small class="source-privee">…</small>`, `{{Infobox Personnalité}}` **removed** (decided by Eric, 2026-11-07: the new site has no infobox) with its parameters listed in the report to be rebuilt by hand, **any other template an error naming it**; a page with an error is **not made**.
- **Other things**: categories removed, `[[:Category:x|label]]` made plain text (reported), `<references />` removed, interwiki `wikipedia:fr:Title` → `https://fr.wikipedia.org/wiki/Title` (an unknown prefix is an error), links rewritten to the new addresses (Pandoc marks the internal ones `title="wikilink"`), a link without a label loses its `/fr`, a link to a language variant points at the French page and is reported.
- **Properties asked by the rule**: it can be run twice (a page whose place exists is skipped), `--dry-run` writes nothing, a **report** (created, already there, skipped with the reason, notes, errors by page), `--check` (every page resolves through `find()`).

## Tests and verification

- **Tests** (`tests/wiki-migration/`, PHPUnit without WordPress, 9): the places, what is skipped, the address, a link target, redirects and roots, the templates (including the selector in running text, an unbalanced brace), categories, interwiki, the links of the HTML, and one page through the real Pandoc 3.1.3.
- **Tried** on the scratch WordPress with **a sample of eleven pages written by Claude** (not pages of the SelfPedia): dry run, real run (roots as drafts, five pages made as `publish`), second run (all "exists"), `--check` (5 of 5), the rendered page (the links became `wiki-link`, a missing one a red link, the private source rendered); an unknown template and a duplicate place were reported as errors.
- **Never tried**: on the real wiki, on any real page (the templates, `<syntaxhighlight>`, definition lists, tables, a malformed `<nowiki>` in a `<ref>`, the quotations are **not known**); the notes as the **Footnotes block** of WordPress (the script writes the plain list that Pandoc produces; the block keeps its notes in a post meta next to markers, to verify on `sb.vli.st` before changing the script); the images (the media panel's business); the volume; the status of the page made when the user is not the administrator.

## The procedure (by hand)

1. Fetch the JSON of the wiki (by page id), keep the files.
2. `--dry-run`; read the report; fix what is reported (a template to add to the table, a page to rebuild by hand); repeat.
3. Run for real on a copy of the site, then `--check`; look at the pages (what Pandoc loses is **written down**).
4. The media: the panel of Media Helper inserts the photos from Nextcloud (not part of this slice).

## What is not the module's business

The redirect from the **old host** (`wiki.vli.st`, with its `/wiki/Title` and `index.php?title=` forms, and the `/fr` to strip) to the new site is a rule of the old server or of its front proxy. Whether the new wiki keeps the old host is **open**.

## Not done

The history of revisions; the categories of MediaWiki; the display title carried by `{{Langues}}` (a feature of its own, a meta); a Markdown conversion.
