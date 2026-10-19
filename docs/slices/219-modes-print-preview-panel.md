<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 219 — Books: a print preview panel in the post editor

Status: **first version coded (2026-10-21), tried only under automated tests**; not yet tried in a real editor. Eric's precisions of 2026-10-19 and 2026-10-20 are recorded as **Decided** below; everything else is **Proposed** or **Open** until Eric confirms. Dependencies: slices 200 (modes), 202 (variants) and 213 (stylesheet of a mode). Module: `Books` (see **Decided**, with a reservation of Eric).

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

13. **Tried by Eric on testing (2026-10-21): the print mode works on the preview address of WordPress** (the address opened by **Preview** in the editor, with `preview_id`, `preview_nonce` and the other parameters) when `&mode=print` is added. The question left open by decision 1 about the preview address is answered for that case. **Not known yet:** whether that preview then shows the **unsaved** changes of a published post (WordPress renders the autosave there), and how the nonce is obtained from the editor for the iframe; neither was tried.

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

First version: the **Refresh** button plus the "out of date" mark on a dirty post (cheap, no change to the server). Then, in this order and each one optional: refresh by itself after an **Update**; a refresh on the Media Helper hook; a **live** preview of unsaved changes, which needs the preview address of WordPress (it renders the autosave of a published post) or a render of the edited content on the server, and which decision 13 makes more realistic: the preview address already accepts the mode, what is left to try is the unsaved changes (the editor gives the preview link, `getEditedPostPreviewLink` in `core/editor`, which already carries the nonce). Open whether an automatic refresh on every change is wanted at all (it costs a full render of the page each time; a short delay after the last change would be needed).

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
9. Drafts and unsaved changes stay out of the first version (decisions 1 and 12). The preview address honours the mode (decision 13); the open points are the autosave of a published post and the link given by the editor.

## Detailed plan of the first version (Proposed, to confirm before any code)

Scope = decisions 1, 2, 3, 4, 6–9, 11, 12 and 13 of **Decided**. Default format **A5, 148 × 210 mm, margins 10 mm** (Eric, 2026-10-21); an overflow is reported as "overflows by N mm", with no verdict of error.

### Server side (module `Books`, namespace `Otherguise\Books\`)

- **`Settings\PageFormat`**: reads and sanitises the option `books_page_format` (an array `width_mm`, `height_mm`, `margin_mm`). Defaults 148, 210, 10. Bounds: width and height between 50 and 1000 mm, margin from 0 up to less than half of the smaller side; a value out of bounds, or not a number, falls back to the default of that field. Pure PHP, no WordPress call but `get_option`/`apply_filters` passed in, so it is tested without WordPress.
- **Settings screen** **Settings → Otherguise books** (Settings API, so the nonce is checked by WordPress): three number fields in millimetres. Capability `manage_options`, changed by the filter `books_admin_capability`. The label says that the numbers are used by the preview only, until the `@page` of the theme reads them (open question 1).
- **`Module::boot()`** registers the screen and the editor script; **`Module::uninstall()`** deletes the option. Hooks are prefixed `books_`, the option too (module rules of `docs/IA.md`).
- **Editor assets** on `enqueue_block_editor_assets`, only for a user who can edit the post being edited: the script `plugin/modules/books/editor/preview/preview.js` (+ `panel.js`, `geometry.js`, `preview.css`; the dependencies are declared in `EditorPreview`, so there is no `preview.asset.php`), and an inline `window.booksPreview = { page: {...}, modes: [...], defaultMode: 'print', frameModes: ['print'] }` (the modes come from `Modes`, the frame is drawn for the modes of the filter `books_preview_frame_modes`, `print` by default).
- **Admin bar in the iframe**: the iframe is loaded with the argument `books_preview=1`; on the front end the filter `show_admin_bar` answers false for it (cosmetic only, nothing is shown that a logged-in user would not see). Priority 10, documented as shared with other plugins.

### Editor side (plain JavaScript, no build step, like `modes/link`)

