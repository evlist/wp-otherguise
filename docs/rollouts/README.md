<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rollouts

One file per iteration of the move of Eric's blog towards the modes and the books: what is done, in which order, how to check it and how to go back. The method is in [`../design/rollout.md`](../design/rollout.md); the tools are in [`../../tools/staging/`](../../tools/staging/README.md). The procedure of an iteration is a **script** ([`../../rollouts/`](../../rollouts/), one per rollout, built on [`rollout-lib.sh`](../../tools/staging/rollout-lib.sh)) that runs the commands, checks and asks the validations and the manual gestures; this directory keeps the story of each one. The script is rehearsed on staging as often as needed and run on production when it is right; production refuses a script that was not rehearsed to the end on staging.

The files are named `NNN-name.md`; the number `NNN` is the order of the iteration and is **not** the number `N` of the git tag `rollout-N`, which is given by `rollout.sh create` when the rollout is created on staging and written in the file at that moment.

| File | Iteration | State |
|---|---|---|
| [`TEMPLATE.md`](TEMPLATE.md), [`../../rollouts/TEMPLATE.sh`](../../rollouts/TEMPLATE.sh) | the models to copy | - |
| [`001-baseline.md`](001-baseline.md) | the baseline: record what already runs on production (one command, no script) | applied on production |
| [`002-child-theme.md`](002-child-theme.md), [`../../rollouts/002-child-theme.sh`](../../rollouts/002-child-theme.sh) | the templates move into the child theme, which becomes the active theme | applied on production (2026-10-13), tag `rollout-2` |
| [`003-print-format.md`](003-print-format.md), [`../../rollouts/003-print-format.sh`](../../rollouts/003-print-format.sh) | `?mode=print` replaces `?print`, the galleries and the QR code come from the new blocks, eight plugins are deactivated, `wp-pdf-helper` last | applied on production (2026-10-15), tag `rollout-3` |
