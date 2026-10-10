<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 210 — Media: the attachment method of Otherguise for Media Helper

Status: **done** (Eric: "démarre le module media"), tried on a scratch WordPress with the **real** Media Helper `c6247a5`; not tried by a user in the panel. Dependencies: the Triples and Modes modules; Media Helper with its contract version 1 (slice 042 of that plugin, steps 1 to 4 coded there, never tried on a real site). Module: `Media`, new. Design and decisions: [`../design/media-helper.md`](../design/media-helper.md).

## Goal

Let a media item be attached to **several posts**, **in the modes the author chooses**, **at a rank**, through statements `post media/illustrated-by attachment` (qualified by `modes/mode` and `triples/position`), instead of `post_parent` alone. Media Helper does the panel; this module is the *method* it calls.

## The module

- `Media\Module` (`media`, depends on `triples` and `modes`): registers the predicate `media/illustrated-by` (a post, an attachment, qualified by `modes/mode`; `triples/position` is built in), and **registers the method** with the filter `wp_media_helper_attachment_methods`, only when Media Helper is there (`WP_MEDIA_HELPER_CONTRACT` is 1 and the interface exists). Without Media Helper the module only declares the predicate. It does **not** make its method the active one: that is a setting of Media Helper (`wp_media_helper_attachment_method`).
- `Media\Link\Links`: the statements, with no WordPress call but those it is given (the MIME type of an attachment): link, unlink, set the modes and the rank of a link, the attachments of a post for a mode (`listing()` with the scope), the posts of an attachment.
- `Media\Method\MediaHelperMethod` implements `WP_Media_Helper\Attachment\Method`: `id` = `otherguise`; `capabilities()` = `multiple_posts` true and two declared fields, **`modes`** (multi-select, the options are the registered modes, the default is **every mode written out**) and **`position`** (integer, empty by default); `describe`, `attach`, `detach`, `update`, `attached`, `posts_of`, `may_remove`.
- `Media\Method\PostParents`: the only class that reads and writes `post_parent` (the tests put a double in its place).

## Rules

- **The reading rule of Triples**: a link with no mode statement is shown in no mode. `attached( $post, array( 'context' => 'print' ) )` returns the links that have the mode `print`, ordered (pinned ranks first, then the order of creation); with no context, or one that is not a registered mode, **every** link of the post (nothing is filtered).
- **Primary parent.** The method keeps `post_parent` meaningful for WordPress and the other plugins: attaching to a first post sets it; detaching from the primary parent designates the oldest link that remains, and clears it only when none remains. Attaching to a second post leaves it alone.
- **Validation.** The data of a link are validated again here (Media Helper coerces them first): the modes must be registered, with no duplicate; the position an integer or empty. A link whose attachment or post does not exist is refused.
- **Capability.** `attach` and `detach` need `edit_post` on the attachment (as `native`); otherwise `WP_Error` with the code `not_allowed`.
- **Several steps, one transaction.** Attaching or updating a link (the link, its modes, its rank) is all or nothing.
- **`may_remove`**: `trash` is accepted (the user was asked first; Triples removes the statements when the attachment is deleted); `remove` is **refused** while the attachment is linked to a post, as `native` does.
- **`describe`**: `attached_here`, `elsewhere` (the other posts, the primary parent first), `protected` null (no blocking with several posts) and `data` (`modes`, `position`) of the link with this post; one query per attachment of the page (Triples reads by object one at a time: a batch read in Triples is a candidate).

## Verified

- PHPUnit with a database and a stub of the interface (`MediaMethodDbTest`, 11 tests): capabilities and the declared fields; attaching with the defaults (every mode written out), idempotence; several posts and the primary parent; describing an item that is attached nowhere; every refusal stores nothing (unknown mode, not a list, duplicate, non-string, bad rank, missing attachment or post); `not_allowed` without the right; update of only the keys given, taking a rank away, no mode (still linked, shown nowhere); detach with the primary parent moving to the oldest link that remains, then cleared, the rank and the modes going with the link; `attached` by mode, without context, with an unknown context, with a MIME family and a whole type, with a pinned rank; `may_remove`; the module (hooks, the method added only with contract 1, nothing without the modules). Plus the loader of the modules.
- **Real WordPress 7.1.3 with the real Media Helper** ([`tests/real-wordpress/media-method/`](../../tests/real-wordpress/media-method/run.php), 27 checks with `wp eval-file`, and [`column.js`](../../tests/real-wordpress/media-method/column.js), 4 checks in Chromium): the method is registered next to `native` and becomes the one in use through the setting of Media Helper; its fields; attach to two posts, the primary parent and its moves; `wp_media_helper_get_attached_media()` by mode, by MIME family and type, without context; `posts_of`, `describe`, `update`, `may_remove`; deleting an attachment removes its links; back on `native`; the column "Uploaded to" of the media library lists every post with the method of Otherguise and the parent only with `native`.
- **A finding on the real site:** the scratch must-use plugin of the Triples tests declared `media/illustrated-by` itself and clashed with the module (`Predicate ... is already registered`); it no longer declares it.

## Not verified

The panel of Media Helper in the editor with this method (the fields drawn, *Edit link…*, "Also in"); the bulk actions of the panel with a real file of an external source; the cost of `describe()` and `posts_of()` on a large library (one read per attachment, Triples having no batch read by object); several authors; multisite.

## Not in this slice

The migration of `wpdfh.print` (a WP-CLI command), the position per mode (a rank on the mode statement: the model allows it, the field is one integer), choosing the modes in the same step as attaching (Media Helper's limit), items that are not native attachments, and the change of the blocks of Eric (they call `wp_media_helper_get_attached_media()`).

## Tests

See "Verified".

## To confirm

1. The method is named `otherguise` and is **not** made active by the module.
2. `remove` is refused while the item is linked to a post; `trash` is accepted.
3. One integer position on the link (not per mode) in the first version.
