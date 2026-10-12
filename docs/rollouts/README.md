<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rollouts

One file per iteration of the move of Eric's blog towards the modes and the books: what is done, in which order, how to check it and how to go back. The method is in [`../design/rollout.md`](../design/rollout.md); the tools are in [`../../tools/staging/`](../../tools/staging/README.md). A file is **written before it is run**, run on staging **from the text**, then replayed on production as written. Anything done outside the text is not part of the rollout.

The files are named `NNN-name.md`; the number `NNN` is the order of the iteration and is **not** the number `N` of the git tag `rollout-N`, which is given by `rollout.sh create` when the rollout is created on staging and written in the file at that moment.

| File | Iteration | State |
|---|---|---|
| [`TEMPLATE.md`](TEMPLATE.md) | the model to copy | - |
| [`001-baseline.md`](001-baseline.md) | the baseline: record what already runs on production | written, not run |
