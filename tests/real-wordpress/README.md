<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Tests on a real WordPress site

What the PHPUnit suite cannot show, because it runs on stubs: how WordPress core really behaves. These scripts are **not** part of the PHPUnit suite and are not run by the CI: they need a scratch WordPress site, its database, a web server and WP-CLI.

| Directory | Slice | What it tries |
|---|---|---|
| [`201-template-hooks/`](201-template-hooks/) | [201](../../docs/slices/201-modes-template-hooks.md) | Four ways of replacing a template or a template part by its variant in a given mode. |

## Running a script

```sh
# A scratch site: WordPress (any release), a block theme (Twenty Twenty-Five) active, plain permalinks, served at some URL.
php -S 127.0.0.1:8090 -t /path/to/wordpress &
tests/real-wordpress/201-template-hooks/run.sh /path/to/wordpress http://127.0.0.1:8090
```

`WP` names the WP-CLI command (default `wp`). The script creates and removes its fixtures (templates, a template part, a classic theme, an option) and restores the active theme. **Never run it on a site that matters.** The last run is recorded in the document of the slice, with the versions it ran on.
