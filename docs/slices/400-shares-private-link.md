<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 400 — Access: a private link with a secret token

Status: **coded (2026-10-25)**, see "Verified" for what ran and what did not. Dependencies: none: a new module, **`Access`** (decided by Eric on 2026-10-25; this slice was planned under the name `Shares`, and the file keeps its name), with no dependency on the other modules (checked by `ArchitectureTest`). It is to be used by Books (the PDF of posts that are not published) through the function `access_url()`; that use is **not** in this slice. Reasoning: [`../design/books.md`](../design/books.md) ("Previewing and converting unpublished posts", "Roles, a loop block, unlisted posts…").

## Goal

Let someone who is not logged in see a post that is **not public** (draft, pending, private, future) through a link with a **secret random token**, as Nextcloud shares a file. Two uses, decided with Eric on 2026-10-23:

1. **The PDF conversion** of a post being prepared: Gotenberg fetches the page as an anonymous visitor and WordPress shows a draft only to a user who may edit it.
2. **Sharing** a private page, outside the PDF, for example to have a section proofread before it is published.

Eric's judgement of the risk: the pages concerned are **of little sensitivity** (the worst that can happen is that someone sees a section before it is finished), so a token that stays valid until it is revoked is acceptable. This is a link that gives **read access**; the precautions below limit the damage of a leak, they do not make it impossible.

## What it does

1. **One token per post**, created on demand: 16 random bytes (`random_bytes`) written in the URL-safe form of base64 (22 characters, 128 bits). Kept **in clear** in two protected post metas, `_access_token` and `_access_token_created` (the date in GMT), because a shared link has to be shown again. A new token replaces the old one (**regenerate**: the old link stops working at once; a new draw is made if it gave the same bytes), and **revoke** deletes both metas.
2. **The link identifies the post by its token** (Eric, 2026-10-23): the address of the **site** with the query argument `shared` and, optionally, the argument `mode` of the Modes module: `https://site/?shared=<token>&mode=print`. No permalink and no `?p=<id>`. `access_url( $post, array( 'mode' => 'print', 'create' => true ) )` builds it; it returns `false` when the post has no link (or cannot have one, or the module is not enabled); with `create` it makes the link first (the use by the PDF job: **one token per post, made when it is needed**, which is the simplification proposed by Eric on 2026-10-23 and applied here; there is no token that covers a section, no `shared_post` and no filter to extend what a token opens).
3. **What a valid token opens**: the **main request of that post, read-only**, whatever its status (draft, pending, private, future, published) and type (a page, an article, a section), shown as the front page of the site would show it, with the template of the active mode (`modes-mode-print` with `&mode=print`). The post is found by the token (a query on the meta, then `hash_equals` on the value found; a value that has not the form of a token never reaches the database). An **unknown, malformed, empty or revoked** token gives a **404**, never the home page, and so does the token of a post in the trash. The address is **not** redirected to the permalink (that would lose the token).
4. **The token opens one thing.** It counts only when it is the **only** query variable of the request: with a feed, a search, a REST route, a page of results or a post given by its address, it is ignored and the request is what it was. Not another post, not the REST API (401 for an anonymous request), not the feeds, not the lists (the draft is not in the home page, the feed or the REST list).
5. **Which posts can have a link**: the posts that can be seen on the front end (`is_post_type_viewable`), except the attachments, which are not in the trash and are not automatic drafts. The routes and the command refuse the others.
6. **The response is marked**: `X-Robots-Tag: noindex, nofollow` and the `robots` meta of WordPress (`wp_robots`), `nocache_headers()`, the constant `DONOTCACHEPAGE` for the page caches, and `Referrer-Policy: no-referrer`, so that neither a search engine, a cache nor a third-party host (the tiles of a map, a font) keeps or sees the address.
7. **The editor panel "Share link"** (plain JavaScript, `editor/link/`, in the sidebar of the post editor, for the viewable types): "No link" with **Create the link**; with a link, a warning ("Anyone who has this address can read this post, even if it is not published, until you revoke it"), the address, the date, **Copy** (the clipboard, or the address is selected and the person is asked to copy it), **Replace** and **Revoke**, each of the last two asking "Yes / No" in the panel.
8. **REST**: `access/v1/posts/<id>`: `GET` the state (`active`, `url`, `created`), `POST` to make the link (`regenerate=true` to replace it), `DELETE` to revoke it. The capability `edit_post` on the post and the REST cookie nonce for each: nobody else is given the token (the metas are protected and not registered for REST: they are not in `wp/v2`). A post that does not exist: 404 `access_not_found`; one that cannot have a link: 400 `access_not_eligible`.
9. **WP-CLI**: `wp otherguise access list [--show-token] [--format=table|json]`, `create <id> [--show-token]`, `revoke <id> [--dry-run]`. No capability is asked (no logged-in user); the token is **not** printed unless `--show-token`; each can be run twice.
10. **Export and clean-up**: the two metas are skipped from the WordPress export (`wxr_export_skip_postmeta`: a file that carries a token carries a valid link); they go with the post; `uninstall()` deletes them for every post.

