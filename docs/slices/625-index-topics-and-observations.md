<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 625 — Index: topics (abstractions) and observations

Status: **design only, nothing coded.** The vocabulary and the principle are **decided by Eric on 2026-11-24** (revised the same day: **no merge and no split, only assignments**); every other point is marked **Proposed** or **To decide** and waits for Eric. Dependencies: 600 to 624 (the whole Index module as it stands). Slices that follow, once this one is validated: 626 (storage and migration), 627 (enrichment before creation), 628 (screens and vocabulary), 629 (purge, prune and reset in the new vocabulary). Context: [`../design/index.md`](../design/index.md).

## Why (Eric, 2026-11-24)

> *"We have not gone to the end of the design, and the vocabulary of the merge screens shows it. We should separate the topics as abstractions (the river Allier is one topic; the département Allier is another, with its own tree: it is part of a region, it has cantons…) from the observations we make of them. An observation can be made from a geolocated photograph, a Wikipedia link, a tag. They are not really topics: they are observations of a topic. And what we call merge is giving each observation the right abstract topic. It does not delete the observations, it changes their assignment. The system first guessed an abstract topic, it may have been wrong, and the screens that handle topics and observations let a person put it right."*

## What is wrong today (read in the code, 2026-11-24)

1. **A topic is born from the first thing that names it.** `Resolver::resolve` creates a topic from a candidate (label, kind, keys, the chain of its parents) and **the evidence is not kept**: the only trace of what was seen is the `via` of the mention (a short hash) and the keys that were added to the topic.
2. **A merge destroys information.** `TopicMerger` moves the mentions and the keys of the topic that goes to the one that stays, then deletes it: **the system's first guess is gone**, and **a wrong merge cannot be undone**. Eric's example: *Allier* the river and *Allier* the département were one topic after a link to Wikipedia was resolved to the département; there is **no way to take back the observations that were the river's**.
3. **Reading again is destructive.** `purge` drops the mentions of a source and `prune` deletes the topics nobody mentions: the reading and the curation are tied, and what a person decided about *an observation* has nowhere to live except as a key on a topic.
4. **Doubt is stored as fake topics.** An ambiguous candidate becomes a topic of status `candidate` with a marker key (`ambiguous:…`): **the queue of what is to decide is made of topics that are not topics.**
5. **The words are wrong** in the merge and duplicates screens ("goes in it", "stays"): they handle two topics where the user thinks of **things that were seen** and **the concept they are attached to**.

## Vocabulary (Decided by Eric, 2026-11-24)

| Word | Meaning |
|---|---|
| **Topic** | An **abstraction**: a concept that can be indexed (the river Allier, the département Allier, the commune Moulins). It has a **label**, a **kind**, a **place in its own tree** (parent), **identifiers** (`wikidata:Q…`, OSM, and the keys of the evidence that was assigned to it) and a status. It exists **by itself**: it is edited as a topic, not deduced from what was seen. |
| **Observation** | **Something seen** in a post by a source: a link to Wikipedia, a tag, a category, a mark `data-index`, a geolocated photograph, the start of a track. It has a **source**, a **position** in the post, **evidence**, and **the topic it is attached to** (its **assignment**). |
| **Assignment** | The link from an observation to a topic. **Automatic** (proposed by the system, from the keys) **or manual** (decided by a person). **It is the only relation between what was seen and what is indexed**: everything that was called *merge*, *split*, *link* or *correct* is **an assignment that changes**. |
| **Entry** | A line of a printed index (unchanged). |
| *Merge*, *split* | **Words that are abandoned** (Decided by Eric, 2026-11-24): there are **assignments** and **screens to manage them**. Moving the observations of one topic to another is *assigning them again*. |
| **Mention** | In the code today, a statement `(post, topic)`. **It becomes a derived view**: *the mentions of a post* are its observations **grouped by topic** (the builder and Books read that). The word stays for the text of a book (a mark `data-index`), not for the data. |

## The model (Proposed)

### Topic

As today (`TermTopics`): label, kind (editable data, slice 603), parent, status (approved, candidate, refused), keys (identifiers and equivalences), by-hand flags on kind and label, state of the enrichment. **One change of meaning**: **the hierarchy belongs to the topic** (a commune is under its département, which is under its region) and is edited there; an observation **does not carry its ancestors**. The roll-up and the context of the builder follow the parents of the topic, as now.

### Observation

