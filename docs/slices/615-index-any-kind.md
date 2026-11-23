<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 615 — Index: the root kind `any`, and `other` as a kind of its own

Status: **coded on 2026-11-23**. Dependencies: 603 (the kinds), 614 (the levels of a place). Context: [`../design/index.md`](../design/index.md).

## Why (Eric, 2026-11-23)

`other` had two meanings: **the kind of a topic that nobody classified** (the default of a link to Wikipedia, of a mark with no kind) and **a kind for what fits no other**. The roll-up of the places (614) showed the cost: a country topic made earlier by a link had the kind `other`, a candidate said `country`, and the resolver could not refine it (`country` is not below `other`): `kind:country` missed it.

## Decided (Eric, 2026-11-23)

- **`any`** is **the root of every kind** (a topic of this kind is not classified, and may take any kind below it); **`other`** is **a kind of its own**, a sibling of `place` or `species`.
- **`any` is the implicit root**: it is a built-in kind (protected, in the kinds screen) but **it is the stored parent of none**: nothing is moved, `Kinds::is_a( x, 'any' )` is true for every kind, `descendants( 'any' )` is all of them, `kind:any` in `include`, `exclude` or `rollup` means every kind (`all` stays a synonym for the roll-up).
- **A migration, once and automatic**: the topics of kind `other` whose kind **no person chose** become `any` (one query, option `index_kinds_any`). A kind set by hand stays. **The kind of a category or of a tag is not touched** (it uses the same meta key: the query is limited to the terms of the topics).

## As coded

- **Kinds**: `any` built-in (seed version 3, created at the next `init`); `is_a`, `descendants`, `compatible` know it; **no CSS class** (every line would carry it).
- **Defaults**: a link to Wikipedia or Wikidata, and a mark with no valid kind, give `any` (they gave `other`).
- **The resolver** needs no new rule: `any` is above every kind, so a topic of kind `any` takes the more specific kind that a candidate says (**unless a person chose its kind**), and is never made less precise. A topic of kind **`other` is classified and is not refined**.
- **`other` and the conflicts**: a kind `other` **still conflicts with nothing** (as `any`): a topic is not made ambiguous because a person typed `other` on a tag or a category. **Open**: whether `other` should conflict with the kinds that are not below it.
- **The Wikidata enrichment** gives its deduced kind to a topic of kind `any` too (it did to `other`).

## What it does not solve

A **tag or a category whose own kind a person set to `other`** gives the candidate `other`, which is a classified kind: a topic of the same name keeps **the kind that it had first** (a place that says `country` does not refine a topic made `other`, and a tag that says `other` does not make a `country` less precise). For such a tag, **set its kind to `any`** (or to the real kind) in the kinds of the terms. The merge of topics (not done) is the other tool.

## Tests

`IndexKindsTest` (the root, the descendants, no class, the conflicts), `IndexResolverTest` (`any` refined, a kind by hand kept, `other` not refined), `IndexKindsMigrationTest` (once, a failure is tried again), `IndexPagesTest` (the default is `any`). On a scratch WordPress: one topic `other` changed, a topic with the kind set by hand, a species and the meta of a category untouched; the second run changes nothing. **Not tried on the blog.**
