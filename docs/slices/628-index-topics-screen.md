<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 628 — Index: one screen for the topics and their designations, and the words

Status: **coded on 2026-11-24** (1001 PHPUnit tests, 6 of them for the screen, with a real database for the statements; the screen also tried on a **scratch WordPress** with the real stores of topics; **never in a browser, never on the blog**). Dependencies: 625 (the design), 626 (designations and assignments), 627 (pending designations), 618 (the suggestions), 622 (the screen of the synchronisation). Context: [`625-index-topics-and-observations.md`](625-index-topics-and-observations.md).

## What was built (Eric's decisions of 2026-11-24)

**One screen, Otherguise → Topics, with three views** (tabs). It replaces the screen *Index duplicates* of slice 618. The screens of the kinds (603), of the terms (600) and of the synchronisation (622) stay.

- **Topics.**
  - **The list**: a search (accents and case ignored), filters (a kind with its sub-kinds, *with no designation*, *chosen by hand*), the tree (a topic shows what it is under), the number of designations, 50 lines a page.
  - **A topic**: **its label, kind and parent are edited in place**. What a person changes is **by hand**: the enrichment never replaces it (`Topics::set_label( …, true )`, `set_kind( …, true )`), and the new label **adds its key** (the old one stays, so that what was seen under the old name still comes to the topic). A topic is **not put under itself or under one of its own sub-topics**. Under it: **its identifiers**, **its designations** (source, what was designated, **number of posts**, **who assigned it**, state) and **its sub-topics**.
  - **A designation**: the posts where it is found (paginated, with the link to each) and where it is assigned.
- **To resolve**: the designations that are `pending` (Wikidata has not answered: they resolve by themselves) or `ambiguous` (several topics fit), with the same form to assign them.
- **Suggestions**: the topics that may be the same (the finder of slice 618), with its preview and its safe suggestions, **in the vocabulary of the assignments** ("Receives", "Gives its designations", "Assign"; *the designations and the observations stay, the topic that is left with nothing is deleted*).

**Assigning** (the only gesture, no merge, no split): tick designations, or **"all the designations of this topic"**; the target is **a topic by its number or its label** (a label that several topics bear is refused and says which numbers), or **a new topic** (label, kind, parent); **a preview first** (how many designations, observations and posts; nothing is deleted), then **one click**; **no assignment across kinds that exclude each other** without a box ticked; **the mentions of the posts follow at once**; the keys of a topic follow its designations when all of them go. Each form has the capability (`index_capability`) and a nonce.

**The commands** (no alias is kept: Eric): **`wp otherguise index assign`** (`--topic=<id>` with `--designation=<ids>` or `--from-topic=<id>`, a preview unless `--apply`, `--force`, and **`--with-sub-topics`** that repeats the assignment down the tree: the sub-topics of the same name give their designations to the sub-topics of the receiving topic, the others move under it, and what is left with nothing is deleted) and **`wp otherguise index suggestions`** replace `index merge` and `index duplicates` (which are gone). `index designations list|clean|migrate|measure` stay.

## Not done

- **The exception on an observation** (assign *one* post's observation to another topic, slice 625): it needs the observation to carry a topic of its own and the mentions to honour it; **not built**. The screen assigns designations only.
- **The search of the target is a number or an exact label**, not a live search over the topics: the page has no script. A search field with suggestions is a later step.
- **The "To resolve" view lists 200 designations at most**; the list of the topics and the posts of a designation are paginated.
- **Nothing was looked at in a browser**: the markup is plain WordPress administration (`widefat`, `nav-tab`), the layout was not seen.
- **The words of the other screens and of the documents of the first slices** (600 to 624) still say *merge* where they described what was done then; the design note of the module and the amendments say what replaced it.

## Tests

`IndexTopicsScreenDbTest`: the list and its filters (a stranger sees nothing); a topic and a designation; **saving** (by hand, the old label key stays, no cycle, nothing without the capability and the nonce); **assigning after a preview** (a preview changes nothing, the apply does, **the mentions follow**, a missing label is said, a new topic is made, nothing ticked is said); **all the designations of a topic** with the checks; the tabs *To resolve* and *Suggestions*. `IndexDuplicatesScreenTest` follows the new words; `IndexMergeDbTest` tests `index suggestions`; `IndexDesignationsDbTest` tests `index assign --with-sub-topics`.
