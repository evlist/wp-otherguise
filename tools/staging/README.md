<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Copy a production site onto its test copy

[`sync-site.sh`](sync-site.sh) copies a WordPress site that runs in Docker onto a test copy: the files (rsync), the database (dump and load), the addresses (`wp search-replace`), then makes the copy safe. It reads the production site and never writes to it. Run it **on the Docker host**.

```sh
tools/staging/sync-site.sh --dry-run     # a summary of what rsync would change; nothing else is touched
tools/staging/sync-site.sh               # asks you to type the name of the test container
tools/staging/sync-site.sh --yes --skip-uploads
```

The defaults are those of Eric's setup (containers `docker-e-vli-st_wordpress_1` and `docker-sb-vli-st_wordpress-sb_1`, volumes `/volumes/e-vli-st/wordpress` and `/volumes/sb-vli-st/wordpress`, addresses `https://e.vli.st` and `https://sb.vli.st`); every one can be changed with an environment variable, listed at the top of the script.

## What it does

1. **Checks**: refuses when both containers, volumes, addresses or databases are the same, when the production site does not say it is `PROD_URL`, or when the test site says it is neither `STAGING_URL` nor `PROD_URL`. WP-CLI is **copied from the production container** into the test one when missing (nothing is downloaded; copy it again after the container is recreated, the script does it).
2. **Files**: `rsync -a --delete`, except `wp-config.php` (its database settings, keys and constants stay those of the test site), the caches, logs and the safety plugin of the copy. `--skip-uploads` leaves the uploads of the test site alone (it then gets none of the images of production, thumbnails included: do not use it when you want them); `EXCLUDES` adds patterns. Plugins mounted as volumes from git clones are not in the volume of WordPress, so they are not touched.
3. **Database**: dump of the production database (`--single-transaction`, UTF-8) and load into the test database. The dump goes to a private temporary file, checked for its final `Dump completed` line, and deleted at the end (`--keep-dump` keeps it: it holds personal data). The credentials are read from each `wp-config.php` and passed through the environment, not the command line. The official `wordpress` image has no mysql client: give `PROD_DB_CONTAINER` and `STAGING_DB_CONTAINER` (the database containers) when the script says so.
4. **Addresses**: `wp search-replace` of `https://e.vli.st` then of `//e.vli.st`, in every table, **except the `guid` column**. It handles serialized PHP and JSON, which a `sed` on the dump would corrupt (the length of every serialized string would be wrong). It then counts the other mentions of the host name that were left on purpose (mail addresses, text).
5. **A safe copy**: `blog_public = 0`, `WP_ENVIRONMENT_TYPE = staging`, `DISABLE_WP_CRON = true` (`--keep-cron` to keep it; the scheduled tasks of production plugins would otherwise run on the copy), and a must-use plugin (`wp-content/mu-plugins/staging-safety.php`) that sends no mail, asks the search engines to stay away and shows "TEST COPY" in the admin bar.

## What is not done

- Nothing is **anonymized**: the copy holds the users, e-mail addresses and comments of production. Keep it private (HTTP authentication, not indexed).
- **API keys and services** of production plugins (analytics, payment, backup, newsletters) are not disabled one by one; check them in the copy before logging in as a customer would. Disabling cron and mail covers what leaves WordPress by itself, not what a plugin calls from a page view.
- The plugin under development is not installed: it is in your own volume. Activate it on the copy (`wp plugin activate otherguise`), which also creates its tables.

## Tested

With **stand-ins for `docker` and `rsync`** ([`tests/staging/sync-site-test.sh`](../../tests/staging/sync-site-test.sh), 26 checks): the guards, the dry run, the order (files, dump, load, addresses), the arguments (host and port split, wp-config kept, guid skipped), the file written for the safety plugin (valid PHP). It was **never run against real containers**: the first run should be `--dry-run`, then a full run on an expendable test site.
