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

## Planned

| Slice | Title |
|---|---|
| [208](208-modes-plugin-contract.md) | Modes: the contract for other plugins — filters and per-mode options (to confirm) |

## Candidates (not planned yet)

Titles only. Each one is to be planned with Eric, from the decisions recorded in [`../design/`](../design/README.md), before any code is written.

- `1xx` Triples, after 105: 106 JSON export and import; later REST, then RDF export.
- `2xx` Modes, after 205: propagation of the mode in internal links, a QR code to another mode, per-mode assets and options, side effects (`noindex`, `canonical`).
- `3xx` Books: composition of a book (ordered `contains` statements, parts by date range); single book page; table of contents; page numbers (declared strategy); index; export; renderer backends.
- `206` Modes, edit the variants in place and save with a button ([planned in detail](206-modes-screen-save.md), **not a priority**): change the template, the variant and the modes of a row, one **Save changes** button, all or nothing.
- `2xx` Modes, create a variant by copying: in the form **Add a variant** of **Settings → Otherguise modes**, an option "create the variant as a copy of the template" (with a name), so that a print version can be started from the web template without going through the site editor. Eric's request after finding that the list of templates of the editor offers only *Edit* and *Reset* (the **Add Template** button is at the top right of that list, and the template can then be filled through the code editor). Open: what the copy keeps (the whole content and the parts it uses), and whether it also declares the relation.
- `2xx` Modes, **disabled by default** (Eric, after trying the plugin on a copy of his site: the modes do nothing until a variant is declared, so they need not be on at activation). Proposal: the setting is off after activation, the screen says so at the top with the checkbox, and declaring a variant while the modes are off shows a warning that the variant has no effect yet. Changes the meaning of an absent setting (today: enabled).
- `2xx` Modes, default template per post type (Eric's request, not fundamental): the template that serves the **default mode** for a post type (`publication-randonnee` for posts) is the one given to new posts, instead of picking it by hand each time, and the other modes reach their own version through the relations (`publication-randonnee-print`). Proposed in [`../design/modes.md`](../design/modes.md); whether WordPress or the theme can already preselect a template was **not checked**.
- Integration with Media Helper (attachments qualified by mode, one image attached to several posts).
