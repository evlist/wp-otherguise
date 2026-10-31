<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 234 — Books: the lists and the edit screens of the printer profiles and of the book projects

Status: **coded (2026-10-31)**. Dependencies: slice 230 (the two types of content), slice 233 (the Otherguise menu). Eric's remarks on testing: the "quick edit" of the lists is the one of posts (date, password, "private"); the edit screens carry the boxes that the other plugins add to every type (the OSM map, the QR code of Kaya) and the box "Publish" with its draft and published; a printer profile is a setting but a book project is not: it should have a menu of its own, level with the posts and the pages.

## What it does

- **A menu for the book projects**: `books_project` has `show_in_menu` at `true`, in the position 21 (after the pages) with the icon `dashicons-book`; its submenu is "Book projects" and "Add a book project". The printer profiles stay a setting: the entry "Printer profiles" of the Otherguise menu (slice 233), and the overview of that menu lists them only.
- **The lists** (`Project\EditScreens`): no quick edit (the row action) and no bulk edit; the filters by status are gone (only "All" and "Trash" are kept); the labels are those of the type ("Search printer profiles", "No book project found.", "Book project saved." after a save, not "Post updated."); the columns are `Title` and, for a profile, the **page** (width, height, margin, and the gutter when it is not the margin) and the **page numbers**; for a project, the **root section**, the **printer profile** and the **scaling**. The date and the columns that other plugins add are not there.
- **The edit screen**: the boxes of the other plugins and the box "Publish" are taken away (`add_meta_boxes_<type>`, priority 999, after the others), the box of the type is kept, and a box **Save** is added (a button, and the link "Move to the trash" for what exists already). The scripts of the other plugins may still be loaded: the boxes only are removed.
- **No draft**: what is saved is published (`wp_insert_post_data`: a draft, a pending or a scheduled post of these two types becomes published). A post that WordPress keeps as an auto-draft until the first save, and the trash, are left alone. Nothing of these types is ever shown on the site, so the notion has no meaning.

## Tests

`BooksEditScreensTest` (no quick edit and no bulk edit, the views, the columns and their cells for a profile with a gutter and without, a project with and without relations, the boxes taken away and the box Save, the box Save before and after the first save, what is saved is published, the messages); `BooksProjectTest` (the arguments of the two types: the menu, the position, the icon, the labels; the entry and the overview of the profiles only); the hooks in `BooksModuleTest`. 627 PHPUnit tests pass with a database, `phpcs` and `reuse lint` are clean.

## Verified

On a scratch WordPress 7.1.3, logged in as admin in Chromium, with a box and a column added to every type by a test plugin: the menu "Book projects" between "Pages" and "Comments", the Otherguise submenu without it; the lists without quick edit, with the new columns and labels, without the column of the other plugin; the edit screen with the box of the type and the box Save only; a save shows "Book project saved."; a new project saved from "Add a book project" is published.

**Not verified**: testing and the production site (with the real boxes of OSM and Kaya), a user other than the administrator, the translations of the new strings (no `.pot` update).
