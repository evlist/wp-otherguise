<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Three sites: production, staging, testing

**Decided** (Eric, 2026-10-12): there are three sites. **Production** (`e.vli.st`). **Staging** (today's `sb.vli.st`, renamed by its role): a fresh copy of production, pinned on a rollout, where a procedure is rehearsed. **Testing**: another copy, which follows the development branches and where anything goes, with the real articles and photos. The scripts of this directory work on the three: [`sync-site.sh`](sync-site.sh) copies production onto staging or testing (`--target=`), [`set-environment.sh`](set-environment.sh) declares what a site is, [`rollout.sh`](rollout.sh) puts the same versions of the plugins on staging and on production (see the end of this page).

[`sync-site.sh`](sync-site.sh) copies a WordPress site that runs in Docker onto a copy (staging by default, testing with `--target=testing`): the files (rsync), the database (dump and load), the addresses (`wp search-replace`), then makes the copy safe. It reads the production site and never writes to it. Run it **on the Docker host**.

```sh
tools/staging/sync-site.sh --dry-run     # a summary of what rsync would change; nothing else is touched
tools/staging/sync-site.sh               # asks you to type the name of the test container
tools/staging/sync-site.sh --yes --skip-uploads
```

When the volumes are not writable by your user, use `sudo` for **rsync only**, not for the whole script (the Docker commands and the temporary files belong to your user):

```sh
sudo -v                                  # asks the password now, so that it is not asked in the middle of a long copy
RSYNC='sudo rsync' tools/staging/sync-site.sh --dry-run
RSYNC='sudo rsync' tools/staging/sync-site.sh
```

The script writes nothing else into the volumes from the host: the safety plugin is written through `docker exec`. rsync, even as root, only writes in `STAGING_VOLUME` (the production volume is the source) and the script refuses to start when the two volumes, containers or addresses are the same.

The testing site has **no defaults** (its containers and address are yours to choose): give `TESTING_CONTAINER`, `TESTING_VOLUME`, `TESTING_URL` (and `TESTING_DB_CONTAINER` when its WordPress container has no mysql client) and run `sync-site.sh --target=testing`; see [`target.sh`](target.sh).

The defaults, for production and staging, are those of Eric's setup (containers `docker-e-vli-st_wordpress_1` and `docker-sb-vli-st_wordpress-sb_1`, volumes `/volumes/e-vli-st/wordpress` and `/volumes/sb-vli-st/wordpress`, addresses `https://e.vli.st` and `https://sb.vli.st`); every one can be changed with an environment variable, listed at the top of the script.

## Which site is this? A coloured frame and a label

[`site-environment.php`](site-environment.php) is a must-use plugin for the **three** sites. It draws a thin coloured frame around the window and a small label in the bottom right corner, both transparent to clicks (they never hide a button) and **not printed** (nothing in the PDF of a page):

| Site | Colour and label | Where |
|---|---|---|
| production | red, `production` | the administration (and the admin bar of the public pages, which only logged-in users see) |
| staging (a fresh copy to rehearse a procedure) | orange, `staging` | every page: public, administration, login |
| testing (free experiments, real data) | green, `testing` | every page |

On a **copy** (staging, testing) it also keeps the search engines away, whatever the settings say: the virtual `robots.txt` of WordPress disallows everything, the pages carry `noindex` (meta tag and header `X-Robots-Tag`) and the sitemaps are off. A **real** `robots.txt` file would be served by the web server before WordPress is reached, so the sync neither copies the one of production nor keeps one on the copy. This is a request to well-behaved robots, not a protection: keep the copies behind HTTP authentication.

It reads `WP_ENVIRONMENT_TYPE`, which WordPress already knows: `production` (what you get when nothing is defined, so **nothing to configure on production**), `staging`, and `development` or `local` (shown as "testing"). On a copy the label also shows the date of the copy (`staging (copy of 2026-10-12 10:03)`), recorded by the sync script in the option `otherguise_site_synced_at`.

- **Staging and testing:** `sync-site.sh` sets the environment of the site it refreshes (`staging`, or `development` for testing) and installs the plugin. On a **new server**, before its first copy, or after an update of `site-environment.php`: `tools/staging/set-environment.sh staging` (or `testing`); `set-environment.sh --show testing` says what the site thinks it is. The script refuses a site that does not say it is the address expected.
- **Production:** install it **by hand**, the scripts never write there: copy `site-environment.php` into `wp-content/mu-plugins/` of the production site. If production has it, the sync brings it to the copy, which then overwrites it with its own.
- The "TEST COPY" entry of the admin bar (`staging-safety.php`) stays: it says which production site the copy is **not**.

## Rollouts: the same versions on staging and on production

The plugins under development are git clones mounted into each container; the sync leaves them alone, so each site can be on another commit. [`rollout.sh`](rollout.sh) removes that drift. A **rollout** is a git tag `rollout-N`, put on the commit that runs on staging in **every** such repository, and pushed to GitHub; production then checks out that tag. The repositories are not configured: they are the bind mounts of the site's container under `wp-content/plugins` (and `themes`) whose source is a git clone **or a directory of one** (`<clone>/plugin`, as for Eric's plugins). Run it on the Docker host. `--site=prod|staging|testing` is always required (there is no default site, so no accidental production).

