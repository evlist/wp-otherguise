<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 642 — Index: the status `ambiguous`, and no more "sure" sources

Status: **coded on 2026-11-25** (1050 PHPUnit tests; **never tried on the blog**). Dependencies: 635, 641.

## Why (Eric, 2026-11-25)

After slice 641 the only topics that were still *candidates* were the placeholders of an unresolved doubt: *"if ambiguous is the only candidate, call it ambiguous."* **Go for the complete version.**

## What was built

- **`Topics::CANDIDATE` becomes `Topics::AMBIGUOUS`** (`ambiguous`): the filter of the screen *Assignments* (*Topic status*), the view of the screen *Topics* (`show=ambiguous`), the command `index approve` and the help of `index list`/`topics` say it.
- **No migration to run**: the WordPress store **reads the old stored value `candidate` as `ambiguous`**, filters on both, and writes the new one (`Topics::LEGACY_CANDIDATE`). The value on disk changes whenever a status is set.
- **The notion of a sure source is removed**: `Source::is_sure()`, `Candidate::sure`, the `sure` of the stored evidence and the parameter `$sure` of `Resolver::resolve()` are gone; **a topic made by a source is always approved**, and an ambiguous topic that a source names is approved.
- A candidate level that nobody classified is the kind `any` like in 641.

## Not tried

The store on WordPress (reading and filtering the old value) was **never run**: the PHPUnit tests use the in-memory store. To check on the blog: the filter *Ambiguous* of *Assignments* and `wp otherguise index approve` (dry run).
