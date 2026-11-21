<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 603 — Index: the kinds as data, with a hierarchy

Status: **coded (2026-11-21), on `main`; see "What was built, and what differs from the plan" at the end.** Dependencies: slice 600 (the kinds of the terms and of the entries), 602 (the source `page` gives kinds). Needed by slice 610 (the attributes `include` and `exclude` of the index block take kinds and their sub-kinds). Context: [`../design/index.md`](../design/index.md), "The kind of an entry".

## Decided (Eric, 2026-11-21)

- The kinds can be **added** from the interface, and they have a **hierarchy** meaning *is a sort of* (a waterway **is a** place).
- **The starter kinds are protected** (Eric: "protéger les types wikidata de départ"): the kinds that the automatic deduction from Wikidata will map to (it comes in a later slice) must keep their identity.
- One slice of its own, before the builder of the index (610).

## Proposed

### The data

- A hidden hierarchical taxonomy **`index_kind`**: one term per kind. The **slug of the term is the identifier of the kind** (`place`, `waterway`...); this is what the terms (categories, tags) and the entries already keep in `_index_kind`, so **nothing is migrated**. The name of the term is the label; the parent is the broader kind. Term meta: `_index_kind_css` (the suffix of the CSS class, the slug by default) and `_index_kind_builtin` (`1` for a starter kind).
- **The starter kinds** are created once, in the language of the site, when the module starts and one is missing: `place` (with `waterway` under it), `species`, `route`, `activity`, `season`, `person`, `work`, `product`, `event`, `other`. **Protected** means: cannot be deleted, cannot be moved, its slug does not change. Its **label and its CSS class can be edited** (translation, wording), and sub-kinds can be added below it.
- **Kinds given by code** (the filter `index_kinds`, a list `slug => label`) still work and **add** roots when no kind of the site has the slug; they appear read-only on the screen. (Before this slice the filter *replaced* the list.)
- **Limits**: a slug is `[a-z][a-z0-9-]{0,30}`; at most **4 levels**; no cycle (a kind cannot go under itself or under one of its descendants).

### What the hierarchy changes

- **`Kinds::is_a( $kind, $of )`**: the kind is `$of` or one of its sub-kinds. `Kinds::descendants()`, `ancestors()`, `compatible( $a, $b )` (one is a sort of the other).
- **The resolver**: two kinds that are **compatible** do not conflict (a `place` and a `waterway` of the same label are one entry); `other` conflicts with nothing, as before.
- **Filtering**: `include="place"` takes the sub-kinds too (slice 610).
- **Typography**: an entry carries the classes of its kind **and of its ancestors**: `index-kind-waterway index-kind-place`.
- **Selectors** (the screen of the terms, the commands) list the kinds as a tree, with an indent.

### Deleting a kind

A kind that has **sub-kinds** is refused. A kind that is **used** (by terms or entries) is refused unless another kind is given to take them over (`--reassign=<kind>` / a selector on the screen): the terms and the entries are changed in one go. A protected kind is never deleted.

### Screen and commands

- **Otherguise → Index kinds**: the tree of the kinds with, for each, its slug, label, CSS class, number of terms and of entries, and a *protected* or *from code* mark; a form to add (slug, label, parent) and, per row, to edit and to delete. Capability `index_capability`, a nonce on each form.
- **`wp otherguise index kinds list|add|rename|css|move|delete`**; every command that writes can be run twice.

### Names

Taxonomy `index_kind`; term meta `_index_kind_css`, `_index_kind_builtin`; admin-post actions `otherguise_index_kind_add`, `_save`, `_delete`; page `otherguise-index-kinds`.

## Tests (planned)

Without WordPress (an in-memory store of the kinds): the tree and its order, `is_a`, `compatible`, the classes, the limits (slug, depth, cycle), the protection of a starter kind, the kinds from code, the deletion with and without reassignment, the resolver with compatible kinds, the screen (capability, nonce, saving, deleting), the commands, the seeding of the starter kinds (once, and not again after a rename). On the scratch WordPress: the taxonomy and its terms for real.

## Not done

The automatic deduction of the kind from Wikidata (a table from *instance of* to a kind, using the protected kinds); a translation of the labels of the starter kinds after they were created (they are data: rename them); the kinds shared between sites (export).

## What was built, and what differs from the plan (2026-11-21)

Built as proposed: the taxonomy `index_kind` (`TermKindStore`) behind a `KindStore` interface, the facade `Kinds` (the tree, `is_a`, `ancestors`, `descendants`, `compatible`, `classes`, `usage`, `add`, `edit`, `move`, `delete`, `seed`), the starter kinds seeded once on `init` (priority 12, one option), the screen **Otherguise → Index kinds** (`Admin\KindsScreen`) and `wp otherguise index kinds list|add|rename|css|move|delete`. The resolver uses `Kinds::compatible()`.

Decided while coding:

- **"Protected" means**: a starter kind **cannot be deleted or moved**, its **slug never changes**, and a form cannot move it either; its **label and CSS class can be edited**, and sub-kinds can be added below it. (Eric asked to protect the starter kinds "wikidata de départ"; I read it as the kinds that the automatic deduction from Wikidata will map to: **all eleven are protected**, `waterway` sits under `place`.)
- **The filter `index_kinds` adds roots and no longer replaces the list**; a kind of the site wins over a kind of code of the same slug.
- **The depth limit is four levels** (a root is level one); the selectors show a sub-kind with a dash per level.
- **The classes are `index-kind-<css>`**, the kind's own first, then those of its ancestors.
- The labels of the starter kinds are **stored in the language of the site at creation**; renaming is the way to change them.
- **Uninstall** removes the option that says the starter kinds were seeded; the kind terms stay (they are data).

### Verification

- **Under automated tests** (PHPUnit, 851 tests in the suite; an in-memory store of the kinds): the starter kinds and the seeding, the tree and its order, the hierarchy, the classes, the checks (slug, label, parent, depth, cycle), the kinds of code, the protection, moving, deleting with and without reassignment, the command, the screen (add, save, move, delete, capability, nonce, printing) and the resolver with compatible kinds.
- **On a scratch WordPress 7.1.4** (the real taxonomy): the eleven starter kinds were seeded once, a kind was added under `waterway`, its classes were `index-kind-riv index-kind-waterway index-kind-place`, a starter kind could not be moved or deleted and its label could be edited, a kind used by a category and an entry was refused, then deleted with `--reassign` and the category and the entry took the other kind. **WP-CLI and the screen were never run** (the screen is tested with doubles and printed in the tests only), and **nothing was tried on the blog**.
