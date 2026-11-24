<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 630 — Index: the screen of the assignments

Status: **coded on 2026-11-24** (1012 PHPUnit tests, 8 of them for this slice, with a real database for the statements; 4 tests of the script in jsdom; the table also run on a **scratch WordPress** with the **real** `WP_List_Table`; **never in a browser, never on the blog, never with thousands of designations**). Dependencies: 626 (designations and assignments), 628 (the Topics screen, which stays). Context: [`625-index-topics-and-observations.md`](625-index-topics-and-observations.md).

## Why (Eric, 2026-11-24)

*"The screens of the topics do not seem very practical and we will come back to them later. What is missing at this stage is **a screen to manage the assignments of designations to topics**. It needs a WP table."* The Topics screen starts from a topic; to assign, one had to type a number or an exact label.

## What was built

**Otherguise → Assignments**: a `WP_List_Table` with **one line per designation**.

| Column | What |
|---|---|
| (box) | to tick, for the bulk actions |
| **What was designated** | the label, the key in small, the row action **Posts** (the posts where it is found: the view of a designation of the Topics screen) |
| **Source** | `post/link`, `image/address`… (sortable) |
| **Posts** | how many posts observe it (sortable) |
| **Assigned to** | the topic (a link to it), its kind and the topics above it (sortable) |
| **By** | `system` or `hand` |
| **State** | assigned, ambiguous or pending, and the identifier of Wikidata when there is one (sortable) |

- **The views** above the table, with their numbers: *All*, *To resolve* (pending or ambiguous), *Assigned by the system*, *Assigned by a person*, *Observed by no post* (the number of the last one is not known until it is chosen: it costs a pass over every observation).
- **A search** (accents and case ignored) in the label, the key and the label of the topic; **sorting**; **pages of 50**.
- **Bulk actions**:
  - **Assign to the topic chosen above…**: a field with **a search as one types** (the topics whose label contains the text, the labels that begin with it first, each with its kind and the topics above it, to tell *Moulins (Allier)* from *Moulins* the river). **A confirmation comes first** (how many designations, observations and posts; nothing is deleted), then one click. A conflict of kinds is refused unless a box is ticked. The mentions of the posts follow.
  - **Confirm the assignment**: what the system proposed becomes a decision of a person; a new reading never changes it.
  - **Take the assignment away**: the designation goes to *To resolve*, the mentions of its posts go; **a new reading may propose a topic again** (the decision to keep it away is not recorded).
  - **Delete**: only the designations that no post observes; the others are left, and the number is said.
- Each action checks **the capability** (`index_capability`) and **the nonce of the table**; the table posts to **the screen itself** (its field `action` would be taken for an action of `admin-post.php`), the request is handled when the page loads and redirected; **only the confirmation** posts to `admin-post.php`, with a nonce of its own.

**The field that finds the topic**: a small script (`modules/index/admin/topic-picker.js`, loaded on this screen only) over a route **`GET /wp-json/index/v1/topics?search=`** (read only, the capability of the module). Typing waits a quarter of a second, one letter is not searched, an answer that comes late is ignored, **typing again forgets the topic that was chosen**. Without the script, **a number or an exact label typed in the field** still works.

**The data**: `Designation\Listing` (without WordPress) reads **the assignments in two queries** whatever their number, and **asks the number of posts only for the lines of the page**, except to sort by it or to list the designations that nothing observes (one pass over every observation). The stores of the topics and of the designations now put **their terms and their meta in the cache in one go** (a list does not make seven queries per line).

## Where it may be too slow (to measure)

Sorting by the number of posts, and the view *Observed by no post*, read **every observation** (tens of thousands of statements): this is **the threshold of slice 625** (a page in a second). **`wp otherguise index designations measure`** and a look at the screen on the blog will tell. If it is too slow, **the number of posts goes into the designation** (kept at each reading) or **the designations and the assignments go to a table of the database**; the screen and `Listing` do not change.

## Not done

- **No exception on one observation** (as in slice 628). **No edit of a designation** (its evidence is read only).
- **Taking an assignment away does not remember the decision** (a reading may propose again).
- **Nothing in a browser**: the layout is the standard one of WordPress, and **the script was only run in jsdom**.
- The screen of the topics (628) is **unchanged**, as Eric said.

## Tests

`IndexAssignmentsDbTest` (a stand-in of `WP_List_Table` for the table): the list (views and their numbers, search, sort by label, posts and topic, pages, the number of posts of the lines); the assignments and the counts; the finder (a number, a label, a label that several topics bear, the search with its order, the kind and the topics above); the route; the table (columns, sortable columns, bulk actions, views, the lines **escaped**); **the bulk actions** (the capability and the nonce first, nothing ticked, assign → confirmation with the topic typed, confirm, delete only what no post observes, take away → the mentions go); the confirmation and the assignment (the mentions follow, a conflict of kinds refused, a nothing-ticked said); the page (two forms, a stranger sees nothing). `tests/js/index-topic-picker.test.js` (4 tests): the suggestions and the choice, one letter, a late answer and a failed search, destroying.

## Amendment (2026-11-24, the same evening)

**A bug seen on the blog**: the table printed its views and its pagination but **no header and no line**. A table of a plugin must give its columns to `WP_List_Table` itself (`_column_headers` in `prepare_items()`); without it WordPress looks for them in a filter of the screen and prints none. The test with the stand-in of the class and the first try on the scratch WordPress only looked for the fields, not for the columns: **the test now checks `_column_headers`**, and the scratch WordPress was run again with a real designation (the headers, the line, the checkbox, the sortable columns are printed).
