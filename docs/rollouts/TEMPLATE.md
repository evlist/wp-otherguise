<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rollout NNN: title

Status: **written** / **rehearsed on staging on YYYY-MM-DD** / **applied on production on YYYY-MM-DD**. Tag: `rollout-N` (filled in by `rollout.sh create`).

The procedure is the script `rollouts/NNN-name.sh` (copy [`../../rollouts/TEMPLATE.sh`](../../rollouts/TEMPLATE.sh)); this file keeps what a script cannot say: why, what to watch, what was observed. Do not repeat the commands here.

## Goal and visible effect

One or two sentences: what changes, and what a visitor or the administrator sees on production afterwards (often: nothing).

## Prerequisites: what must exist before the rollout

What has to be **implemented** in the plugins and in the theme, and **tried on testing**, before the rollout is rehearsed on staging: one line per feature, with the repository, the commit or the tag, and where it was tried. A rollout only moves what already works; it does not develop it. Mark each line when it is true.

| Done | Feature | Repository and commit | Tried on testing |
|---|---|---|---|
| [ ] | | | |

Plugins that are **not git clones mounted in the containers** (those copied from Gitea) are not covered by the tag `rollout-N` and are put back to the state of production at every synchronisation: say which ones, which commit to install, and how the script checks it (a functional check, not a version number).

## The script

How to run it (staging, tag, production) and what it does, in a short paragraph; what `--no-validate` skips.

## What to watch

The pages and the settings to look at, and the one risk that deserves a look.

## Versions

The output of `rollout.sh create --site=staging` (repositories and commits), pasted when the rollout is created.

## Log

Date, site, who ran it, what differed from the text and the correction made to it.
