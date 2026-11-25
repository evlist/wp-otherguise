<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 634 — Index: the vocabulary of the module, a designation is now a motif

Status: **coded on 2026-11-25** (1033 PHPUnit tests, with a real database; **never tried on the blog**). Dependencies: 625 to 633. The words and what they mean: [`../design/vocabulary.md`](../design/vocabulary.md) (**decided by Eric on 2026-11-25**).

## Why (Eric, 2026-11-25)

*"This module associates topics with posts by observing patterns in the posts."* The word *designation* was heavy, and *pattern* clashed with the block patterns of WordPress: the word is **motif** (it works in French and in English, and WordPress does not use it). **Observation** and **kind** stay.

## What was done

- **The screens** (Assignments, Topics, Suggestions, the quick edit): *motif*, *motifs*, *Search motifs*, the column **Motif**, the messages, the notices, the help line, the words of the script.
- **The command is `wp otherguise index motifs`** (`list`, `assign`, `clean`, `migrate`, `measure`, `resolve`), **with no alias**: `index designations` no longer exists. In `index motifs assign`, the option **`--designation=<ids>` is `--motif=<ids>`**. The messages of the other commands (`purge`, `reset`, `enrich`) say *motif* too.
- **The REST routes** of the quick edit are `index/v1/motifs/<id>/neighbours` and `index/v1/motifs/<id>/topic`.
- **The labels of the predicate of the assignment** in the registry of Triples: *Is assigned to* and *Has the motif*.
- **The documents**: `docs/IA.md` and the vocabulary; the documents of slices 625 to 633 are history and keep the old word (a note says so).

## What was not renamed, on purpose

The storage (the hidden taxonomy `index_designation`, the meta `_index_*`, the predicate `index/designates`), the **classes and the variables of the code** (`Designation\…`, `Designations`, `$designations`), the **names of the fields of the forms and of the arguments of the addresses** (`designation[]`, `?designation=`), and the files. **A rename of the storage would need a migration of the data of the blog and brings nothing to a person: it stays open.** **In the code, *designation* means *motif*.**

## To look at

- The French translation of the strings, if the plugin has one (the `.pot` was not regenerated).
- Anything that calls `wp otherguise index designations` outside this repository (a script of `shared-room`, a cron line of the blog): **it has to say `motifs`**.
