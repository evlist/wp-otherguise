<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 620 — Index: dropping what a source said

Status: **coded on 2026-11-23**. Dependencies: 605 (the sources), 616 (the merge), 619 (the cleaning). Context: [`../design/index.md`](../design/index.md).

## Why (Eric, 2026-11-23)

After `index prune`, the old topic of the safe merge (*A Guarda (Place)*, under a comarca) was **still at the top of the list**: **the pages that mention it had not been read again**, so a mention was still there, and a topic that a post mentions is **not** pruned. What is needed is **a command that removes what a source said**, to run **before `index pages start`**, so that reading the pages again starts from nothing.

## Decided (Eric, 2026-11-23)

A command that drops **all the topics of a given source**, to be launched before reading the pages again.

## As coded

**`wp otherguise index purge --source=<path> [--apply]`**:

1. **The mentions of the source go** (`Mentions::drop_source()`): the source and its `via` and visibility are withdrawn; **a mention that no other source also makes is deleted**; **a mention that another source also makes stays, without this source** (*Vigo* mentioned by a photograph and by a tag keeps the tag). **The source stands for its sub-sources**: `image` is `image/address`, `image/title`…, `trace` is `trace/start` and `trace/end`. The other sources, and what a person made by hand (`hand`), are not touched.
2. **Then the topics that nothing mentions any more are deleted**: the topics that the source made, **and their broader topics that nothing else mentions and that no topic kept below them holds** (a country that a link mentions stays; a province with no mention and no child goes). **A topic whose kind or label a person chose, and a refused topic, stay.**
3. **Nothing is changed without `--apply`**: without it the command says how many mentions would go and lists the topics that would be deleted.

**What to run after** (the command says it): `wp otherguise index pages start` makes again the links, the marks **and the photographs** (they are made while a page is read); `wp otherguise index rebuild` makes again the categories and the tags. What is deleted is made again, with its keys, if a source names it again.

## Not done

- A **screen**; a **dry run that shows the mentions** (only the counts and the topics); an **undo** (save the database first).
- **Not tried on the blog.**

## Tests

`IndexPruneDbTest::test_dropping_a_source`: the dry run (counts, the topics, nothing changed), the mentions that go and the one that stays without the source, the topic that goes and the broader topic that stays because something below it is mentioned, a kind chosen by a person, another source untouched, the sub-sources (`trace`) and the broader topic that then has nothing. A bad source name is refused with the list of sources.
