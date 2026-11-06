<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 500 — Wiki: the module and the spaces

Status: **proposed (2026-11-06), not coded**. Dependencies: slice 000 (the module loader), 233 (the menu Otherguise: the screen is declared to it), the module `triples` (from slice 501; declared here so that the module has its dependency from the start). Context: [`../design/wiki.md`](../design/wiki.md) (open): the moratorium of the SelfPedia applies (nothing is built before the ten pages of the autobiography are written; this slice only prepares the work).

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

## Tests (planned)

PHPUnit without WordPress for the normalisation of a name (the accepted characters, the case, the duplicates), the reader, the sanitising of the option (a record with a bad name is dropped with a message, an unknown key is ignored); the screen (the form, the capability, the declaration to the overview, the registration of the setting) as for `Books\Admin\SettingsScreen`; the module and its uninstall in the module tests; `ArchitectureTest` (the dependency on `triples`, and only on it).

## Not done

The pages and their addresses (501 to 503); the rights per space (the reflection of [`../design/capabilities.md`](../design/capabilities.md) is open: one capability for all the spaces to begin with).

## Verification (planned)

A scratch WordPress: the screen in the menu, saving, uninstall. Never tried on a real site.