| Field | What | Notes |
|---|---|---|
| **identity** | A stable key: `sha1( source leaf, evidence key, n )` for one post | **Reading again updates the observation instead of creating another**: that is what makes `sync` idempotent and a decision durable. `n` tells two occurrences of the same evidence apart (the same link twice in a post). |
| **post** | The post | |
| **source** | A path of the registry (`post/link`, `post/mark`, `post/tag`, `post/category`, `image/address`, `trace/start`, `trace/end`, `hand`…) | As today. |
| **position** | The element (`anchor`, or the number `n` of slice 612) | So that the page of an observation is the page of its element. **Photographs have none yet** (see slice 623). |
| **evidence** | What the source saw: the label met, the Wikipedia key (`wikipedia:fr:Allier_(rivière)`), the term id of a tag, **the chain of levels of a place** as Media Helper gave it (names, numbered levels, Wikidata identifier) and the short hash of a file | **Never a URL or a precise coordinate**: the rule of slice 606 stays (only what is derived is kept). |
| **proposal** | The topic the system chose (or none), and why (the key that matched) | Kept even when a person reassigns: **the system's guess stays visible**. |
| **assignment** | The topic it is attached to now, **or none** | |
| **by** | `system` or `hand` | A `hand` assignment is never changed by a new reading. |
| **state** | `pending` (waits for the enrichment), `ambiguous` (several topics fit), `assigned` | The queue **To resolve** is the list of the observations that are not `assigned`: **no more fake topics**. |
| **strength, role, visible** | As today (`main`/`passing`, `index/role`, the modes) | |

### Operations in this vocabulary

- **Read a post**: the sources give observations; each is **upserted** by its identity; the resolver proposes an assignment by the **same rules as today** (the first key that matches decides, the label last, a kind conflict is ambiguous), **unless a person decided**; an observation that the page no longer shows is **deleted**.
- **Assign** observations to a topic, **one**, **several** (the ones that are ticked on a screen) or **all those of a topic** (*"every observation now attached to Allier the département goes to Allier the river"*). The target is an existing topic, or **a new one** (label, kind, parent). The assignment is **manual**: a new reading never changes it. **"Remember"** (ticked by default) **moves the keys of the evidence** (the Wikipedia key, the label key under its parent, the id of the tag…) **to the target topic**, so that **the next reading assigns the same evidence there by itself**; unticked, only these observations change (**an exception for this occurrence**).
- **Nothing else is needed to fix a double.** Two topics that are the same thing are fixed by **assigning the observations of one to the other**; a topic **with no observation left and nothing chosen by a person** is deleted by the cleaning (or by hand). **Its sub-topics are topics too**: they are **moved under another parent** on the topic screen (below), or have their own observations assigned elsewhere. A convenience **"and the sub-topics of the same name"** repeats the assignment down the tree (**to decide**).
- **A topic is managed as a topic**: its **label**, **kind** and **parent** are edited on the topic screen. A label chosen by hand is **never replaced** (by the enrichment either); **the old label key stays** on the topic, so that what was seen under the old name still comes to it.
- **Confirm** an assignment that the system proposed (it becomes manual, and stable).
- **Purge a source** = delete the observations of that source; **prune** = delete the topics with no observation and no decision of a person; **reset** = delete the observations (and with `--everything` the topics too).
- **Enrichment before creation** (slice 627): an observation that carries a Wikipedia link is `pending` until Wikidata has answered; it is then assigned to the topic that holds `wikidata:Q…`, **which is created complete** (label, kind from the classes, parent) if it does not exist. **No topic is created unenriched.** If the service says "wait", the observation stays `pending` and the next worker goes on; the index is **incomplete for a while but never degraded**. What has no Wikipedia link (tags, categories, photographs, marks) needs no enrichment.

## Where it is stored (Proposed; Eric 2026-11-24: Triples for now, a table if the performance degrades)

- **An observation is a statement of Triples**: subject the post, predicate **`index/observes`**, **object the topic**, and **a reserved term `unassigned`** while it has none. The other fields are qualifiers (identity, source, evidence as JSON, by, state, strength, role, anchor, visible). **Why the topic is the object**: the new hot path is *"the observations of a topic"* (the merge, the split and the topic screen), which is exactly the lookup by predicate and object that Triples is built for, as `Mentions::of_topic` is today.
- **`Mentions` stays as the facade** (`of_post`, `of_topic`, `mentioned_topics`, `sync`…) **on top of the observations**, so that the builder, the merge of the books and the commands that read mentions change **nothing** in the first step. The old predicate `index/mentions` is migrated, then retired.
- **Volume** (an estimate, to be measured on the test blog): a blog of some 600 posts with tens of photographs each gives **tens of thousands of observations**, against a few thousand mentions today. **To measure before choosing to stay on Triples**: reading all the observations of a section (the builder), listing those of one topic, and the cost of one `sync` run. **The criterion to leave Triples** for a table of the module: one of these exceeds a threshold that Eric sets (**To decide**: a second for a topic screen?). The `Mentions` facade makes the change local.

## Migration (Proposed)

- **The topics stay as they are** (labels, kinds, parents, keys, by-hand flags, enrichment state, refused ones).
- **The mentions of the automatic sources cannot be converted** (they have no evidence): **a run from scratch rebuilds them** as observations (`index reset` then `index sync start --from-scratch`). **The mentions made by hand** (source `hand`) are converted one to one, with the evidence "by hand" and `by = hand`.
- A command **`index migrate observations`** (`--dry-run`, can be run twice) does the conversion and says what it did. **It is a step of the rollout to production** (a document in `shared-room/docs/rollouts/`), rehearsed on staging; a backup of the database first.
- Nothing else of the module changes in this step: **kinds, the registry of sources, the selection tokens, the roll-up and the context, the integration with Books, the Wikidata class table.**

