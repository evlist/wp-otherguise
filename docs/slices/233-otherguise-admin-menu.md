<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 233 — One menu "Otherguise" for the settings and the tools of the modules

Status: **coded (2026-10-29, third version 2026-10-31)**. Dependencies: the modules that have a screen (Modes 204, Books 219 to 232, Access 401, Triples 105) and the two types of content of Books (230). Eric's request: the entries of Otherguise were spread over the Settings menu (five of them: the modes, the books, the access, the printer profiles, the book projects) and Tools (the relations); he wanted them grouped.

## What it does

- A **top-level menu "Otherguise"** (`Core\AdminMenu`, `includes/Core`, started with the plugin, registered at priority 5 of `admin_menu` so that it exists before the modules add to it). It is shown to who has `edit_others_posts`; each screen keeps its own capability.
- Under it, in this order: **Overview**, then the settings **Modes**, **Books**, **Access**, **Printer profiles**, then, **set apart by a line**, the tool **Otherguise relations** (the Triples screen, which was under Tools). The modules add their pages with `add_submenu_page( AdminMenu::SLUG, … )`: Modes, Books and Access at priority 10, the list of the printer profiles at 11 (the type has `show_in_menu` at `false` and `PostTypes::add_menu()` adds it, because `show_in_menu` would put it before the pages), the relations at 20 (they come last). A module that is not active adds nothing.
- **The line** that sets the tools apart: WordPress has no separator in a submenu, so it is a style (`li:has(> a[href$="?page=triples"]) { margin-top; padding-top; border-top }`) that the menu adds (`wp_add_inline_style`), for the first screen that a module declares in the group `tools`.
- **The overview** (first entry) lists the screens that the user may open, with a line that says what each is for, in two parts, "Settings" and "Tools". A module declares its screens with the filter `otherguise_admin_screens` (a title, a line, a capability, `page`, `post_type` or `url`, a `group` that is `settings` or `tools`, an `order`).
- The addresses change: the pages of the modules are at `admin.php?page=<slug>` (`modes`, `books`, `access`, `triples`) and not at `options-general.php?page=…` or `tools.php?page=triples`; the redirections of the Modes and Triples screens and the filter forms of the statements follow. The old addresses answer "not allowed".
- The screens of Books and Access, which printed the notices of the Settings API (`Settings saved.`) only because they were under Settings, call `settings_errors()` themselves.
- The messages that named the place now say "Otherguise, Access".

**Book projects are not in this menu** (slice 234): they are not a setting but the content of a book, and have a menu of their own, level with the posts and the pages.

## How it got there

1. **First version (7a7c965)**: this menu, with the screens in the order of registration (the two lists of contents first), the relations left under Tools.
2. **Second version (cbc8d61)**: Eric wanted one entry under Settings, which WordPress does not allow to have a submenu. A single entry "Otherguise" in the Settings menu opened a **hub** (a page that listed the screens with a line each and "See also" for the relations), the screens being out of the menu and reached from the hub with a breadcrumb. Tried, with the hiding of the lines, `remove_submenu_page()` (WordPress then knows no title for the page: a PHP deprecation notice in `admin-header.php`, and a plugin may not assign the global `$title`), a parent of `options.php` or `null` (the same notice, or the Settings menu does not open). Eric: it fights the interface of WordPress, the Settings entry does not show that Otherguise is selected.
3. **This version**: back to a menu of its own, with the tools after a line (Eric: "a menu Otherguise that groups the settings and the tools, with a separator if possible").

## Tests

`CoreAdminMenuTest` (the hooks, the top-level entry and its overview entry, the overview with the lines escaped, the order, the settings and then the tools, without screen or without tool, the style of the line and the cleaning of a slug); each module's test checks that its page is under `otherguise`, its title, its capability and that it declares its screen (Books and the list of the profiles, Access, Modes, Triples among the tools); the Modes and Triples tests follow their new addresses. 620 PHPUnit tests pass with a database, `phpcs` and `reuse lint` are clean.

## Verified

On a scratch WordPress 7.1.3 with `WP_DEBUG` on, logged in as admin in Chromium: the menu Otherguise (Overview, Modes, Books, Access, Printer profiles, Otherguise relations; the Book projects moved out by slice 234), no entry of Otherguise left in Settings or Tools, each screen open at its address with its own entry marked and the menu open, a line of 1 px above the relations, no PHP notice, the filter form of the statements (now `admin.php`) working, the form of Books saved with "Settings saved." shown once.

**Not verified**: the real-WordPress scenarios of `tests/real-wordpress/` (their addresses were changed, they were not run), a user who is not an administrator (the menu for an editor, who sees the list of the profiles and an overview with fewer entries: by unit test only), a browser without support for `:has()` (the line would not show), the production and testing sites, the translations of the new strings (no `.pot` update).
