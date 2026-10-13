<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 215 — Media: a generic criterion for `wp otherguise media migrate`

Status: **done** (Eric, 2026-10-13: the flag `wpdfh.print` "was really a hack of mine"; the command should select by any key/value of meta). Dependencies: slice 211.

## Goal

Slice 211 hard-wired the flag of `wp-pdf-helper`. What the command really does is: *an image is shown in the mode X only if it satisfies a criterion on a meta of the attachment*. The criterion and the mode become options; the defaults are those of slice 211, so the command behaves as before without options.

## The options (added to those of slice 211)

```
wp otherguise media migrate [--meta-key=<key>] [--meta-value=<value>] [--restrict-mode=<slug>] [--mime=<family>] ...
```

- `--meta-key` (default `wpdfh.print`): the meta of the attachment the criterion is about.
- `--meta-value`: the value the meta must have (compared as text). Without it the meta must simply **not be empty** (the old rule: `"0"` counts as set, as it did).
- `--restrict-mode` (default `print`; `--print-mode` is the old name and still works, `--restrict-mode` wins): the mode that **only** the attachments that satisfy the criterion get. They are linked in every mode; the other images in every mode but this one.
- `--mime` (default `image`): the MIME family the criterion applies to; the other files (GPX, PDF…) are linked in every mode.

What does not change: idempotent, `--dry-run`, `--verbose`, `--limit`, `--batch-size`; a link that exists is left alone (so a second run with other options changes nothing already done); `post_parent` and the metas are never changed. The warning when the restricted mode is not registered now names the mode.

## Not in this slice

Several criteria, operators other than "equal" and "not empty", regular expressions, free choice of the modes of each group, a criterion on the post. Written when a site needs them.

## Verified

PHPUnit with a database (`MediaMigrationDbTest`, 409 tests in all): the criterion reaches the source (default and given), `restrict_mode` and the old `print_mode`, the MIME family, `AttachmentSource::matches` (not empty, equal, non-scalar values, compared as text), the options of the command and the warning. **Not tried again with the real WP-CLI** on a scratch site (the check of slice 211 had 12 checks): the options are the only change, but `AttachmentSource::batch` reading an arbitrary meta key is only exercised by the stub in PHPUnit.
