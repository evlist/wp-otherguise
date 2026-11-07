<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 500 — Wiki: the module switch and the rules of a branch

Status: **reworked for the hierarchical model, coded on the branch `wiki` (2026-11-07)**, not merged into `main`. The first version (option `wiki_spaces`, a screen of spaces) is kept at the tag `wiki-model-1`. Dependencies: slice 000 (module loader), 233 (menu Otherguise), the module `triples`. Context: [`../design/wiki.md`](../design/wiki.md).

## What it does

### 1. A switch per module (decided 2026-11-07)

- Option **`otherguise_modules`** (id => bool), edited in a section at the end of the Otherguise overview (Settings API, capability `manage_options`, filter `otherguise_modules_capability`).
- A module that implements the marker interface `DisabledByDefault` is off until switched on: **`wiki` is off by default**; the others are on.
- `ModuleSwitches::enabled()` closes the choice over dependencies: a module needed by an enabled one is on and its box is locked (a hidden field posts it). The filter `otherguise_enabled_modules` receives the computed list (`null` = all modules).
- `DescribedModule` gives each module a title and a description for the screen. A module switched on gets its `activate()` run when the option is saved. `uninstall.php` deletes the option.

### 2. The rules of a branch

- Post meta **`wiki_rules`** on any `page`: `{template, class, status}`, all optional, all cleaned (slug, CSS tokens, plain text).
- **Effective value** of a key: the nearest ancestor-or-self that sets it (walk by `post_parent`, depth guard 64, per-request cache).
- Effects: the body class; the template of the page (`page_template_hierarchy` prepends `<slug>.php` unless the page has its own `_wp_page_template`); the block **`wiki/branch-status`** (server-rendered paragraph with the status line); a new page, or a page getting its first key, **starts with the template of its branch**.
- **REST** `wiki/v1/rules/<id>`: own and effective values, with the node they come from. Capability `edit_others_pages`, filter `wiki_rules_capability`.
- **Editor panel** "Branch rules" (template, class, status), showing "Inherited: value (Title)".

## Tests

PHPUnit (`CoreModuleSwitchesTest`, `WikiRulesTest`, `WikiTreePagesDbTest`, `WikiModuleTest`): defaults, dependency closure, sanitising, inheritance, cleaning, hooks. jsdom: `wiki-rules-panel.test.js`. Full suite OK (808 PHP tests), `phpcs` clean.

## Verification

- **Tried** on a scratch WordPress (PHP dev server, MariaDB), as an administrator in Chromium and over REST: the switches (wiki off by default, locked dependencies, the Wiki menu appearing when on); rules set through the REST meta (markup stripped by the real `sanitize_text_field`); inheritance over three levels; the route; the body class, the template `page-no-title` and the status block on the front end (a child's own status overriding its parent's; a page outside the branch unaffected); the panel in the block editor showing inherited values, no JavaScript error.
- **Never tried**: typing in the panel and saving from the editor; a user without the capability; the block in the Site Editor; the blog instance; nginx; multisite.

## Not done

Rights per branch (open); the effect of the switch on the blog instance before merging `wiki` into `main` (the hooks act on every `page`: keep the module off there until cutover).
