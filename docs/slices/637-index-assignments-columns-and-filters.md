<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 637 — Index: the topic first, and a filter for each column of the screen of the assignments

Status: **coded on 2026-11-25** (1044 PHPUnit tests, with a real database; the table, the bar of the filters and the links of the views run on a **scratch WordPress** with the **real** `WP_List_Table`; the script under jsdom; **never in a browser, never on the blog**). Dependencies: 630, 633, 635, 636.

## Why (Eric, 2026-11-25)

*The screen of the assignments can be used in many cases with a few improvements. The column "Assigned to" should be called "Topic" and be the first one; the search over every column is handy, but a filter column by column is needed too.* Eric chose: **a bar above the table, in the style of WordPress**; the filter on the number of posts **is accepted** even if it is heavy; and **the status of the topic is a column of its own, with its own filter**.

## What was built

- **The columns**: checkbox, **Topic** (it was *Assigned to*), **Topic status** (new: `approved`, `candidate`, `refused`, or *—* when there is no topic), Motif, Source, Posts, By, State. **The motif stays the main column** of the table (its actions, *Posts*, are there); *Change topic* stays in the column of the topic. The status and *By* sort.
- **The bar of the filters** (`AssignmentsTable::filter_bar()`), above the table, **one field per column, in the order of the columns**, in the style of WordPress (`tablenav`, the button *Filter*): the **topic** and the **motif** (a text, accents and case ignored; the motif is searched in its label **and its key**), the **status of the topic** (any, approved, candidate, refused, *no topic*), the **source** (the tree of the sources; **a source stands for the sources below it**: `post/mark` takes `post/mark/whobird`), the **number of posts** (any, *no post*, at least 1, 2, 5, 10, 25, 50), **who assigned it** (the system, a person, *no assignment*) and the **state** (assigned, pending, ambiguous, excluded). *Reset the filters* shows when one is on.
- **They combine** with each other, with the **view** (All, To resolve, Excluded…) and with the **search over every column**. They are in the address (`f_topic`, `f_status`, `f_motif`, `f_source`, `f_posts`, `f_by`, `f_state`) and **follow the sorts, the pages, the bulk actions, the confirmation and the quick edit**; **the links of the views keep the search and the filters**.
- **The filter on the posts costs a pass** over every observation (`Observations::post_counts()` with no list, what `index motifs measure` times): it is made **only when that filter, the view *Observed by no post* or the sort by the posts asks for it**. The other filters cost nothing more than the list.
- **The quick edit** updates the new column too (`status` in the answer of the route).
- *Candidate (not in the index)* is the answer to the 81 topics made from tags with no kind (slice 635): **a filter on the status of the topic, then *Change topic*, *Exclude* or the approval**.

## Not done, and what to look at

- **Never in a browser or on the blog.** The bar may be wide on a narrow screen: **to look at**.
- The filter on the number of posts is **not measured on the blog** (6,560 motifs); the first thing to time.
- No action *Approve the topic* on the lines yet (the topics are approved from the screen *Topics* or `wp otherguise index approve`): **a bulk action here would be the next step**, if Eric wants it.
- The filter of the source lists every source of the registry, even those that nothing reads.
