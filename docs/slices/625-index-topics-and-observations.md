<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 625 — Index: topics, designations and observations

Status: **design validated by Eric on 2026-11-24; coded in slice [626](626-index-designations-and-observations.md) (which says where the code differs from this design).** The vocabulary and the principle are **decided by Eric on 2026-11-24**, in three steps the same day: **topics and observations**; **no merge and no split, only assignments**; **designations** (the class) **and observations** (the instance), the assignment being made on the **designation**. Eric's answers of 2026-11-24 to the questions below are recorded as **Decided**; what is still **Proposed** or **To decide** waits for him (the threshold for leaving Triples). Dependencies: 600 to 624 (the whole Index module as it stands). Slices that follow, once this one is validated: 626 (storage and migration), 627 (enrichment before creation), 628 (screens and vocabulary), 629 (purge, prune and reset in the new vocabulary). Context: [`../design/index.md`](../design/index.md).

## Why (Eric, 2026-11-24)

> *"We have not gone to the end of the design, and the vocabulary of the merge screens shows it. We should separate the topics as abstractions (the river Allier is one topic; the département Allier is another, with its own tree: it is part of a region, it has cantons…) from the observations we make of them. An observation can be made from a geolocated photograph, a Wikipedia link, a tag. They are not really topics: they are observations of a topic. And what we call merge is giving each observation the right abstract topic. It does not delete the observations, it changes their assignment. The system first guessed an abstract topic, it may have been wrong, and the screens that handle topics and observations let a person put it right."*

> *"There are only assignments, automatic or manual, and screens to manage them: no merge, no split. We also need a page to manage the topics and change their labels, unless one page manages both."*

> *"We should tell the **classes** of observations from their **instances**. The instances are what we find (a commune that comes from the geolocation of one given photograph). The class is that same commune coming from the geolocation of all the photographs used by the pages of the site. Listing every instance in the admin pages would be an endless list: it is the classes that have to be managed."*

## What is wrong today (read in the code, 2026-11-24)

1. **A topic is born from the first thing that names it.** `Resolver::resolve` creates a topic from a candidate (label, kind, keys, the chain of its parents) and **the evidence is not kept**: the only trace of what was seen is the `via` of the mention (a short hash) and the keys that were added to the topic.
2. **A merge destroys information.** `TopicMerger` moves the mentions and the keys of the topic that goes to the one that stays, then deletes it: **the system's first guess is gone**, and **a wrong merge cannot be undone**. Eric's example: *Allier* the river and *Allier* the département were one topic after a link to Wikipedia was resolved to the département; there is **no way to take back what was the river's**.
3. **Reading again is destructive.** `purge` drops the mentions of a source and `prune` deletes the topics nobody mentions: the reading and the curation are tied, and **what a person decided has nowhere to live** except as a key on a topic.
4. **Doubt is stored as fake topics.** An ambiguous candidate becomes a topic of status `candidate` with a marker key (`ambiguous:…`): **the queue of what is to decide is made of topics that are not topics.**
5. **Nothing is at the right level for a person.** A mention is *(post, topic)*: the same Wikipedia link in fifty posts is fifty things, and the same commune seen through two hundred photographs is two hundred; **what a person judges is "this link", "this commune from the photographs"**, not each occurrence.
6. **The words are wrong** in the merge and duplicates screens ("goes in it", "stays").

## Vocabulary (Decided by Eric, 2026-11-24)

