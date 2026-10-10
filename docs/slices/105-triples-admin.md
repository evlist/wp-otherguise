<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 105 — Triples: administration screen

Status: **done** (the ten points under "To confirm" were confirmed by Eric on 2026-10-09). **Tried on a real site afterwards** (see "Tried in a real browser" below). Dependencies: slices 100 to 104. Module: `Triples`.

## Goal

Let an administrator **see and clean** what the module stores, without a database client: the statements, what is registered, what is left behind, and the one setting. It is an inspection and cleanup tool, not an editor: the statements are created by the modules and plugins that use them (Modes, Books, Media Helper), through the API.

Background: [`design/triples.md`](../design/triples.md); the API in [slice 103](103-triples-statements.md); the cleanup in [slice 104](104-triples-lifecycle.md), whose limits (deactivated plugins, direct SQL) this screen makes visible.

## The screen

One page, **Tools → Otherguise relations** (`tools.php?page=triples`), with three tabs. No JavaScript: every action is a link or a form, so that the screen works without scripts and the confirmation is made by the server.

### Tab "Statements"

A table in the style of the core list tables (`WP_List_Table`): bulk actions, pagination, filters above it.

| Column | Content |
|---|---|
| Id | The id of the statement. |
| Subject | The entity: its label and, when there is one, a link to its edit screen; `type:id` otherwise; "missing" in a distinct style when the entity does not exist (an orphan). |
| Predicate | The label of the registered predicate and its slug; an unregistered slug is shown as such. |
| Object | An entity as the subject, or a literal with its datatype. |
| About it | The number of statements about this one, and their predicate and object on a second line (`modes/mode → mode:print`). |
| Created | Date of creation (GMT, shown in the site's time zone). |

- **Filters**: predicate (the registered ones, plus "not registered"), entity type, an entity (`type:id`, exact match, subject or object), "show the statements about statements" (off by default: they appear in the column "About it" of their statement), and **orphans only**.
- **Order**: by id or by date of creation, ascending or descending. 20 per page, changed by the **screen option** `triples_per_page` (stored in the user's meta).
- **Actions**: *Delete* on each row and as a bulk action. Deleting asks for confirmation on a second page that says how many statements will go (the statement and the statements about it, recursively); the confirmation is a form with a nonce. The deletion goes through `Statements::delete()`, so the events of slice 104 are fired.
- **No creation and no edition** of statements here (see "To confirm").

### Tab "Registered"

Read-only. Three tables: the **predicates** (slug, label, inverse label, subject and object types, limits, symmetric, `on_delete`, qualified by, qualifies, and the **number of statements**), the **entity types** (slug, label, and whether the type can check existence, recognize objects and load them), and the **datatypes**. It shows what the modules registered, which is the first thing to look at when a statement is refused. Statements of predicates that are **no longer registered** (a plugin was deactivated) are listed here too, with their count and a *Delete these statements* action with confirmation.

### Tab "Maintenance"

- **Orphans.** Statements with an end that no longer exists (a type that can check existence says so) or a subject statement that is gone. The scan goes through the table by **batches of 200 statements in the order of the ids**, starting after a given id; the page shows the orphans found in the batch and a link to the next batch. *Delete the orphans of this batch* is a form with a nonce: the server **scans the same range again** and deletes what it finds, whatever the browser sent. No scan runs by itself and nothing is stored between two requests (a WP-Cron job with progress, as the conventions ask for long jobs, is left to the day the tables are large enough to need it).
- **Setting.** "Delete all the data when the plugin is deleted" (`triples_settings['delete_data_on_uninstall']`, the flag the uninstaller of slice 102 already reads), saved through the Settings API with a nonce and a sanitizing callback. Off by default.

## Entities on the screen

`EntityType` gets one more optional callable, **`describe( $id )`**, returning `array( 'label' => string, 'url' => string|null )` or null (the type cannot describe it). `Statements::describe( EntityRef )` calls it and says whether the entity exists. Built-ins: a post or a media item (title, edit link), a term (name, edit link), a user (display name, edit link). A type without it is shown as `type:id`. The labels are escaped on output.

## Security and conventions

- **Capability.** The screen and every action check `current_user_can( $capability )`, where the capability is `manage_options` by default and can be changed by the filter `triples_admin_capability` (the capability belongs to the module's naming: one place, one filter, no role is modified, nothing to remove on uninstall).
- **Nonces.** Row deletion `triples_delete_{id}`, bulk and confirmation forms `triples_bulk_delete`, orphans `triples_delete_orphans`, unregistered predicates `triples_delete_predicate_{slug}`, settings by the Settings API. A failed check ends the request (`wp_die`) before anything is read or written.
- **Handlers** are `admin_post_` actions (`triples_delete`, `triples_delete_orphans`, `triples_delete_predicate`) that redirect (`wp_safe_redirect`) to the screen with a result code, shown as a notice. The screen only registers when `is_admin()`.
- **Input.** Predicates must match the slug pattern, entities are parsed by `EntityRef::parse()` in a try block, numbers go through `absint()`, the order is checked against a list. All output is escaped; the translatable strings use the text domain `triples` (module rule 4), loaded by the module, with the module's own `languages/` directory. Dates use `wp_date()`.
- **Multisite.** The screen is per site, in the site's admin; there is no network screen.
- **Uninstall.** The user meta `triples_per_page` is removed with the data (when the flag is on), by `Uninstaller`.

## Changes to existing code

- `EntityType`: optional `describe` callable; `WordPressEntities` gives it to the built-ins.
- `StatementStore`: `counts_by_predicate()` (one grouped query, cached); `StatementQuery`: an offset-based `limit()` already exists; new `order_by_created()`, `in_range( $after_id, $count )` for the scans, and `with_unregistered( array $registered )` for the predicates that are no longer registered.
- `Statements::describe()`.
- `Module::boot()` registers the admin classes when `is_admin()`; `Uninstaller` removes the user meta.
- New classes (`Triples\Admin\`): `Admin` (builds the classes and hooks them), `AdminPage` (menu, frame, tabs, notices, screen option), `StatementsScreen`, `RegisteredScreen` and `MaintenanceScreen` (what each tab prints), `StatementsTable` (the list table), `StatementsFilters` (the query string, checked), `StatementsView` and `RegisteredView` (data to display; no WordPress call), `OrphanScanner`, `AdminActions` (the `admin_post_` handlers), `SettingsPage`, `Markup` (escaped fragments) and `Environment` (capability, nonces, input, redirections, dates, links: the only contact with the session).

### As delivered

- **Hooked in the administration only.** `Module::boot()` builds `Admin` and registers its hooks when `is_admin()` is true (the function is injected, as are `$add_action` and `$do_action`); on the front end only the deletion callbacks and the translation loader are added.
- **One nonce for the confirmation.** The row link and the bulk action lead to the same confirmation page (a read, no nonce); its form carries the single nonce `triples_bulk_delete`. The nonces `triples_delete_orphans` and `triples_delete_predicate_{slug}` are as planned. The handlers check the capability first, then the nonce, and a nonce made for another handler or another predicate is refused (tested).
- **Input** is read by `filter_input_array()` with a list of the keys the screen uses (no superglobal is touched, so no inline `phpcs:ignore` is needed); output that comes from `Markup` goes through `wp_kses_post()` because the escaping check of the standard cannot follow a helper. The tests replace `wp_kses_post()` by an identity function, so they check that the data is escaped by the code, not what `wp_kses_post()` would strip.
- **Orphans-only filter** scans from `after` until the page is full: no page numbers, no total, a "look for more" link carrying the id to continue from.
- **The screen option** is saved by WordPress as the user meta `triples_per_page` (plain name, seen on a real site: it was first assumed to be prefixed per site, which a test on WordPress 7.1.3 disproved); the uninstaller deletes both that key and the prefixed one with the data.
- **Text domain** `triples` is loaded by `Module::load_textdomain()` from `modules/triples/languages/`, which holds no translation yet. `.vscode/phpcs.xml` accepts the text domains `otherguise` and `triples` and the prefixes `otherguise` and `triples`.
- `Statements::describe()`, `StatementQuery::order_by()`, `after_id()`, `with_type()`, `excluding_subject_type()` and `with_other_predicates()`, `StatementStore::counts_by_predicate()` are as planned.
- The files of `StatementQuery` (435 lines), `StatementStore` (476) and `Statements` (401) are over the 400 lines that warn.

## Out of scope

Creating or editing statements by hand; a graph view; import and export (106); REST routes; the editor panel; network-wide administration; a background scan.

## Tests

- **Without WordPress or a database:** `StatementsView` (every filter and order turned into the expected query, invalid values refused or replaced by the default, the rows turned into display data with the labels of the predicates, the missing entities and the qualifications); `OrphanScanner` (existing, missing, types without a check, a subject statement that is gone, the end of the table, the batch boundaries); the capability and nonce checks of every handler (no capability: refused; bad nonce: refused; nothing deleted in both cases); the output of every view escaped (a label containing `<script>` appears escaped); the menu registered with the right capability and only in the admin.
- **Integration on a real database:** the grouped counts, the range scan on a table with holes in the ids, the deletion of a statement from the screen firing the events and deleting the dependents, the deletion of an unregistered predicate, `describe()` through the store; the page of statements and the count agree with the filters.
- Every entry point (the page, the three handlers, the settings) has at least one test, as the conventions ask.
- The architecture test still passes; `phpcs` and `reuse lint` stay clean.

## Tried in a real browser

After the slice was delivered, the plugin was installed on a scratch WordPress 7.1.3 (PHP 8.3.6, MariaDB 10.11.14, Twenty Twenty-Five) and the screen was driven with Chromium through Playwright: [`tests/real-wordpress/admin-screen/`](../../tests/real-wordpress/admin-screen/README.md), 32 checks, no PHP error and no failed request. Screenshots: [list](../screenshots/105-10-list.png), [registered](../screenshots/105-13-registered.png), [confirmation](../screenshots/105-12-confirm.png), [orphans](../screenshots/105-14-orphans.png), [unregistered predicate](../screenshots/105-16-unregistered.png).

What it found, and what was changed: the screen option is saved as the user meta `triples_per_page` (not prefixed per site), so the uninstaller removed the wrong key (it now removes both); the button that deleted the statements of an unregistered predicate had no confirmation page (it is now a link to one); the filters were cramped (spacing added). The activation created the table with the real `dbDelta`, the module worked with real `WP_Post` objects and the real deletion actions (`wp_delete_attachment()`, `wp_delete_post()`, the trash), and a subscriber, a missing nonce and a forged nonce were all refused with HTTP 403.

Still not verified after this run: other browsers and narrow screens, keyboard use and contrast, multisite, a persistent object cache, real language packs, other WordPress and PHP versions, Apache or nginx, and a large table.

## Not verified by this slice

**At the time of the slice the screen had never been displayed in a browser** (see the section above for what was tried afterwards): the list table, the notices, the layout and the screen options were checked by nobody. The tests run on stubs of the WordPress admin functions and of `WP_List_Table`. The nonces and the capability checks are tested as logic, with a test environment that says what is valid, not against a real session; `Environment` itself (`current_user_can()`, `wp_verify_nonce()`, `filter_input_array()`, redirections) and `Module::load_textdomain()` have no test. `wp_kses_post()` was never run on the output, so a tag it strips would show only in a browser. The translations are not produced here: `triples.pot` is generated by `wp i18n make-pot`, which is not available in the development session, so the `.pot` is added by hand or at the first run of the CI. The behaviour with a very large table is not measured.

## To confirm

1. One screen under Tools with three tabs (Statements, Registered, Maintenance), no JavaScript.
2. Inspection and cleanup only: no creation or edition of statements by hand.
3. Deletion with a server-side confirmation page, through `Statements::delete()`; bulk deletion included.
4. Statements about statements are hidden from the main list by default and shown in the column "About it".
5. Orphans found by batches of 200, resumable by id, recomputed by the server before deleting; no stored state, no cron job yet.
6. Statements of predicates that are no longer registered are shown with their count and can be deleted per predicate.
7. Capability `manage_options` by default, with the filter `triples_admin_capability`; no custom role or capability stored.
8. The text domain of the module is `triples` (loaded by the module, `languages/` in the module directory); the `.pot` is generated outside this session.
9. The setting `delete_data_on_uninstall` and the screen option `triples_per_page`; the user meta is removed with the data.
10. `EntityType::describe()` as an optional callable, given for the built-in types.

## Done when

PHPUnit (unit and integration), phpcs and `reuse lint` pass; the architecture test passes; this document, `docs/IA.md` and `docs/design/triples.md` describe the delivered classes; the "not verified" section above is still true or has been corrected.

> **Later change:** the modules no longer have a text domain of their own: every string uses `otherguise` (the slug of the plugin, which Plugin Check and WordPress.org require), and the modules no longer load a domain. See [IA.md](../IA.md), rule 4. What is written above about `triples` and `modes` as text domains is history.

> **Later change (menus):** the entries are named after the plugin so that an administrator who has just installed it can find them: **Tools → Otherguise relations** and, since the modes screen is mostly a setting, **Settings → Otherguise modes** (`options-general.php?page=modes`). The page slugs are unchanged. The screenshots above show the earlier titles.
