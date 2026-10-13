<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 211 — Media: migration of the native attachments and of the `wpdfh.print` flag

Status: **done**; the criterion (the meta key and value, the restricted mode, the MIME family) became options in [slice 215](215-media-migrate-criterion.md), with the defaults described here (Eric: "prépare la commande", after the proposal of the Media Helper session). Dependencies: slice 210. Design: [`../design/media-helper.md`](../design/media-helper.md) (point 6).

## Goal

Turn what a site has today (`post_parent` of the attachments, and the flag `wpdfh.print` of `wp-pdf-helper`) into statements `post media/illustrated-by attachment`, so that the method `otherguise` of Media Helper starts from the existing content.

## The command

```
wp otherguise media migrate [--dry-run] [--print-mode=<slug>] [--batch-size=<n>] [--limit=<n>] [--verbose]
```

- For each attachment whose `post_parent` is not 0 and that has **no link to that post yet**, creates the link and its mode statements. `post_parent` and the flag are **never changed or deleted**.
- **Idempotent**: a link that exists is left alone (its modes are not touched), so the command can be run again, and run before or after links were made in the panel.
- `--dry-run` writes nothing and prints what would be done (counts by kind of mode set; with `--verbose`, one line per attachment).
- `--print-mode` (default `print`): the mode to which the flag `wpdfh.print` applies. `--batch-size` (default 100): attachments read at a time. `--limit`: stop after that many links created (or that would be).

## Which modes a migrated link gets

The old behaviour is reproduced, not simplified. `wp-printable-gallery` showed **every** attached image on the web and, with `?print`, only the flagged ones; `wp-attached-gpx` showed the files whatever the mode.

| Attachment | Modes |
|---|---|
| image **flagged** (`wpdfh.print` not empty) | every registered mode |
| image **not flagged** | every registered mode **except** the print mode |
| not an image (GPX, PDF…) | every registered mode |

If the print mode is not registered, every attachment gets every registered mode, and the command says so. Giving the flagged images the print mode **only** would have removed them from the web gallery, so it is not done.

## Reporting

Created, already linked, skipped (parent missing or not allowed, with the reason), by kind. Exit code 0 unless the command cannot run (modules not available).

## Not in this slice

- The **union on the read side** (a native `post_parent` counts as a link inside the method, switchable by a setting): slice 212, to be confirmed.
- An administration screen for the conversion (the command only).
- The reverse conversion (lossy).

## Verified

- PHPUnit with a database (`MediaMigrationDbTest`, 8 tests): the modes by kind of attachment, a missing parent, idempotence with an existing link keeping its modes, dry run, batches and limit, the print mode missing or changed, the command (options, output, error without the modules).
- **Real WordPress 7.1.3** ([`tests/real-wordpress/media-migration/run.php`](../../tests/real-wordpress/media-migration/run.php), 12 checks, the real WP-CLI): dry run then run then run again, the modes of a flagged image, a plain image and a GPX file, `post_parent` and the flag untouched.

## Not verified

A site with thousands of attachments (one read of the flag and one lookup per attachment), and Eric's own site.

## Tests

PHPUnit with a database (rules, idempotence, dry run writes nothing, missing parent, limit, batches, no mode registered), the command (output and options), the hook `cli_init`; real WordPress: the command on the scratch site.
