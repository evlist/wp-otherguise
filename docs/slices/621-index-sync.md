<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 621 — Index: one synchronisation, and it runs by itself

Status: **coded on 2026-11-23**. Dependencies: 602 (the job of the pages), 604 (the enrichment), 606 (the photographs), 619 and 620 (the cleaning). Context: [`../design/index.md`](../design/index.md).

## Why (Eric, 2026-11-23)

*"I am frightened by the complexity of the administration of the index module and its phases of indexing. Can we simplify?"* To keep the index up to date one had to know **six commands in the right order** (`rebuild`, `pages start`, the second pass of the photographs, `enrich start`, `purge`, `prune`), with **three different statuses** and settings spread over options, commands and screens; a wrong order left leftovers.

## Decided (Eric, 2026-11-23)

- **Three phases, only one of them human**: **collect** (the categories and tags, the links, the marks, the photographs), **enrich** (Wikidata), **curate** (the duplicates: the screen). The first two are **automatic**.
- **`wp otherguise index sync`, and it runs by itself every night** (WP-Cron, which Eric's containers run from the system cron).
- To come, in this order (not done): **a dashboard screen** (the state of each phase, a button "Update", a button "Start again from scratch"), **a screen of the settings** (the sources, the contact of Wikidata, the thresholds of the photographs), and **one step of rollout** for the production (enable the module, then `sync`).

## As coded

**`wp otherguise index sync [start|status|stop|auto on|off] [--from-scratch] [--foreground]`**

- **`start`** (the default): **one job**, in the background (an event of WP-Cron every few seconds, a limited time of work for each), that does the phases **in order**:
  1. **collect**: for each post **that changed** (never read, or read before it was modified; when the pages are not read: modified since the last run), the **categories and the tags**, then the **page** (the links, the marks and the photographs, as `pages start` does);
  2. **enrich**: the topics that Wikidata has not been asked about, **when the enrichment is on**; a service that says "wait" pauses it, **the next run goes on**;
  3. **cleanup**: **the topics that nothing mentions any more are deleted** (the ones whose kind or label a person chose, the refused ones and the candidates that wait for a decision stay: the same rules as `index prune`).
- **`--from-scratch`**: first **drops what the sources said** (the pages, the tags and categories, the photographs and the tracks, as `index purge`; **never what a person made by hand**), then reads **every** post. **If a post could not be read, or nothing could, the cleanup is skipped** (what was dropped is missing and the topics that only these posts mentioned would be deleted for nothing): the log says so, and the next run does it.
- **`--foreground`**: does it here and now, printing the progress; **`status`** says the phase, the progress of the reading and of the enrichment, and when the last run finished; **`stop`** stops it (a run of what changed starts again where it can); **`auto on|off`** turns the night run on or off.
- **The nightly run** (event `otherguise_index_sync_auto`, daily at 03:17 UTC, planned at each load if it is not): starts **a run of what changed**, unless one is going on; **on by default**; `index sync auto off` stops it (option `index_sync_auto`).
- **Nothing is done here that a person could not do by hand**: the pieces are those of the commands, which stay as tools for an expert.
- **The posts of a run** are kept in the option `index_sync_ids`, the state in `index_sync` and `index_sync_job`; they are removed when the module is uninstalled.

## What the cleaning protects (changed with this slice)

`index prune` and the cleanup of the synchronisation now **keep the topics that are not approved**: a refused topic (it stops a source from making it again) **and a candidate** (the queue of the ambiguous topics is made of candidates); before, only the refused ones were kept.

## Not done

- The **dashboard** and the **screen of the settings**; a **step of rollout** for the production.
- **Not tried on the blog**: the nightly event, the long runs, the enrichment inside a run. On a scratch WordPress with the real storage (no server to read the pages): the plan of the nightly event, a run of what changed and a run from scratch end well; the pages could not be read (every post failed), which also checks that **the cleanup is skipped** when nothing could be read.

## Tests

`IndexSyncTest` (no database; options, cron and pieces in memory): a run of what changed (the order of the phases, the posts read, the enrichment queue, the cleanup, no tick when it is over), from scratch (the mentions dropped, every post, a failure counted), a run that is running, stop and start again, the enrichment that waits or is off, the automatic run and the planning of the daily event, the cleanup that is skipped, the command (start, already running, status, stop, foreground, auto, a wrong word). `IndexPruneDbTest`: a candidate is kept.
