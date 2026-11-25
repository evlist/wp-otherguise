<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 641 — Index: tags are topics, and `_None_` is the topic of what is not indexed

Status: **coded on 2026-11-25** (1050 PHPUnit tests, jsdom tests; **never tried on the blog**). Dependencies: 635, 636, 637.

## Why (Eric, 2026-11-25)

Two decisions, replacing slice 635:

1. **The "candidate" rule for tags with no kind is abandoned**: every tag is a motif and **its topic is approved** like the topics of the other sources; a person **excludes** the ones that are not wanted.
2. **Excluding is assigning to a real, reserved topic, `_None_`** (not a state of the motif): it shows in the *Topic* column, is filtered, sorted and changed like any assignment. The name starts with `_` because the lists fold case and keep punctuation: it sorts **before** the letters, apart from the other topics.

## What was built

- **`NoneTopic`**: the reserved topic, status `refused`, kind `any`, key `reserved:none`, made on the first exclusion. The topic **says it is the one** by its key (nothing is remembered, so two instances never disagree).
- **Exclude** = assign to `_None_` (by hand); **Include again** = unassign (the motif waits to be resolved again). The state `excluded` is gone.
- **No mention** is made for a motif assigned to `_None_` (`Mentions::skips`, used when a post is derived and when it is indexed); its observations stay.
- **Protections**: never deleted by the cleaning (`index prune`), never merged (either way), not editable on the *Topics* screen.
- **`Source\Terms`** no longer marks an unclassified term as not sure: its topic is approved, of the kind `any`.
- The view *Excluded* of the screen *Assignments* lists the motifs assigned to `_None_`; the column *Topic* says *not in the index*.

## To do on the blog

- The 81 topics that are **already candidates** stay so until a person triages them or runs `wp otherguise index approve --apply`; tags read again (`index rebuild --source=tags`) give approved topics.
- No migration of the old state `excluded` (the blog had none).

## Not tried

Everything ran under PHPUnit and jsdom only; nothing on the real blog.
