<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# CLAUDE.md

Instructions for Claude Code sessions working in this repository.

## Read first

1. `docs/IA.md`: architecture, constraints, validation, current state.
2. `docs/slices/README.md`: the slices of work, done and planned.
3. The brainstorming and the decisions behind the project are in the repository `evlist/shared-room`, directory `wp-otherguise/` (`README.md` there marks every point as decided, proposed or open). Do not assume a point that is not marked decided.

## Language

- Talk to Eric in **French**.
- Everything stored in the repository (code, comments, documentation, commit messages) is in **English**.

## Rules

- Small vertical slices, test first when practical; document a slice in `docs/slices/` before coding it, and note its dependencies.
- Respect the module rules of `docs/IA.md` ("Modules"): one-way dependencies checked by `tests/phpunit/ArchitectureTest.php`, names (tables, options, hooks, REST namespace, capabilities, text domain) that belong to the module, no catch-all "common" code.
- Every file carries an SPDX header (GPL-3.0-or-later); keep `reuse lint` clean.
- WordPress coding standards; no `phpcs:ignore` outside tests.
- Capability and nonce checks, translatable strings and `uninstall` handling come with the feature, not afterwards.
- No `shell_exec` in product logic where a PHP implementation exists.
- Say what ran only under automated tests and what was never tried on a real WordPress site.
- The `.devcontainer/` and the `cs-grafting-*` workflows are grafted from `evlist/codespaces-grafting` and are not edited here.
- Never ask for, store or commit tokens or passwords. Do not create pull requests or push to another branch unless asked.
