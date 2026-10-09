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

First step: read Media Helper's attachment model and the hooks it already offers, then decide whether the change belongs in Media Helper (new hooks), in this project (adapter), or both.
