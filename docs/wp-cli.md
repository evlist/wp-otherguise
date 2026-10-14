<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# WP-CLI commands

The commands that the plugin offers today, written from the code (`Cli\VariantCommand`, `Cli\MigrateCommand`) and their tests. They are the tools of the migrations of the blog ([`design/rollout.md`](design/rollout.md), [`rollouts/`](rollouts/README.md)), and follow the rule of the repository: a step of a migration that touches what the plugin is responsible for is a command that **can be run twice**, with `--dry-run`, a report, and a way to check the result.

Three commands exist. All are registered on `cli_init`, appear in `wp help otherguise`, and need the modules they name to be active (otherwise they stop with a message). To know whether a site has one: `wp cli has-command "otherguise media migrate"` (exit code 0 or 1). The commands that are still **missing** (`variant add|remove`, `modes enable`, `media list`, `status`…) are listed in [`design/rollout.md`](design/rollout.md), each written only when an iteration needs it.

| Command | Does | Slice | Used by |
|---|---|---|---|
| [`wp otherguise modes variant list`](#wp-otherguise-modes-variant-list) | lists the variants (the relations between templates) | [212](slices/212-variant-retheme.md) | rollout 002 (checks and notes) |
| [`wp otherguise modes variant retheme`](#wp-otherguise-modes-variant-retheme) | moves the variants of a theme to another theme | [212](slices/212-variant-retheme.md) | rollout 002 |
| [`wp otherguise media migrate`](#wp-otherguise-media-migrate) | turns the parents of the attachments and a meta into links | [211](slices/211-media-migration.md), [215](slices/215-media-migrate-criterion.md) | rollout 003 (planned) |

## wp otherguise modes variant list

```
wp otherguise modes variant list [--theme=<theme>]
```

One line per relation, **tab separated**, meant for scripts and for checking a result:

```
<kind>	<template>	<variant>	<modes>
template	twentytwentyfive//publication-randonnee	twentytwentyfive//publication-randonnee-print	print
```

- `kind`: `template` or `template_part`. The templates are known by `theme//slug`.
- `modes`: the slugs of the modes, separated by commas.
- `--theme=<theme>`: only the relations where one end is a template of that theme (the stylesheet, such as `twentytwentyfive`).
- Reads only. Modules: Triples and Modes.

## wp otherguise modes variant retheme

```
wp otherguise modes variant retheme <from> <to> [--dry-run]
```

For a site that changes its theme: the variants are declared for `twentytwentyfive//slug`, which point at nothing once a child theme is active. For every relation whose two ends are of the theme `<from>`, the command declares the relation again between the templates of `<to>` with the **same slugs** and the same modes, **then** withdraws the old one.

- `<from>` and `<to>` are the names of the directories of the themes (the stylesheets). They must differ.
- The templates of `<to>` must exist, so the theme must be **active**. When they do not, the relation is **kept** and reported (`Skipped, ...`), and the command **fails at the end** (non-zero exit code, with a message that says to activate the theme).
- A relation with ends of two different themes is left alone and reported.
- `--dry-run`: writes nothing, prints what would be done.
- **Can be run twice:** a second run finds no relation of the old theme. Nothing is lost on a failure, because the old relation is withdrawn only after the new one is declared.
- Does not touch the default template of new posts (it stores slugs without a theme) nor the stylesheets (they belong to modes).

Output: one line per relation (`template A -> B (modes): now A' -> B'`), the skipped ones, then a summary: `Done: N relation(s) moved from <from> to <to>, K skipped.` (`Dry run, nothing written: N relation(s) to move ...`). Modules: Triples and Modes.

```sh
wp otherguise modes variant list --theme=twentytwentyfive
wp otherguise modes variant retheme twentytwentyfive wp-twenty-twenty-five-printable --dry-run
wp theme activate wp-twenty-twenty-five-printable
wp otherguise modes variant retheme twentytwentyfive wp-twenty-twenty-five-printable
wp otherguise modes variant list --theme=twentytwentyfive   # empty
```

## wp otherguise media migrate

```
wp otherguise media migrate [--dry-run] [--restrict-mode=<slug>] [--meta-key=<key>] [--meta-value=<value>] [--mime=<family>] [--batch-size=<n>] [--limit=<n>] [--verbose]
```

For each attachment that has a parent (`post_parent` not 0) and **no link to that post yet**, creates the link `media/illustrated-by` between the post and the attachment, with its modes. It is what the method `otherguise` of Media Helper reads, so that a site starts from its existing content.

**Which modes a link gets** (the old behaviour is reproduced):

| Attachment | Modes |
|---|---|
| an image of the MIME family `--mime` that **satisfies the criterion** | every registered mode |
| an image of that family that does **not** | every registered mode **except** `--restrict-mode` |
| any other file (GPX, PDF…) | every registered mode |

The criterion is on a meta of the attachment: by default `wpdfh.print` **not empty** (the flag of `wp-pdf-helper`); with `--meta-value` the meta must be equal to it (compared as text).

| Option | Default | Meaning |
|---|---|---|
| `--dry-run` | | writes nothing, says what would be done |
| `--restrict-mode=<slug>` | `print` | the mode that only the attachments that satisfy the criterion get (`--print-mode` is the old name; `--restrict-mode` wins) |
| `--meta-key=<key>` | `wpdfh.print` | the meta the criterion is about |
| `--meta-value=<value>` | none (not empty) | the value the meta must have |
| `--mime=<family>` | `image` | the MIME family the criterion applies to |
| `--batch-size=<n>` | 100 | attachments read at a time |
| `--limit=<n>` | none | stop after that many links created (or that would be) |
| `--verbose` | | one line per attachment |

- **Can be run twice.** A link that exists is left alone, with its modes (made by hand in the panel, or by an earlier run); a second run with other options changes nothing already done.
- **Never changes anything on the attachments:** neither `post_parent` nor the metas.
- If the restricted mode is not registered, every attachment gets every registered mode, and the command says so (`Warning: the mode "..." is not registered ...`).
- A parent that does not exist is skipped and reported (`Skipped, attachment N, post M: ...`).

Output: the number of links by set of modes (`  2 in web,print`), the skipped ones, then `Done: N link(s) created, M already linked, K skipped.` (`Dry run, nothing written: N link(s) to create, ...`). The exit code is 0 unless the command cannot run (a module is missing: Triples, Modes and Media must be active).

```sh
wp otherguise media migrate --dry-run                 # read the report first
wp otherguise media migrate --dry-run --verbose | head
wp otherguise media migrate
wp otherguise media migrate --meta-key=book_ok --meta-value=yes --restrict-mode=book   # another criterion
```

Only the links that already exist as `post_parent` are made: a photo cannot be linked to a second post by this command (see the case of the monthly summaries in [`design/media-helper.md`](design/media-helper.md)).

## What was tried

- **Automated tests** (PHPUnit with a database, 413 tests in all): the three commands (output, options, refusals, the case where the modules are missing, the hook `cli_init`).
- **Real WordPress 7.1.3 with the real WP-CLI:** `variant list` and `retheme` (10 checks), and `media migrate` before its criterion options (12 checks, slice 211). The options of slice 215 were **not** tried again on a real site.
- **On the blog:** `variant retheme` ran in rollout 002 on staging and on production. `media migrate` has **not** run on a copy of the blog; it is planned for the rehearsal of [rollout 003](rollouts/003-print-format.md).
