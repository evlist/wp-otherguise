<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

Moved here from `evlist/shared-room` on 2026-10-09. Points are marked **Decided** (Eric accepted it), **Proposed** (suggested by Claude, not confirmed) or **Open**. The delivered state is in [`../IA.md`](../IA.md) and [`../slices/`](../slices/README.md); this document keeps the reasoning.

# Integration with Media Helper

**Raised by Eric; to be studied, nothing decided.** [`wp-media-helper`](https://github.com/evlist/wp-media-helper) currently handles only native WordPress attachments. Statements will bring things it cannot express today:

- attaching a photo to **several posts** (a native attachment has a single `post_parent`);
- attachments **qualified by mode** (a photo for the web only, for print only, or for both), with a position that may differ per mode;
- **other kinds of attachments** than native ones (for example external resources such as `ext:youtube:ID`).

Media Helper must be able to manage these. Eric's idea: an extension mechanism (hooks) in Media Helper.

Directions to examine (**proposed**, not yet checked against Media Helper's code, which was not read for this note):

- **Direction of dependency.** Media Helper should stay usable alone, so it must not depend on this project. It exposes extension points; this project plugs an adapter into them. The reverse (Media Helper using the `Triples` API when present) is the alternative to weigh.
- **Extension points needed.** Probably: a filter resolving "the media attached to a post, for a given mode"; a filter or action for attaching and detaching, so that statements replace `post_parent`; a hook to display and edit the extra attachment data (modes, position) in Media Helper's screens; a hook for the type of an attached item (native attachment or other).
- **Galleries.** The gallery shortcode currently builds its HTML from the attachments of a post; it would have to ask for the attachments of the current mode.
- **Image sizes per mode.** Print needs different (larger) sizes than the web. Media Helper's configurable thumbnail sizes (its slice 033, not implemented) could depend on the mode.
- **Compatibility with core.** What `post_parent` and the "Attached to" column of the media library show when a photo belongs to several posts; keep `post_parent` as a primary parent, or leave it empty.
- **Lifecycle.** Deleting a media item or a post must remove or flag the matching statements (a missing-file report already exists in Media Helper's planned slice 036).

## Findings (2026-10-10)

Read in the repository `evlist/wp-media-helper` (public, commit `f18aca6`; files cited are in `plugin/includes/WP_Media_Helper/`). Facts, not proposals:

1. **An item is a native WordPress attachment; "attached to a post" is its `post_parent`.** Attaching is `wp_update_post( array( 'ID' => attachment, 'post_parent' => post ) )` in `EditorMediaController::processBulkItems()`; detaching sets it to 0 (`detachRows()`); removing and trashing delete the attachment records, trashing for every post and irreversibly (the file itself is never touched).
2. **One file, one attachment, one parent.** An item attached to **another** post is protected (`protected_other_post` on attach, detach and remove) and hidden by default in the panel (slice 016).
3. The state of the listed files (imported, attached here, attached elsewhere) is read in bulk by `AttachmentRegistry::statesFor()` (SQL on `_wp_attached_file` and `post_parent`).
4. **There is no extension point for the attachment lifecycle.** The hooks that exist: the action `wp_media_helper_attachment_registered`; filters about permissions (`wp_media_helper_can_hide_files`, `can_trash_files`, `can_see_hidden_files`, `can_see_trash`), thumbnails, file categories, excluded directories and the allowed base.
5. **Media Helper renders nothing on the front end.** The consumers are Eric's blocks, which read `post_parent` through core's `get_attached_media()`: `wp-attached-gpx` (`'application/gpx+xml'`) and `wp-printable-gallery` (`'image'`, then the post meta `wpdfh.print`).
6. **No editorial order.** Slice 038 (proposed) sorts the gallery for display and lists "an order saved with the post" as a non-goal.
7. Media Helper does not use Triples. Triples already removes the statements of a post or an attachment that is deleted, so trashing through core needs no extra work.

## Proposed design (to confirm)

**Direction of dependency.** Media Helper stays usable alone: it offers extension points and a reading function; Otherguise plugs an adapter into them. Eric's blocks call Media Helper's function, not Otherguise's, so they work with or without the modes.

**1. Reading, for the blocks.** A public function in Media Helper, `wp_media_helper_get_attached_media( $post_id, $args )` (`$args`: `mime_type`, and `context`, a free string that Otherguise fills with the slug of the mode). By default it is `get_attached_media()`; a filter `wp_media_helper_attached_media` lets the adapter answer from statements (several posts, a mode, an order). `wp-attached-gpx` and `wp-printable-gallery` replace `get_attached_media()` by it, and the gallery drops the `wpdfh.print` meta and its `$_GET['print']` test.

**2. Writing, from the panel.** Hooks in Media Helper around attach and detach: a filter that can take the operation over (`wp_media_helper_pre_attach`), actions after (`wp_media_helper_attached`, `wp_media_helper_detached`), and a filter on the rule "protected when attached to another post" (default: protected; the adapter allows several posts).

**3. Showing and editing the extra data in the panel.** A filter that adds data to each item sent to the panel (the modes an image is shown in), and a JavaScript hook (`wp.hooks`) in `editor-media-panel.js` through which a plugin adds a control to an item (one box per mode). Media Helper provides the slot; Otherguise provides the control. *Open: the structure of the panel script was not read in detail.*

**4. Data (Triples).** A statement `post media/illustrated-by attachment` (the predicate already imagined in [`triples.md`](triples.md): it accepts the qualifiers `modes/mode` and `triples/position`), in a module of its own, `media` (depending on `triples` and `modes`), since rule 4 of the module rules gives the names to a module. **Correction of the first version of this note:** it said that no mode qualifier meant "shown in every mode". The **decided reading rule** says the opposite (a statement with no mode statement belongs to no mode, so that nothing becomes visible by deleting something and a mode declared later does not include old statements by surprise). So "web and print" is written as **two mode statements**, and the default offered by the panel ticks every mode that exists. A position per mode **is** supported by the model (a position on a mode statement is a scope pin: that mode only).

**5. Compatibility with core.** `post_parent` stays the **primary** parent: set when an attachment gets its first post, left alone when others are added, moved to the next post (or 0) when the primary one is detached. The "Attached to" column and every plugin that reads `get_attached_media()` keep working.

**6. Migration of the "Print" flag.** One WP-CLI command turns every `wpdfh.print` meta into a statement (the attachment, its parent post, the mode `print`); the flag was global to the attachment, so its parent post is the only post it can be migrated to. Then the column of `wp-pdf-helper` has no reason to exist: the panel field replaces it.

## Open questions

- Names of the hooks and of the function (the ones above are placeholders to agree with the other session).
- The JavaScript extension mechanism of the panel.
- What the panel shows for an item attached to several posts, instead of the protection and the red warning of slice 016.
- Items that are not native attachments (`ext:youtube:ID`): a later step, with an entity type.
- Image sizes per mode (Media Helper's slice 033, not implemented).

## The proposal of the Media Helper session (its slice 042, 2026-10-10)

[`042-attachment-methods.md`](https://github.com/evlist/wp-media-helper/blob/main/docs/slices/042-attachment-methods.md) was written in parallel by the session that develops Media Helper. It is more complete than the points above on the **writing side** and is the one to follow there: a filter `wp_media_helper_attachment_methods` registers a method (an object implementing an interface `Method`: `describe`, `attach`, `detach`, `update`, `may_remove`), the built-in `native` method keeps today's behaviour exactly, the events `wp_media_helper_attached` and `detached` are fired whatever the method, and the method **declares fields** (`modes` as a multi-select, `position` as an integer) that Media Helper draws in the panel, so that the adapter needs no JavaScript. The active method is a site setting.

Where it agrees with this note: Media Helper stays usable alone and knows nothing of Otherguise; the adapter is a module of Otherguise (`media`) that registers the method; the predicate `media/illustrated-by` with `modes/mode` and `triples/position`; Triples already follows `deleted_post`.

**Amendments to ask for** (to carry to that session):

1. **A reading side is missing.** The contract covers the panel and the writes, but nothing lets the blocks of Eric (`wp-attached-gpx`, `wp-printable-gallery`) list *the media of a post for a mode, in order*; "the shortcode reads the method's data" leaves them depending on Otherguise. Add to the interface a method `attached( int $post_id, array $args ): int[]` (`$args`: `mime_type`, `context` = the slug of the mode, free for Media Helper), and a public function `wp_media_helper_get_attached_media( $post_id, $args )` that delegates to the active method; the `native` method is `get_attached_media()`. The blocks call that function and work with or without Otherguise.
2. **The default of the field `modes`** is every mode that exists, written explicitly (see the correction above), not "no mode".
3. **The position per mode** is supported by the model: the field `position` can later become one value per mode; the first version can keep one position.
4. **The column "Attached to" shows every post** (Eric's wish): Media Helper replaces the content of the column of the media library by the list given by the active method, for which the interface needs a batch method `posts_of( int[] $attachment_ids ): array<int, int[]>` (the posts of each attachment, one query for the page). Core only knows `post_parent`, so this is Media Helper's job, not the adapter's.

**Answers to its open decisions** (Eric, 2026-10-10: 1, 3 and 4 accepted, 2 with the wish below; 1 is accepted knowing that the module `media` only serves Media Helper, which seems unavoidable): (1) the adapter is a module of Otherguise, `media`; (2) **Eric prefers that Media Helper shows several values in the column "Attached to"** (amendment 4); the method **also** keeps a primary parent in `post_parent` (the first post that gets the item; moved to the next post, or 0, when it is detached) because core and other plugins read only that field: without it the filter "Unattached" of the media library, `get_attached_media()` of third parties and the `post` field of the REST API would call an item attached to several posts unattached; (3) the method is chosen per site, with the filter available for a per-post-type choice; (4) declarative fields first (`multiselect`, `integer` are enough here), no JavaScript slot until a field cannot be expressed.

## Order of the work (revised)

1. Media Helper: the interface, the `native` method with today's behaviour, the reading function, the registration filter and the setting (its slice 042, steps 1 and the reading side).
2. Otherguise: the module `media` (the predicate, the method, `post_parent` as primary parent) and the reading side.
3. Media Helper: the events, the multi-post panel and the declarative fields; Otherguise: the fields of the method.
4. Eric's blocks call the reading function; a WP-CLI command migrates `wpdfh.print` (an attachment, its parent post, the mode `print`); `wp-pdf-helper` is retired.
