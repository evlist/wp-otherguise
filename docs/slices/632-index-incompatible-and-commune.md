<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 632 — Index: a commune is a third-level division, "incompatible" is not "ambiguous", a clearer screen

Status: **coded on 2026-11-24** (1025 PHPUnit tests, with a real database; the jsdom test of the box is run locally, CI does not run JavaScript tests; **never on the blog**: the designations that were "ambiguous" for this reason stay so **until `wp otherguise index designations resolve --apply` is run again**). Dependencies: 614, 615, 631.

## Why (Eric, 2026-11-24, from the screen of the assignments)

*Apt, which was considered ambiguous, has a single suggestion, and so has every ambiguous line of the page.* Read on the blog (the designation and the topic, by SQL): the designation `image/address|chain:france > provence-alpes-cote d'azur > vaucluse > apt` says **Apt is a `commune`**; the only topic Apt (14662, under *Vaucluse*) is **`admin3`**, with the one key `label:14661/apt`. `admin3` and `commune` were **two sisters** under `place`, so `Kinds::compatible` said "incompatible", and the resolver added a **fictitious candidate** ("another topic") to the one that exists: the list filtered it, and one choice was left. Eric: *it is far from a special case*, and *it should not be called ambiguous but incompatible*.

## What was built

- **A commune is a third-level division**: the built-in kind `commune` is **below `admin3`** (the same place is `admin3` for an administrative source and `commune` for the chain of a photo). `Kinds::seed` (version 5) **moves it once** on a blog that was seeded before, only a built-in kind that nobody moved. Consequences, all wanted: the two are compatible, the topic **takes the more precise kind** (`refine_kind` did that already for a sort of the kind), the filter `--kind=admin3` **includes the communes**, and the context of a label in the index (`index_context_skipped_kinds`, which skips the third divisions that are exactly `admin3`) **no longer skips a commune that was refined**: *Apt* shows in the context of its places.
- **"Incompatible" is not "ambiguous"**: a **single** topic of that label whose kind the source cannot mean is a result with `ambiguity.type = incompatible` (the topic as the only candidate, `found_kind`, `asked_kind`), **without the fictitious candidate**. The state stored stays `ambiguous` (no change of the states that the commands know), **the screen says it**: a view **Incompatible** with its count, the state *incompatible*, the question *"Apt" is incompatible: the topic is a Third-level division, the source says Commune.*, and one choice, **Keep this topic** (the choice is pinned like the others). A real ambiguity (several topics of the label) is unchanged. A topic of the same name cannot be made beside the one that exists (WordPress refuses it), so there is **no "new topic" choice** here: the person keeps the topic, or assigns the designation elsewhere with *Assign*.
- **"Any (not classified)"** read, in a filter, as "only what is not classified" (every kind is a sort of `any`, so choosing it showed **everything**). The filter of the screen of the topics **no longer lists the root**: its first entry is **Any kind**. The root is named **Unspecified (not classified yet)**, migrated once unless a person renamed it.
- **The box that says where "Assign to the topic…" puts the lines is hidden** until that action is chosen (in either list of actions); without JavaScript it stays visible.

## To do on the blog

1. `wp otherguise index designations resolve` (a dry run), then `--apply`: the designations of the communes are assigned to their topic, the topic takes the kind `commune`; what is left of "incompatible" is a real disagreement.
2. Look at the view *Incompatible* of Otherguise → Assignments.

## Not done

- Other pairs of kinds that are one thing seen twice (a `site` that is also a `route`...) are not looked for: the view *Incompatible* will show them.
- The kind of a topic that was refined is not moved back if `commune` is moved again by hand.

## Amendment (slice 640)

A **commune and a site** are now one place too (the same place at two precisions), without changing the tree of the kinds: see [`640-index-site-and-commune.md`](640-index-site-and-commune.md).
