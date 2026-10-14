<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 217 — Media: every type of media, and a list of posts, for `media migrate`

Status: **done** (Eric, 2026-10-14). Dependencies: slices 211 and 215.

## Goal

Two limits of the command of slice 215: the criterion applied to **one MIME family** (images by default; the other files got every mode), and it migrated **every post** of the site. Both become options, with the old behaviour as the default.

## The options (added to those of slices 211 and 215)

```
wp otherguise media migrate [--mime=<families>] [--post-ids=<ids>] ...
```

- `--mime` takes **a list of families** separated by commas (`image,video`), or **`all`** (or `*`) for every type of media: the criterion on the meta (`--meta-key`, `--meta-value`) and the restricted mode (`--restrict-mode`) then apply to images, videos, audio, GPX files, PDF… A family is the part of the MIME type before the slash (`image` for `image/jpeg`, `application` for `application/gpx+xml`); the comparison ignores the case. Default: `image`, as before.
- `--post-ids=<ids>`: **only the attachments whose parent is one of these posts**, a list of ids separated by commas (spaces are accepted): `--post-ids=12,40,41`. Every post by default. A value that is not a post id (`12,x`, `0`, an empty list) is refused before anything is read.

Nothing else changes: idempotent, `--dry-run`, never touches `post_parent` or the metas, a link that exists is left alone, a parent that does not exist is skipped and reported. A list of posts is the way to try the migration on a few posts, or to run it post by post, before the whole site.

## Verified

PHPUnit (415 tests in all): `--mime=all` (a GPX file that does not satisfy the criterion loses the restricted mode), the list of families (case, spaces, `*`, a family is a whole word before the slash), the list of posts reaches the source and limits the attachments, no list means every post, the options of the command and the refusal of a bad list. **Not tried on a real WordPress site**: the query `post_parent__in` of `AttachmentSource` is exercised by the double in PHPUnit only.

## Not in this slice

A list of attachments instead of posts; a selection on the post type or the date; several criteria.