| Word | Meaning |
|---|---|
| **Topic** | An **abstraction**: a concept that can be indexed (the river Allier, the département Allier, the commune Moulins). It has a **label**, a **kind**, a **place in its own tree** (parent), **identifiers** (`wikidata:Q…`, OSM) and a status. It exists **by itself**: it is edited as a topic, not deduced from what was seen. |
| **Designation** | **The way a source designates something**, the same wherever it is found: *the link to `wikipedia:fr:Allier_(rivière)`*, *the place Moulins, in Allier, in France, from the geolocation of a photograph*, *the tag "Vélo"*, *the mark "Allier"*. **It is the class**: it has **as many observations as there are places where it was found**. **It is what is assigned to a topic.** |
| **Observation** | **One occurrence of a designation in a post**: this link in that post, this photograph, the start of that track. **It is the instance.** It has a **position** in the post (so that the page of an element is known). |
| **Assignment** | The link from a **designation** to a **topic**, and so, through it, of all its observations. **Automatic** (proposed by the system, from the keys) **or manual** (decided by a person). **It is the only relation between what was seen and what is indexed**: everything that was called *merge*, *split*, *link* or *correct* is **an assignment that changes**. |
| **Exception** | The assignment of **one observation** to another topic than the one of its designation (this mark "Allier", in this post, means the river). Rare; made by hand. |
| **Entry** | A line of a printed index (unchanged). |
| *Merge*, *split* | **Words that are abandoned**: there are **assignments** and **screens to manage them**. Moving the designations of one topic to another is *assigning them again*. |
| **Mention** | In the code today, a statement `(post, topic)`. **It becomes a derived view**: *the mentions of a post* are its observations **grouped by the topic of their designation** (the builder and Books read that). The word stays for the text of a book (a mark `data-index`), not for the data. |

*Why "designation".* **Class** is already taken (Wikidata classes, CSS classes, PHP classes); **attestation** and **reference** were the other candidates.

## The model (Proposed)

```
 Topic  <──assigned──  Designation  <──observed──  Observation (in a post, at a position)
 (abstraction)         (the class)                 (the instance)
 label, kind, parent   source, key, evidence       post, position, strength, role,
 identifiers           proposal, assignment, by    visibility, via
```

### Topic

As today (`TermTopics`): label, kind (editable data, slice 603), parent, status (approved, candidate, refused), keys (identifiers), by-hand flags on kind and label, state of the enrichment. **One change of meaning**: **the hierarchy belongs to the topic** (a commune is under its département, which is under its region) and is edited there; a designation **does not carry its ancestors**. The roll-up and the context of the builder follow the parents of the topic, as now.

### Designation

| Field | What | Notes |
|---|---|---|
| **key** | What makes two observations the same designation | **Per source** (table below). It is the identity: **reading again finds the designation again instead of making another.** |
| **source** | A leaf of the registry (`post/link`, `post/mark`, `post/tag`, `post/category`, `image/address`, `trace/start`, `trace/end`, `hand`…) | As today. |
| **evidence** | What the source saw, enough to judge it: the label met, the Wikipedia key, the term id of a tag, **the chain of levels of a place** as Media Helper gave it (names, numbered levels, Wikidata identifier) | **Never a URL, a file name or a precise coordinate**: the rule of slice 606 stays (only what is derived is kept). |
| **proposal** | The topic the system chose (or none), and why (the key that matched) | Kept even when a person assigns another one: **the system's guess stays visible**. |
| **assignment** | The topic it is assigned to now, **or none** | |
| **by** | `system` or `hand` | **A `hand` assignment is never changed by a reading.** |
| **state** | `pending` (waits for the enrichment), `ambiguous` (several topics fit), `assigned` | The queue **To resolve** is the list of the designations that are not `assigned`: **no more fake topics**. |
| **counts** | The number of observations and of posts | Derived, for the screens. |

**The key of a designation, by source (Proposed):**

| Source | Key | Note |
|---|---|---|
| `post/link` | the Wikipedia (or Wikidata, or OSM) key of the target | one designation per page linked, whatever the post |
| `post/mark` | the explicit identifier when the mark gives one, else the text of the mark | **a text can mean two things**: that is what the exception is for |
| `post/tag`, `post/category` | the term | |
| `image/address`, `trace/start`, `trace/end` | **the place**: its Wikidata identifier when Media Helper gives one, else the chain of its levels | **all the photographs taken in one commune are one designation** |
| `hand` | the topic and the post | a mention made by hand |

A key **too coarse** gathers two things (the mark "Allier"): an **exception** is made on the observation that differs. A key **too fine** makes two designations of one thing (a place spelled two ways): **both are assigned to the same topic**, which does no harm.