## How the request is turned into the request of the post (tried before it was written, WordPress 7.1.3)

An experiment with no product code, as slice 201 did, on a scratch site, with a post of each status and a visitor who is not logged in:

- Changing the query variables to `p` (or `page_id`) and the type is **not enough**: WordPress then hides any post that is not public from a visitor who is not logged in (`WP_Query::get_posts()`, "User must be logged in to view unpublished posts"), so the draft, the pending, the private and the future posts gave a 404 while the published one was shown.
- What works: **`parse_request`** replaces the query by `p` or `page_id`, the type **and the status of the post** (WordPress lets a status that was asked for pass through: "If the post_status was specifically requested, let it pass through"); **`posts_pre_query`** gives the post to the main query (the SQL, and so the checks of the capabilities that it makes for the statuses, is not run); **`redirect_canonical`** answers false. The unknown token is `array( 'error' => '404' )`, which WordPress turns into its 404. The trashed post is refused by the module.
- Result: the six statuses and types were shown (200) with the right template, the trashed post and the unknown token gave a 404, a draft by `?p=` without a token a 404, and the print mode worked (`modes-mode-print`).

Coexistence (documented in `Visit`): `parse_request` and `posts_pre_query` at priority 10; `posts_pre_query` answers only for the main query of a request that has a token and returns what it receives otherwise.

## What it does not do

- No password, no expiry (Nextcloud has one: can be added), no per-user link, no write access, no comment.
- No change to the **media**: the files of the uploads folder are public already.
- The links of the page (menu, other posts) are **not** given a token: a draft linked from a shared page stays closed.
- **No token that covers a section** (see 2). To proofread a section before it is published, a link per post, or the assembled PDF.
- **The use by the PDF job** (the job asks `access_url( $id, array( 'create' => true, 'mode' => … ) )` when the post is not published, and the panel then shows that a link exists) comes in a slice of Books.
- **The unlisted state** ("published but hidden") of the module `Access` is not here: a slice of its own.

## Precautions (security-sensitive)

- The token is **never** in the REST API (protected metas, not registered), never logged by the plugin, never in an error message, never in the list of the command unless `--show-token`.
- The export of WordPress leaves it out; the capability `edit_post` and the nonce are needed to read, make, replace or revoke it.
- The URL, and so the token, appears in the **access logs** of the server and in the **browser history**; a revocation closes the link, nothing removes it from a log. To state in the readme.
- The check is done **only** on the main request of the front end whose only variable is the token (not in the admin, not in REST, not in `admin-ajax`, not with a feed or a search); the core's own `preview_nonce` and capability checks are **not weakened** (the token is a second way in, narrower, and a post by its address or by `?p=` stays as closed as before).
- A link is **read access to a post that was not meant to be public yet**: one that leaks shows a draft earlier than intended (the date and, for a trip, the place); revoking is the answer.
- The 128 bits make a search by guessing hopeless, `hash_equals` is used on the value found, and a value that has not the form of a token never reaches the database.