```sh
rollout.sh status --site=staging           # each repository: branch or tag, commit, rollout tags, local changes, distance from origin
rollout.sh update --site=staging           # fast-forward each repository to the latest commit of its default branch (--dry-run shows them)
rollout.sh create --site=staging           # tag the commits that run on staging rollout-N in every repository and push the tags
rollout.sh create --baseline --site=prod   # the same for what runs on production now (the starting point; it only tags)
rollout.sh apply 3 --site=prod --dry-run   # what putting production on rollout-3 would change
rollout.sh apply 3 --site=prod             # asks you to type the name of the container; --yes skips it
rollout.sh rollback --site=prod            # back where every repository was before the last apply
```

- **`create` tags what runs on staging; it does not pull.** Pulling first would tag commits that were never rehearsed: `update` is the separate step that brings staging to the latest commit, and the rehearsal happens after it. `create` refuses local changes and commits that are not on GitHub (the tag would not be reproducible), and numbers the rollout one above the highest number found in any repository. If a push fails, nothing is left half done (the local tags and the pushed ones are taken back).
- **`apply` is all or nothing before it starts:** it fetches the tags, refuses if one repository lacks the tag or has local changes, and only then checks out `rollout-N` everywhere (detached head), remembering where each repository was so that `rollback` can go back. Production is never "updated to the latest": `update` refuses it, only a tag can be applied.
- It only moves the code. What a plugin does when its version changes (tables, options) is up to the plugin; check the site afterwards.

## The templates of the database: `templates.sh`

