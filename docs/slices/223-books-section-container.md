<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 223 — Books: a section as a container

Status: **planned (2026-10-23), not coded.** Dependencies: Triples (slices 103 and 104: `Statements`, `listing()`, the clean-up on deletion), slice 219 (how the editor scripts of Books are loaded). Module: `Books`. Reasoning: [`../design/books.md`](../design/books.md) ("The definition of a book: sections, units and kinds", "A section is a post, not a new post type").

## Goal

Let Eric create a **section** and **attach posts to it** as he prepares them, with nothing else: no PDF of the section, no merge, no numbering, no table of contents. The section is only a **container**, which can stay a draft while it holds posts that are already published. The attachment can be made **from the section** ("add these posts") and **from a post** ("put this post in that section").

## What it does

1. **A section is any post or page that is marked as one.** The mark is a protected post meta `_books_section` (value `1`), not registered for REST, set by a switch "Use this post as a section" in the panel, and set **automatically** the first time a post is added to it. The mark is needed because a **new section is empty**: without it nothing could list it, and the choice "in which section?" would have to scan every post. A section is a post of the types `post` or `page` (filter `books_section_post_types`), whatever its status.
2. **The relation `books/contains`** is registered by Books (action `triples_register_predicates`): subject and object are posts, **ordered** (the position of Triples), no qualifier yet (the modes and `books/pages` of the example of `usage-examples.md` come with later slices). A post can be in **several** sections. The statements follow the deletion of a post (Triples' clean-up, nothing to write).
3. **The order** is the natural order of Triples' `listing()`: **the date of the post, oldest first** (then the id); a **pin** (`triples/position` on the statement) overrides it. **Up** and **Down** pin the item at its new rank (the item that was there moves to the next free rank, as `PinnedOrder::merge` does); **Reset the order** removes the pins. Adding a post pins nothing: a day appears at its date.
4. **The panel "Section"** in the sidebar of the post editor (plain JavaScript like the preview panel, tested with jsdom), for the types above:
   - **In sections** (this post is contained in…): the sections that contain this post, each with its title (a link to its editor) and a **Remove** button; a field **Add to a section** that searches **among the sections only**, as the user types (debounced, 20 results, the most recently modified first when the field is empty), and adds the post at the end of the chosen section.
   - **Use this post as a section** (the switch of point 1); when on, **Contains (n)**: the ordered list (title, date, status), with **Up**, **Down**, **Remove** and **Reset the order**, and a button **Add posts**.
   - **Add posts** opens a **dialog** (`wp.components.Modal`): a text search, a **from / to** date range, a choice of statuses (published by default), the results in pages of 50 with **check boxes** (those already in the section are shown but cannot be ticked), **Add the selection** and **Add all the results** (at most 200 at a time, in the order of the results, which is the date). The dialog stays open to add more; it reports how many were added and how many were already there.
5. **Finding a section from a post must not scan the posts.** Two lookups, both indexed: *which sections contain this post* is `subjects_of( $post, 'books/contains' )`, which uses the index `(object_type, object_id, predicate)` of the Triples table; *which sections exist* is a query on the meta key `_books_section` (an indexed column) with the search text, **never** a listing of every post. The result pages are small and capped.
6. **Rules enforced on the server** (the browser is never trusted):
   - the user needs `edit_post` on the **section** to add, remove, reorder, mark or unmark it; to add a post the user also needs `read_post` on it (an article of another author that the user cannot read cannot be attached);
   - a section **cannot contain itself or one of the sections that contain it** (no cycle, however deep): the request is refused with a message;
   - the same post is not added twice (the unique index of Triples; the answer says "already there", it is not an error);
   - the ids of a batch are checked one by one (a post that does not exist or cannot be read is skipped and reported), at most 200 per request; the page and the number per page are capped;
   - unmarking a section that still contains posts is refused until it is emptied (the relations are not silently orphaned).
7. **REST routes** (`books/v1`, capability checks and the REST cookie nonce, a message that can be translated for each refusal):
   - `GET sections?search=&page=` the sections the user may edit;
   - `GET sections/<id>` the state: marked or not, the contents in order, the sections that contain it;
   - `PUT sections/<id>` mark or unmark;
   - `POST sections/<id>/contents` add `ids[]`;
   - `DELETE sections/<id>/contents/<post>` remove;
   - `POST sections/<id>/contents/<post>/move` pin at a rank (`up`, `down` or `rank`), and `DELETE sections/<id>/order` to reset;
   - `GET sections/<id>/candidates?search=&after=&before=&status=&page=` the posts that can be added (capped, those already in marked).
8. **WP-CLI** (every entry point has a command and a test; each can be run twice; `--dry-run` where something changes): `wp otherguise books section list [--format=…]`, `show <id>`, `add <section> <ids…>` (or `--search=`, `--from=`, `--to=`, `--status=` to select the posts), `remove <section> <ids…>`. The same server rules as the REST routes.
9. **Clean-up**: the meta goes with the post; `uninstall()` of the module deletes the metas of every post; the statements stay under the care of Triples' uninstall.

## What it does not do

- No kind of unit (day, opener, summary…), no page counts, no templates for a section: later slices ([design](../design/books.md), "kinds").
- No PDF of a section, no merge, no folios, no table of contents or index.
- No private link (slice 400): the container works on drafts and published posts of the same site as the editor.
- No automatic content (a section filled by a rule such as a range of dates): the posts are chosen.
- The panel is a sidebar panel and a dialog, not a floating panel as the preview; to see after use whether a floating panel is wanted.

## Tests (planned)

- PHPUnit: the registration of `books/contains` (types, ordered); the marking (set, unset, refused when not empty, set by the first addition); adding (one, several, twice, a missing post, an unreadable one, 201 ids refused), removing, the order (natural by date, ties by id, up, down at the ends, rank, reset, pins surviving the removal of another item); the cycles (itself, a section in its child, in a grandchild, a post that is not a section); the capabilities (`edit_post` of the section, `read_post` of the post); the lookups (sections of a post, search among sections only, capped, an empty field); the REST routes (permission, nonce, validation of every argument, the answers); the commands (output, run twice, `--dry-run`, selection by search and dates); `uninstall`; an entry for each new hook in the test of the module; the architecture test (Books uses Triples only through its API).
- With a database (`OTHERGUISE_TEST_DB`): the indexed lookups and the order with pins, as the Triples tests do.
- jsdom: the panel (no sections, add to a section by the search, remove, switch on and off, up/down/remove/reset, the dialog with filters, adding a selection, a failing request, a refused cycle).
- With a real WordPress and its editor (Playwright), as for slices 219 and 220: create a section, attach published posts from the section and from a post, reorder, remove.

## Verified

Nothing yet.
