<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 503 — Wiki: the addresses and the routing

Status: **proposed (2026-11-06), not coded**. Dependencies: slices 500, 501, 502. Context: [`../design/wiki.md`](../design/wiki.md) (the level 3). **What a scratch test showed (2026-11-06, a throw-away rule, nothing of this slice)**: see "What was checked".

## What it does

The **address of a page is its space and its exact title**: `https://site/Biography:Sant_Llorenç_de_Morunys` (and `/Biography:1958-1968/fr`), the form of MediaWiki. The module resolves it itself; WordPress is not asked to find a slug.

### The address

- `<Space>:<Title>`, the space as registered (slice 500), the title as shown (slice 501) with **the underscore for the space**, the slash kept, every other character percent-encoded in UTF-8 (`rawurlencode` of each part, **upper-case** hexadecimal digits), **no trailing slash**. The first colon of the path separates the space; the others belong to the title.
- Where the module is not at the root of the site: **a prefix**, a setting of the module (empty by default: **the root is safe**, since no slug of WordPress has a colon, a path whose first segment has one is the module's or nobody's). **Open** (Eric): root or prefix.
- `get_permalink()`, `get_post_permalink()`, the sitemaps and the SEO plugins read the address through the filter `post_type_link`: the module gives the form above for a `wiki_page` that has a current name; a page with none (a draft that was never named) keeps the plain address `?p=<id>`. **To check**: that nothing reads `post_name`.
- `ModeUrl::build()` (Modes) adds `?mode=print` to such an address as to any other: **to check**.

### The routing

- **One rewrite rule, at the top**: `^([^/:]+):(.+?)/?$` => `index.php?wiki_space=$matches[1]&wiki_title=$matches[2]`; the two query variables are registered (`query_vars`); the rules are flushed when the module is activated and when the prefix changes (and once after an update: the module keeps a version of its rules in an option).
- **On `parse_request`** the request is resolved:
  1. the space is looked up without regard to the case (`Spaces::find()`); an unknown space **is not ours**: the request goes on as the 404 it is;
  2. the title is put through the rules of slice 501 and looked up (`Names::resolve()`);
  3. a **current name**: the main query is made to ask for that page (`post_type` `wiki_page`, `p`), so that the template hierarchy, the modes and the variants work as for any post;
  4. an **alias**: a **301** to the current address of the page;
  5. **no such name**: a **404** that keeps what was asked (the space and the exact title) for the page that offers to create it (slice 504).
- **The canonical form is ours**: WordPress's own `redirect_canonical` is **turned off** for these requests (it adds a trailing slash and writes the percent-encoding in lower case: see below), and the module sends a **301** when the path asked for is not the canonical one of the page (another case of the first letter, a space instead of an underscore, a lower-case hexadecimal digit, a space name in another case, a trailing slash).
- **A page that cannot be seen** (a draft, a private page) follows the usual rules of WordPress for the main query; **the unlisted flag** of the module `access` (slice 402) is not looked at here.
- No query is made for a request that does not match the rule: the cost is that of one regular expression on every request.

### What was checked (2026-11-06)

A scratch WordPress 7.1.3 with pretty permalinks (`/%postname%/`) behind the built-in server of PHP with a router, and a **throw-away `mu-plugin`** (removed afterwards) that registered that rule and printed what it received:

- the rule reaches `wiki_space` and `wiki_title` for `/Biography:Sant_Llorenç_de_Morunys`, for `/Biography:Sant_Llorenç_de_Morunys/fr`, for `/Biography:1958-1968/fr/` and for the **percent-encoded** form: **the values arrive decoded** (UTF-8), the slash stays in the title;
- **`redirect_canonical` answers a 301** for these requests, to a form **with a trailing slash and the hexadecimal digits in lower case** (`Sant_Lloren%c3%a7_de_Morunys/`): it must be turned off for them;
- the rule matches **any** `word:word` (`/biography:sant-llorenc` too): the space has to be checked by the handler, and an unknown space is an ordinary 404 (the path `/about-nothing` is a 404 as usual).

**Not checked**: loading the page from the main query and its template; `get_permalink()` through the filter; the sitemaps and the SEO plugins; `ModeUrl`; a real server (Apache, nginx: the rule only needs the front controller, as for every pretty permalink); the behaviour of a proxy or a CDN with a colon in the path (the colon is valid in a path segment, RFC 3986, except in the **first** segment of a relative reference: the module only writes absolute addresses).

## Tests (planned)

The builder of the address (the encoding, the underscore, the first letter, the prefix, a title with a colon, a slash, an accent, a character to encode) and its inverse; the filter `post_type_link`; the resolution (a current name, an alias, an unknown name, an unknown space, a space in another case, a title with a space or a lower-case first letter): each answer, with the real database; the canonical redirects (301 and its target); `redirect_canonical` off; the rewrite rule and its flush. A Chromium run on a scratch WordPress with pretty permalinks (the router of the spike): the pages, a redirect, a 404.

## Not done

The page that offers to create the page (504); the link by name (505).
