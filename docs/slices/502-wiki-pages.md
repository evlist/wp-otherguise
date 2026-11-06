<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 502 — Wiki: the pages (a content type, the space and the title)

Status: **proposed (2026-11-06), not coded**. Dependencies: slices 500 (the spaces), 501 (the names, as statements of `triples`), 233 (the menu), 209 (the default template). Context: [`../design/wiki.md`](../design/wiki.md).

## What it does

A **content type `wiki_page`** whose pages have a **space** and a **title that is a name** (slice 501), with the block editor, and the checks that keep the index true.

- **The type**: `wiki_page`, not hierarchical, `public`, **no rewrite rule of its own** (`rewrite` false: the address is slice 503), `show_in_rest` true (the block editor), `has_archive` false, supports `title`, `editor`, `excerpt`, `thumbnail`, `revisions`; capabilities of the type `page` (a filter `wiki_capability_type` for the reflection of `capabilities.md`); its **own top-level menu** "Wiki" with the lists and edit screens made as those of slice 234 (no quick edit, a column for the space, a filter by space, no box of the other plugins). Its `post_name` is technical (WordPress makes it; nobody reads it).
- **The space of a page** is a registered post meta `wiki_space` (shown in REST, protected), chosen in a panel of the editor and in a box on the classic screen; **required**, set when the page is created, **not editable afterwards** (moving a page to another space is the tool of slice 505: a move changes an address).
- **The name is claimed when the page is saved**: on `rest_pre_insert_wiki_page` (the block editor: an error 409 with the message "A page of this name exists in this space: <title>", which the editor shows) and on `wp_insert_post_data` (the classic screen: the page stays a draft and a notice says why); then `Names::claim()` in `wp_after_insert_post`. A **new title** on an existing page is a **rename**: the old name stays as an alias (its address will redirect, slice 503). A refused title (slice 501) is told the same way.
- **The default template**: a new page starts with the template of its space (`Spaces::find()['template']`, as `modes_default_templates`, slice 209).
- **The status of the information**: the body class `wiki-space-<name>` and the text of the space are given to the templates (a block `wiki/space-status`, to be written with the wiki theme; here only the data).
- **When a page is deleted** (not trashed) its names go with it: **the cleanup of Triples deletes its statements** (slice 104), nothing to write here; a page in the trash keeps its names (it can come back).
- **`uninstall`**: the pages are not deleted (they are the content); the statements of the module are (slice 501: the index can be rebuilt with `wp otherguise wiki names --rebuild`, the aliases are lost).
- WP-CLI: `wp otherguise wiki create --space=<name> --title=<title> [--status=draft|publish] [--file=<html|md>]` (the claim and the checks of the editor; `--file` gives the content: the **semi-manual import**, enough while the wiki has few pages) and `wp otherguise wiki names [--space=<name>]` (the index, with `--check`: a page without a name, a name without a page, and `--rebuild`).

## Tests (planned)

The type (registration, no rewrite, the REST flag, the capability filter); the meta (required, not editable after the creation, the error through REST: 409 with the message; the classic screen keeps a draft); the claim on save, the rename and its alias, a refused title, a duplicate in the same space and the **same title in another space** (accepted); the deletion (Triples' cleanup takes the names); the default template; the screens (the columns, the filter) as `BooksEditScreensTest`; the commands (`create` from a file, `names --check`).

## Not done

The address and the routing (503); the page that does not exist (504); the link by name (505, the shortcode); the move between spaces (505).

## Verification (planned)

A scratch WordPress with Chromium: create two pages of the same title in two spaces, a duplicate refused in the editor, a rename. The block editor part is not testable in jsdom for the panel only. Never tried on a real site.