### Observation

| Field | What |
|---|---|
| **post**, **designation** | The two ends |
| **position** | The element (`anchor`, or the number `n` of slice 612), so that the page of an observation is the page of its element. **Photographs have none yet** (see slice 623). |
| **strength, role, visible** | As today (`main`/`passing`, `index/role`, the modes) |
| **via** | The short hash of the file for a photograph or a track, as today (**one per photograph**: the number of photographs of a place in a post is the number of its observations) |
| **exception** | A topic, when a person assigned this observation elsewhere than its designation |

Identity of an observation: the post, the designation and an order number (`n`: the same link twice in a post). **Reading again updates it instead of making another.**

### Operations in this vocabulary

- **Read a post**: the sources give observations, each tied to a designation; **a designation seen for the first time is created** and **the resolver proposes an assignment** by the **same rules as today** (the first key that matches decides, the label last, a kind conflict is ambiguous), **unless a person decided**; **an observation that the page no longer shows is deleted**.
- **Assign** designations to a topic: **one**, **several** (ticked on a screen) or **all those of a topic** (*"everything now assigned to Allier the département goes to Allier the river"*). The target is an existing topic or **a new one** (label, kind, parent). The assignment is **manual** and **a new reading never changes it**. **The identifiers (keys) of a topic whose designations all go elsewhere follow them**, so that a new designation that names the old topic does not make it again.
- **Make an exception**: assign **one observation** to another topic; **hidden in the detail of a designation**, never the usual way.
- **Nothing else is needed to fix a double.** Two topics that are the same thing are fixed by **assigning the designations of one to the other**; a topic **with no designation left and nothing chosen by a person** is deleted by the cleaning (or by hand). **Its sub-topics are topics too**: they are **moved under another parent** on the topic screen, or have their own designations assigned elsewhere; a convenience **"and the sub-topics of the same name"** repeats the assignment down the tree (**to decide**).
- **A topic is managed as a topic**: its **label**, **kind** and **parent** are edited on the topic screen. A label chosen by hand is **never replaced** (by the enrichment either); **the old label key stays** on the topic, so that a designation that uses the old name still finds it.
- **Confirm** an assignment that the system proposed (it becomes manual, and stable).
- **What lasts, and what is rebuilt**: **the observations are recalculated at each reading** (they come and go with the pages); **a designation that has a manual assignment lasts even when it has no observation left** (**Decided**: kept for ever, with a **filter "no observation"** and **a button to delete** on the admin screen), so that **`index reset` and `sync --from-scratch` never lose what a person decided**; a designation that was only proposed by the system and has no observation left is deleted by the cleaning. **Purge a source** deletes the observations of the source (and its automatic designations that have none left); **prune** deletes the topics with no designation and no decision of a person; **reset** deletes the observations and the automatic designations (with `--everything`, the manual ones and the topics too).
- **Enrichment before creation** (slice 627), **once per designation, not once per occurrence**: a designation that carries a Wikipedia link is `pending` until Wikidata has answered; it is then assigned to the topic that holds `wikidata:Q…`, **which is created complete** (label, kind from the classes, parent) if it does not exist. **No topic is created unenriched.** A link repeated fifty times costs **one** query. If the service says "wait", the designation stays `pending` and the next worker goes on; the index is **incomplete for a while but never degraded**. What has no Wikipedia link (tags, categories, photographs, marks) needs no enrichment.

## Where it is stored (Proposed; Eric 2026-11-24: Triples for now, a table if the performance degrades)

The two levels have **very different sizes**, which is the point: **the designations are few** (the pages linked, the places, the tags: a few thousand, to measure), **the observations are many** (tens of thousands, the photographs especially).

