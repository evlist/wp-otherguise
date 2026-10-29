<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 401 — Access: a settings page

Status: **coded (2026-10-25)**, see "Verified". Dependencies: slice 400 (module `Access`). Slices 402 and 403 read the settings of this one, so it comes first.

## Goal

Not every site wants a module that shows drafts through a link. Let the administrator decide **whether Access exposes its functioning**, as slice 400 does by default (panel in the editor, REST routes, WP-CLI, the `?shared=` argument).

## Settings

1. **Private links** (default: on). Off: the hooks of slice 400 (`query_vars`, `parse_request`, `posts_pre_query`, `redirect_canonical`, `send_headers`, `wp_robots`, the REST routes, the panel) are **not registered**; a link is then an ordinary address and the page is the home page; `access_url()` returns `false`; `wp otherguise access create` refuses. The tokens stay in the database, and come back to life if the setting is switched on again; `list` and `revoke` still work, to clean up. The export still skips the tokens.
2. **Who may make a link** (default: whoever may edit the post). Three values: `edit_post`, `edit_others_posts`, `manage_options`. The user must always be able to edit the post *and* have the capability of the setting. Checked on the server by the REST routes and by the panel (which is not even loaded otherwise); the command runs as the server's own user.
3. **Unlisted state** (default: on). Stored and read by `Settings::unlisted_enabled()`; **nothing uses it before slice 402**, and the screen says so.
4. *(Not coded, room left in the schema.)* A lifetime for the tokens (unlimited, as decided on 2026-10-25).

The settings are read on `init`, priority 1 (not at boot), so that a plugin that sets a filter on `plugins_loaded` is heard. Filters: `access_links_enabled`, `access_link_capability` (a value that is not one of the three is ignored), `access_unlisted_enabled`, `access_admin_capability` (the screen, default `manage_options`).

## Shape

- **Otherguise → Access**, like the screen of Books: a form posted to `options.php` (the Settings API checks the nonce and the capability), translatable strings, the option `access_settings` removed by `uninstall`. Classes `Settings\Settings` and `Admin\SettingsScreen`; `Module::boot()` now registers only the screen (in the administration), `init`, the export and the command, and `Module::enable_links()` registers the rest.
- A filter lets another plugin force a value; they run at the default priority and no other plugin is known to answer them.

## Tests

`AccessSettingsTest` (defaults, stored values, filters, sanitizing, who may share, the route, the screen and its form, the command), `AccessModuleTest` (the hooks before and after `enable_links()`, nothing hooked when off, the option deleted by `uninstall`). The screen has no script.

## Verified

On a scratch WordPress 7.1.3: without the option the links work; switched off, the token address shows the home page (the title of the draft does not appear), the REST route is a 404, `access_url()` is `false`, `create` refuses; switched on again the same address works; an editor can share when the setting is `edit_post` or `edit_others_posts` and not when it is `manage_options`; the screen loads, saves and shows the saved values (Chromium). 

**Never tried**: the blog's sites, the panel of the editor with a user who has not the capability (tested by unit tests only), a cache plugin, multisite.