- **`geometry.js`** (pure functions, tested with jsdom/node): millimetres to CSS pixels (96 dpi), the frame of the page at a scale, the usable part, the overflow in mm from a measured height, the address of the iframe (permalink of the published post, preview link otherwise, plus `mode` and `books_preview`), and the "stale" rule.
- **`panel.js`**: the floating panel in plain DOM (no React), so that jsdom tests it for real; it has the frame, the verdict, the mode selector, the Refresh button, the drag and the resize.
- **`preview.js`**: connects the panel to the editor. Besides the menu item, a **button in the toolbar of the editor**, in the area of the pinned items next to the icon of Media Helper (Eric's request, 2026-10-22): a `Fill` of `wp.components` in the slot `PinnedItems/core` (what `PinnedItems` of `@wordpress/interface` fills; that package has **no global** `wp.interface`, found in the real editor) with a `Button`, pressed while the panel is open, driving the same panel as the menu item. The menu item stays too (useful on phones, Eric 2026-10-22). Without the slot the menu item is alone; without a menu, the button alone. **The script must not depend on the handle `wp-interface`**: it does not exist, and WordPress then drops the script silently (this is why the first version of the button made the menu item disappear too). Also: a plugin registered with `wp.plugins.registerPlugin`, a **menu item "Print preview"** in the options menu of the editor (`PluginMoreMenuItem`) that opens or closes a **floating panel** (a fixed-position box with a title bar to drag, a **Refresh** button, a mode selector, a close button, and a resize handle that keeps the proportion). It shows the page frame, the usable part inside it, the `iframe` (laid out at the usable width and clipped to the usable height, then scaled to the panel), and the line "fits" or "overflows by N mm" with the measured height. Position and size are kept in the browser (`localStorage` in a try/catch; the panel works without it).
- **Measure**: after the iframe `load` event and once the images in it have loaded (their `load`/`error` events, with a time limit), the height of the document of the iframe (`documentElement.scrollHeight`) is read; same origin, so allowed.
- **Stale and refresh**: a subscription to the `core/editor` store sets "out of date" when `isEditedPostDirty()`; the iframe is reloaded when a save ends without failing (`isSavingPost()` goes from true to false, `didPostSaveRequestSucceed()`), **not** on an autosave (`isAutosavingPost()`), and on the **Refresh** button.

### Order of work (test first)

1. `PageFormat` and its tests (defaults, bounds, fallbacks).
2. Settings screen, option, `uninstall`, capability and nonce tests.
3. `geometry.js` and its tests.
4. The editor script with a fake `wp` (jsdom): opening, the frame, the verdict, stale, reload after save and not after an autosave.
5. Server hooks (`enqueue`, `show_admin_bar`) and the architecture test (`Books` depends on `Triples` and `Modes`, nothing else).
6. `docs/wp-cli.md` is not concerned; the slice index and `IA.md` current state are updated.

### Entry points and checks (conventions of the project)

An admin screen (capability and nonce), no REST route, no WP-CLI command. Strings are translatable (text domain `otherguise`); the SPDX header is on every file; `uninstall` is part of the slice.

### Built (what exists)

`Books\Settings\PageFormat`, `Books\Admin\SettingsScreen` (**Settings → Otherguise books**), `Books\Preview\EditorPreview`, `Books\Module` (hooks: `admin_init`, `admin_menu`, `option_page_capability_books_settings_group`, `enqueue_block_editor_assets`, `show_admin_bar`; `uninstall` removes `books_page_format`), and `plugin/modules/books/editor/preview/` (`geometry.js`, `panel.js`, `preview.js`, `preview.css`). Tests: `BooksPageFormatTest`, `BooksModuleTest` (PHPUnit), `books-preview-geometry`, `books-preview-panel`, `books-preview-editor` (jsdom). The height is read from `documentElement.getBoundingClientRect()` of the page in the iframe (rounded up to a pixel).

### Honest verification

Run under PHPUnit and jsdom only (all pass): the format, the settings screen (registration, capability, form), the loader of the assets, the filter of the admin bar, the geometry, the panel (open, load, verdict, images, refresh, close, move, resize, storage), and the connection to the editor (stand-in `wp`). **Tried by Eric on testing (2026-10-22), with the format set to A4 "like last year":** the floating panel in the real editor, the frame, the usable part and the verdict ("Fits in the page. (255.6 / 277 mm)"); the preview looks close to the PDF of the same post. **Tried by Claude in a real editor** (WordPress 7.1.3 scratch site, Chromium driven by Playwright, with Media Helper active; a post of the default theme, so the page is not a real print page): the toolbar button is there, next to Media Helper's icon, pressed while the panel is open; the menu item is there; both open the same panel; the panel loads `?p=4&mode=print&books_preview=1` and shows a verdict; no script error. **Not tried yet:** the measure on a real print page (the print template has no page format, so the width of the layout may differ from the printed one), the images that load late, the behaviour with Media Helper's panel open next to it. The admin bar did not show in the iframe of the scratch site (logged in as administrator), which suggests that the filter works.

## To verify when built

Say what ran only under automated tests (jsdom) and what was never tried in a real editor on a real site: the real height of the iframe, the preview address with the mode, and the behaviour of lazy-loaded images are only known on a real site.

## Not in this slice

Producing the PDF; the pagination of a book; editing the print template from the editor.