- **A designation is a term of a hidden taxonomy `index_designation`**, as a topic is a term of `index_topic`: its key is its slug (a lookup by key is what a reading does most), its source, evidence, proposal, `by` and `state` are term meta. **Its assignment is a statement of Triples** `(designation, index/designates, topic)` qualified by `by`: *"the designations of a topic"* (the screen, the assignment of all of them) is then the lookup by predicate and object that Triples is built for. *(Alternative: the topic in a term meta, with a query on the meta; to compare in the first task of slice 626.)*
- **An observation is a statement of Triples** `(post, index/observes, designation)` qualified by its position, `via`, strength, role, visibility and its optional exception. *"The observations of a post"* (the builder, the books) is a lookup by subject; *"of a designation"* by predicate and object.
- **`Mentions` stays as the facade** (`of_post`, `of_topic`, `mentioned_topics`, `sync`…) **on top of both**: the mentions of a post are its observations grouped by the topic of their designation (**a map designation → topic, a few thousand entries, loaded once for a build**), so that the builder, the merge of the books and the commands that read mentions change **nothing** in the first step. The old predicate `index/mentions` is migrated, then retired.
- **An assignment that changes is one statement**, not thousands of observations rewritten: this is what keeps Triples fit for the volume.
- **To measure before choosing to stay on Triples** (first task of slice 626, on the test blog): the number of designations and of observations; reading all the observations of a section (the builder); listing the designations of a topic; the cost of one `sync` run. **The criterion to leave Triples** for tables of the module: one of these exceeds a threshold that Eric sets (**To decide**). The facade makes the change local.

## Migration (Proposed)

- **The topics stay as they are** (labels, kinds, parents, keys, by-hand flags, enrichment state, refused ones).
- **The mentions of the automatic sources cannot be converted** (they have no evidence): **a run from scratch rebuilds them** as designations and observations (`index reset` then `index sync start --from-scratch`). **The keys that a person added to a topic** (`index link`, the merges done so far) **still make the system find the topic**, so the rebuilt designations come back **assigned where they were**. **The mentions made by hand** (source `hand`) are converted one to one: a designation `hand` assigned **by hand**, and one observation.
- A command **`index migrate observations`** (`--dry-run`, can be run twice) does the conversion and says what it did. **It is a step of the rollout to production** (a document in `shared-room/docs/rollouts/`), rehearsed on staging; a backup of the database first.
- Nothing else of the module changes in this step: **kinds, the registry of sources, the selection tokens, the roll-up and the context, the integration with Books, the Wikidata class table.**

## The slices that follow (Proposed)

| Slice | What | Depends on |
|---|---|---|
| **626** | **First, the measure** on the test blog (designations, observations, lookups). Then the designation (term, key per source, upsert), the observation (statement, identity, upsert), the assignment (statement), `Mentions` as a facade, `index migrate observations`, the resolver that proposes an assignment and keeps the proposal. | 625 validated |
| **627** | **Enrichment before creation**, per designation: the `pending` state, the batch with a cache of the Wikipedia key → Wikidata item, the phase "enrich" of `index sync` becomes the retry of the pending designations. | 626 |
| **628** | **One screen to manage the topics and their assignments** (below), the queue **To resolve** and the suggestions as views of it; the words of the interface, the commands and the documents are changed (`index merge` and `index duplicates` give way to `index assign` and `index suggestions`, `index designations --topic=<id>` comes; **no alias is kept**: Eric, 2026-11-24). | 626 |
| **629** | `purge`, `prune`, `reset` and `sync --from-scratch` in the new vocabulary; **the topics of status `candidate` that stood for doubts are gone** (coded: [629](629-index-doubts-and-cleaning.md)). | 626, 628 |

## The screen (Proposed): topics and assignments on one page

*Eric, 2026-11-24: "a page to manage the topics and change their labels, unless one page manages both the topics and the assignments."* **Proposed: one screen, Otherguise → Topics**, because **one gesture needs both** (rename a topic, then move what was wrongly attached to it). It replaces the screen of the duplicates (slice 618) and the commands that merge; **the screens of the kinds (603) and of the terms (600) stay** (they are about other things: the kinds and the tags and categories of WordPress). The screen of the state of the synchronisation (622) stays as it is, **Otherguise → Index**. **The screens list designations, never observations** (Eric: *"it is the classes that have to be managed"*).

