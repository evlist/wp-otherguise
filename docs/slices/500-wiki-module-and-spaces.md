<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 500 — Wiki: the module and the spaces

Status: **coded on the branch `wiki` (2026-11-06)**, not merged into `main`. Dependencies: slice 000 (the module loader), 233 (the menu Otherguise: the screen is declared to it), the module `triples` (from slice 501; declared here so that the module has its dependency from the start). Context: [`../design/wiki.md`](../design/wiki.md) (open): the moratorium of the SelfPedia was lifted by Eric on 2026-11-06 to validate the feasibility of the migration; the module is developed on the branch `wiki`.

## What it does

A sixth module of Otherguise, `wiki` (namespace `Otherguise\Wiki\`, `plugin/modules/wiki/src/`), which **depends on `triples`** (the names of the pages are statements, slice 501), and **the spaces**: the kinds of sections of the wiki (the encyclopaedic part, the opinions, the biography, the archive...), set on a screen of **Otherguise → Wiki**.

- **The module**: `Module implements ModuleInterface` (`id` `wiki`, dependency `triples`, `boot`, `activate`, `uninstall`), added to `plugin/includes/modules.php` and to the table of `docs/IA.md`. Enabled with the others; an instance that is not a wiki can leave it out with `otherguise_enabled_modules` (the wiki is probably **not** on the instance of the blog: see the design note).
- **A space** is a record of the option **`wiki_spaces`** (an array, not a table: a handful of spaces):
  - `name`: the word of the address, written as it appears in it (`Biography`): letters, digits, hyphen and underscore, no colon, no slash, no space; **unique without regard to the case** (a request in another case is redirected to the registered form, slice 503);
  - `label`: what a reader sees (`Autobiography`);
  - `template`: the slug of the template that a new page of this space starts with (like `modes_default_templates`, slice 209);
  - `class`: a CSS class added to the body of the pages of the space (the look of a space is the business of the theme);
  - `status`: a short text shown on every page of the space ("Fact, source, interpretation", the status of the information: the SelfPedia does not claim neutrality); empty for none;
  - `order`: the order of the lists.
- **The screen**: a list of the spaces, a form to add one, edit one, remove one; saved through the Settings API on `options.php` (nonce and capability by WordPress); the capability is `manage_options`, changed by the filter `wiki_admin_capability`; the screen is declared to the overview of the menu (`otherguise_admin_screens`, group `settings`, order 40).
- **Removing a space** is refused while it holds pages (slice 502 fills this check: until then there are none), **renaming** one too (a rename moves every address; the tool of slice 505 does it).
- **A reader of the spaces** for the other slices: `Spaces::all()`, `Spaces::find( $name )` (case-insensitive, returns the record or null), `Spaces::canonical( $name )`.
- **`uninstall.php`** removes the option; strings are translatable (text domain `otherguise`).

## Coded on 2026-11-06 (branch `wiki`)

- `plugin/modules/wiki/src/`: `Module` (id `wiki`, dependency `triples`, the screen only in the administration, `uninstall` deletes the option), `Space\Spaces` (the reader `all()`, `find()`, `canonical()`, the cleaning of a name and `sanitize()` of the option) and `Admin\SettingsScreen` (**Otherguise → Wiki**, group `settings`, order 40, capability `manage_options` through the filter `wiki_admin_capability`). The module is added to `plugin/includes/modules.php` (it is **enabled with the others**: a site that is not a wiki leaves it out with `otherguise_enabled_modules`), and `wiki` to the prefixes of `.vscode/phpcs.xml`.
- **The name of a space**: letters of any alphabet (`Encyclopédie` is valid), digits, hyphen and underscore, in normal form C, at most **40 characters** (the qualified name of a page is at most 191 bytes, slice 501); no colon, slash, space or dot. Unique **without regard to the case** (`BIOGRAPHY` is refused when `Biography` exists).
- **The form** is a table with one row per space and an **empty row at the end** to add one. Each row carries the name it had (`was`, a hidden field) and a box *Remove*. The list posted is the whole list; the server decides: a `was` that is not registered counts for none. A refused change (a bad name, a duplicate, the removal or the renaming of a space that holds pages) gives a notice and **leaves the record as it was**.
- **The pages are not there yet**: the check "holds pages" is the filter `wiki_space_has_pages` (false until slice 502 answers it), so the refusals are tested with the filter set, not with pages.
- The order defaults to ten times the position; a record has six keys and nothing else; the template is a slug (`a-z`, digits, `_`, `/`, `-`), the class a list of CSS tokens.

## Tests

PHPUnit without WordPress (`WikiSpacesTest`, `WikiModuleTest`: 15 tests): the names accepted and refused, the reader (order, malformed records, case), the cleaning of the rows (add, empty row, bad name, duplicate, rename, removal, refusals when the space holds pages, the unknown `was`), the registration of the screen, its form and its capability, the module and its uninstall; `ModuleLoaderTest` expects the new order and the dependency; `ArchitectureTest` passes (the module depends on Triples only). `phpcs` is clean on the new files.

## Verification

- **Tried** on a scratch WordPress 7.1.x (PHP development server, MariaDB), the plugin of this branch, driven in Chromium as an administrator: the entry in the menu and in the overview; adding a space and one with an accent (`Encyclopédie`); saving through `options.php` (the option holds the six keys); the notices for a duplicate in another case and for `bad:name`; renaming; removing with the box. The layout of the table was corrected after a first screenshot (the fields overflowed).
- **Never tried**: the refusals with real pages (there are none before slice 502), a user who has not `manage_options`, the uninstall on a real site, a multisite network, other browsers, narrow screens, keyboard use.

## Not done

The pages and their addresses (501 to 503); the rights per space (the reflection of [`../design/capabilities.md`](../design/capabilities.md) is open: one capability for all the spaces to begin with).
