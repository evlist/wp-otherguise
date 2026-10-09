<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slices

One document per slice of work, named `NNN-name.md`. A slice is documented before it is coded, lists its dependencies, and is marked done when its tests pass.

Numbering: `0xx` foundation, `1xx` Triples, `2xx` Modes, `3xx` Books.

## Done

| Slice | Title |
|---|---|
| [000](000-repository-scaffold.md) | Repository scaffold: structure, tooling, module loader, architecture tests |
| [100](100-triples-registry.md) | Triples: entity types and predicate registry (qualifier part superseded by 101) |
| [101](101-triples-registry-revision.md) | Triples: registry revision for statements about statements |
| [102](102-triples-storage.md) | Triples: storage of statements (one table) |

## Planned

| Slice | Title | Status |
|---|---|---|
| [103](103-triples-statements.md) | Triples: creating and reading statements | done |
| [104](104-triples-lifecycle.md) | Triples: cleanup, actions and cache | done |

## Candidates (not planned yet)

Titles only. Each one is to be planned with Eric, from the decisions recorded in [`../design/`](../design/README.md), before any code is written.

- `1xx` Triples, after 103: 104 cleanup when posts, terms, media or users are deleted, the actions, and the object cache; 105 admin screen; 106 JSON export and import; later REST, then RDF export.
- `2xx` Modes: modes and the query-string trigger; relations between templates (block templates from the site editor and from files, template parts); mode link block; mode options and integration hooks.
- `3xx` Books: composition of a book (ordered `contains` statements, parts by date range); single book page; table of contents; page numbers (declared strategy); index; export; renderer backends.
- Integration with Media Helper (attachments qualified by mode, one image attached to several posts).
