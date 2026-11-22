<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 605 — Index: the sources as a hierarchy, visibility, and the filter `include` / `exclude`

Status: **coded (2026-11-22), on `main`; see "What was built, and what differs from the plan" at the end.** Dependencies: 600 (mentions), 602 (the source `page`), 603 (kinds with descendants), 610 (the builder). Needed by 611 (the block of a book) and 612 (the markers). Context: [`../design/index.md`](../design/index.md), "Vocabulary and the dimensions of an index".

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

## What was built, and what differs from the plan (2026-11-22)

Built as proposed: `Index\Sources` (the registry, `is_a`, `descendants`, the tree, the filter `index_sources_registry`, a default visibility per source), the qualifiers `index/via` and `index/visible`, `Build\Selection` (the tokens, the descendants, the three dimensions), the selection in `IndexBuilder` and `wp otherguise index build --include/--exclude`, `data-index-modes` on a mark, and `wp otherguise index sources list|migrate|check`.

What differs or was decided while coding:

- **The harvesters keep their names** (`categories`, `tags`, `page`: what the option `index_sources` and `--source=` call them); **the sources of the mentions are the paths**. **`Source::mention_sources()` is new in the interface**: the sources (paths) a harvester can make, so that the indexer brings **each** of them in line with the post (a link that disappears leaves the mark of the same page). `Candidate::from( $source, $via, $modes )` gives a candidate its source, what produced it and its modes; `Source\Terms` makes `post/category` or `post/tag`, `Source\Page` makes `post/link` (a link to Wikipedia or to Wikidata) and `post/mark`. **A plugin that implements `Source` must add the method.**
- **The unit tested by the selection is a topic, a post and one source of the mention** (not the mention as a whole): a mention made by a tag and by a link is two units; **it is kept with the sources that pass and dropped when none does**; the locators the builder gives carry the **`sources` that passed**. This makes `source:image` give the photographs of a topic without the tag that also mentions it, and `exclude source:post/tag` leave the other sources of the same mention.
- **The values of `via` and `visible` are stored as `<source>|<value>`** so that each source owns its values (a sync replaces its own and withdrawing a source takes them); a value longer than 191 bytes keeps its beginning and a short hash. A candidate shown in no particular mode is **shown in every mode**, also when another candidate of the same topic and source names modes (**every mode wins**); a mention with no `index/visible` matches every `visibility:` token (Eric: every mode by default). The registry may give a source a default visibility; none does yet.
- **`sources migrate` renames `categories` → `post/category` and `tags` → `post/tag`, and drops `page`** (it is two sources now: a mention with no source left is deleted); **`wp otherguise index rebuild --source=page` then makes the links and marks again from the signals that slice 602 stored: no request**. Run twice it finds nothing to do.
- **`sources check`** lists the sources the mentions hold (flagging those that are not in the registry, and the old names) and the modes of the visibilities; it flags an unknown mode only when the filter **`index_known_modes`** answers (**nothing answers it yet**: the Modes module has no adapter, so the modes are listed but not judged).
- **A bare word in `include` or `exclude` is still a kind** (slice 610's form); a malformed token is refused by the command with the reason (the unknown dimension, kind or source).

### Verification

- **Under automated tests** (PHPUnit, 908 tests in the suite): the registry (paths, hierarchy, tree, the filter), the tokens and each dimension, `include` across dimensions, `exclude` winning, the qualifiers (several sources on a mention, a sync replacing only its own, withdrawing a source, nothing written twice, a long value), renaming and dropping a source, the `page` source making links and marks with their `via` and modes, candidates for one topic merging, **Eric's three indexes of a small book** (visible in print, general, photographs), the command with tokens and its refusals, the command of the sources.
- **On a scratch WordPress 7.1.4 holding mentions of the earlier slices**: `sources check` flagged the four old names, `migrate` changed 16 qualifications and a second run none, a reindex made `post/link` and `post/mark` again (no request), and the builder selected by `source:post/category` (8 lines), `source:post/tag` (2) and `kind:waterway` (3). **WP-CLI was not run** (the commands are tested with doubles).
- **Never tried**: on the real blog; the photographs and tracks as sources (slice 606); the block of a book (611).
