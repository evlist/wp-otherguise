<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 224 — Books: the section panel in a floating window

Status: **planned (2026-10-24), not coded.** Dependencies: slice 223 (the sections, their routes and the panel), slice 219 (the floating panel of the preview, whose drag, resize and memory of the place are taken again). Module: `Books`. Model for the ergonomics: the Media Helper panel (`editor-media-panel.js` of `evlist/wp-media-helper`: the link "Open in a floating window" at the top of the panel, the window moved by its title bar and resized by its corner, the same panel in the sidebar and in the window, a menu item of the editor).

## Goal

The panel of slice 223 works but is heavy when the post being edited is a **section**: the whole list of its contents, with three buttons on each line, fills the sidebar (screenshot of Eric, 2026-10-24). On a plain post, on the contrary, the panel is discreet and fine. Decided with Eric (2026-10-24): **the list moves into a floating window** designed like Media Helper's, and the sidebar of a section keeps a **summary only**.

## What it does

1. **The title is "Book section"** (panel of the sidebar, window, menu item), not "Section", which says too little. The word "section" stays in the texts inside the panel.
2. **The sidebar**:
   - on a **plain post** nothing changes: "In sections", the field "Add to a section" and the switch "Use this post as a section" (screenshot 2 of Eric);
   - on a **section** the contents are no longer listed there. The sidebar shows the switch, **"Contains (n)"** with the range of the dates (the oldest and the newest post), and, **at the top of the panel**, the link **"Open in a floating window"**. "In sections" stays, since a section can be part of a book.
3. **A menu item** in the "⋮" menu of the editor, as Media Helper has: "Open the Book section in a window" / "Close the Book section window". It is shown for a section only.
4. **The window** (plain DOM, `role="dialog"`, `aria-label` "Book section"): a title bar with the title of the section (moved by it), a ✕ button and Escape to close, a corner to resize it (at least 280 x 220 px), kept in view, **place and size remembered by the browser** (`localStorage`, key `booksSectionWindow`, in a `try`: without storage it is simply not remembered). On a narrow screen (a phone, under 600 px) it covers the screen. Open or closed, it is the same panel and the same state as the sidebar (one model, two views): what is done in one is seen in the other.
5. **The lines** of the window, one per post of the section, in the order of the section:

   ```
   ┌───────────────────────────────────────────┐
   │ ▲ move up                      ＋ add before │
   │  Title of the post                        │
   │  2026-09-29 · publish · moved   🗑  👁  ✎ │
   │ ▼ move down                     ＋ add after │
   └───────────────────────────────────────────┘
   ```

   - **The featured image of the post as the background of the line, lightened**: an `<img>` behind the text (`position: absolute`, `object-fit: cover`, low `opacity` over a white line), **`loading="lazy"` and `decoding="async"`**, `alt=""`; a post without featured image has a plain light line. The text stays dark and readable (the contrast of the title is checked on a dark photo).
   - **Top and bottom edges**: small icons with arrows to **move up** and **move down** (disabled at the ends), and icons **"add before"** and **"add after"**.
   - **Middle**: icons **remove** (with a confirmation in the line: "Remove from the section? Yes / No", Escape or No cancels, no dialog of the browser), **view** (the page; the preview link for a post that is not published) and **edit**, the last two in a new tab (`rel="noopener"`).
   - Icons are buttons with a `title` and an `aria-label`, reachable and visible with the keyboard (Dashicons, which the editor loads).
   - Below the list: **"Add posts…"** (at the end) and **"Reset the order"**, as in the sidebar of slice 223.
6. **"Add before" and "add after"** open the dialog of slice 223 (search, dates, statuses, selection) with a target. The chosen posts are put **just before or just after that line, whatever their date** (Eric: the dates are kept as they are and the order is manual; manual actions to sort, for example by date, could be added later). The new posts take the ranks of the place, **every post from there to the end gets a position** (the same principle as a move in slice 223: exact whatever the positions that already exist), a post that is in the section already is not moved ("already there"), and the rules of slice 223 are the same (cycles, capabilities, at most 200). "Add posts…" at the end of the list keeps its behaviour: the posts appear at their date among those that were not moved.
7. **Server**: the route `POST sections/<id>/contents` takes an optional **`before`** or **`after`** (the id of a post of the section; both is refused; a post that is not in the section is refused with `books_section_not_in`). The **state** of a section gets, for each post, **`thumb`** (the address of the featured image, size `medium`, or empty) and **`view`** (the permalink, or the preview link of a post that is not published), and, for the section, **`range`** (`from` and `to`, the dates of the oldest and the newest post, empty without posts). The state must be made **without a query per post**: the posts, their metas and the attachments of the featured images are loaded together (`_prime_post_caches`). `Sections::add()` gets the same optional place.
8. **Texts**: the strings of the scripts use the text domain `otherguise` (slice 223 left it out).

## What it does not do

- No drag and drop (arrows only), no selection of several lines, no sorting actions (later, manual, as said above), no kinds of units.
- The window is for sections: a plain post has nothing to detach.
- The preview panel of slice 219 is not changed; its code for the window (move, resize, memory) is copied, not shared. A common frame could be extracted later.

## Code (planned)

`section-model.js` (the state, the calls of the API and the listeners, shared by the two views), `section-ui.js` (the sidebar view, reduced), `section-window.js` (the window and its lines), `section.js` (the connection to the editor: the panel, the menu item `PluginMoreMenuItem`, the title), `section.css`; on the PHP side `Section\Sections` (`add` with a place, `state` with `thumb`, `view` and `range`), `PostStore` (`info` gets `thumb` and `view`; `prime` also loads the featured images), `Rest\SectionRoutes` (`before`, `after`).

## Tests (planned)

- PHPUnit with a database: adding before and after (first, middle, last line; several posts; a post already there; a place that is not in the section; both `before` and `after`; the order that results with positions that already exist; the cycles and the limits as before), the state (`thumb`, `view`, `range`; empty section), the route (the arguments, the refusals).
- jsdom: the model shared by the two views; the sidebar of a plain post (unchanged) and of a section (summary, no list, the link); the menu item; the window (open, close, Escape, ✕, remembered place and size, kept in view, narrow screen); the lines (image lazy, no image, the arrows and the ends, remove with confirmation and cancel, view and edit links, add before and after with the dialog); a failing request.
- With a real WordPress and its editor (Playwright), as for slice 223.

## Open

- **First display with many photos**: Media Helper makes the thumbnail sizes on demand, so the first opening of a section of a hundred posts could be slow; `loading="lazy"` is the answer for now, to measure on testing before deciding more.
- Whether the window should also be offered from a **toolbar button** (as the preview does); not planned.

## Verified

Nothing yet.