## Module and names

Module `Access` (`Otherguise\Access\`, `plugin/modules/access/`), **no dependency**, declared in `plugin/includes/modules.php` (checked by `ArchitectureTest`: the order of loading has it first and its dependencies are empty). Names belonging to it: the metas `_access_token` and `_access_token_created`, the argument `shared`, the REST namespace `access/v1`, the function `access_url()`, the command `otherguise access`, the panel `access-link`. Text domain `otherguise`. Other modules use it **through the function** (and check that it exists, as Books does for the modes), not through its classes. `.vscode/phpcs.xml` has the prefixes `access` and `Access`.

## Code

`Link\PostMeta` (the metas in WordPress), `Link\Links` (the tokens: make, replace, revoke, find, list, the address, which posts are eligible), `Link\Visit` (the request: `query_vars`, `parse_request`, `posts_pre_query`, `redirect_canonical`, `send_headers`, `wp_robots`), `Rest\LinkRoutes`, `Editor\LinkPanel` (loads `editor/link/link-panel.js`, `link.js` and `link.css`), `Cli\LinksCommand`, `Module` and `functions.php` (`access_url()`).

## Tests

- PHPUnit (no database): `AccessLinksTest` (the form and the alphabet of a token, never twice the same, created once, replaced even when a draw gives the same bytes, revoked, found only by the right token, the list, the address with and without a mode, the eligible posts), `AccessVisitTest` (a token gives the query of its post, with its status; the pages; what opens nothing is a 404; the token counts for nothing with anything else in the request; nothing stays from one request to the next; the post is given to the main query only; no canonical redirection; the headers and the directives), `AccessRoutesTest` (the route and the capability on each method, the state, creation twice, replacement, revocation, the refusals, the commands: output, twice, `--dry-run`, no token printed), `AccessModuleTest` (no dependency, the hooks with their priority, the export, `uninstall`, the loader of the panel, the public function without the module), and the order of the modules in `ModuleLoaderTest`.
- jsdom: `access-link-panel.test.js` (13 tests with `access-link-editor.test.js`: no link, creation, copy with and without a clipboard, replacement and revocation with their question, a failing request, refresh and removal, the connection to the editor and its routes).

## Verified

- **Automated**: 539 PHPUnit tests (with MariaDB 10.11.14) and 140 jsdom tests pass; `phpcs --standard=.vscode/phpcs.xml .` (the command of the CI) reports nothing.
- **Real WordPress 7.1.3 (scratch site, 2026-10-25)**:
  - **WP-CLI**: `create` (with `--show-token`, twice), the trashed post and a missing post refused, `list`.
  - **A visitor who is not logged in**, with the links made by the command: a pending, a private, a future, a draft page, a draft post and a published post were all shown (200) with their title, `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private`, `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer` and the `robots` meta; the print mode gave `modes-mode-print`; a short, empty or unknown token gave a 404, `?p=` on the draft a 404, a token with `feed`, `s` or `p` was ignored (the feed, the search, the post asked for), the REST route gave 401, and the draft was not in the home page, the feed or the REST list.
  - **REST** (`rest_do_request`): a subscriber is refused (403) on the three methods, an administrator is served; the token is not in `wp/v2`.
  - **Export** (`wp export`): the token metas are not in the file; the other metas are.
  - **The editor in Chromium (Playwright), logged in**: the panel "Share link" on a draft: "No link", **Create**; the address (200 for an anonymous visitor, also with `&mode=print`); **Copy** ("Copied.", the clipboard holds the address); **Replace** asks, **No** keeps the link, **Yes** gives another (the old one gave a 404, the new one a 200); **Revoke** (the panel says "No link", the link gave a 404). No error in the console.
- **Never tried**: the production and testing sites of the blog; a page cache plugin (the constant `DONOTCACHEPAGE` and the headers were checked, not a real cache); **Firefox** (Eric's browser); a custom type of post; a user with limited capabilities in the editor (REST and unit tests only); a very long use (many links); the link behind a reverse proxy or a CDN that ignores the headers.
