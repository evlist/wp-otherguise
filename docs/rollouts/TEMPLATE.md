<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rollout NNN: title

Status: **written** / **rehearsed on staging on YYYY-MM-DD** / **applied on production on YYYY-MM-DD**. Tag: `rollout-N` (filled in by `rollout.sh create`).

The procedure is the script `rollouts/NNN-name.sh` (copy [`../../rollouts/TEMPLATE.sh`](../../rollouts/TEMPLATE.sh)); this file keeps what a script cannot say: why, what to watch, what was observed. Do not repeat the commands here.

## Goal and visible effect

One or two sentences: what changes, and what a visitor or the administrator sees on production afterwards (often: nothing).

## The script

How to run it (staging, tag, production) and what it does, in a short paragraph; what `--no-validate` skips.

## What to watch

The pages and the settings to look at, and the one risk that deserves a look.

## Versions

The output of `rollout.sh create --site=staging` (repositories and commits), pasted when the rollout is created.

## Log

Date, site, who ran it, what differed from the text and the correction made to it.
