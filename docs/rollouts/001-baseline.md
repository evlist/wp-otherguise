<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rollout 001: baseline

Status: **applied on production on 2026-10-12** (`rollout.sh create --baseline --site=prod`). Tag: `rollout-1`, in the four repositories.

## Goal and visible effect

Production was set up **before** the rollouts: Otherguise, Media Helper and Scatter Elsewhere run from git clones, the modes are enabled and the modes and variants are declared by hand. This rollout changes **nothing** on production. It writes down, as a git tag in each repository, **what runs there now**, so that every later rollout starts from a known and reproducible state, and it makes the preconditions of the next ones true.

State reported by Eric on 2026-10-12 (`rollout.sh status --site=prod`):

| Repository | Commit on production |
|---|---|
| `wp-otherguise` | `dba206f` (the last commit that changes the plugin itself: the 9 commits after it only touch the tools, the tests and the documentation) |
| `wp-media-helper` | `8ab96c5` |
| `wp-scatter-elsewhere` | `60439b9` |

The settings by hand: the modes enabled, the modes and the variants declared on the templates of Twenty Twenty-Five (`twentytwentyfive//...`); **no** stylesheet, **no** default template, the templates not touched.

## Preconditions

- [ ] **WP-CLI in the production container, and that it survives a recreation.** The containers of production were recreated on 2026-10-12 and lost it. Add to the `Dockerfile` of the production image, for example, `COPY --from=wordpress:cli /usr/local/bin/wp /usr/local/bin/wp` (check that the image has it there), rebuild, and check: `docker exec docker-e-vli-st_wordpress_1 wp --info --allow-root`. Needed by the synchronisation, `templates.sh` and the migrations; not by `rollout.sh`.
- [ ] The three clones of production are clean and pushed: `rollout.sh status --site=prod` shows no `LOCAL CHANGES` and no `ahead`.
- [ ] **For the next rollouts, not this one:** the clone of the child theme mounted on every site as `/var/www/html/wp-content/themes/wp-twenty-twenty-five-printable` (same name everywhere), and, before the blocks are changed (step 6), the clones of `wp-attached-gpx`, `wp-attached-videos`, `wp-printable-gallery`, `wp-printable-qrcode` and `wp-whobird` mounted like the other plugins.

## Backup (production)

A database backup now is also the baseline of the settings of the modes:

```sh
docker exec -u www-data docker-e-vli-st_wordpress_1 wp db export /tmp/baseline.sql
```

(then copy it out of the container.)

## Commands

On production, from the Docker host, `wp-otherguise/tools/staging/rollout.sh` (the repositories are the clones mounted in the container):

1. `rollout.sh status --site=prod`: the three commits above, no local changes. If one differs, stop and say so: the baseline would record something else.
2. `rollout.sh create --baseline --site=prod --dry-run`: the three repositories with their commits and the name of the tag (`rollout-1`); nothing is tagged.
3. `rollout.sh create --baseline --site=prod`: tags the commits that run on production `rollout-1` and pushes the tags to GitHub. **It never changes the code.**
4. Check: `git -C <each clone> ls-remote --tags origin 'rollout-*'` lists `rollout-1` for each repository.

## Manual steps

None.

## Verification

| Check | Command | Expected |
|---|---|---|
| the tag is on what runs | `rollout.sh status --site=prod` | each repository shows `tags: rollout-1` |
| production did not move | `rollout.sh status --site=prod` | the same commits as at the start |
| the tags are on GitHub | `git ls-remote --tags origin 'rollout-*'` in each clone | `rollout-1` |

## Rollback

Nothing to undo on the site. To delete the baseline (nothing depends on it yet): `git push origin :refs/tags/rollout-1` and `git tag -d rollout-1` in each clone.

## Log

2026-10-12, production, Eric: `create --baseline --site=prod` created and pushed `rollout-1` in four repositories:

| Repository | Commit tagged |
|---|---|
| `wp-otherguise` | `4450ef2` (Eric had pulled since the status: the commits after `dba206f` only touch the tools, the tests and the documentation, so the plugin code is the same) |
| `wp-scatter-elsewhere` | `60439b9` |
| `wp-media-helper` | `8ab96c5` |
| `wp-twenty-twenty-five-printable` | `98cf9a3` (the clone was already mounted on production, in the themes directory; the theme is **not activated**) |

Differences from the text: the theme clone was already mounted (a precondition of the next rollouts, now true); the baseline was taken at `4450ef2` and not at `dba206f`; the output message said "apply 1 on production", which is wrong for a baseline (corrected in the script: nothing to apply). Not yet done: WP-CLI in the image of production, the database backup, the verification table.
