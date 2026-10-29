<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 233 — One menu "Otherguise" for the settings of the modules

Status: **coded (2026-10-29)**. Dependencies: the modules that have a settings screen (Modes 204, Books 219 to 232, Access 401) and the two types of content of Books (230). Eric's request: the entries of Otherguise were spread over the Settings menu (five of them: the modes, the books, the access, the printer profiles, the book projects) and the Tools menu; he asked for **one submenu** that groups all the settings.

## What it does

- A **top-level menu "Otherguise"** (`Core\AdminMenu`, `includes/Core`, registered at the start of the plugin, priority 5 of `admin_menu` so that it exists before the modules add to it). It is shown to who has `edit_others_posts`; each screen keeps its own capability. Its first entry is an **overview** that lists the screens of the active modules that the user may open.
- Under it: **Modes**, **Books**, **Access** (the pages of the modules: `add_submenu_page( AdminMenu::SLUG, … )`) and **Printer profiles**, **Book projects** (the two types of content of Books: `show_in_menu => AdminMenu::SLUG`). A module that is not active adds nothing.
- **The Relations screen of Triples stays under Tools** (Eric: it is a tool, not a setting).
- The pages are at `admin.php?page=<slug>` now (`modes`, `books`, `access`), not at `options-general.php?page=<slug>`; the redirections of the Modes screen follow. The old addresses answer "not allowed".
- The screens of Books and Access, which print the notices of the Settings API (`Settings saved.`) only because they were under Settings, call `settings_errors()` themselves.
- The messages that named the place now say "Otherguise, Access" (PDF of a post that is not published, WP-CLI `access link`).

## Tests

`CoreAdminMenuTest` (the hook before the modules, the top-level entry and its overview, the list for a user, the entries read from `$submenu`); the tests of the screens check their parent (`otherguise`), their title and their capability; `BooksProjectTest` the `show_in_menu` of the two types. 618 PHPUnit tests pass with a database, `phpcs` and `reuse lint` are clean.

## Verified

On a scratch WordPress 7.1.3, logged in as admin in Chromium: the menu Otherguise (Overview, Printer profiles, Book projects, Modes, Books, Access), the Settings menu with none of them, the Tools menu with "Otherguise relations", the six pages and the Relations page open (HTTP 200), `options-general.php?page=books` refused (403), the form of Books saved with the notice "Settings saved." shown.

**Not verified**: the real-WordPress scenarios of `tests/real-wordpress/` (their addresses were changed, they were not run), a user who is not an administrator (the menu for an editor, the overview with fewer entries: by unit test only), the production and testing sites, the translations of the new labels (no `.pot` update).
