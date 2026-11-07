<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 502 — Wiki: the pages (the type `page`, the check at saving, the creation)

Status: **rewritten on 2026-11-07 for the hierarchical model and coded on the branch `wiki`** (not merged into `main`). The first-model version (a flat type `wiki_page`, a space meta, a panel and a box) is kept at the tag `wiki-model-1`. Dependencies: slice 501 (the index of the tree: `Tree\Title`, `Tree\Keys`). Context: [`../design/wiki.md`](../design/wiki.md), "The model".

## What it does

The pages of the wiki are **the pages of WordPress** (the hierarchical type `page`, `post_parent` is the tree): **no content type of our own, no meta "space", no panel to choose one** (the selector of the parent of WordPress does it). This slice keeps the **index of slice 501 true** when a page is saved, and gives one **service** to create a page.

- **The check before a save** (REST, the block editor): the key `(parent, title)` is checked against the index, and the editor is told at once: **409** `wiki_name_taken` with "A page of this title exists under this parent: <title>"; **400** for a title that the rules refuse (a slash, a title made only of digits at the first level, a forbidden character…). A page **with no title** (an auto-draft) is left alone. The parent and the title are those **sent**, or those stored when the request does not carry them.
- **The claim after any save** (REST, the classic screen, the quick edit, an import: `wp_after_insert_post` and `rest_after_insert_page` call the same idempotent `sync`): the page gets its key from its parent and its title. A **new title is a rename** and **a new parent is a move**: the old key stays as an alias, and **only the key of this page changes** (the descendants follow, slice 501). A page that cannot have its key goes **back to draft** with a notice on the next screen (the same notice as the first model), so that no published page is left without its key.
- **A page in the trash keeps its keys** (it can come back); **a page that is deleted** loses its statements through the cleanup of Triples.
- **The service `Tree\Pages::create( $parent_id, $title, $html, $status )`**: the parent must exist and be a page; the key is checked **before** the page is made; the page is made with its parent, the key is claimed, and **if the claim fails the page is deleted again**. The same service serves the command below, the creation from the 404 (slice 504) and, from outside the plugin, the script of the migration (slice 508).
- **WP-CLI `wp otherguise tree create`** (`--parent=<id>` or `--path=<Titles/of/the/parents>`, `--title=<title>`, `[--status=draft|pending|private|publish]`, `[--file=<html>]`) and **`wp otherguise tree rebuild`** (the keys again from the posts, the aliases kept, the pages refused listed). The command is called `tree` **while the first-model command `wiki` exists**; it takes the name `wiki` when the first model is removed.

## What is not in this slice

**The rules of a branch** (template, class, status line, the panel and their inheritance) and the **switch that disables the module by default**: they are the slice 500, reworked next; so **a new page has no starting template yet**. **The titles of the first level that a route serves first** (the test of slice 503). **The address, the routing and the canonical form** (503), **the 404 and the offer to create** (504), **the links** (505).

## Side by side with the first model

The first-model hooks, type `wiki_page`, command `wiki`, router and 404 are **untouched**: the new hooks act on the type `page` only and the old ones on `wiki_page` only, so the two can run on the same site. **The new hooks act on every `page` of a site where the module is enabled**, so **the module must be switched off by default before the branch is merged into `main`** (it is enabled with the others today): a **merge blocker**, noted in the slice 500.

## Coded on 2026-11-07 (branch `wiki`)

- `Tree\Nodes` (the pages of WordPress, replaceable by a double), `Tree\Pages` (`create`, `sync`, `check`, `find`), `Tree\Saving` (the hooks: `rest_pre_insert_page`, `rest_after_insert_page`, `wp_after_insert_post`) and `Cli\TreeCommand` (`wp otherguise tree create` and `rebuild`), wired by `Module` (`Module::tree()`); `Keys::check()` added to slice 501's service (every check, without writing); one more code in `NameException` (`UNKNOWN_PARENT`).
- The **notice** of a page put back to draft reuses the transient and the display of the first model (`wiki_name_notice_<user>`), so no second notice is needed while the two models live together.
- **The module's first-model hooks and the new ones are both registered**; they do not meet (`wiki_page` against `page`).

## Tests

`WikiTreePagesDbTest` (11, on a real MariaDB with the real statements and an in-memory store of pages): creating (the case kept, the content kept as it is, the parent), a taken key refused with nothing left behind and a different case accepted, the other refusals (unknown parent, slash, number and route at the first level, empty title, bad status), a page deleted again when the claim fails after the insert, the claim after a save (no title, trash, auto-draft, a **rename** and a **move** with their aliases), the check before a save, the REST filter in each case, the hooks after a save (draft and notice), the command (by id and by path, every refusal said), the rebuild. `WikiModuleTest` expects the hooks. The whole suite passes with the database (788 tests, none skipped), `phpcs` is clean.

## Verification

- **Tried** on the scratch WordPress 7.1.x, in the block editor session of an administrator, through the REST of the pages: a first-level page, a child, the **same title again 409** ("A page of this title exists under this parent"), the same title under another parent **201**, `paris` (another case) **201**, `Ansò` and `Anso` **both 201**, a title with a slash **400** (`wiki_name_slash`), a number at the first level **400** (`wiki_name_digits`) and below **201**, a draft with no title **201**, a **rename** (the old key becomes an alias), a rename to a taken title **409**, a **move** to another parent (the key changes, the old one is an alias), a page as its own parent **400**. In the **block editor itself**: publishing a page whose title is taken at that level fails with the server's message shown by the editor; no JavaScript error. With `Tree\Pages::create()`: a page made under its parent, a refused creation leaves no post, a duplicate saved the **classic way** (`wp_insert_post`) goes back to draft with the notice, a rename by `wp_update_post` keeps the old key as an alias, and **`rebuild` after wiping the keys** makes them again (3 pages of the site were refused: duplicate titles under one parent that the scratch site already had, which is what the report is for).
- **Never tried**: the quick edit and the bulk edit of the list of the pages, an import (WXR), a user who may not edit the page, `wp otherguise tree` run by the real WP-CLI (the class is tried with doubles; WP-CLI is not installed in the scratch), a site with thousands of pages.

## Not done

The columns of the list of the pages (the key, the path), the quick edit (it renames a page: it goes through the same check after the save, which puts the page back to draft), a check at the moment the title is typed.
