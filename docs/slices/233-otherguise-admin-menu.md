<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 233 — One entry "Otherguise" in the Settings menu, a hub for the screens of the modules

Status: **coded (2026-10-29)**. Dependencies: the modules that have a screen (Modes 204, Books 219 to 232, Access 401, Triples 105) and the two types of content of Books (230). Eric's request: the entries of Otherguise were spread over the Settings menu (five of them: the modes, the books, the access, the printer profiles, the book projects) and Tools; he wanted **one entry under Settings**.

## What it does

- **Settings → Otherguise** (`Core\AdminHub`, `includes/Core`, started with the plugin) is a **hub**: a page that lists the screens of the active modules that the user may open, each with a line that says what it is for, in the order the modules ask for: **Modes**, **Books**, **Access**, **Printer profiles**, **Book projects**. At the bottom, a section **See also** for what is a tool and not a setting: the **relations** of Triples (a link to the screen that stays under **Tools**).
- **The screens are not in the menu any more**, and still work at their own addresses (`options-general.php?page=modes`, `books`, `access`; the lists `edit.php?post_type=books_printer` and `books_project`, which are no longer in the menu: `show_in_menu` is `false`). A page that a module adds to the Settings menu stays registered there, with its title; its line is hidden by a style that the hub adds to the administration (`otherguise-hub`, `li:has(> a[href$="?page=<slug>"]) { display: none }`). Taking it out of the menu with `remove_submenu_page()` was tried: WordPress then knows no title for the page and PHP 8.1 or later says so (a deprecation notice in `admin-header.php`), and a plugin may not assign the global `$title`; a parent of `options.php` or `null` was tried as well, with the same notice or without the Settings menu opening.
- **When one of those screens is open** the Settings menu stays open and "Otherguise" is the marked entry (filters `parent_file` and `submenu_file`), and a breadcrumb "**Otherguise › Books**" above the page leads back to the hub (`in_admin_header`). The screens themselves are not changed.
- **A module declares its screen** with the filter `otherguise_admin_screens`: a title, a line, a capability (`manage_options` by default), one of `page` (a slug of the Settings menu), `post_type` (a list of contents) or `url` (an address), a `group` (`settings`, or `see-also`) and an `order`. A module that is not active declares nothing, so its line is not there. The entry of the hub is shown to who has `edit_others_posts`; each line, to who has the capability of its screen.
- The messages that named the place now say "Settings, Otherguise, Access".

## First version, replaced the same day

A first version (commit 7a7c965) made a **top-level menu "Otherguise"** with the screens under it, and moved their addresses to `admin.php?page=<slug>`. Eric asked for the screens to be reached from Settings and the relations to stay under Tools; the second version is this one. The top-level menu, `Core\AdminMenu` and the addresses `admin.php?page=<slug>` are gone.

## Tests

`CoreAdminHubTest` (the entry of the Settings menu and its hooks, the list with the lines escaped and the order, "See also", the hub without any screen, the Settings menu and the breadcrumb on a screen of a module and not on a tool, the style that hides the lines and cleans a slug); the tests of each module check that its screen is declared (Books and its two lists, Access, Modes, Triples in "See also") and their hooks. 621 PHPUnit tests pass with a database, `phpcs` and `reuse lint` are clean.

## Verified

On a scratch WordPress 7.1.3 with `WP_DEBUG` on, logged in as admin in Chromium: the Settings menu has one new entry "Otherguise" and no entry for the screens (their lines are `display: none`), the hub lists the five screens and "See also" with the relations, each screen opens at its address with the breadcrumb and the Settings menu open on "Otherguise", the two lists too, the relations screen is unchanged under Tools, no PHP notice anywhere, the form of Books saves with the notice "Settings saved." shown once.

**Not verified**: the real-WordPress scenarios of `tests/real-wordpress/` (the addresses of the Modes screen are unchanged, they were not run), a user who is not an administrator (the hub for an editor, which lists the two lists of contents only: by unit test only), the production and testing sites, the translations of the new strings (no `.pot` update), a browser without support for `:has()` (the three lines would show in the menu, and still lead to the right pages).
