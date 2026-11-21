<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 601 — Index: reading a page (the signals of a rendered page)

Status: **coded (2026-11-21), on `main`; see "What was built, and what differs from the plan" at the end.** Dependencies: slice 600 (the module, the resolver, the sources). Context: [`../design/index.md`](../design/index.md), "Reading the rendered page" and "What a real page contains". After the categories and the tags (600), Eric's order is **the links to Wikipedia and the names of birds**; the photographs are later.

## Goal

Turn **a string of HTML** (the page as WordPress renders it) into a list of **signals**: what the page says about what could be indexed. **A pure function**, tested **without a site and without a network**; fetching a page is another slice (602). Decided (Eric, 2026-11-21): the index reads the **rendered page**, because plugins and themes add what they want to a post (the WhoBIRD block and the GPX link of Eric's blog are **in the templates**, outside the contents of the post).

## What it does

`Index\Harvest\PageReader::signals( string $html, int $post_id = 0 ): Signal[]` and the value object `Signal` (type, value, anchor, evidence, scope). No fetching, no database, no WordPress except the HTML API of the core.

### The scopes (Proposed, from the three pages of the blog that were read)

- **The page**: everything inside the element `<main>` (a block theme; **to check** on a classic theme).
- **The contents of the post**: the element with the class `wp-block-post-content` (or `entry-content`). **The generic harvesters of links read only this region**, so that a footer, a menu or a widget "recent posts" is not taken for a mention.
- **Not read at all**: the elements `nav`, `header`, `footer`, `aside`, the comments (`#comments`), the blocks of lists (`wp-block-query`, `wp-block-latest-posts`: they repeat the contents of other posts), and anything under an element with **`data-index-ignore`**.

### The signals

| Type | Where it is read | What is found |
|---|---|---|
| `entry` | **every element with `data-index`** in the page (explicit markers are trusted everywhere) | the value (an identifier `wikidata:Q…`, `osm:node/…`, `geonames:…`, **or** a label; `A > B` for a sub-entry; `a \| b` for several) with `data-index-kind`, `data-index-label`, `data-index-sort`, `data-index-main` and the `id` of the element as the **anchor** |
| `wikipedia-link` | the contents of the post | `<a href>` to `*.wikipedia.org/wiki/<Title>` (and `index.php?title=`, mobile hosts): the **language** and the **title** from the address, decoded, the underscores as spaces, the fragment dropped; **the label comes from the address, never from the text of the link** (the only link of one post was the text "Wikipédia" to *Beaufort (fromage)*) |
| `wikidata-link` | the contents of the post | `<a href>` to `www.wikidata.org/wiki/Q…` |
| `osm-object` | the contents of the post | an address of an **object** (`/node/…`, `/way/…`, `/relation/…`); **the attribution link** of a map (`openstreetmap.org/copyright`) **is noise and is never a signal** |
| `gpx-file` | **all of `<main>`** (the plugin that shows the track is often in the template) | every address ending in `.gpx` in an attribute (`href`, `src`, `data-…`), whatever the folder; for `wp-gpx-maps` it is the link `#wpgpxmaps_downloadfile` |
| `image` | `<main>` | every `<img src>` and every `<a href>` that points to an image file (jpg, jpeg, png, heic, webp, tiff), **one address per photograph** (the original when a link names it, as the lightbox of NextGEN Gallery does, else the thumbnail); the raw address only: **mapping it to a file and reading its coordinates is for the photographs** (Media Helper) |

**Deliberately not signals**: the external links that are not Wikipedia, Wikidata or OpenStreetMap objects (a tourist site, a press article: **not a sure sign**, Eric), the text of the page (no full-text search here), and the tags and categories (they come from the database, slice 600).

### Which HTML parser (to decide by trying)

**Proposed**: the **HTML API of the core** (`WP_HTML_Processor`, a parser of the HTML5 algorithm, with the breadcrumbs of an element, so the scopes are exact) when it can read the page, and **`WP_HTML_Tag_Processor`** for the simple scans; **a fallback** to `DOMDocument` only if the first cannot read a page (it may stop on some markup: **to check** on the real pages). **Never a regular expression on the whole HTML** for the structure.

### A command to try it on the real pages

`wp otherguise index read <file-or-url> [--format=json|table]` prints the signals of a saved page or of a public address (an ordinary HTTP request, a timeout, **no job and no stored result**). **This is what Eric can try first on his pages** and what shows what the reader sees and what it misses. Not a replacement for slice 602.

## Tests (planned)

PHPUnit **without WordPress where possible** (the HTML API is the core's: the tests that need it load it, as the tests of the wiki links do with `OTHERGUISE_WP_CORE`). **Fixtures: excerpts of the three real pages** (the block of the GPX link, the block of the WhoBIRD observations with seven species and its Wikipedia links, the contents with their links, a few images of a grid, the NextGEN gallery with its lightbox links, the attribution of the map, the footer), **cut to a few kilobytes each** and kept in `tests/phpunit/fixtures/index/`. Cases: each type of signal and its scope; **a Wikipedia link outside the contents is ignored**; **an explicit marker outside the contents is kept**; the attribution link; a `data-index` with `|` and `>`; entities and percent-encoding in a title; a `.gpx` address in a `data-` attribute; a nested `data-index-ignore`; markup the parser cannot read (the fallback); a page of 250 KB read in **well under a second** (**a measure to report**).

## Verification (planned)

`index read` on the **three public pages** read for the design: *Étroubles ⇾ Beaufort* (one GPX link before the contents, 66 images, one Wikipedia link: the cheese), *Plateau de l'Hers* (the WhoBIRD block after the contents with seven species, three Wikipedia links in the contents), *Vers le Sud* (NextGEN: 76 images in links, a GPX link inside the contents). **Never tried on a page with a cache plugin or a firewall in front.**

## Not done

Fetching a page in the background (**602**: the loopback, the private link and the internal address of Books, the schedule); the convention **in WhoBIRD** (a few lines in its renderer, in its own repository: `data-index="wikidata:Q…"` on each `wpwbd-bird-entry`); the enrichers (a Wikipedia link to its Wikidata item and its kind); the photographs and the files; the marks `data-index` written in the editor.

## To check

Whether the theme of the blog always wraps the contents in `wp-block-post-content` (the three pages did); the HTML API on pages of 250 KB with scripts and styles in the body; whether **WordPress filters `data-` attributes** on saving for the roles with no `unfiltered_html` (it matters for the marks written by hand, not for what a plugin prints).

## What was built, and what differs from the plan (2026-11-21)

Built: `Index\Harvest\PageReader::signals( $html, $post_id )` and `Harvest\Signal` (type, value, scope, evidence, anchor, data), and the command `wp otherguise index read <file-or-address> [--format=table|json]` (an ordinary HTTP request with a timeout, or a local file; nothing is stored, no job).

What differs:

- **The parser is the tag processor of WordPress with a stack of open elements kept in the reader**, not `WP_HTML_Processor`: the HTML processor refuses some markup and is not in every version, while the tag processor never stops. The stack is tolerant (a closing tag pops down to its opening tag; an unclosed `<p>` or `<li>` does not leak out of its parent) and exact on the well-formed markup that WordPress prints. No `DOMDocument` fallback is needed. **A badly broken page** (a `<div>` never closed inside the contents) **would widen a scope**: not seen on the real pages.
- **Two kinds of exclusion**: `nav`, `header`, `footer`, `aside`, `#comments` and the lists of other posts are not read for **links, files and images**, but an explicit mark `data-index` is trusted there; only `data-index-ignore` hides everything below it.
- **The images are every `<img src>` and every link to an image file in `<main>`, the external ones included**: the Wikimedia thumbnails that the whoBIRD block shows are signals (page scope). Sorting what is a photograph of the blog is the work of the photographs slice, not of this reader. A link to a Wikipedia page whose title ends in `.jpg` is not an image.
- **A document with no `<main>` is read whole, and one with no contents region reads its links as the contents**: this is what makes a fragment (the `post_content` of a post) readable.
- **The names of birds are invisible without the whoBIRD patch**: its Wikipedia links are after the contents, so the reader ignores them on purpose. They come with `data-index="wikidata:Q…"` once that change is made in whoBIRD (not made: that repository is read-only here).
- A signal of `wikipedia-link` carries the language and the title from the address, never the text of the link.

### Verification

- **Under automated tests** (PHPUnit, 826 tests in the suite): every type of signal and its scope; a Wikipedia link outside the contents ignored; a mark outside the contents kept; the attribution link of a map; `|` and `>` in a mark; entities and percent-encoding; a `.gpx` address with a query string; a nested `data-index-ignore` and unclosed elements; a document of 250 KB read in well under a second; the command (table, JSON, errors). **Fixtures are excerpts cut from three public pages of the blog** (`tests/phpunit/fixtures/index/`: the GPX link and the attribution of `wp-gpx-maps`, the whoBIRD block, a NextGEN gallery) plus one handwritten file of marks. The tests are **skipped, not passed, without `OTHERGUISE_WP_CORE`** (a `wp-includes` directory with `class-wp-token-map.php`).
- **On the four real pages saved for the design** (on a scratch WordPress 7.1.4, with the real core, not the test loader): *Étroubles ⇾ Beaufort* 66 images, 1 GPX file, 1 Wikipedia link (175 KB, 11 ms); *Plateau de l'Hers* 97 images, 1 GPX file, 3 Wikipedia links; *A Miranda et Cap Ortegal* 150 images, 1 GPX file, 1 Wikipedia link; *Vers le Sud* (NextGEN) 77 images, 1 GPX file. **WP-CLI was not run** (the command class is tested with doubles); **no page behind a cache plugin or a firewall was tried**.
