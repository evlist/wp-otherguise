<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 400 — Shares: a private link with a secret token

Status: **planned (2026-10-23), not coded.** Dependencies: none (a new module, `Shares`, with no dependency on the other modules). It is used later by Books (slice 220, the PDF of posts that are not published) and by the sections (`docs/design/books.md`, "The definition of a book"). Reasoning: [`../design/books.md`](../design/books.md) ("Previewing and converting unpublished posts").

## Goal

Let someone who is not logged in see a post that is **not public** (draft, pending, private, future) through a link with a **secret random token**, as Nextcloud shares a file. Two uses, decided with Eric on 2026-10-23:

1. **The PDF conversion** of a post being prepared: Gotenberg fetches the page as an anonymous visitor and WordPress shows a draft only to a user who may edit it.
2. **Sharing** a private page, outside the PDF, for example to have a section proofread before it is published.

Eric's judgement of the risk: the pages concerned are **of little sensitivity** (the worst that can happen is that someone sees a section before it is finished), so a token that stays valid until it is revoked is acceptable. This is a link that gives **read access**; the precautions below limit the damage of a leak, they do not make it impossible.

## What it does

1. **One token per post**, created on demand: 16 random bytes (`random_bytes`) written in the URL-safe form of base64 (22 characters, 128 bits). Kept **in clear** in a protected post meta (`_shares_token`, with `_shares_created` the date in GMT), because a shared link has to be shown again. A new token replaces the old one (**regenerate**), and **revoke** deletes it: the old link stops working at once.
2. **The link identifies the post by its token** (Eric, 2026-10-23): it is the address of the **site**, with the query argument `shared` and, optionally, the argument `mode` of the Modes module: `https://site/?shared=<token>&mode=print`. No permalink and no `?p=<id>` (a draft has no usable one). `shares_url( $post, array( 'mode' => 'print' ) )` builds it and returns `false` when the post has no token. The name `shared` was chosen after `share` (used by Jetpack) was set aside; to check against the query variables of the plugins of the sites.
3. **What a valid token opens**: the **main request of that post, read-only**, whatever its type and status (a page, an article, a section), shown as the front page of the site would show it, with its template of the active mode. Not another post, not the REST API, not a feed, not an AJAX call. The post is looked for **by the token** (`meta_key`/`meta_value`, `hash_equals` on the value found). An **unknown or malformed** token gives a **404**, never the home page. The address is not redirected to the permalink (`redirect_canonical` is turned off for these requests), otherwise the token would be lost.
4. **What the token covers can be extended by another module**, through the filter `shares_covered_posts( $ids, $post_id )` (the ids that a token of this post also opens). A covered post is chosen by a second argument, **`shared_post=<id>`**, accepted only when the id is in the covered list: `https://site/?shared=<token of the section>&shared_post=<id of a day>&mode=print`. The module that knows the sections (Books) adds the units of a section, recursively, like the share of a folder; Shares knows nothing of sections.
5. **The response is marked**: `X-Robots-Tag: noindex, nofollow` (and the filter `wp_robots`), `nocache_headers()`, the constant `DONOTCACHEPAGE` for the page caches, and `Referrer-Policy: no-referrer`, so that neither a search engine, a cache nor a third-party host (the tiles of a map, a font) keeps or sees the address.
6. **The editor panel** "Share link" (plain JavaScript, in the sidebar of the post editor): "No link" with **Create**; with a link, the address, **Copy**, **Regenerate**, **Revoke**. The REST routes `shares/v1/posts/<id>` (`GET` the state, `POST` create or regenerate, `DELETE` revoke) check `current_user_can( 'edit_post', $id )` and the REST cookie nonce; the token is returned only to such a user.
7. **WP-CLI**: `wp otherguise shares list [--format=<table|json|csv|yaml>]` (the posts that have a link, with the date, never the token unless `--show-token`), `wp otherguise shares create <id>`, `wp otherguise shares revoke <id>`. Each can be run twice; `--dry-run` for `revoke`.
8. **Clean-up**: the meta goes with the post; the module's `uninstall()` deletes the metas of every post.

## What it does not do

- No password, no expiry (Nextcloud has one: can be added), no per-user link, no write access, no comment.
- No change to the **media**: the files of the uploads folder are public already.
- The links of the page (menu, other posts) are **not** given a token: a draft linked from a shared page stays closed unless it is covered by the token (point 4).
- The use by the PDF job and the coverage of the sections come in the slices of Books (the job asks `shares_url()` when the post is not published, and creates the link if there is none; the panel then shows that a link exists).

## Precautions (security-sensitive: to review before and after the code)

- The token is **never** in the REST API (the meta is protected and not registered for REST), never logged by the plugin, never in an error message.
- It is skipped from the WordPress export through the filter `wxr_export_skip_postmeta` (otherwise an exported file carries a valid link).
- The creation, regeneration and revocation need the capability `edit_post` and a nonce; the reading of the token too.
- The URL, and so the token, appears in the **access logs** of the server and in the **browser history**; a revocation closes the link, nothing removes it from a log. To state in the readme.
- The check is done **only** on the main request of a front page of the post (not in the admin, not in REST, not in `admin-ajax`); the WordPress core's own `preview_nonce` and the capability checks are **not weakened** (the token is a second way in, narrower).
- A token lookup by `meta_value` is a plain query; the 128 bits make a search by guessing hopeless, and `hash_equals` is used on the value found.

## To verify first (an experiment, no product code, as slice 201 did)

How to make the **main query** of a front request show the post that the token designates, whatever its status (**draft, pending, private, future**) and type, to an anonymous request on WordPress 7.1, without opening anything else. The candidates: setting the query (`p`, `post_type`, `post_status`) on `parse_request` or `pre_get_posts` for the argument `shared`, `posts_results` or `the_posts`, with the checks the core makes afterwards (`get_post_status`, the 404, `redirect_canonical`, the private posts needing a capability, the template chosen for the type and for the mode). The answer is recorded here before the module is written.

## Module and names

Module `Shares` (`Otherguise\Shares\`, `plugin/modules/shares/src/`), **no dependency**, registered in `plugin/includes/modules.php` and checked by `ArchitectureTest`. Names belonging to it: the meta `_shares_token` and `_shares_created`, the argument `shared` (and `shared_post`), the REST namespace `shares/v1`, the functions and filters `shares_*`, the command `otherguise shares`. Text domain `otherguise`. Other modules use it **through the function and the filters** (and check that the function exists, as Books does for the modes), not through its classes.

## Tests (planned)

- PHPUnit: the token (length, alphabet, never twice the same), creation, regeneration (the old token no longer opens), revocation; `shares_url` (with and without `mode`, no token); the check (a right token opens the post, a wrong, empty, array, too long or revoked token opens nothing, the token of another post opens nothing, the covered posts through the filter); the headers and the constants; the REST routes (no capability: refused, no nonce: refused, the token only in the answer for the author); the commands (output, twice, `--dry-run`, no token printed by default); the export filter; `uninstall`; an entry for each hook in the test of the module; an `ArchitectureTest` for the new module.
- jsdom: the panel (no link, creation, copy, regeneration, revocation, a failing request).
- With a real WordPress and database: the experiment above, then the whole path (create, open as an anonymous visitor, revoke, 404).

## Verified

Nothing yet.
