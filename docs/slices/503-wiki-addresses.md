<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 503 — Wiki: the addresses and the routing

Status: **reworked for the hierarchical model, coded on the branch `wiki` (2026-11-07)**, not merged into `main`. The first version (rule `Space:Title`, `Address\Router`) is kept at the tag `wiki-model-1`; its classes are still in the tree but **unwired**. Dependencies: slices 500, 501, 502. Context: [`../design/wiki.md`](../design/wiki.md), "The routing".

## What it does

The **address of a page is the chain of the exact titles of its ancestors and of the page**: `/Biography/Sant_Llorenç_de_Morunys`. **No rewrite rule of its own.**

- **The form** (`Tree\Address::path()`): underscore for the space, every other character percent-encoded in UTF-8 in **upper case** but `: ( ) , ' ! * @` which stay readable, **no trailing slash**, no prefix. `Address::titles()` does the opposite and decodes a segment only when it still holds a `%XX` (nginx hands the path raw, the built-in server of PHP decoded; a title never holds a percent sign).
- **The routing** (`Tree\Router`, action `parse_request`): the module acts **only** when WordPress produced `pagename`, `name`, `attachment` or the error 404, and none of `feed`, `embed`, `trackback`, `s`, `p`, `page_id`, `attachment_id`, `preview`. The specific routes of WordPress (feeds, search, archives, `/wp-json/`) therefore always win. The path is walked **one level at a time** through the index (`Keys::resolve_path`).
  - **Found at its canonical address**: served as it is (`page_id`), with no redirect.
  - **Found through an alias, with a trailing slash, with a lower-case encoding**: **301** to the canonical address, query string kept.
  - **Not found, and the first title exists** (the path enters the tree): a **404**, WordPress is not asked for a slug; `Router::missing()` returns `{deepest, rest}` for slice 504.
  - **Not found, and WordPress would find a page of the tree by its slug** (`/biography/paris/`, a title in another case): the same 404. **There is no slug door.**
  - **Not found and not in the tree** (`/hello-world/`, an unknown path): left to WordPress, untouched.
- **`redirect_canonical`** (priority 5): turned off for the requests that are ours (otherwise WordPress adds a slash and we remove it: a loop); and, for the others, a redirect that would land on a page of the tree is dropped (WordPress guesses a 404 from the last slug: `/Nowhere/Paris` went to `/Biography/Paris`; it has no filter of its own).
- **`page_link`**: the permalink is the address, with pretty permalinks. The static front page keeps `/` and is served at its own address too. A page with no key keeps WordPress's link.
- **Reserved titles of the first level** (`Tree\Reserved`, given to `Keys`): refused when the first rewrite rule that matches the address is not one of those that produce `pagename`, `name` or `attachment`; or it starts with `wp-`; or it is in a short list (`index.php`, `xmlrpc.php`, `robots.txt`, `favicon.ico`, `readme.html`, `license.txt`, `sitemap.xml`); or a file or directory of that name exists at the root of the site. `feed` and `wp-admin` are refused, `Feed`, `category` (a one-segment `/category` is an ordinary page for WordPress) and `Accueil` are not.
- **The first model is unwired**: no rule `Space:Title` any more, no `query_vars`/`post_type_link` hooks. One flush (option `wiki_rules` at version `2`, on activation or the first request after the update) drops the stored rule. The addresses `Space:Title` of the first model stop working, as does their 404 page, until slice 504 is reworked.

## Tests

`WikiTreeAddressTest` (form, decoding, reserved titles with and without rules), `WikiTreeRouterDbTest` (real statements: depth, same title in two branches, nginx-style encoded request, alias and other forms redirected with the query kept, wrong case, slug door closed, other routes untouched, unknown path left alone, the guess, permalink, front page), `WikiModuleTest` (hooks). Suite OK (822 tests), `phpcs` clean.

## Verification

- **Tried** on the scratch WordPress (PHP built-in server, `/%postname%/`): `/Biography`, `/Biography/Paris`, `/Biography/Paris/Rue` 200 (body class and status of slice 500, `rel="canonical"` is our address); trailing slash 301; `/Biography/paris` and `/biography/paris/` 404; an accent in upper- and lower-case hexadecimal (200 / 301); a **rename** (the old address 301 to the new one, descendants included); `/Nowhere/Paris` 404 (was 301 before the fix); `/hello-world`, `/feed/`, `/wp-json/`, `/sample-page/` as before; the static front page at `/` and `/Accueil`; `get_permalink()`; the **sitemap of the core lists our addresses**; the creation of `feed` and `wp-admin` at the first level refused through `Tree\Pages::create`.
- **Never tried**: nginx (the absence of `PATH_INFO` is covered by a unit test and by the earlier first-model finding, not by a request); Apache; a permalink structure with a date; a site in a subdirectory; a page of the trash or a private page for a logged-in user; `ModeUrl` with these addresses (only the query string kept on a redirect); the performance (one lookup per level, nothing cached); the page list of the Site Editor; the blog instance.

## Not done

The page that offers to create (504); the map of the addresses cache (open); the links by title (505).
