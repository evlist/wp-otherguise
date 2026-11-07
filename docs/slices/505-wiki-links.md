<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 505 — Wiki: the links, the red links, the move of a page

Status: **rewritten for the hierarchical model and coded on the branch `wiki` (2026-11-07)**, not merged into `main`. Dependencies: slices 501 to 504 (a red link leads to the offer of 504). Context: [`../design/wiki.md`](../design/wiki.md), "Linking a page by its name". The earlier versions (a shortcode, then the address `Space:Title`, with `rename-space`) are superseded: there are no spaces any more.

## What it does

**No syntax, no stored address.** The pages are edited with Gutenberg; the native link tool (`Ctrl+K`) makes the links, and an ordinary link to the address of a page **is** a link by name (the address is the chain of the titles).

### The render (`Tree\Links`, filter `the_content`, priority 20, `WP_HTML_Tag_Processor`)

`titles_of( $href )` turns an `href` into titles: a path that starts with `/`, on the host of the site (relative or absolute; http/https only; the path of `home_url()` stripped); the query and the fragment are ignored. Then, for each `<a href>`:

- **a page the reader may see at its current address**: class `wiki-link`;
- **an alias** (the page or an ancestor was renamed or moved): the `href` is **replaced by the current address** (query and fragment kept) and `wiki-link` is added: no redirect to follow;
- **a path that enters the tree but stops**, or a **page the reader may not see** (not told apart from one that does not exist): classes `wiki-link wiki-missing` and `title="This page does not exist"`; the address stays and leads to the offer of slice 504 (a red link);
- **anything else is untouched**: another host, `mailto:`, a fragment, a post, and **a path whose first title is unknown** (a first-level page that does not exist yet cannot be told from a broken link of the site) **unless** that title is a first-level title written another way (an accent, a case), which is a red link.

A path is looked up **once per request** (a cache in the object). Nothing is stored.

### The editor

`Tree\SearchTitles`: the link tool lists the pages through `/wp/v2/search`, where two pages of one title in two branches both read "Paris". Core has no filter on the title of a result: the module hooks `the_title`, active **only between `rest_post_search_query` and `rest_post_dispatch`**, and gives `Paris — Opinion` (`Rue — Paris / Biography`: the ancestors, nearest first). First-level pages are unchanged.

### Move and rename a page

`Pages::move( $id, $parent, $title = null, $apply = true )` and `wp otherguise tree move <id> [--parent=<id>|--path=<titles>] [--title=<title>] [--dry-run]`: only the key of the page changes, the **old key becomes an alias** (so the old address of the page and of its descendants redirects, and links written with it are rewritten at render). **Refused, nothing written**: a title taken under the new parent, an unknown parent, the page or one of its descendants as the parent. Can be run twice (nothing to do). A rename is a move with a title (editing the title already does it, slice 502).

## Tests

`WikiTreeLinksDbTest` (5, real statements): the address read (relative, absolute, other host, `mailto:`, fragment, accent, prefix); the state of a path (page, missing, wrong case, other, alias after a move, hidden page); the render (class, alias rewritten with query and fragment, red link, untouched links); the move (alias, descendants, run twice, the three refusals, dry run); the titles of the search. The render test loads the tag processor from the files of WordPress named by `OTHERGUISE_WP_CORE` (skipped without it) with two stand-in functions in `tests/phpunit/support/`. Suite OK (827), `phpcs` clean.

## Verification

- **Tried** on the scratch WordPress: a post with five links: the current address got `wiki-link`; the **old address was rewritten** to the current one (absolute); a missing page under `Biography` got `wiki-link wiki-missing` and its `title`; a post link and another host were left alone. `/wp/v2/search` returned `Lutèce — Biography`. A move of a page with a child to `Opinion` (renamed): the old address of the child 301 to the new one, a move under its own child was refused.
- **Never tried**: the link tool in the browser itself (only the REST answer); a link typed in the block editor; the `the_content` filter on excerpts, widgets or feeds; many links on one page (no persistent cache: one lookup per distinct path per request, **not measured**); a prefix (unit test only); `wp otherguise tree move` from a real WP-CLI (the service was called, not the command); the blog instance.

## Not done

- A persistent cache with a version number (only when measuring shows it is needed) and a batch lookup.
- `relink` (rewriting the stored links): the render already does it on the fly.
- The tool to insert a link aimed at the wiki (search by name, state of each result, creation of a missing page): not designed; the creation of a page from the native tool (it makes a `page` with no parent) is not checked.
- Incoming links and wanted pages (506).
