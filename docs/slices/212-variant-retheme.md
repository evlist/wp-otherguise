<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 212 — Modes: moving the variants to another theme (WP-CLI)

Status: **done** (Eric: "écris la commande retheme", to move the blog to a child theme, rollout 002). Dependencies: slices 202 (variants) and 211 (the model of a WP-CLI command that can be run twice).

## Goal

The variants of the modes are relations between templates known as `theme//slug` (`twentytwentyfive//journee`). When the blog moves to a child theme, the variants declared for the parent point at nothing. The command declares them again for the new theme, with the same modes, and withdraws the old ones.

## The commands

```
wp otherguise modes variant list [--theme=<theme>]
wp otherguise modes variant retheme <from> <to> [--dry-run]
```

- `list`: one line per relation (templates and template parts), tab separated: kind, template, variant, modes. Meant for scripts and for checking a result; `--theme` keeps the relations of one theme.
- `retheme`: for every relation whose two ends are of the theme `<from>`, declares (`Variants::declare`) the relation between the templates of `<to>` with the same slugs, in each of its modes, **then** withdraws the old one. The new templates must exist, so the new theme must be active: when they do not, the relation is **kept**, reported (`Skipped, ...`) and the command fails at the end. A relation with ends of two themes is left alone and reported. `--dry-run` writes nothing.
- **Can be run twice:** once the relations have moved a second run finds none of the old theme. Nothing is lost on a failure: the old relation is withdrawn only after the new one is declared.
- The default template of new posts (`modes_default_templates`) stores slugs without a theme, and the stylesheets belong to modes: neither needs moving.

## Verified

- PHPUnit with a database (`ModesRethemeDbTest`, 8 tests): relations and modes moved (a template with two modes, a template part), run twice, the old relation kept when the new templates do not exist, dry run, another theme left alone, the commands (output, refusals, a failure when something was skipped, the modules missing); the hook `cli_init` of the Modes module.
- **Real WordPress 7.1.3 with the real WP-CLI and a real child theme** ([`tests/real-wordpress/variant-retheme/run.php`](../../tests/real-wordpress/variant-retheme/run.php), 10 checks): refused with nothing lost while the child theme is not active, dry run, moved, listed, run twice, the variant of the request read from the child templates.

## Not verified

On the blog: the variants of the three pairs of templates; the cost of the command (a handful of relations).

## Not in this slice

Adding and removing a variant from the command line (`variant add`, `variant remove`): on the list of [`../design/rollout.md`](https://github.com/evlist/shared-room/blob/main/docs/design/rollout.md), written when a rollout needs them.
