<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 619 — Index: the context without the comarca, and the cleaning of the topics nobody mentions

Status: **coded on 2026-11-23**. Dependencies: 610 (the builder), 614 (the levels of a place), 616 (the merge). Context: [`../design/index.md`](../design/index.md).

## Why (Eric, 2026-11-23)

Seen on the screen of the duplicates (slice 618): the safe merge *A Guarda* proposed a topic under *Espagne > Galice > O Baixo Miño*, a comarca, where Eric expects the province (*Pontevedra*). It is **a topic of the older chain** (before the levels were read from their numbers, slice 617): reading the pages again makes *Galice > Pontevedra > O Baixo Miño > A Guarda* **as a new topic**, and the old one has no mention left. And the label of a commune would show its **direct** parent, the comarca: *A Guarda (O Baixo Miño)*, where the province, which tells two communes of one name apart, is wanted: *A Guarda (Pontevedra)*.

## Decided (Eric, 2026-11-23)

Both: the context **skips the comarcas**, and a command **cleans the places nobody mentions**.

## As coded

### The context (the broader topic in parentheses)

- The label shows **the nearest broader topic that is not of a skipped kind**: by default the third divisions (**`admin3`**): *A Guarda (Pontevedra)*. **The comarca itself keeps its province** (*O Baixo Miño (Pontevedra)*), and **a site below a commune keeps the commune** (*San Román (Lascellas-Ponzano)*). When **every** broader topic is of a skipped kind, the direct one is shown.
- **The kinds that are skipped** are those of the filter **`index_context_skipped_kinds`** (default `admin3`): a **setting** for the countries where the third level means something else is **to come**.

### `wp otherguise index prune [--kind=<kinds>] [--apply]`

- Lists, and with **`--apply` only** deletes, **the topics that no post mentions**: by default the **places** (`place` and its sub-kinds); `--kind=` changes the kinds (`any` is every kind). **Read the pages again first** (`wp otherguise index pages start`).
- **A topic stays** when a post mentions it (`Mentions::mentioned_topics()`, one query), when **a topic below it stays** (a region with a province that is mentioned), when **a person chose its kind or its label**, when it is **refused** (it stops a source from making it again), or when it is of **another kind** than the ones asked for. The deepest topics go first.
- **What is deleted is made again, with its keys, if a source names it again.** **Nothing is deleted without `--apply`.**

## Not done

- The **screen** of the cleaning (the command is the tool for now); a **setting** of the skipped kinds per index (the block).
- **Not tried on the blog**: the effect of the context on the printed index, and the cleaning of the old places after a re-reading.

## Tests

`IndexBuildTest` (the context: the province, the comarca with its province, the direct topic when nothing else is above, the filter); `IndexPruneDbTest` (what goes and what stays: mentioned, kept child, kind or label by hand, refused, another kind, every kind; the dry run; the command). On a scratch WordPress with the real storage: the old chain goes, deepest first, and the mentioned topic stays.
