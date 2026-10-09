<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Otherguise — Architecture and Current State

This document is the current handover note for the repository.

Its purpose is to describe what is implemented today, which architectural decisions are valid, which constraints apply to new work, and which directions are still open. It prefers verified current behavior over session history. The reasoning behind the decisions is in `evlist/shared-room`, directory `wp-otherguise/`.

## Purpose

Otherguise lets a WordPress site present the same content in several forms (web, print, book). It replaces a hack that chose a `-print` template from the query string with declared relations, and helps assemble books from posts.

## Verified Current State

The repository contains the structure of the plugin and nothing else:

- the plugin header and bootstrap (`plugin/otherguise.php`), `uninstall.php`, `readme.txt`,
- the module loader, the autoloader and the three empty modules,
- PHPUnit tests of the loader, the autoloader, the architecture rules and the version consistency.

No feature, table, screen or hook of the modules exists yet.

## Modules

The plugin is a single plugin with three modules, to be split into three plugins later if useful.

| Module | Namespace | Directory | Depends on |
|---|---|---|---|
| Core | `Otherguise\Core\` | `plugin/includes/Core/` | nothing |
| Triples | `Otherguise\Triples\` | `plugin/modules/triples/src/` | nothing |
| Modes | `Otherguise\Modes\` | `plugin/modules/modes/src/` | Triples |
| Books | `Otherguise\Books\` | `plugin/modules/books/src/` | Triples, Modes |

The composition root is `plugin/includes/modules.php`, the only file that knows every module. A module implements `Otherguise\Core\ModuleInterface` (`id`, `dependencies`, `boot`, `uninstall`). `ModuleLoader` boots modules dependencies first and uninstalls them dependents first, rejects unknown dependencies and cycles, and supports a list of enabled modules (filter `otherguise_enabled_modules`).

### Rules that keep the split cheap

1. **One-way dependencies, checked by a test.** `tests/phpunit/ArchitectureTest.php` fails when a module uses a module it does not depend on, or when the core uses a module.
2. **Talk through a public API, not concrete classes.** A module never writes SQL in the tables of another module; it uses interfaces and hooks.
3. **Each module owns its data:** creation and migration of its tables, its schema version in its own option, its own cleanup in `uninstall()`.
4. **Names belong to the module, not to the umbrella:** table names (`triples_statements`, not `otherguise_statements`), options, hooks, REST namespace (`triples/v1`), capabilities and text domain of a module are prefixed by the module. Renaming stored data later needs a migration.
5. **A layout that lets a module be lifted out:** `plugin/modules/<module>/` holds its sources (`src/`), and will hold its tests' fixtures, translation files and admin scripts.
6. **Tests per module** that run without loading the other modules, except those it depends on.
7. **A module loader:** each module has its own bootstrap; the plugin loads the enabled modules.
8. **No catch-all "common" directory.** Shared code belongs to the core or is duplicated.

## Constraints and Working Rules

### Product and code constraints

- Full WordPress standards compliance is required.
- Full REUSE compliance with `GPL-3.0-or-later` is required.
- Comments and documentation stay in English.
- Product logic should not assume shell execution at runtime when a PHP integration exists.
- PHP 8.1 or later, WordPress 6.6 or later.

### Repository and environment constraints

- Runtime code stays under the PSR-4-style layout described above, loaded by `Otherguise\Core\Autoloader` (no Composer dependency yet).
- Managed `.devcontainer/` graft files and `cs-grafting-*` workflows are grafted from `evlist/codespaces-grafting` and are not edited by hand.
- Vendored third-party code, when there is some, goes under `plugin/third-party/` and is treated as upstream-managed.

### Delivery discipline

Small-slice XP workflow: tiny vertical slices, test first when practical, focused validation before widening scope, behavior-oriented tests, deletion of stale scaffolding rather than speculative accumulation. Slices are documented in `docs/slices/`.

## Validation

Run these before pushing. The grafted CI runs PHPUnit, the repository-wide phpcs check, `reuse lint` and Plugin Check once the codespace is grafted.

- **PHPUnit**: `phpunit` from the repository root (`phpunit.xml`). Expected: `OK`.
- **phpcs**: `phpcs --standard=.vscode/phpcs.xml .` from the repository root. Expected: no output and exit code 0. The ruleset sets `warning-severity` to 0, so only errors count, and it scans the tests as well as the plugin. The custom sniffs are in `.vscode/phpcs-standard/Otherguise/` (no `phpcs:ignore` outside the tests, file length 400/700 lines).
- **REUSE**: `reuse lint`.

What the automated tests do not cover: everything that needs WordPress. The tests run on stubs (`tests/phpunit/bootstrap.php`); nothing has been run in a real WordPress site yet.

## Lifecycle and Schema Changes

Not applicable yet. When a module gets tables, it owns their creation, a schema version stored in its own option, and idempotent migration steps run in version order (the model of `wp-i18nly`).

## Open Items

See `docs/slices/README.md`. The decisions still open are tracked in `evlist/shared-room`.

## Scope Rule for Future Updates

When updating this document: keep current behavior separate from future design, remove historical session notes once superseded, avoid frozen commit histories and stale TODO inventories, and prefer concise factual summaries over brainstorming dumps.
