<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Otherguise — Architecture and Current State

This document is the current handover note for the repository.

Its purpose is to describe what is implemented today, which architectural decisions are valid, which constraints apply to new work, and which directions are still open. It prefers verified current behavior over session history. The reasoning behind the decisions is in [`design/`](design/README.md).

## Purpose

Otherguise lets a WordPress site present the same content in several forms (web, print, book). It replaces a hack that chose a `-print` template from the query string with declared relations, and helps assemble books from posts.

## Verified Current State

The repository contains the structure of the plugin and the registries of the `Triples` module:

- the plugin header and bootstrap (`plugin/otherguise.php`), `uninstall.php`, `readme.txt`,
- the module loader, the autoloader and the three modules (`Modes` and `Books` are still empty),
- `Triples`: entity references and types, datatypes (literals), predicate definitions with the references that say which predicates may qualify which, and the three registries filled lazily through the actions `triples_register_entity_types`, `triples_register_datatypes` and `triples_register_predicates` (slices 100 and 101),
- `Triples` storage (slice 102): the table `{prefix}triples_statements` (created on activation, checked on every request, removed on uninstall only when the administrator asked), `Statement`, `StatementStore` (insert, read, queries including statements qualified or not by others, recursive deletion), `StatementQuery`, `Transaction`, and `Database`, the only class that runs SQL,
- `Triples` service (slice 103): `Statements` (obtained from `Module::statements()`; `Module::store()` is the unchecked store) with `triple`, `create`, `remove`, `delete`, `replace`, `transaction`, `check` and the reads `find`, `find_by_triple`, `match`, `objects_of`, `subjects_of`, `qualifications_of`, `listing`, `resolve`. Arguments are the WordPress objects the caller holds (recognized by the entity types, `EntityResolver`), a stored `Statement` or an `EntityRef` (`Ref::post( 12 )` and the like); a scalar object is a literal. `StatementValidator` applies the registry (types, ids, values, existence, qualification rules, limits) and refuses with `InvalidStatementException` (a code per failure); `StatementReader` reads by pattern, both ways for symmetric predicates; `StatementListing` and `PinnedOrder` apply the reading rule for scopes and positions; `Transaction` is re-entrant (savepoints),
- `Triples` lifecycle (slice 104): the statements follow the deletion of posts, media items, terms and users (`WordPressCleanup` on `deleted_post`, `deleted_term` and `deleted_user`, priority 10; `Statements::forget()` for the types of other modules; predicates with `on_delete = keep` stay), the actions `triples_statement_created` and `triples_statement_deleted` fired after the outermost commit (`EventQueue`), and the object cache of the reads (`Cache`: group `triples`, `last_changed` token bumped by every write, every rollback and the outermost commit),
- PHPUnit tests (see `phpunit`): loader, autoloader, architecture rules, version consistency, the `Triples` classes, the service checks without a database, and integration tests on a real database.

