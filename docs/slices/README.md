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
| [103](103-triples-statements.md) | Triples: creating and reading statements |
| [104](104-triples-lifecycle.md) | Triples: cleanup, actions and cache |
| [105](105-triples-admin.md) | Triples: administration screen |
| [200](200-modes-active-mode.md) | Modes: declared modes, the mode of the request, access to the services |
| [201](201-modes-template-hooks.md) | Modes: where to hook the choice of a template (verification, no product code) |
| [202](202-modes-variants.md) | Modes: variants of templates and template parts |
| [203](203-modes-apply-variants.md) | Modes: applying the variants |
| [204](204-modes-admin.md) | Modes: the screen of the variants |

## Planned

None at the moment.

## Candidates (not planned yet)

Titles only. Each one is to be planned with Eric, from the decisions recorded in [`../design/`](../design/README.md), before any code is written.

- `1xx` Triples, after 105: 106 JSON export and import; later REST, then RDF export.
- `2xx` Modes, after 204: links and propagation of the mode, per-mode assets and options, side effects (`noindex`, `canonical`).
- `3xx` Books: composition of a book (ordered `contains` statements, parts by date range); single book page; table of contents; page numbers (declared strategy); index; export; renderer backends.
- Integration with Media Helper (attachments qualified by mode, one image attached to several posts).
