<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

Moved here from `evlist/shared-room` on 2026-10-09. Points are marked **Decided** (Eric accepted it), **Proposed** (suggested by Claude, not confirmed) or **Open**. The delivered state is in [`../IA.md`](../IA.md) and [`../slices/`](../slices/README.md); this document keeps the reasoning.

# Packaging

**Decided: one plugin with three modules** (`Triples`, `Modes`, `Books`), built so that it can later be split into three plugins at little cost. Extract the core into its own plugin once its API has been stable for a while and a second consumer exists.

Reasons:

- The triples model is still moving (see the reification discussion); three plugins would freeze an unstable cross-plugin API from the first day.
- The core tables would be owned by one plugin while holding the data of the others; uninstalling it would destroy or orphan that data. WordPress 6.5+ `Requires Plugins` declares dependencies but does not constrain versions.
- Costs triple with three plugins: translations, `uninstall.php`, schema migrations, admin screens, CI and releases.
- Against: the boundaries are enforced by discipline and tests rather than by packaging, which is the reason for the rules below. Eric's earlier plugins (`wp-scatter-elsewhere`, `wp-media-helper`) are separate repositories.

Namespaces are necessary but not sufficient. The eight rules that keep the split cheap:

1. **One-way dependencies, checked by an automated test** (`deptrac` or equivalent): `Triples` knows nothing, `Modes` sees only `Triples`, `Books` sees only the other two.
2. **Talk through a public API, not concrete classes.** `Modes` never writes SQL in the `Triples` tables; it goes through interfaces and hooks (for example registering the `mode` entity type).
3. **Each module owns its data:** creation and migration of its tables, its schema version in its own option, its own cleanup in `uninstall`.
4. **Names belong to the module, not to the umbrella:** table names (`triples_statements`, not `otherguise_statements`), options, hooks, REST namespace (`triples/v1`), capabilities and text domain. This is the costliest to fix afterwards, because renaming stored data and settings needs a migration.
5. **A directory layout that lets a module be lifted out:** `plugin/modules/triples/`, `modules/modes/`, `modules/books/`, each with its own sources, tests, translation files and admin scripts.
6. **Tests per module** that run without loading the other modules (except the ones it depends on). This is the real proof that the separation exists.
7. **A module loader:** each module has its own bootstrap, and the plugin loads the list of enabled modules.
8. **No catch-all "common" directory.** If two modules need the same utility, either it belongs to the core or it is duplicated.

Work left at split time: plugin headers and `Requires Plugins`; a runtime check of the `Triples` API version (WordPress does not enforce plugin versions); taking over existing data and the activation order; CI, releases and translations in three copies; regrouping the admin menus. For Eric's own blog this is almost immediate; if other people install the plugin first, taking over their data needs more care.
