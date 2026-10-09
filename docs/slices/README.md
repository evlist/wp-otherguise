<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slices

One document per slice of work, named `NNN-name.md`. A slice is documented before it is coded, lists its dependencies, and is marked done when its tests pass.

Numbering: `0xx` foundation, `1xx` Triples, `2xx` Modes, `3xx` Books.

## Done

| Slice | Title |
|---|---|
| [000](000-repository-scaffold.md) | Repository scaffold: structure, tooling, module loader, architecture tests |

## Candidates (not planned yet)

Titles only. Each one is to be planned with Eric, from the decisions recorded in `evlist/shared-room` (`wp-otherguise/README.md`), before any code is written.

- `1xx` Triples: predicate registry; storage of statements and qualifiers (two tables); PHP API; admin screen with JSON export and import.
- `2xx` Modes: modes and the query-string trigger; relations between templates (block templates from the site editor and from files, template parts); mode link block; mode options and integration hooks.
- `3xx` Books: composition of a book (ordered `contains` statements, parts by date range); single book page; table of contents; page numbers (declared strategy); index; export; renderer backends.
- Integration with Media Helper (attachments qualified by mode, one image attached to several posts).
