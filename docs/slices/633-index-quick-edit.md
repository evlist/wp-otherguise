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

## Amendment: itineraries in the table of the classes of Wikidata (Eric, 2026-11-24)

*Can Wikipedia/Wikidata not tell the type of EuroVelo 9?* They can: Wikidata says it is a *long-distance cycling route* (Q353027), a sort of *cycling route* (Q102307360), then of *thoroughfare* (Q83620) and *route* (Q137255322). The plugin reads that chain, but **the table of the classes had no line for itineraries**, so the topic stayed *Unspecified*. Four lines were added to the default table, all of the kind `route` and all **checked on Wikidata on 2026-11-24**: *thoroughfare* (Q83620: a road, a street, a motorway, a cycling route, a hiking trail and a long-distance trail all come under it, so this one line would do), *road* (Q34442), *cycling route* (Q102307360) and *hiking trail* (Q2143825), kept so that the table says what it means. A **street** (Q79007) comes under the thoroughfare too: it is now a `route`, which is a choice to look at.

**A blog that already changed the table does not get them**: the table is a stored copy as soon as `wp otherguise index enrich map` has been used once, and the defaults are no longer read. Print it with `enrich map --list` and add the lines by hand (`enrich map Q83620 route`).

## Amendment: the kind is deduced again (Eric, 2026-11-24)

*`enrich run --topic=29269 --dry-run` said "0 topics looked at" for EuroVelo 9, which had its identifier `wikidata:Q3060539` and stayed *Unspecified*.* A topic that already has its identifier and a label of its own was **never read again** (`Enricher::source_of` said "nothing to ask"): **the kind was deduced once, at the first reading**, so a line added later to the table of the classes did nothing for the topics already read, and `--topic`, documented as "looked at again whatever its state", did not keep its word.

- `enrich run --topic=<id>` **reads the topic again by its identifier** of Wikidata, whatever its label (the label can then be renamed as usual).
- **`wp otherguise index enrich kinds [--apply]`**: the approved topics that have an identifier of Wikidata and the kind `any` or `other` that no person chose are read again in batches of 50, and **only their kind changes** (never a kind chosen by hand, never the label, never the state of the enrichment). A dry run by default, one line per topic (*id, label, old kind => new kind, item*). **To run after each `enrich map`.**
- The kind of the form *or to a new topic* of the screen of a topic was the first of an alphabetical list (*Activity*); it is now *Unspecified*.
- Not done: the nightly job does not read the kinds again by itself; the command is the way. The command `kinds` itself is not covered by a test (the command `enrich` has none): its work, `Enricher::reread_kinds()`, is.

## Correction: the thoroughfare is too wide (Eric, 2026-11-24)

The first lines for the itineraries (the thoroughfare Q83620, the road, the cycling route, the hiking trail) were run by `enrich kinds --apply` on the blog: **23 of the 44 topics that got `route` were not routes** (the squares *Grand-Place*, *Piazza del Campo*, *Place Saint-Pierre*; the bridges and the viaduct of Millau; the tunnels; *Port of Vigo*; *Amsterdam Central station*; *RER C* and *ligne H*). Read on Wikidata, they all came under **thoroughfare** (square, bridge, road tunnel, port, geocode area…). **The generic class route (Q137255322) takes in the thoroughfare, so it is wider still** (3565 subclasses, the watercourses among them): it was **not** put in the table.

- **The default table has four precise lines, all `route`**: road (Q34442; the streets and the motorways come under it), cycling route (Q102307360), hiking trail (Q2143825) and trail (Q628179). **The thoroughfare is not in it** (a test says so, with a square, a bridge and a port). Tried on the 44 topics against Wikidata: **21 stay `route`**, all real itineraries (EuroVelo 8, 10 and 13, Via Francigena, Via Alpina, Via Tolosana, the road to Le Puy, the Camino inglés, Stevenson's trail, the national roads…).
- **`enrich kinds --reset=<kind> [--apply]`** undoes a kind that a line too wide gave: the approved topics of this kind that have an identifier of Wikidata and **that no person gave it** (not by hand, **not born of a term of WordPress**, whose kind a person gave in the screen of the terms) are read again and get what the table says now, **or `any`** when it says nothing. A dry run by default: **read its list before `--apply`**.
- On a blog whose table was changed by hand: `enrich map Q83620 --remove` and `enrich map Q137255322 --remove`, then `enrich kinds --reset=route`.
- Two cases to look at by hand: *Pont Charles* stays `route` because Wikidata also says it is a *street*; *Chemin de la Mâture* is in none of the four classes (it would go back to `any`).
