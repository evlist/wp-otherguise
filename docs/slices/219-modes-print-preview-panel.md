<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 219 — Modes: a print preview panel in the post editor

Status: **draft, no code**. Eric's precisions of 2026-10-19 are recorded as **Decided** below; everything else is **Proposed** or **Open** until Eric confirms. Dependencies: slices 200 (modes), 202 (variants) and 213 (stylesheet of a mode). Module: `Modes`, unless a later book module is a better home (open).

## Goal

Eric starts a book generated from the blog. His first step is to choose the photos of the `print` pages (with Media Helper) and to review their text. Media Helper lets him choose, but not see whether the result **fits on one page**. He wants to see the page in print mode **while editing**, and to be told whether it overflows.

## What the editor can and cannot do (read from the code, not tried)

- The theme loads its `style.css` in the editors (`add_editor_style`), but **not** `modes/print.css`, which the Modes module loads only on the site, for the active mode.
- The post editor shows the content of the post only: not the header, the title block or the QR code of the print template, which is chosen at render time by the plugin.
- The editor does not paginate: it can never say "this overflows".
- `modes/print.css` defines **no page format** today (no `@page`, no height).

So the editor is not the place to compute the fit. The real rendering is.

## Decided (Eric, 2026-10-19)

1. **Published pages first**: for now the preview is of posts that are already published, so the address is the permalink with the print mode and the preview nonce of drafts is not needed.
2. **An indication only**: the definitive answer can be given only when printing. The measure is a hint, not a guarantee.
3. **The page size and the margins are a configuration** (not written in the CSS of the theme alone).
4. **A window proportional to the page**, in an `iframe` (and/or a `canvas`), and possibly the **usable part** of the page (the page minus the margins) shown too.
5. **A side panel or a floating one** (the choice stays open).

The child theme has its own repository, [`evlist/wp-twenty-twenty-five-printable`](https://github.com/evlist/wp-twenty-twenty-five-printable): `modes/print.css` and the templates are there; it defines no `@page` today.

## Proposal

**A panel of the post editor that shows the post in an `iframe`, in print mode, at the size of the page.**

1. **The iframe.** A sidebar panel (`PluginSidebar`, or a larger panel opened from a button) contains an `<iframe>` whose address is the post with the print mode (`?mode=print`, the `?print` alias being the same mode). Same site, so no framing problem. The rendering is the real one: print template, `modes/print.css`, `modes/link` blocks, QR code, Media Helper gallery.
2. **The size.** The iframe has the width and height of the page of the book (for example about 559 × 794 px for A5 at 96 dpi), reduced with `transform: scale()` to fit the panel.
3. **The usable part and the verdict.** The page is drawn as a frame in proportion to the configured size, and the margins as an inner frame (the usable part). The The editor script is on the same origin, so it can read the height of the document of the iframe once it has loaded (images included) and compare it with the height of the usable part: a light "fits / overflows by N px" shown next to the preview. Only an indication (decision 2).
4. **Fresh content.** The iframe shows what is saved (the permalink, decision 1), and it reloads after each **Update**, and on a **Refresh** button (Media Helper changes go through REST without saving the post; whether Media Helper offers an event to hook on is open).

## Prerequisites

- **The format of the page** (width, height, margins) is a configuration of the plugin (decision 3). Open: where (a section of **Settings → Otherguise modes**, a filter, or `theme.json`), the unit (millimetres), and whether the same values should feed the `@page` of the theme so that the preview and the printing agree.
- `?mode=print` on the permalink is the mode that the plugin already serves; nothing to add.

## Open questions (do not assume)

1. Where the format and the margins are configured, and whether a single configuration also writes the `@page` of the theme (above).
2. Format of the page to start with (A5, A4, square), one page per post or several; is an overflow onto a second page an error or only a note?
3. Side panel (narrow, small scale) or floating panel (movable, larger)? Both can show the same component.
4. `iframe` only, or an `iframe` plus a `canvas` for the frames and the verdict? The measure is an approximation: lazy-loaded images, `break-inside` and fixed boxes may change the height; the engine of the final PDFs is not chosen.
5. Does Media Helper emit a JavaScript event after a change of attachments, to refresh the preview? (Its changes go through REST without saving the post, and the iframe shows the saved page.)
6. Capability: the panel appears for those who can edit the post; no other privilege.
7. Drafts and unsaved changes are out of scope for now (decision 1); later, the preview address of WordPress would be needed, and whether it honours `?mode=print` is untried.

## To verify when built

Say what ran only under automated tests (jsdom) and what was never tried in a real editor on a real site: the real height of the iframe, the preview address with the mode, and the behaviour of lazy-loaded images are only known on a real site.

## Not in this slice

Producing the PDF; the pagination of a book; editing the print template from the editor.
