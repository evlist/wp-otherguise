<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 613 — Index: the broader topics with the pages of what is below them (roll-up)

Status: **coded on 2026-11-23** (form 1 of Eric's choice: a flat roll-up; the hierarchical index, form 2, is not done). Dependencies: 610 (the builder), 611 (the block), 603 (the kinds), 605 (the selection). Context: [`../design/index.md`](../design/index.md).

## Why (Eric, 2026-11-23)

The first index of a book showed *Cyclo-randonnées (Vélo)* but no line *Vélo*: a topic has a line only when a post mentions it itself, and a post in a child category is not in the parent one. Eric wants the parent categories to get a line, and the same for the places (the country, the region, the department of France), **with a mechanism that is not limited to categories**.

## Decided (Eric, 2026-11-23)

- **Form 1**: **a flat roll-up**. The broader topic has **a line of its own with the pages of everything below it**; the line of the narrower topic stays as it was. The hierarchical index (the narrower topics indented under the broader one) is **for later**, on top of this.
- **A general mechanism**, not one for the categories.

## As coded

- **The option `rollup`** (block attribute, `wp otherguise index build --rollup=…`, key of the options of the filter `index_build`): **the kinds of the broader topics that get a line**, written as the kind tokens of the selection (`kind:other`, `kind:place`, several allowed; a value stands for its sub-kinds), or **`all`** (every broader topic). Empty: nothing, as before. **Only kinds**: `source:` and `visibility:` are refused (they are chosen with `include` and `exclude`); a wrong value is an error in the command; through the filter it is **told, not hidden** (see below).
- **What a rolled-up line holds**: for each line made from the mentions (the **snapshot**, so that the result does not depend on the order), every **broader topic up the chain** (at most 20 levels, cycles stopped) **of an asked kind** gets the locators of that line: **once per post**, the **strongest strength**, the sources and the `via` of every descendant (so the print index finds the pages of the elements that show them), **in the order of the book**. A broader topic that a post mentioned itself **keeps** its own mentions, merged.
- **What it does not do**: it rolls up **what the selection kept** (an excluded source stays out); **an approved topic only** (a candidate gets no line, but the topics below it still have theirs); the broader topic is **not tested against `include`** (asking for `kind:place` in `rollup` is enough to give a line to a place that no post mentioned).
- **Order and labels** are those of the builder: a line is placed by its own label; the broader topic is shown in parentheses after the label as before (`context`).

## A wrong option is told, not hidden (2026-11-23)

A wrong `include`, `exclude` or `rollup` used to make the filter `index_build` answer `null`, and **the block then printed nothing**: an index silently empty (found by Eric with `kind:category`, a suggestion of the block that was wrong). **Now the filter answers an array with `errors` and no group**; the messages list what is valid (`Unknown kind "category". The kinds: place, species, …`). `IndexSlots` puts **a visible note** (`books-index__error`, "Index not made: …") **in print always** (a PDF is not public) **and, elsewhere, for the people who can edit posts**; a visitor of the web page sees nothing. The suggestions of the block, of the command and of the documentation now name kinds that exist (`kind:other`, `kind:place`).

**Checked while typed (2026-11-23)**: the sidebar of the block asks, after a pause of half a second, the route `GET books/v1/index-options` (`include`, `exclude`, `rollup`; for the people who can edit posts), which asks **the module of the index** (the filter `index_build` for no post: the options are checked before anything is computed, field by field) and answers the errors of each field; the message is shown **in red under its field**, with the list of what is valid. A route that is missing or fails says nothing and the editing goes on. Not done: a completion of the kinds while typing (the same route could give the list).

## What it needs for the places (not done)

**A country, a region, a department and a commune are all of the kind `place`** today, so `kind:place` would give a line to **every level, the communes too** (France with every page of the book). To choose the levels, **the level of a place has to be data**. The proposal, **not decided**: **sub-kinds of `place` created by the kinds of the places** (`country`, `region`, `department`, `commune`, `site`), **set by the photographs and the tracks when they make the chain of a place** (`PlaceChain`) and editable like any kind; `rollup="kind:country kind:region kind:department"` would then do what Eric asks. It needs **a migration of the topics already made** (their kind is `place`) and the choice of the names of the levels for the countries that are not France.

## Tests

`IndexBuildTest`: flat by default; the roll-up up a chain of three levels, once per post, strongest strength, in the order of the book; the hamlet keeps its line; only the kinds asked for; `all`; the selection and the approval; the validation of the option. Under PHPUnit and with real statements; **not tried on the blog** (the PDF with the page numbers of the rolled-up lines has not been made).
