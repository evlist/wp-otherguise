<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 504 — Wiki: the page that does not exist

Status: **reworked for the hierarchical model, coded on the branch `wiki` (2026-11-07)**, not merged into `main`. The first version (offer to create `Space:Title`) is kept at the tag `wiki-model-1`. Dependencies: slices 500 to 503. Context: [`../design/wiki.md`](../design/wiki.md), "The page that does not exist".

## What it does

A path that does not resolve stays a **404** (slice 503); the router remembers where it stopped (`Router::missing()`: the deepest page that exists, the titles that remain). This slice shows what the visitor can do next. Classes in `Missing\` (same names as before, rewritten): `MissingPage`, `CreateAction`, `Presentation` (unchanged: the block `wiki/missing-page`, the block-theme template `wiki-missing`, the classic-theme file).

- **What is shown** (markup with classes `wiki-missing`, `wiki-similar`, `wiki-angles`, no style): the path asked; **the approaching paths** ("Did you mean:"); **the same last title elsewhere** (the angles); and, for a user who may create, the form.
- **Approaching paths** (first version, **Proposed**, to confirm with Eric by use): the remaining titles are read level by level from the deepest page, each title **exact if the page exists, otherwise through the folded keys** (an accent or a case that differs, at any level, several candidates possible); only paths that end on an existing page are listed, **at most 10**, only pages the user may see. The router now also treats as ours a path whose **first title is a first-level title written another way** (`/biography/lutece`), so that it gets this page and not a bare 404.
- **Creation** (a logged-in user with `create_posts` on `page` and the right to edit the parent; filter `wiki_may_create( $allowed, $parent, $user )`; nobody else sees a form, a crawler makes no page):
  - **one title remains**: "Create this page", made below the deepest page;
  - **several remain**: **the chain is listed** (every page that would be made) and the form needs a **ticked box** (`required` in the browser and `wiki_confirm=1` checked by the server); at most **10** titles.
  - The form posts to `admin-post.php` (action `wiki_create`) with a **nonce tied to the parent and the titles**. The server checks nonce, right, titles (the rules of slice 501, reserved titles included) and the confirmation; pages are created as **drafts**, each under the previous, **never with rules of their own** (they inherit those of the branch, and the starting template of slice 500); the user lands in the editor of the last. A title taken in the meantime: the chain goes on under its holder (the last one taken sends the user to that page). A refusal stops with a message; drafts already made stay.
- The first-model offer (`Space:Title`) is gone; the module still registers the page type `wiki_page` and its code until the cutover.

## Tests

`WikiTreeMissingDbTest` (9, real statements): one title, approaching paths (case at two levels, accent), the angles, the chain with its confirmation, no form without the right or for a refused or too long title, the action (one draft; chain refused without confirmation then created; taken title; bad nonce, no right, reserved title, empty, too deep, unknown parent), the link with the router; `WikiTreeRouterDbTest` (the first-level variant). Suite OK (822), `phpcs` clean. The old `WikiMissingDbTest` is removed with the model it tested.

## Verification

- **Tried** on the scratch WordPress (`/%postname%/`), as a visitor (404, the message, no form) and as an administrator in Chromium: `/biography/lutece` (404, "Did you mean /Biography/Lutèce" and the offer); `/Biography/Nobody/Deeper` (the two-page chain, the box blocks the submit); ticking it created two drafts under `Biography` and landed in the editor; both addresses then answer 200 for the administrator.
- **Never tried**: a user with the right but not on that parent; the block in the Site Editor; a classic theme (file template); a **large tree** (the approaching paths read the index level by level: not measured); a page that is a draft or private in the suggestions for a logged-in user; nginx; the blog instance.

## Not done

The exact definition of an approaching path stays **open** (the first version above); no rules are offered at creation; the links by title (505).
