<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 625 — Index: topics (abstractions) and observations

Status: **design only, nothing coded.** The vocabulary and the principle are **decided by Eric on 2026-11-24**; every other point is marked **Proposed** or **To decide** and waits for Eric. Dependencies: 600 to 624 (the whole Index module as it stands). Slices that follow, once this one is validated: 626 (storage and migration), 627 (enrichment before creation), 628 (screens and vocabulary), 629 (purge, prune and reset in the new vocabulary). Context: [`../design/index.md`](../design/index.md).

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
| **Topic** | An **abstraction**: a concept that can be indexed (the river Allier, the département Allier, the commune Moulins). It has a **label**, a **kind**, a **place in its own tree** (parent), **identifiers** (`wikidata:Q…`, OSM, and the keys of what was merged into it) and a status. It exists **by itself**: it is edited as a topic, not deduced from what was seen. |
| **Observation** | **Something seen** in a post by a source: a link to Wikipedia, a tag, a category, a mark `data-index`, a geolocated photograph, the start of a track. It has a **source**, a **position** in the post, **evidence**, and **the topic it is attached to** (its **assignment**). |
| **Assignment** | The link from an observation to a topic: **proposed by the system**, **or decided by a person**. |
| **Merge** | **Reassigning the observations of a topic to another one.** The observations stay. |
| **Split** | **Reassigning some observations** of a topic to another topic (new or existing). |
| **Entry** | A line of a printed index (unchanged). |
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
- **Merge A into B**: the observations of A are reassigned to B, **the keys of A are added to B** (so that what said "A" yesterday says "B" tomorrow), the children of A move under B (or merge with their namesakes, as now), and A is deleted **when nothing is attached to it and no person chose anything about it**.
- **Split A**: a person picks observations of A (by source, by post, by evidence key: *"the Wikipedia links to `Allier_(rivière)`"*) and assigns them to a topic B (existing, or new with a kind and a parent). **The key of the evidence moves from A to B**, so the next reading follows the decision. An **"only this one"** choice reassigns the single observation and leaves the keys alone.
- **Reassign / confirm** one observation.
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
| **628** | **Screens and words**: a topic screen with its observations (post, source, evidence, who assigned), reassign, confirm, **split**, merge; the queue **To resolve** made of observations; the duplicates screen becomes **suggested merges**; the words of the interface, the commands and the documents (`index merge` stays, `index observations --topic=<id>` and `index reassign` come). | 626 |
| **629** | `purge`, `prune`, `reset` and `sync --from-scratch` in the new vocabulary; **the topics of status `candidate` that stood for doubts are gone**. | 626, 628 |

## To decide (Eric)

1. **Does an observation outlive its evidence?** *Proposed*: **no**: when the page no longer shows the link, the observation is deleted, **even if a person reassigned it**; what lasts is **the key moved to the topic** by the decision (the default of "reassign"). A person who wants to keep one occurrence sets it **by hand** on the post (a `hand` observation). Is that right?
2. **Reassigning one observation: by default for this occurrence only, or for every observation with the same evidence?** *Proposed*: **for all of them** (the key moves), with **"only this one"** as a choice.
3. **Predicate**: a new `index/observes` with `Mentions` as a facade (proposed), **or** keeping `index/mentions` and changing its meaning? *Proposed*: **new**, to avoid two meanings in one database.
4. **The threshold** that makes us leave Triples for a table of the module (above).
5. **A topic that is merged away leaves nothing but its keys on the one that stays.** Is it enough, or should a **redirect** (a topic that points to another, kept for a while) exist, for a link or a note that names the old id? *Proposed*: **keys only**.

## Not tried

Nothing here is coded. The volume of observations, the cost of the lookups in Triples and the time of a run are **estimates to measure** (the first task of slice 626 is a measure on the test blog, before the model is built on it).
