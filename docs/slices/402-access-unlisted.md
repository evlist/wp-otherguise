<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 402 — Access: published but hidden (unlisted)

Status: **proposed (2026-10-25)**, nothing coded. Dependencies: slice 400 (module `Access`) and slice 401 (the setting that enables it). Reasoning: [`../design/books.md`](../design/books.md) ("Roles, a loop block, unlisted posts…", point 4).

## Goal

A post whose address works but which is **absent from the places where the blog lists its content**: a cover, a title page, a page of a book that is not meant to be read as a post. Not a draft, not private, so the PDF job reaches it **without a token**.

## What it does (proposed)

- A protected meta `_access_unlisted` on the post (not a custom status: the block editor handles them badly).
- Removed from: the home page and archives and other queries that are not a single post (`pre_get_posts`, main query and secondary ones such as Query Loop), the feeds, the search, the sitemaps (`wp_sitemaps_posts_query_args`), the previous/next links (`get_previous_post_where`, `get_next_post_where`), the public REST listing; `noindex` through `wp_robots`.
- Still served at its address, and reachable by its id.
- The switch "unlisted" in the panel "Book section" (decided 2026-10-25), the command `wp otherguise access unlisted <id> [--off]`, a function `access_is_unlisted()` for the other modules. Books only reads it.

## Open points

- Whether `pre_get_posts` is enough for the Query Loop of the theme (to try on a scratch WordPress before coding).
- Whether an editor in the admin lists unlisted posts (proposed: yes, with a mark).
- A plugin that reads the database without the WordPress filters sees them: documented, not solved.

## Tests (planned)

PHPUnit for each filter; every entry point (panel, REST, CLI) tested; `uninstall` removes the meta. To try on a real WordPress: home, archive, search, feed, sitemap, previous/next, anonymous visit of the address.
