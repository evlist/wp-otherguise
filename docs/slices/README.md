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
| [205](205-modes-link-block.md) | Modes: the link to another mode (block `modes/link`) |
| [207](207-modes-stylesheets.md) | Modes: a stylesheet per mode |
| [210](210-media-method.md) | Media: the attachment method of Otherguise for Media Helper (several posts, modes, ranks) |
| [209](209-modes-default-template.md) | Modes: the default template of new posts (WordPress has no such setting; independent of the modes) |
| [204](204-modes-admin.md) | Modes: disabled by default (a change of slice 204: see its last note) |

## Planned

None at the moment.

## Candidates (not planned yet)

Titles only. Each one is to be planned with Eric, from the decisions recorded in [`../design/`](../design/README.md), before any code is written.

- `1xx` Triples, after 105: 106 JSON export and import; later REST, then RDF export.
- `2xx` Modes, after 205: propagation of the mode in internal links, a QR code to another mode, per-mode assets and options, side effects (`noindex`, `canonical`).
- `3xx` Books: composition of a book (ordered `contains` statements, parts by date range); single book page; table of contents; page numbers (declared strategy); index; export; renderer backends.
- `208` Modes, a contract for other plugins: the filters `modes_active_mode` and `modes_option` and per-mode options set on the screen ([planned in detail](208-modes-plugin-contract.md), **not needed for now**: parameters of a block are better set in its call, in each template; useful for plugins without a block).
- `206` Modes, edit the variants in place and save with a button ([planned in detail](206-modes-screen-save.md), **not a priority**): change the template, the variant and the modes of a row, one **Save changes** button, all or nothing.
- `2xx` Modes, create a variant by copying: in the form **Add a variant** of **Settings → Otherguise modes**, an option "create the variant as a copy of the template" (with a name), so that a print version can be started from the web template without going through the site editor. Eric's request after finding that the list of templates of the editor offers only *Edit* and *Reset* (the **Add Template** button is at the top right of that list, and the template can then be filled through the code editor). Open: what the copy keeps (the whole content and the parts it uses), and whether it also declares the relation.
- Integration with Media Helper (attachments qualified by mode, one image attached to several posts).
