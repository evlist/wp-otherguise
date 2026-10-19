<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 219 — Books: a print preview panel in the post editor

Status: **draft, no code**. Eric's precisions of 2026-10-19 and 2026-10-20 are recorded as **Decided** below; everything else is **Proposed** or **Open** until Eric confirms. Dependencies: slices 200 (modes), 202 (variants) and 213 (stylesheet of a mode). Module: `Books` (see **Decided**, with a reservation of Eric).

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

6. **Home: the module `Books`** of Otherguise (depends on `Triples` and `Modes`), not `Modes` (which knows which version of a page, not the paper) and not a new plugin for now. `Books` holds the configuration of the page, its settings screen and the preview panel; the address of the page in a mode comes from `Modes` (`modes_url()`).
7. **Every post can be part of a book**, so the panel is for every post, not only for some.
8. **Global settings for now** (page size and margins). When the definition of the books progresses, they may **migrate** to the books themselves.

9. **The preview is refreshed on demand** (a **Refresh** button), in a first version. The result changes at each attachment or detachment of a photo in print mode (through Media Helper, without saving the post), and also after an **Update** of the post. Later, Media Helper will be asked to add a hook (a JavaScript event after a change of attachments) so that the panel refreshes itself; that request is not made yet.

10. **Freshness is one question for the whole page, not only for Media Helper** (Eric, 2026-10-20): the rendering also changes with the title, the content, the excerpt and the featured image, which are WordPress core data. An automatic refresh that follows Media Helper only would be incomplete: if the panel refreshes itself, it must do it for the core changes as well.

11. **A floating panel** (Eric, 2026-10-21, from a screenshot of the editor): Media Helper occupies the sidebar of the editor (a plugin sidebar), and the editor shows **one sidebar at a time**, so a second sidebar could not be open together with it. A floating panel lets Eric see the preview **while** choosing the photos with Media Helper. The side panel option is dropped for this version.
12. **First version = steps 1 and 2 of "Keeping the preview up to date"**: the **Refresh** button with the "out of date" mark on a dirty post, and the refresh after each **Update**. The Media Helper hook, the live preview of unsaved changes and any refresh on every change are left for later.

**Reservation (Eric):** the preview could be more useful than a print preview: to **preview a post being edited, whatever the mode**. If so, `Books` is too narrow a home. Consequence recorded here, **Proposed**: build the panel **independent of the print mode** from the start, a component that takes a mode as a parameter (a selector in the panel, `print` by default), whose **page frame** (size, margins, usable part, verdict) is an optional layer enabled when the mode has a page format. The component must not use the internal classes of `Books`, so that it can be moved later to `Modes`, to a module of its own (for example `Preview`, depending on `Modes`) or to a plugin, without a rewrite. Open: where it lives finally; decided when the second use (another mode, or drafts) is wanted.

The child theme has its own repository, [`evlist/wp-twenty-twenty-five-printable`](https://github.com/evlist/wp-twenty-twenty-five-printable): `modes/print.css` and the templates are there; it defines no `@page` today.

## Proposal

**A panel of the post editor that shows the post in an `iframe`, in print mode, at the size of the page.**

1. **The iframe.** A sidebar panel (`PluginSidebar`, or a larger panel opened from a button) contains an `<iframe>` whose address is the post with the print mode (`?mode=print`, the `?print` alias being the same mode). Same site, so no framing problem. The rendering is the real one: print template, `modes/print.css`, `modes/link` blocks, QR code, Media Helper gallery.
2. **The size.** The iframe has the width and height of the page of the book (for example about 559 × 794 px for A5 at 96 dpi), reduced with `transform: scale()` to fit the panel.
3. **The usable part and the verdict.** The page is drawn as a frame in proportion to the configured size, and the margins as an inner frame (the usable part). The The editor script is on the same origin, so it can read the height of the document of the iframe once it has loaded (images included) and compare it with the height of the usable part: a light "fits / overflows by N px" shown next to the preview. Only an indication (decision 2).
4. **Fresh content.** The iframe shows what is saved (the permalink, decision 1), and it reloads on the **Refresh** button (decision 9) and after each **Update** of the post. Media Helper changes go through REST without saving the post, so until it offers a hook the user presses **Refresh**.

### Keeping the preview up to date (Proposed)

Two kinds of change, which do not behave the same way:

- **Core fields of the post** (title, content, excerpt, featured image, template): they live in the data store of the editor (`core/editor`) and reach the database only on **Update** (or on the autosave, which for a published post is stored apart and not shown by the permalink). The iframe shows the saved page. So the panel can **detect** that the edited post differs from the saved one (the editor knows when the post is dirty) and show an **"out of date"** mark on the preview; the page itself is refreshed after the **Update**.
- **Media Helper attachments**: written to the database at once by REST, without touching the post. Today nothing tells the panel; later the hook of decision 9.

First version: the **Refresh** button plus the "out of date" mark on a dirty post (cheap, no change to the server). Then, in this order and each one optional: refresh by itself after an **Update**; a refresh on the Media Helper hook; a **live** preview of unsaved changes, which needs the preview address of WordPress (it renders the autosave of a published post) or a render of the edited content on the server, and which is the point left out by decision 1. Open whether an automatic refresh on every change is wanted at all (it costs a full render of the page each time; a short delay after the last change would be needed).

## Prerequisites

- **The format of the page** (width, height, margins) is a configuration of the plugin (decision 3). Open: where (a section of **Settings → Otherguise modes**, a filter, or `theme.json`), the unit (millimetres), and whether the same values should feed the `@page` of the theme so that the preview and the printing agree.
- `?mode=print` on the permalink is the mode that the plugin already serves; nothing to add.

## Open questions (do not assume)

1. Where in the settings the format and the margins are configured (global, decision 8), and whether a single configuration also writes the `@page` of the theme (above).
2. Format of the page to start with (A5, A4, square), one page per post or several; is an overflow onto a second page an error or only a note?
3. The floating panel (decision 11): how it is opened (a button of the toolbar of the editor, a shortcut), whether it can be moved and resized, and whether its position and size are remembered (in the browser of the user). Resized, the page keeps its proportion.
4. `iframe` only, or an `iframe` plus a `canvas` for the frames and the verdict? The measure is an approximation: lazy-loaded images, `break-inside` and fixed boxes may change the height; the engine of the final PDFs is not chosen.
5. The hook asked of Media Helper for later (decision 9): its name and arguments (an event after an attachment or a detachment, with the post and the attachment) are to be agreed with the Media Helper session; nothing is assumed about what exists today.
6. Capability: the panel appears for those who can edit the post; no other privilege.
7. The final home of the component after the reservation of decision 6 (kept in `Books`, or lifted out when the preview of another mode or of a draft is wanted).
8. Which of the refresh steps above are wanted, and whether a live preview of unsaved changes is ever wanted.
9. Drafts and unsaved changes are out of scope for now (decision 1); later, the preview address of WordPress would be needed, and whether it honours `?mode=print` is untried.

## To verify when built

Say what ran only under automated tests (jsdom) and what was never tried in a real editor on a real site: the real height of the iframe, the preview address with the mode, and the behaviour of lazy-loaded images are only known on a real site.

## Not in this slice

Producing the PDF; the pagination of a book; editing the print template from the editor.
