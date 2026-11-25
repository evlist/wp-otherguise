<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 640 — Index: a site and a commune can be one place

Status: **coded on 2026-11-25** (1048 PHPUnit tests, 2 of them new; **never tried on the blog**: the 18 motifs of the view *Incompatible* are settled by `wp otherguise index motifs resolve`, not yet run). Dependencies: 614, 632.

## Why (Eric, 2026-11-25)

The 18 motifs of the view *Incompatible* are **all of the same kind of disagreement**: *"Igoumenítsa" is incompatible: the topic is a Site (below the commune), the source says Commune.* The chain of a photograph gives a locality as the **commune** (`settlement`), the chain of another gives it as a **site** below the commune (`locality`, `district`): one place, two readings, as with the ten motifs that Eric kept by hand (slice 632). *Can the rule be softened to accept a site when a commune is expected?*

## What was built

- **`Kinds::compatible()` accepts a commune and a site as one place**, **in both directions**: a new list of **pairs of kinds that name the same place at two precisions** (`Kinds::same_place()`, `commune` and `site` by default), changed by the filter **`index_kinds_same_place`**.
- **The tree of the kinds is not changed** (a site is not a sort of commune: `kind:commune` does not include the sites), and **the kind of the topic that is found is not changed** either (`refine_kind` only makes a kind more precise **down the tree**): nobody says which of the two precisions is the right one, so the topic keeps the one it has.
- **Every place where the compatibility is asked follows**: the resolver (a topic of the kind site met by a chain that says commune is found, not left *incompatible*), the assignment of a motif to a topic (no warning, no *force*), the merges.
- **The other disagreements stay** (a department is not a site, a species is not a commune): they are still *Incompatible*.

## To do on the blog

`wp otherguise index motifs resolve` (a dry run says how many settle), then `--apply`: the 18 motifs of the view *Incompatible* are **assigned by the system to the topic that has their name**, and the view should be empty (what is left is a real disagreement).

## A defect of the dry run, found the same day

`wp otherguise index motifs resolve` (a dry run) said **0 would be settled** for the 23 ambiguous motifs of the blog, with the rule in place. **The dry run was wrong, not the rule**: a dry run **makes nothing**, so a level that does not exist yet (the *last* level of the chain of each of these motifs: *paradiset*, *sandegyde*…) answers *no topic*, and the command counted it **still ambiguous**, although the real run makes the topic and assigns the motif. **A dry run now counts it as settled and says how many need a topic to be made** (`looked, settled (N with a topic to make), still`).

## Not done

- Other pairs that may be one place (`admin3` and `site`, a `route` and a `site`…) are not made: **the view *Incompatible* will say which ones come back**; the list is one filter.
