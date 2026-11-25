<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 635 — Index: the tags and the categories with no kind are read

Status: **coded on 2026-11-25** (1038 PHPUnit tests, with a real database; the stores (`set_status`, the exclusion) run on a **scratch WordPress** with the **real** term stores; the quick edit under jsdom; **never in a browser, never on the blog**). Dependencies: 600, 626, 630, 633, 634. The words: [`../design/vocabulary.md`](../design/vocabulary.md).

## Why (Eric, 2026-11-25)

*Why only 23 `post/tag` assignments for 143 tags?* Because **a term with no kind was not read at all** (slice 600): only the terms that a person had classified entered the index, and nearly all of the tags of the blog are places and routes. Eric: *consider that the motifs that should stay out of the index are the exception: give a default kind `any` to the tags that are discovered, and exclude the ones that mean nothing to an index.*

## What was built

- **A term with no kind is read** (`Source\Terms`): a candidate of the kind `any`, **not sure** (`Candidate::sure = false`, a new field: `null` leaves the choice to the source). A kind that is not a kind counts as none. **The broader term** of a category with no kind is a candidate too; a level that has a kind stays sure, **whatever is below or above it** (the resolver reads the flag **per level**).
- **The topic made for it is a candidate** (status `candidate`): **it is not in the index** (the builder lists only the approved ones), but it has its mentions. **A term that has a kind is as before: sure, approved.**
- **Giving the term a kind approves its topic** (a sure level that meets a candidate topic approves it, and refines its kind); a level that is not sure and meets an **approved** topic leaves it approved. **Taking the kind off a term keeps its topic** (the motif of a term is read the same way with or without a kind); to take a term out of the index, **exclude its motif**.
- **Approving the candidates**: **`Topics::set_status()`**; on the screen *Topics*, the filter **"Candidates (not in the index yet)"** and **one button that approves the ones of the list** (the server lists them again from the search and the kind: **it takes no list of numbers from the browser**); the command **`wp otherguise index approve [--kind=] [--search=] [--apply]`** (a dry run by default).
- **Excluding a motif** (`Designation\Exclusion`, the state **`excluded`**): **no topic, the observations stay, the mentions of its posts go, a reading leaves it alone** (the resolver is not asked, as for a motif that a person assigned) and **the cleaner keeps it**. On the screen *Assignments*: the bulk actions **Exclude from the index** and **Include again** (the next reading proposes a topic), the view **Excluded** with its count, *Not in the index* in the column of the topic; **in the quick edit, the last choice of the list**, by REST (`exclude: true`).
- **The evidence keeps the flag** of a level that nobody classified, so that a motif read again from its evidence is read the same way.

## Why a state and not a topic "None"

Eric proposed to exclude by assigning to **no topic or to a topic "None"**. **"No topic" does not hold** (a motif with no topic is proposed one again at every reading); a **reserved topic** would have to be protected from the cleaning, the merges, the enrichment, the renaming and the index. **A state of the motif** records the same decision and **touches none of that**.

## Not done, and what to look at

- **Never tried in a browser or on the blog.** The first thing to do: **`wp otherguise index approve`** (a dry run) after the next synchronisation, and the filter *Candidates* of the screen *Topics*.
- **A topic read from a tag has no Wikipedia or Wikidata key**: **the enrichment never classifies it** (it starts from a key). A person gives the kind. Looking the label up on Wikidata is **not decided** (homonyms).
- **The synchronisation has to run** for the tags with no kind to be read: reading is done by `wp otherguise index sync`.
- A **category** with no kind under a parent with no kind makes **two candidates**; approve them together.
- The command `approve` is tested as a class (`IndexApproveCommandTest`); **its registration with WP-CLI is not**.