- `Triples` administration (slice 105): the screen **Tools → Relations** (`Admin\`): statements (filters, pages, confirmed deletion), registered predicates, entity types and datatypes with counts, and maintenance (orphans by batches of 200, the setting `delete_data_on_uninstall`); capability `manage_options` (filter `triples_admin_capability`), one nonce per action, text domain `triples`. Hooked in the administration only.
- `Modes` (slice 200): the modes (`web`, the default, and `print` with the alias `?print`; others through the action `modes_register_modes`), the mode of the request (`?mode=`, `modes_active_mode()`), the class `modes-mode-{slug}` on the body, and the registrations in Triples (entity type `mode`, predicate `modes/mode`). The core gives access to the booted modules (`Core\Modules`) and Triples offers `triples_statements()`. No template is changed by it.
- `Modes` variants (slice 202): the entity types `template` and `template_part` (ids `stylesheet//slug`), the predicates `modes/has-variant` and `modes/has-part-variant`, and `Variants` (declare, withdraw, remove, `variant_of`, `map`). Applied by `VariantApplier` (slice 203): the filters `{$type}_template_hierarchy` (priority 90) and `render_block_data`, for the mode of the request, on the front only.
- `Modes` administration (slice 204): **Tools → Modes** (`Admin\`): the modes, the variants of templates and of template parts with the way to withdraw a mode or remove a relation, and the forms that declare them; capability `edit_theme_options` (filter `modes_admin_capability`), one nonce per action.

No REST route exists yet. What was never run on a real WordPress: the existence checks and recognizers for terms and users (posts and media items were tried, with `get_post()`, `wp_delete_post()` and `wp_delete_attachment()`; the stubs of `WP_Term`, `WP_User`, `get_term()` and `get_userdata()` remain the only evidence for the others), and the deletion of terms and users;  the limits are checked without a database lock, so two simultaneous requests can both pass a limit of 1; the cost of `listing()` on very long lists is not measured. The administration screen was driven in Chromium on WordPress 7.1.3 (`tests/real-wordpress/admin-screen/`, 32 checks, screenshots in `docs/screenshots/`); not checked: other browsers, narrow screens, keyboard and contrast, multisite. The next slice is 106 (JSON export and import). Also never run on a real site: the deletion actions of WordPress (only the callbacks and their registration are tested), a persistent object cache (only the array stubs), and a multisite network (a user deleted from the network admin is forgotten on one site only).

### Triples: vocabulary

- **Statement**: a triple *(subject, predicate, object)* that has an identity. A qualification is another statement whose subject is the statement qualified: `(statement:41, mode, mode:print)`, `(statement:43, position, 1)`.
- **Entity reference** (`EntityRef`): `type:id`, for example `post:123` or `ext:youtube:abc`. The id is opaque; its entity type checks the format (the built-in types `post`, `attachment`, `term`, `user` and `statement` take positive integers). Existence is checked when a statement is created.
- **Datatype** (`DatatypeInterface`): the type of a literal object (`string` up to 191 bytes, `integer`, `boolean`), with its XSD name. Entity type slugs and datatype names share one namespace (20 characters at most).
- **Predicate** (`PredicateDefinition`): slug `owner/name` (64 characters at most, for example `modes/has-variant`), labels, allowed subject types (entity types) and object types (entity types or datatypes), limits, symmetry, behavior on deletion, optional IRI, and `qualified_by` / `qualifies`: a predicate X may qualify the statements of P when P lists X in `qualified_by`, or X lists P or `*` in `qualifies` (`PredicateRegistry::can_qualify()`). The references are checked when the registry is first read after the registrations.
- **Built-in qualifier**: `triples/position` (a statement about a statement, with an integer object, at most one per statement).
- A module registers its entity types, datatypes and predicates from callbacks added in its `boot()` to the three actions above; each action runs once, when its registry is first read. The entity types and datatypes are registered before the callbacks of the predicates run.

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

- **PHPUnit**: `phpunit` from the repository root (`phpunit.xml`). Expected: `OK`. The integration tests need a MySQL or MariaDB database: set `OTHERGUISE_TEST_DB` to `host=127.0.0.1;port=3306;user=root;password=;dbname=wordpress` (adapt). They create their table under a unique prefix and drop it. Without the variable they are reported as **skipped**, not passed: check that the count of skipped tests is zero when you rely on them.
- **phpcs**: `phpcs --standard=.vscode/phpcs.xml .` from the repository root. Expected: no output and exit code 0. The ruleset sets `warning-severity` to 0, so only errors count, and it scans the tests as well as the plugin. The custom sniffs are in `.vscode/phpcs-standard/Otherguise/` (no `phpcs:ignore` outside the tests, file length 400/700 lines).
- **REUSE**: `reuse lint`.

`tests/real-wordpress/` holds scripts that run on a scratch WordPress site (not in the PHPUnit suite or the CI); the first one, for slice 201, ran on WordPress 7.1.3 with PHP 8.3.6 and MariaDB 10.11 (32 checks passed). The Triples module has been run on a real site once (activation, `dbDelta`, the API with real posts and media, the deletion actions, the administration screen); nothing else.

What the automated tests do not cover: everything that needs WordPress (the real `dbDelta`, the activation hook, multisite, the hooks). The tests run on stubs (`tests/phpunit/bootstrap.php`); nothing has been run in a real WordPress site yet. The integration tests ran on MariaDB 10.11, not on MySQL.

## Lifecycle and Schema Changes

Not applicable yet. When a module gets tables, it owns their creation, a schema version stored in its own option, and idempotent migration steps run in version order (the model of `wp-i18nly`).

## Open Items

See `docs/slices/README.md`. The decisions still open are listed in [`design/README.md`](design/README.md).

## Scope Rule for Future Updates

When updating this document: keep current behavior separate from future design, remove historical session notes once superseded, avoid frozen commit histories and stale TODO inventories, and prefer concise factual summaries over brainstorming dumps.
