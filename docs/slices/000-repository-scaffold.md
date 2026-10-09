<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 000 — Repository scaffold

Status: **done**. Dependencies: none.

## Goal

Start from a repository that already enforces the working rules: the layout of `wp-i18nly`, a plugin split into modules whose dependencies are checked by a test, and the tooling (PHPUnit, phpcs with the custom sniffs, REUSE) so that the first feature slice can be test-first.

## What it contains

- `plugin/`: plugin header, bootstrap, `uninstall.php`, `readme.txt`, `REUSE.toml`.
- `plugin/includes/Core/`: `Autoloader`, `ModuleInterface`, `ModuleLoader`.
- `plugin/includes/modules.php`: the composition root.
- `plugin/modules/{triples,modes,books}/src/Module.php`: three empty modules with their dependencies.
- `tests/phpunit/`: tests of the loader (order, enabled modules, unknown dependency, cycle, duplicate), of the autoloader, of the architecture rules, and of version consistency (plugin header, constant, `readme.txt`).
- `.vscode/phpcs.xml` and `.vscode/phpcs-standard/Otherguise/`: WordPress standard plus two custom sniffs (no `phpcs:ignore`, file length).
- `docs/IA.md`, `docs/slices/`, `CLAUDE.md`, `README.md`, `LICENSES/`.

## Left out on purpose

`.devcontainer/` and the `cs-grafting-*` workflows: they will be grafted from `evlist/codespaces-grafting`, which may rewrite `.vscode/phpcs.xml` (keep the custom ruleset reference when it does). No CI is configured until then.

## Not verified

Nothing was run in a real WordPress site. The tests run on stubs.
