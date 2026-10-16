<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 218 — Media: forget the links of the attachments that Media Helper removes

Status: **done** (Eric, 2026-10-16). Dependencies: slices 210 and 105 (the cleanup of the Triples module).

## Goal

Rollout 004 (`evlist/shared-room`) removes attachment records with `wp media-helper attachments clean`. After a removal that command fires `wp_media_helper_attachments_removed( int[] $ids, string $reason )`. The Triples module already forgets a deleted post or media item on `deleted_post`, which covers a removal through `wp_delete_attachment()`; this hook covers a removal that does not fire it, so that no `media/illustrated-by` link is left pointing to an attachment that is gone.

## Behaviour

- The module `media` listens to `wp_media_helper_attachments_removed` (priority 10, two arguments).
- For each id (cast to a positive integer, duplicates and zero ignored) it calls `Statements::forget( Ref::attachment( $id ) )`: the links to the attachment and what is said about them (modes, rank) go; the predicates declared `keep` stay, as for `deleted_post`.
- Idempotent: forgetting an attachment that has no statement does nothing, and running after `deleted_post` is harmless. The reason is not used.
- Nothing happens when the Triples module is not there, or when the argument is not an array.
- It lives in the module `media` (not in Triples) because the hook belongs to Media Helper.

## Verified

PHPUnit: the hook is registered; the links, modes and ranks of the removed attachments are deleted and those of the others kept; non-ids are ignored; no module, no error. **Not tried on a real WordPress site, nor against Media Helper's `attachments clean`** (its commit was not read): that command is only known by the message of the session that develops it.

## Not in this slice

A command to list the links to attachments that no longer exist (the SQL query given to the rollout session does it for now).
