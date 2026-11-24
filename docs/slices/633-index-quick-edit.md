<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 633 — Index: "Change topic", the quick edit of the table of the assignments

Status: **coded on 2026-11-24** (1028 PHPUnit tests, with a real database; the routes run on a **scratch WordPress** (the real REST pipeline: the answers, the conflict of kinds, a visitor refused with 401); the script of the quick edit runs **only under jsdom** (the tests of JavaScript are not run by the CI); **never in a browser, never on the blog**). Dependencies: 630, 631, 632.

## Why (Eric, 2026-11-24, from the screen of the assignments)

*To change the topic of an assignment, one ticks the line, chooses an action, clicks "Apply", then confirms: it is very laborious. Instead, a link in the table itself with an action "update", like "Quick Edit" in the list of the posts.* And: *when a person changes the topic, offer by default the topics that are close, typically the ones that the suggestions of doubles offer.* Two defects of the confirmation were found at the same time: **the search that one had typed was lost** when coming back, and the warning about kinds was shown **every time**. (Eric first took it for needless, then saw that it was legitimate: *Waterway* against *Commune* are not one a sort of the other. It was true then; it is now said **only when it is true**, with the two kinds.)

## What was built

- **"Change topic" in the actions of the column of the topic** (added by the script `admin/quick-edit.js`, so it is **absent without JavaScript**, where the bulk actions do the same). It opens a panel under the line with:
  - a **list of the close topics** (the default), then a field that searches **any other topic** as one types (the field of slice 630);
  - **Update** and **Cancel**. Update assigns the designation **by hand** (a new reading never changes it; the mentions of its posts follow) through the REST API and **changes the line in place** (topic, by, state): the view, the search, the sort and the page **do not move**;
  - when the kinds exclude each other, the panel says which and **the same button becomes "Assign anyway"**; nothing was changed before.
- **The close topics** (`Designation\Neighbours`) are found like the **first rule of the suggestions of doubles** (slice 616): **the same name once what is in parentheses is left out** (*aisne (02)* and *Aisne (affluent de l'Oise)*), from the label of the designation **and** the label of the topic it has; the topic it has and the refused topics are not offered; the same name comes first, then what begins with it. **The second rule (a letter or two that differ) is not used**: it compares every topic with every other, which is too heavy for a click. It stays in the screen of the suggestions.
- **Two routes** (`Rest\AssignmentRoute`, capability of the module, nonce of the REST API): `GET index/v1/designations/<id>/neighbours` and `POST index/v1/designations/<id>/topic` (`topic`, `force`). A conflict of kinds is **an answer, not a failure**: `ok: false`, `conflict: true`, the sentence with both kinds.
- **The confirmation** (the path without JavaScript) says the kinds **only when they exclude each other** (a warning with the designation and the two kinds, and the box "Assign them anyway"); otherwise it shows neither the warning nor the box. For a new topic, the kind that was chosen is the one compared.
- **What the page was showing is kept everywhere**: the view, the search, the sort and the page follow the bulk action, the confirmation (its form, its *Cancel* and *Back*) and the assignment, including the errors.
- `Assigner::assign()` says **which designation and which two kinds** (`conflict`).

## Not done, and what to look at

- Never tried in a browser: the placement of the panel in the table (a row `inline-edit-row` over every column), the picker inside it, the in-place update of the line. The first thing to look at on the blog.
- The line changed in place shows the topic **with its link** to the screen of the topics, its kind and the topics above (the answer of the route carries the address); the other cells (by, state) are plain text. (First version, corrected the same day on Eric's remarks: the link was in the first column and the topic had no link after the change.)
- The confirmation of an assignment to a **new topic** still goes through the page of confirmation.
- Not asked, not done: a close topic from the **letters that differ** (see above), and "apply to every designation that has the same label".
