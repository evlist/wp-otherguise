<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 219 — Modes: a print preview panel in the post editor

Status: **draft, nothing decided, no code** (Eric asked for the slice to be started, 2026-10-19). Everything below is **Proposed** or **Open** until Eric confirms. Dependencies: slices 200 (modes), 202 (variants) and 213 (stylesheet of a mode). Module: `Modes`, unless a later book module is a better home (open).

## Goal

Eric starts a book generated from the blog. His first step is to choose the photos of the `print` pages (with Media Helper) and to review their text. Media Helper lets him choose, but not see whether the result **fits on one page**. He wants to see the page in print mode **while editing**, and to be told whether it overflows.

## What the editor can and cannot do (read from the code, not tried)

- The theme loads its `style.css` in the editors (`add_editor_style`), but **not** `modes/print.css`, which the Modes module loads only on the site, for the active mode.
- The post editor shows the content of the post only: not the header, the title block or the QR code of the print template, which is chosen at render time by the plugin.
- The editor does not paginate: it can never say "this overflows".
- `modes/print.css` defines **no page format** today (no `@page`, no height).

So the editor is not the place to compute the fit. The real rendering is.

## Proposal

**A panel of the post editor that shows the post in an `iframe`, in print mode, at the size of the page.**

1. **The iframe.** A sidebar panel (`PluginSidebar`, or a larger panel opened from a button) contains an `<iframe>` whose address is the post with the print mode (`?mode=print`, the `?print` alias being the same mode). Same site, so no framing problem. The rendering is the real one: print template, `modes/print.css`, `modes/link` blocks, QR code, Media Helper gallery.
2. **The size.** The iframe has the width and height of the page of the book (for example about 559 × 794 px for A5 at 96 dpi), reduced with `transform: scale()` to fit the panel.
3. **The verdict.** The editor script is on the same origin, so it can read the height of the document of the iframe once it has loaded (images included) and compare it with the height of the page, minus the margins: a light "fits / overflows by N px" shown next to the preview.
4. **Fresh content.** The iframe shows what is saved. For a draft it uses the preview address of WordPress (`preview=true`), and it reloads after each save or autosave, and on a **Refresh** button (Media Helper changes go through REST without saving the post; whether Media Helper offers an event to hook on is open).

## Prerequisites

- **The format of the page** is fixed in `modes/print.css` of the theme (`@page` size and margins). Open: A5, A4, square? One page per post, or several pages allowed?
- The mode must be taken into account on the preview address (see the first open question).

## Open questions (do not assume)

1. **Does `?mode=print` apply on the preview address of a draft** (`?p=…&preview=true&preview_nonce=…&mode=print`)? To try first, by hand, on testing: open the preview of a post from the editor and add `&mode=print`.
2. Format of the page, one page or several (above). Is overflowing onto a second page acceptable, or an error?
3. Where does the panel live: the sidebar (narrow, so the page is shown at a small scale) or a button that opens a wide panel or a new tab?
4. How is the height of the page compared when the print CSS uses `break-inside` or fixed boxes? The measure is an **approximation**: it is not the pagination of a print engine, and the engine that will produce the PDFs of the book is not chosen.
5. Does Media Helper emit a JavaScript event after a change of attachments, to refresh the preview?
6. The panel needs a capability (editing the post) and, for the preview address, the nonce that WordPress gives; no other privilege should be added.

## To verify when built

Say what ran only under automated tests (jsdom) and what was never tried in a real editor on a real site: the real height of the iframe, the preview address with the mode, and the behaviour of lazy-loaded images are only known on a real site.

## Not in this slice

Producing the PDF; the pagination of a book; editing the print template from the editor.
