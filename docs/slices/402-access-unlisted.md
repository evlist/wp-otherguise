<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 402 — Access: published but hidden (unlisted)

Status: **coded (2026-10-25)**, see "Verified". Dependencies: slice 400 (module `Access`) and slice 401 (the setting that enables it). Reasoning: [`../design/books.md`](../design/books.md) ("Roles, a loop block, unlisted posts…", point 4).

## Goal

A post whose address works but which is **absent from the places where the blog lists its content**: a cover, a title page, a page of a book that is not meant to be read as a post. Not a draft, not private, so the PDF job reaches it **without a token**.

## What it does

- A protected meta `_access_unlisted` = `1` on the post (not a custom status: the block editor handles them badly). A draft can carry it: the state holds when the post is published. Attachments and posts in the trash cannot (same rule as the links). The meta is **exported** (it is a choice, not a secret).
- Left out of, by `Access\Unlisted\Filters` (registered on `init` priority 1 **only when the setting of slice 401 is on**):
  - every query that is not the lookup of one post (`pre_get_posts`: `post__not_in`): home page, archives, search, feeds, the Query Loop blocks and the widgets of the theme, the public REST listing;
  - the sitemaps (`wp_sitemaps_posts_query_args`) and the previous and next links (`get_previous_post_where`, `get_next_post_where`);
  - and the page itself gets `noindex` (`wp_robots`).
- Left alone: a query that asks for a post by `p`, `page_id`, `attachment_id`, `name`, `pagename`, `attachment` or `post__in`; **the administration**; **a REST request of a user who may edit posts** (the panels of the editor, among them the one of Books, must find them); and a query with the variable `access_include_unlisted` for the code that needs them. The list of the administration marks them ("Unlisted").
- The address keeps working; a token is not needed (the PDF job reaches it as for any published post).
- Entry points: the route `access/v1/posts/<id>/unlisted` (`GET`, `POST` with `unlisted`; `edit_post` on the post, a 400 for a post that cannot be unlisted), the commands `wp otherguise access unlisted <post> [--off] [--dry-run]` and `unlisted-list [--format=json]`, the function `access_is_unlisted( $post )`, and **the switch "Unlisted" in the panel "Book section"** of Books (decided on 2026-10-25). Books does not depend on Access: its editor script gets the route from the function `access_unlisted_path()` when it exists (`window.booksSection.unlisted`), and shows the switch only then.
- `uninstall` deletes the meta.

## Precautions and limits

- `pre_get_posts` runs **even for the query that reads the ids** (`get_posts` with `suppress_filters` does not prevent it): without a guard the module called itself without end (found while trying it, the first `wp post list` did not return). `Unlisted::ids()` has the guard and reads the ids once per request.
- A plugin that reads the database without the filters of WordPress sees the unlisted posts. The feed of the comments is not filtered.
- The list of the ids is read at every request (one query on the meta key); it is meant for a handful of posts (covers, title pages), not thousands.
- Not checked by the unit tests: that an unlisted post is no longer in the real lists; see "Verified".

## Open points left

- A Query Loop of the theme that **sets `post__in`** (a hand-made list of posts) shows them: by design (an explicit list), to remember when it is used in a template.
- Whether the sitemap of the pages, the taxonomies' counts and the "recent comments" widget need the same care.

## Tests

`AccessUnlistedTest` (the state, the list query, what is left alone, sitemaps and adjacent links, marks and robots, the route, the command, the public functions), `AccessModuleTest` (the hooks, nothing when the setting is off, `uninstall`), `books-section-editor.test.js` (the switch: absent without the route, read, changed, restored on a failure, absent without the toggle component).

## Verified

On a scratch WordPress 7.1.3 (Twenty Twenty-Five, block theme, plain permalinks), a published post made unlisted with the command: it **disappears** from the home page (a Query Loop block), the search, the feed, the sitemap, the anonymous REST listing, and from the previous link of the next post (which then points to the one before); the address answers 200 with `noindex`; **listed again, everything is back**. The editor (cookie session) sees it in the REST listing; the route reads and changes the state; the administration marks it; the route is a 401 for an anonymous visitor; the switch of the panel "Book section" in the real block editor (Chromium) reads, changes and changes back the state.

**Never tried**: the blog's sites and its real templates, a custom post type, a page-cache plugin, the taxonomy archives and their counts, a theme that sets `post__in`, Firefox.
