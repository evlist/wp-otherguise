<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 503 — Wiki: the addresses and the routing

Status: **coded on the branch `wiki` (2026-11-06)**, not merged into `main`. Dependencies: slices 500, 501, 502. Context: [`../design/wiki.md`](../design/wiki.md) (the level 3). **What a scratch test showed (2026-11-06, a throw-away rule, nothing of this slice)**: see "What was checked".

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

## Coded on 2026-11-06 (branch `wiki`)

- `Address\Address::path( $space, $title )` (the form of the address: the underscore for the space, the slash kept, `rawurlencode` in upper case for the rest, but the colon, the parentheses, the comma, the apostrophe, `!`, `*` and `@` stay readable; the space name is encoded too) and `Address\Router` (the rule, the resolution, the redirects, the permalink), wired by `Module` on `init` (the rule, then a late `maybe_flush`), `query_vars`, `parse_request`, `redirect_canonical` (priority 5) and `post_type_link`.
- **The rule** `^([^/:]+):(.+?)/?$` => `index.php?wiki_space=$matches[1]&wiki_title=$matches[2]`, **at the top**. It is flushed **when the module is activated**, **once after an update** (the option `wiki_rules` holds the version of the rule, `Router::VERSION`) and, **since the first test on `sb.vli.st`**, **whenever the rules that WordPress keeps do not hold it** while the site has pretty permalinks (at most once a minute). **Why**: on `sb.vli.st` the pages were found by `?post_type=wiki_page&p=<id>` and the permalink was right, but `/Encyclopédie:Accueil` was a 404 while the other pretty addresses worked: the version of the rule was already recorded as flushed, but the rules stored by WordPress did not hold it (a flush made while the permalinks were plain keeps no rule at all, and a version recorded then is not a proof). The cause on that site is **inferred, not seen**; the check of the stored rules makes the module repair itself.
- **The resolution** on `parse_request`: an **unknown space** is an ordinary 404 (and not ours: the redirect of WordPress is left alone); a **current name** makes the main query ask for the page (`post_type` `wiki_page`, `p`), so that the templates, the modes and the variants work as for any post; an **alias** is a **301** to the current address; a **name that nobody has** is a **404 that keeps** `wiki_space` (as registered) and `wiki_title` (as the rules make it) as query variables, for slice 504.
- **The canonical form is ours**: for a request that is ours the `redirect_canonical` of WordPress is turned off, and a **301** (with the query string kept) is sent when the path received is not exactly the canonical one: another case of the space or of the first letter, a space or a lower-case digit in the encoding, a character that was not encoded, a trailing slash. The path is the one the server received (read as `add_query_arg( array() )` does), not the decoded one.
- **The permalink** (`post_type_link`) is the address for a page that has a current name, **when the site has pretty permalinks**; a page with no name, or a site with plain permalinks, keeps `?post_type=wiki_page&p=<id>`.
- **One more rule of the names**: a title with a part that is `.` or `..` (`Paris/../fr`) is refused, because a browser would rewrite the address (slice 501).
- **Not done**: the **prefix** (the module is at the root of the site; the question stays **open**), `?post_type=wiki_page&p=<id>` is **not** redirected to the address (WordPress does not do it for this type; the page is shown as it is), the unlisted flag of `access`, `ModeUrl` checked only through the query string kept on a redirect.

## Tests

`WikiAddressTest` (2: the form of an address, the readable signs), `WikiRouterDbTest` (9, with the real statements: a current name, the same title in another space, every non-canonical form redirected with the query kept, an alias, an unknown name and an unknown space, another request left alone, the permalink, the rule and its flush), `WikiModuleTest` (the hooks, the activation) and `WikiNameTest` (the dots). The whole suite passes with the database (746 tests, none skipped), `phpcs` is clean.

## Verification

- **Tried** on the scratch WordPress 7.1.x with pretty permalinks (`/%postname%/`) behind the built-in server of PHP, with real pages and `curl` (statuses and `Location`): `/Biography:Paris` 200 with its content and the body classes of a single post and of the mode; `/biography:Paris`, `/Biography:paris`, `/Biography:Paris/` 301 to the canonical address; the query string kept (`?mode=print`); the alias `/Biography:Lut%C3%A8ce` 301 to `/Biography:Paris` after a **rename**; a title with an accent, in lower-case hexadecimal and written unencoded: 301 to the canonical form; a title with a slash (`Biography:1958-1968/fr`) 200 and with a trailing slash 301; the same title in another space; a title with colons and parentheses (`Encyclopédie:Rue:_Edgar_Faure_(Paris)`); an unknown name and an unknown space 404; a **draft** 404 for a visitor; the ordinary addresses (`/hello-world`, `/`, `/feed/`, `/wp-json/`, `/about-nothing`) behave as before, **including the redirect of WordPress to `/hello-world/`**; **the sitemap of the pages lists the addresses of the module** (and not the draft). With plain permalinks the pages keep `?post_type=wiki_page&p=<id>`.
- **Reproduced locally** (not on `sb.vli.st`): the rule removed from the stored rules with the version marked as flushed gave a 404; with the check of the stored rules the first request flushes and answers 200, and the rule is stored again.
- **Never tried**: Apache and nginx (`sb.vli.st` runs nginx: its pretty addresses of posts and pages work, ours did not before the repair) (the rule only needs the front controller, as every pretty permalink), a site in a subdirectory, a proxy or a CDN with a colon in the path, the SEO plugins, a page of the trash or a private page for a logged-in user, the performance of the permalink on a long list (one lookup per page), a theme that shows the 404 with a form to create the page (slice 504).

## Not done

The page that offers to create the page (504); the link by name (505).
