<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 605 — Index: the sources as a hierarchy, visibility, and the filter `include` / `exclude`

Status: **proposed (2026-11-22), not coded.** Dependencies: 600 (mentions), 602 (the source `page`), 603 (kinds with descendants), 610 (the builder). Needed by 611 (the block of a book) and 612 (the markers). Context: [`../design/index.md`](../design/index.md), "Vocabulary and the dimensions of an index".

## Decided (Eric, 2026-11-22)

- **Topic**, **topic kind**, **topic source**, **topic visibility**; sources are hierarchical (a post, an image, a trace, whoBIRD…); visibility says **in which modes** a topic is visible in a post, and **every mention is visible in every mode by default**.
- The block of index **selects the topics to list with `include` and `exclude` on their kind, source and visibility**, so that a book can have several indexes. The vocabulary change in the code (*entry* → *topic*) is **after slice 611**.

## Proposed

### The registry of sources (`Index\Sources`)

A **fixed list in the code**, with a filter `index_sources_registry` for a plugin of another author: a **path** (`post/tag`), a **label**, its **parent** is the part before the last slash, and an optional **default visibility** (every mode when absent). **Starting list**: `post` > `post/category`, `post/tag`, `post/link` (a link to Wikipedia or Wikidata in the contents), `post/mark` (a `data-index` mark); `image` > `image/address`, `image/title`, `image/category`, `image/tag` (**reserved**: the photographs slice); `trace` > `trace/start`, `trace/end` (**reserved**); `whobird`; `hand` (a mention made by a person). `is_a( $source, $of )`, `descendants( $path )`, as for the kinds.

### What changes on the mentions

- **`index/source` holds the path** of the leaf (`post/tag`), not a flat name. **Migration**: `categories` → `post/category`, `tags` → `post/tag`; the source `page` is **split**: its candidates carry their own source (`post/link`, `post/mark`), so `Source\Page` returns candidates with a `source` and the indexer **brings each source in line separately** (as it does now). `wp otherguise index sources migrate` renames the qualifier of the existing mentions in one go and can be run twice; a `rebuild` then settles the rest.
- **`index/via`** (a new qualifier of `index/mentions`, several values allowed): **what produced the mention**, as `<type>:<value>` — `image:<address>`, `link:<language>:<title>`, `mark:<id or value>`, `trace:<address>`. It is what 612 uses to find the printed element, and what lets an index of photographs name **which** photograph. A mention made by a category or a tag has none.
- **`index/visible`** (a new qualifier, several values): the **slugs of the modes** (`print`, `web`…) in which the mention is shown. **Absent means every mode.** It is written only when something narrows it: **`data-index-modes="web"`** (or `print`, or both) on a mark; or the **default visibility of a source** in the registry. Whether a mode exists is asked to the module Modes **through a filter, so that Index does not depend on it**; an unknown slug is kept and reported by `wp otherguise index sources check`.
- **Strength, role and anchor** are unchanged.

### The filter (`Build\Selection`)

`include` and `exclude` are lists of **tokens `dimension:value`**: `kind:place`, `source:image`, `visibility:print`; separated by spaces or commas. **A value stands for its descendants** (`kind:place` takes `waterway`; `source:post` takes `post/tag`). A token with **no dimension is a kind** (so that `--include=place` of slice 610 keeps its meaning). The rule:

1. a **mention** passes when, **for each dimension that `include` names, it matches one of the tokens** of that dimension (a dimension that `include` does not name is not tested), **and it matches no token of `exclude`**;
2. **kind** is tested on the topic, **source** and **visibility** on the mention; a mention with no `index/visible` **matches every `visibility:` token** (it is visible everywhere);
3. a topic is listed **only if one of its mentions passes**, and **its locators are those that passed**.

`IndexBuilder::build()` takes the selection in its options (`include`, `exclude`; the old forms stay); `wp otherguise index build --include=… --exclude=…` takes the tokens. **Which mentions an index lists is therefore the same in the command, the block of a book and the web block.**

## Tests (planned)

The registry (the paths, `is_a`, descendants, the filter that adds a source, a path that is not in it); the migration of the sources (the qualifiers renamed, run twice, nothing touched that is not old); `Source\Page` giving `post/link` and `post/mark` and the indexer settling each; the qualifiers `via` and `visible` (several values, a mark with `data-index-modes`); the selection (each dimension, the descendants, include across dimensions, exclude wins, a mention with no visibility, a token with no dimension, an unknown dimension refused with a message); the builder and the command with tokens; **the three indexes of Eric** on a small book (visible in print; general; photographs once the source exists).

## Not done

**Measuring the visibility by rendering every mode** (it multiplies the requests of the job; 612 gives the print part for free); the sources of images and traces (**the photographs slice**); the editor of the marks; renaming the code (after 611).
