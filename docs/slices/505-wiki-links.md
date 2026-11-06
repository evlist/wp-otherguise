<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 505 — Wiki: the links (ordinary links by address), the red links, the move of a page, the rename of a space

Status: **proposed (2026-11-06; rewritten the same day, see "What changed"), not coded**. Dependencies: slices 501 (the names), 502 (the pages), 503 (the addresses), 504 (a red link leads to its offer). Context: [`../design/wiki.md`](../design/wiki.md).

## What changed from the first version of this slice

The first version was a **shortcode** (`[page "Space:Title"]`, then the double bracket of MediaWiki, then a one-letter tag). **Eric's remark of 2026-11-06:** the pages are edited with **Gutenberg**, where typing a syntax is far from the easiest way to make a link; the native tool (`Ctrl+K`, with its search) is. With the level 3 **the address is the name** (`/Biography:Sant_Llorenç_de_Morunys`), so **an ordinary link to that address is a link by name**, and a rename or a move leaves the old address as an alias that redirects. **There is no shortcode and no syntax any more**, and the problem of the unqualified names disappears: an address always has its space.

## What it does

### The address, read (`Wiki\Address`, no WordPress, no database)

- `Address::name_of( $href )`: the qualified name that an address stands for, or null when it is not an address of the wiki. Steps: a **relative or absolute** address; the host is the one of `home_url()` (a relative one has none); the path, less the **prefix** of the module if there is one (slice 503); the **first segment** must have a colon and its first word be a **registered space** (without regard to the case); the rest is decoded, put through the **rules of slice 501** (underscore, first letter, NFC...). The reverse, `Address::of( $name )`, is the builder of slice 503.
- **No address is stored.** The address is a **pure function of the name** (and the name of the address): a stored URL would have to be updated at every rename and every change of the host or the prefix, would be longer than the 191 bytes of a statement (percent-encoding makes an accent six characters), and **would not spare the normalisation** (a link typed by hand can be written with a space, a lower-case first letter, a lower-case hexadecimal digit or a trailing slash). What the frequent test costs is **the lookup**, not the comparison: see "The cost".

### What the render does (a filter on `the_content`, `WP_HTML_Tag_Processor`)

For every `<a href>` whose address stands for a name of the wiki (`Address::name_of()`):

- a page with that **current name** that the reader may see: the class `wiki-link` is added;
- an **alias**: the `href` is **replaced by the current address** (no redirect to follow), and the class `wiki-link` is added;
- **no such page** (or a page that the reader may not see: a draft or a private page is not told apart from a page that does not exist): the classes `wiki-link wiki-missing` and a `title` "This page does not exist"; the link keeps its address, which **leads to the offer of slice 504**.

A link that is not an address of the wiki is **not touched**. The filter runs on every post type (a home page of the wiki is not a `wiki_page`).

### The cost: the test is made very often

- `name_of()` is string work: microseconds. The lookup is **one indexed query per distinct name** (`Names::resolve()`, slice 501).
- A **cache of the request** (a name is looked up once) and a **persistent cache** (the object cache, group `wiki_names`: name => page, or "none") whose key carries **a version number** that every claim and release of a name bumps (slice 501), so that the invalidation is exact. A page of two hundred links is two hundred lookups the first time it is shown.
- **To measure, not to write before it is needed**: a batch lookup (`resolve_many()`), if the first view of a page with many links is slow.

### The editor

- **The native link tool finds the pages**: the search of the REST API that it uses lists the `wiki_page` posts with their address as `url` and **ignores the accents** ("Llorenc" finds "Sant Llorenç de Morunys"): **checked on 2026-11-06** on a scratch WordPress with an address filter like the one of slice 503.
- **The results do not tell the spaces apart**: two pages "Paris" in two spaces both show as "Paris" (checked). The title of a result of a `wiki_page` gets its space (`Paris — Biography`): **to find the hook** that lets a plugin change the title of a result of `/wp/v2/search` (the title is `get_the_title()` in the handler: the filter `the_title` during that request is a way, not checked in this case).
- **A link to a page that does not exist yet** is typed by hand in the field of the address (`/Biography:Paris`): it is a red link. The "create" of the native tool makes a page of the type `page`, not of ours: **to check** whether it can be changed.

### Move a page to another space

`wp otherguise wiki move <post> --space=<name> [--title=<title>] [--dry-run]`: claims the name in the target space (**refused** if a page of that name is there: the move does not rename in silence, which WordPress would), the former name becomes an alias (the old address **redirects**, the links written with it **still work** and are shown with the current address), the meta `wiki_space` changes (the only way to change it, slice 502). Can be run twice. A row action on the list of the pages calls the same service.

### Rename a space

`wp otherguise wiki rename-space <old> <new> [--dry-run]`: for every page of the space the name is claimed in the new space with the old one as an alias, then the record of the space (slice 500) changes its name; **refused** if the new name is taken; one transaction per page and a report, run again after a failure it finishes the work.

### Rewrite the stored links (optional)

`wp otherguise wiki relink [--dry-run]`: replaces, in the content of every post, the address of an alias by the current address (what the render does on the fly), so that a stored link stops going through a redirect. Idempotent, with a report; each change makes a revision of the post (as any update).

## An improvement for later (Eric, 2026-11-06; optional)

A **tool to insert a link that is aimed at the wiki**, instead of the generic `Ctrl+K`: a **format of the text** in the toolbar (a button and a key) with a search by name over all the spaces (**labelled by space**), the **state of each result** (exists, or missing), the option to **create the missing page**, and the canonical address inserted. Worth doing if the native tool proves too generic (the spaces not shown, no way to create from it). Not designed.

## Tests (planned)

`Address` (relative and absolute addresses, a prefix, another host, a space in another case, a title with a colon, a slash, an accent, a percent-encoding in lower case, a trailing slash, an address that is not the wiki's, a space that does not exist); the filter (a current name, an alias rewritten, a missing page, a page the reader cannot see, a link that is not the wiki's untouched, no change to the rest of the content); the caches (a request, the persistent one, the invalidation by a claim, a release); `move` (free target, taken target, the alias, the old address redirects, run twice, `--dry-run`); `rename-space` (the aliases of every page, a failure in the middle and a second run); `relink` (an alias replaced, run twice, `--dry-run`).

## Not done

The incoming links (506); the batch lookup; the tool for the editor above; the search result with its space and the creation from the native tool (to find out how).
