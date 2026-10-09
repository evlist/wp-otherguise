<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# The administration screen, in a real browser

Slice 105 delivered the screen **Tools → Relations** of the Triples module. This script drives it on a scratch WordPress site, with Playwright and Chromium: login, the three tabs, filters, the screen option, bulk deletion with its confirmation page, orphans (found, listed, deleted), the statements of a predicate that is no longer registered, the setting, forged and missing nonces, and a subscriber. It checks the page for PHP errors and the network for failed requests, and takes screenshots. 32 checks.

```sh
# A scratch site, as for ../201-template-hooks/: WordPress, a block theme, plain permalinks, served at BASE, the admin account admin/admin.
ln -s "$PWD/plugin" /path/to/wordpress/wp-content/plugins/otherguise && wp plugin activate otherguise
cp tests/real-wordpress/admin-screen/og-predicates.php /path/to/wordpress/wp-content/mu-plugins/
cd tests/real-wordpress/admin-screen
BASE=http://127.0.0.1:8090 WPSH="wp --path=/path/to/wordpress --allow-root" PLAYWRIGHT=/path/to/node_modules/playwright node flow.js /where/to/put/screenshots
```

`seed.php` (run by the script) empties the table of the statements: **never use a site that matters**. The script creates a user `sub` (subscriber).

## What the first run found (WordPress 7.1.3, PHP 8.3.6, MariaDB 10.11, Chromium)

- The table was created by the real `dbDelta` with the expected columns and indexes.
- The screen option is saved as the user meta `triples_per_page` (plain name), not under the prefix of the site as assumed; the uninstaller removed the wrong key. Fixed: it removes both.
- The button that deleted the statements of an unregistered predicate had no confirmation page, unlike the plan. Fixed: it is now a link to a confirmation page.
- Two checks of the script were wrong at first (ids hard-coded after a second seed, a "no items" row counted as a row): the script now reads the ids of its seed and ignores the placeholder row.
- The booted modules are not reachable from outside the plugin: nothing like `triples()` returns the `Statements` service that the module built. The script and the checks in `../` build their own `Module`. A public accessor is needed before another module or plugin can use the API (to plan).