## The slices that follow (Proposed)

| Slice | What | Depends on |
|---|---|---|
| **626** | The observation (statement, identity, upsert), `Mentions` as a facade over it, `index migrate observations`, the resolver proposes an assignment and keeps the proposal. | 625 validated |
| **627** | **Enrichment before creation**: the `pending` state, the batch by post with a cache of the Wikipedia key → Wikidata item, the phase "enrich" of `index sync` becomes the retry of the pending observations. | 626 |
| **628** | **One screen to manage the topics and their assignments** (see below), the queue **To resolve** and the suggestions as views of it; the words of the interface, the commands and the documents are changed (`index merge` and `index duplicates` give way to `index assign` and `index suggestions`, `index observations --topic=<id>` comes; the old names stay as aliases for a time). | 626 |
| **629** | `purge`, `prune`, `reset` and `sync --from-scratch` in the new vocabulary; **the topics of status `candidate` that stood for doubts are gone**. | 626, 628 |

## The screen (Proposed): topics and assignments on one page

*Eric, 2026-11-24: "a page to manage the topics and change their labels, unless one page manages both the topics and the assignments."* **Proposed: one screen, Otherguise → Topics**, because **one gesture needs both** (rename a topic, then move what was wrongly attached to it). It replaces the screens of the duplicates (slice 618) and the commands that merge; **the screens of the kinds (603) and of the terms (600) stay** (they are about other things: the kinds and the tags and categories of WordPress). The screen of the state of the synchronisation (622) stays as it is, **Otherguise → Index**.

- **Three views** (tabs): **Topics** (the list), **To resolve** (the observations that are `pending` or `ambiguous`, which a person must assign) and **Suggestions** (the pairs of topics that look alike, today's duplicates: each offers *"assign the observations of one to the other"*, with a preview and one click, as the safe merges do now).
- **The list of the topics**: a search, filters (kind, status, **with no observation**, **made by hand**), the tree (a topic shows its parent), and the counts (observations, sub-topics). Sorted by label.
- **A topic** (click): **its label, its kind and its parent are editable in place** (a label or kind changed is *by hand*, protected from the enrichment); its **identifiers** are shown (`wikidata:Q…`); then **two lists**: its **observations** (post, source, evidence, **automatic or manual**, state) and its **sub-topics**.
- **Assigning**: tick observations (or **"all of this topic"**), **"Assign to…"**: a search over the topics (with their parent, to tell *Moulins (Allier)* from *Moulins* the rivière), or **"a new topic…"**; **"Remember"** ticked; a **preview** of what changes, then **one click**. **Nothing is deleted**: the observations stay, only their assignment changes, and **the previous assignment is shown** on the observation (so that a mistake is put right the same way).
- **Safe by default**: a preview before any change (a dry run), a capability and a nonce on each form, and **no assignment between topics of kinds that exclude each other** without an explicit confirmation (the rule of `Kinds::compatible`, as now).
- **A long list** is paginated by the server (50 lines), never loaded whole; the tens of thousands of observations are only listed **by topic** or **by state**.

## To decide (Eric)

1. **Does an observation outlive its evidence?** *Proposed*: **no**: when the page no longer shows the link, the observation is deleted, **even if a person reassigned it**; what lasts is **the key moved to the topic** by the decision (the default of "reassign"). A person who wants to keep one occurrence sets it **by hand** on the post (a `hand` observation). Is that right?
2. **Reassigning one observation: by default for this occurrence only, or for every observation with the same evidence?** *Proposed*: **for all of them** (the key moves), with **"only this one"** as a choice.
3. **Predicate**: a new `index/observes` with `Mentions` as a facade (proposed), **or** keeping `index/mentions` and changing its meaning? *Proposed*: **new**, to avoid two meanings in one database.
4. **The threshold** that makes us leave Triples for a table of the module (above).
5. **A topic that has lost all its observations** leaves nothing but its keys on the topic that received them. Is it enough, or should a **redirect** (a topic that points to another, kept for a while) exist, for a link or a note that names the old id? *Proposed*: **keys only**.
6. **One screen or two** (above)? *Proposed*: **one**, with three views.
7. **"And the sub-topics of the same name"** when all the observations of a topic are assigned elsewhere: offered, or left to be done one topic at a time? *Proposed*: **offered**, ticked off by default, with the preview.
8. **The commands**: `index assign` and `index suggestions` with `index merge` and `index duplicates` kept as aliases for a time (they print that the name is old)? *Proposed*: **yes**.

## Not tried

Nothing here is coded. The volume of observations, the cost of the lookups in Triples and the time of a run are **estimates to measure** (the first task of slice 626 is a measure on the test blog, before the model is built on it).
