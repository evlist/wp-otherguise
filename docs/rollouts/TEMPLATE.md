<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rollout NNN: title

Status: **written** / **rehearsed on staging on YYYY-MM-DD** / **applied on production on YYYY-MM-DD**. Tag: `rollout-N` (filled in by `rollout.sh create`).

## Goal and visible effect

One or two sentences: what changes, and what a visitor or the administrator sees on production afterwards (often: nothing).

## Preconditions

What must be true before starting, each with the command that checks it. For example:

- [ ] production and staging mount the clones of the plugins and of the theme: `rollout.sh status --site=prod`
- [ ] staging is a fresh copy of production (date in the label of its frame; otherwise `sync-site.sh`)

## Versions

The output of `rollout.sh create --site=staging` (repositories and commits), pasted here when the rollout is created.

## Backup (production only)

```sh
wp db export before-rollout-N.sql
```

and anything else that the steps change (for example `templates.sh export` of what will be deleted).

## Commands

Numbered. **Each one is a command that can be run twice**, with what to expect. On staging, then on production (`docker exec -u www-data <container> wp ...`).

1. `command`: expected result.

## Manual steps

Only what has no command yet (see the list in `../design/rollout.md`). Numbered, each with **what to check afterwards**.

1. Gesture: check.

## Verification

Commands whose output is compared with what is expected. The rollout is done only when all of them agree, on staging and then on production.

## Rollback

How to go back, in the order to do it (the code: `rollout.sh rollback --site=<site>`; the database: the backup above; the settings: the inverse commands).

## Log

Date, site, who ran it, what differed from the text (and the correction made to the text).
