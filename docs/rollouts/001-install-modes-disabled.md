<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rollout 001: Otherguise and Media Helper installed, modes disabled

Status: **written, not run**. Tag: `rollout-N` (to be filled in by `rollout.sh create`).

## Goal and visible effect

Put the two plugins on production **without changing anything visible**: the modes are disabled by default, so the site keeps answering `?print` with the old plugin. What changes: two plugins are active, one table exists (the statements, qualifiers included: `{prefix}triples_statements`), the screens **Settings, Otherguise modes** and **Tools, Otherguise relations** exist, and the Media Helper panel appears in the editor with its native method (nothing is attached differently). This iteration proves that the versions travel (the tag), that the plugins activate on the real data, and that nothing else moves.

## Preconditions

- [ ] The containers of staging and production mount the clones of `wp-otherguise` and `wp-media-helper` (and of the other plugins of Eric under development): `rollout.sh status --site=staging` and `rollout.sh status --site=prod` list them. **If a clone is not listed, add its mount first (docker compose) and restart the container: a rollout applies tags to what is mounted.**
- [ ] The clones are clean and their `main` is pushed: `rollout.sh status --site=staging` shows no `LOCAL CHANGES`.
- [ ] Staging is a fresh copy of production: `sync-site.sh --target=staging`, then the label of its frame shows today's date.
- [ ] `wp-pdf-helper` is **still** deactivated on production, as it is on staging (this rollout does not touch it).

## Versions (on staging)

```sh
rollout.sh update --site=staging        # the clones at the commits to rehearse
rollout.sh status --site=staging        # note the commits here
```

After the rehearsal below, when everything agrees:

```sh
rollout.sh create --site=staging        # tags them rollout-N and pushes the tags
```

Paste the output here and put `rollout-N` in the header.

## Backup (production only)

```sh
docker exec -u www-data <production container> wp db export /tmp/before-rollout-N.sql
```

(then copy the file out of the container, or write it in a mounted directory.)

## Commands

On staging first, **from this text**, then on production (`<c>` is the container of the site):

1. `rollout.sh apply N --site=prod` **(production only; on staging the commits of `update` are already there)**: the clones are on `rollout-N`. Expected: each repository listed with its change, `Done.`
2. `docker exec -u www-data <c> wp plugin activate wp-media-helper wp-otherguise`: expected: both `Success: Activated`. Media Helper first: Otherguise offers its method only when Media Helper is there.
3. `docker exec -u www-data <c> wp plugin list --fields=name,status,version`: both `active`, the other plugins unchanged.
4. `docker exec -u www-data <c> wp help otherguise media migrate`: the command is known (it is **not** run in this rollout).

## Manual steps

None.

## Verification

Run on staging, then on production; every line must agree.

| Check | Command | Expected |
|---|---|---|
| the modes are disabled | `wp eval 'var_dump( get_option( "modes_settings" ) );'` | `bool(false)` (or `enabled` false) |
| `?print` still works as before | open a post with `?print` | the page of the old plugin, as before |
| no new error | `wp eval 'echo "ok";'` and the PHP error log | `ok`, nothing new in the log |
| the screens exist | open Settings, Otherguise modes | the screen opens, "disabled" is said |
| the tables exist | `wp db query "SHOW TABLES LIKE '%triples_statements'"` | one table |
| the template of the blog is unchanged | open a post with the template `publication-randonnee` | as before |

## Rollback

1. `wp plugin deactivate wp-otherguise wp-media-helper` (the tables stay; they are removed only by the uninstall of the plugin, which is not wanted here).
2. `rollout.sh rollback --site=<site>` puts the clones back where they were.
3. The backup is needed only if something wrote to the database in a way not expected (nothing should).

## Log

(Dates, sites, differences from this text.)