- **Three views** (tabs): **Topics** (the list), **To resolve** (the designations that are `pending` or `ambiguous`, which a person must assign) and **Suggestions** (the pairs of topics that look alike, today's duplicates: each offers *"assign the designations of one to the other"*, with a preview and one click, as the safe merges do now).
- **The list of the topics**: a search, filters (kind, status, **with no designation**, **made by hand**), the tree (a topic shows its parent), and the counts (designations, observations, sub-topics). Sorted by label.
- **A topic** (click): **its label, its kind and its parent are editable in place** (a label or kind changed is *by hand*, protected from the enrichment); its **identifiers** are shown (`wikidata:Q…`); then **two lists**: its **designations** (source, evidence in a few words, **number of observations and of posts**, **automatic or manual**, state) and its **sub-topics**.
- **A designation** (click, in the detail): **the posts where it is found** (a paginated list of its observations, with the link to each), and **"assign this observation elsewhere"** (the exception).
- **Assigning**: tick designations (or **"all of this topic"**), **"Assign to…"**: a search over the topics (with their parent, to tell *Moulins (Allier)* from the river *Moulins*), or **"a new topic…"**; a **preview** of what changes (how many designations, observations and posts), then **one click**. **Nothing is deleted**: the designations and the observations stay, only the assignment changes, and **the previous assignment is shown** (so that a mistake is put right the same way).
- **Safe by default**: a preview before any change (a dry run), a capability and a nonce on each form, and **no assignment between topics of kinds that exclude each other** without an explicit confirmation (the rule of `Kinds::compatible`, as now).
- **Every list is paginated by the server** (50 lines), never loaded whole.

## Decided by Eric (2026-11-24) and still to decide

**Decided:**

1. **Designations with a manual assignment and no observation left** are **kept**, and the admin screen can **filter** them and **delete** them.
2. **The key of a designation, source by source** (table above) is right: **all the photographs of one commune are one designation**, and **a mark with no identifier is designated by its text**.
3. **New predicates** `index/observes` and `index/designates`, with `Mentions` as a facade.
4. **A topic that has lost all its designations** leaves **its keys only** on the topic that received them: no redirect.
5. **One screen** (**Otherguise → Topics**) **with three views**.
6. **"And the sub-topics of the same name"** is offered when all the designations of a topic are assigned elsewhere (not ticked by default, with a preview).
7. **The commands**: `index assign` and `index suggestions` replace `index merge` and `index duplicates`; **no alias is kept**.

**To decide: the threshold for leaving Triples** (question 4), measured on **the operations that a person waits for** (Proposed):

| Operation | Why it matters | Proposed threshold |
|---|---|---|
| **A page of the screen** (the list of the topics, a topic with its designations, the queue To resolve): 50 lines with their counts | A person waits for it | **1 second** |
| **Assigning all the designations of a topic** to another (the preview, then the change) | A person waits for it | **2 seconds** |
| **Building the index of the largest section** (`index build --section=<id>`), the heaviest reading | It runs inside the page of a book and the PDF job | **no more than twice the time of today** (to measure first: it is the reference), and **10 seconds at the most** |
| **Reading one post** (its observations are made again, with 100 photographs) | It runs in the background for hundreds of posts, so **it does not decide alone**: it counts only above **5 seconds** | **5 seconds** |

**What leaving Triples means**: the designations and the observations go to **tables of the module** (`otherguise_index_designations`, `otherguise_index_observations`) behind the same facade, with their own indexes, a migration and an uninstall; **the screens and the builder do not change.** If **only one operation** exceeds its threshold (the builder, say), **only the observations** go to a table. Eric sets the thresholds; the measure on the test blog (first task of slice 626) tells which of them are met.

## Not tried

Nothing here is coded. The numbers of designations and of observations, the cost of the lookups in Triples and the time of a run are **estimates to measure** (the first task of slice 626 is a measure on the test blog, before the model is built on it).