The templates that the site editor stored in the database are not in git, and a database copy **hides** the file of the same slug in the theme. [`templates.sh`](templates.sh) (run on the Docker host, `--site=prod|staging|testing` always required) looks at them, for the move of the templates into the child theme [`evlist/wp-twenty-twenty-five-printable`](https://github.com/evlist/wp-twenty-twenty-five-printable):

```sh
templates.sh check --site=prod                       # each template of the database: SHADOWS (hides a file; last column: identical to it or differs), DATABASE-ONLY, UNUSED (attached to another theme); exit 1 if one SHADOWS
templates.sh export ~/templates-prod --site=prod     # the templates and parts attached to the active theme as files + customTemplates.json (read-only)
templates.sh delete --backup-dir ~/backup --site=staging --dry-run    # which copies would be deleted
templates.sh delete --backup-dir ~/backup --site=staging              # backs them up, asks for the name of the container, deletes them
```

`export` removes the `theme` attribute of the template parts (it makes them render as nothing in a child theme, see the README of the theme) and writes the fragment of `theme.json` for the custom templates (the post type `post` is a guess: check it). Only the copies that **hide a file** are deleted, and never without a backup in the directory you give. **When the templates move to the child theme there is nothing to delete**: the copies attached to Twenty Twenty-Five are not used by the child (they show as `UNUSED` after the switch), and no copy is attached to the child until someone saves a template in the site editor. `delete` is for that later accident, or for tidying.

## The rollout scripts: `rollout-lib.sh` and `rollouts/`

A rollout is a script, [`rollouts/NNN-name.sh`](../../rollouts/), built on [`rollout-lib.sh`](rollout-lib.sh) (model: [`rollouts/TEMPLATE.sh`](../../rollouts/TEMPLATE.sh)). It takes the site as a parameter and runs the same text on staging, testing and production:

```sh
rollouts/002-child-theme.sh --site=staging                  # rehearse, as often as needed (synchronise staging again if it goes wrong)
tools/staging/rollout.sh create --site=staging              # tag what was rehearsed: rollout-N
rollouts/002-child-theme.sh --site=prod --rollout=N         # production
```

The library gives a script `step` (run a command, stop if it fails), `note` (show a state), `check` and `check_output` (what a command can decide), `validate` (a question for you, optional: `--no-validate` skips it once the rehearsal is trusted) and `manual` (a gesture without a command, always asked). On production it asks for the name of the container (`--yes` skips it), backs the database up to `~/rollout-backups` before the first change, and **refuses a script that was not rehearsed to the end on staging with the validations, in its current text** (the fingerprint of the script and of the library is recorded in `~/.local/state/vli-rollouts`; `--force` overrides). A log of every run is kept in `~/rollout-logs`. `--dry-run` prints what would be done. There is no rollback to write: production is backed up and staging can be synchronised again.

## Re-applying only the safety step

`--safety-only` copies nothing: it only re-applies the last step (the options, the constants and the safety plugin of the test site). Use it after an update of this script, for instance to refresh the "TEST COPY" entry of the admin bar (which is **not a link**: a link to production in the administration of the test site is an invitation to edit the wrong site).

```sh
tools/staging/sync-site.sh --safety-only --yes
```

## Example: leaving a big directory out

On Eric's site 67 GB of the 80 GB are in `wp-content/gallery` (images of the NextGen Gallery plugin, which is to be dropped), and 14 GB in `uploads`. To skip the galleries (the pages that show them will have broken images on the copy, nothing else changes):

```sh
EXCLUDES='/wp-content/gallery' tools/staging/sync-site.sh --dry-run
```

Anything that exists **only on the test site** is deleted by the copy (rsync `--delete`), except the mounts of the test container and `wp-config.php`. The dry run lists such plugin directories under `*deleting`; add them to `EXCLUDES` (`EXCLUDES='/wp-content/plugins/loco-translate'`) to keep them.

## What it does

1. **Checks**: refuses when both containers, volumes, addresses or databases are the same, when the production site does not say it is `PROD_URL`, or when the test site says it is neither `STAGING_URL` nor `PROD_URL`. WP-CLI is **copied from the production container** into the test one when missing (nothing is downloaded; copy it again after the container is recreated, the script does it).
2. **Files**: `rsync -a --delete`, except `wp-config.php` (its database settings, keys and constants stay those of the test site), the caches, logs and the safety plugin of the copy. `--skip-uploads` leaves the uploads of the test site alone (it then gets none of the images of production, thumbnails included: do not use it when you want them); `EXCLUDES` adds patterns. Whatever the **test container mounts** under `/var/www/html` (the plugins under development, bind-mounted from git clones) is read with `docker inspect` and excluded from rsync, so that it is neither filled with the production version nor deleted (an empty mount point in the volume would otherwise be removed under a running container). The script says which mounts it found. The free space of the test volume is checked first (in the dry run it is only reported); a copy that does not fit is refused.
3. **Database**: dump of the production database (`--single-transaction`, UTF-8) and load into the test database. The dump goes to a private temporary file, checked for its final `Dump completed` line, and deleted at the end (`--keep-dump` keeps it: it holds personal data). The credentials are read from each `wp-config.php` and passed through the environment, not the command line. The official `wordpress` image has no mysql client: give `PROD_DB_CONTAINER` and `STAGING_DB_CONTAINER` (the database containers) when the script says so.
4. **Addresses**: `wp search-replace` of `https://e.vli.st` then of `//e.vli.st`, in every table, **except the `guid` column**. It handles serialized PHP and JSON, which a `sed` on the dump would corrupt (the length of every serialized string would be wrong). It then counts the other mentions of the host name that were left on purpose (mail addresses, text).
5. **A safe copy**: `blog_public = 0`, `WP_ENVIRONMENT_TYPE = staging`, `WP_DEBUG_DISPLAY = false` and `WP_DEBUG_LOG = true` (the warnings of production plugins are written to `wp-content/debug.log` instead of the pages, where they send the headers early and break sessions and redirects), `DISABLE_WP_CRON = true` (`--keep-cron` to keep it; the scheduled tasks of production plugins would otherwise run on the copy), and a must-use plugin (`wp-content/mu-plugins/staging-safety.php`) that sends no mail, asks the search engines to stay away and shows "TEST COPY" in the admin bar.

## What is not done

- Nothing is **anonymized**: the copy holds the users, e-mail addresses and comments of production. Keep it private (HTTP authentication, not indexed).
- **API keys and services** of production plugins (analytics, payment, backup, newsletters) are not disabled one by one; check them in the copy before logging in as a customer would. Disabling cron and mail covers what leaves WordPress by itself, not what a plugin calls from a page view.
- The plugin under development is not installed: it is in your own volume. Activate it on the copy (`docker exec -u www-data <test container> wp plugin activate wp-otherguise`; the directory is `PLUGIN_DIR`), which also creates its tables.

## Tested

The rollout library and the script of rollout 002 ([`tests/staging/rollout-script-test.sh`](../../tests/staging/rollout-script-test.sh), 45 checks) were tested with stand-ins for `docker`, `rollout.sh` and `templates.sh`: the order of the steps, the checks, the questions, the guard of production, the backup. **Never run against real containers.**

With **stand-ins for `docker` and `rsync`** ([`tests/staging/sync-site-test.sh`](../../tests/staging/sync-site-test.sh), 64 checks): the guards, the dry run, the order (files, dump, load, addresses), the arguments (host and port split, wp-config kept, guid skipped), the file written for the safety plugin (valid PHP), the targets, the date of the copy and `set-environment.sh` (it refuses production and any site that is not the one expected). The sync was run by Eric on his own site (the versions before the modes were added). The frame plugin was tested on a scratch WordPress with WP-CLI ([`tests/staging/site-environment-test.sh`](../../tests/staging/site-environment-test.sh), 12 checks: each environment, front, administration, login, print, the date, the admin bar) and seen once in Chromium (green frame, "TESTING"). The rollout script ([`tests/staging/rollout-test.sh`](../../tests/staging/rollout-test.sh), 53 checks) was tested with **real git repositories** (a bare repository standing for GitHub, a clone per site) and a stand-in for `docker`. The templates script ([`tests/staging/templates-test.sh`](../../tests/staging/templates-test.sh), 29 checks) was tested on a **scratch WordPress** with the real WP-CLI and the child theme active (check, export, delete with its backup). **Not tried:** the new scripts against real containers and a real GitHub, the plugin on production, and the look of the red and orange frames in the block editor.
