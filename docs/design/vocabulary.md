<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Vocabulary of the Index module

The words of the module, in one place. **Decided by Eric on 2026-11-25** (the words and what they mean); **the renaming of the screens, the commands and the documents is not done** (see the end). It replaces the vocabulary of [slice 625](../slices/625-index-topics-and-observations.md), in which what is now called a **motif** was called a *designation*.

> *This module associates topics with posts by observing patterns in the posts.* (Eric)

## The words

| Word | What it is | Example |
|---|---|---|
| **Post** | A post **or a page** of WordPress (the word of WordPress). | the article *Via Francigena, stage 3* |
| **Topic** | **The concept** that is indexed: an abstraction that exists by itself. It has a **label**, a **kind**, **identifiers** (Wikidata, Wikipedia, OpenStreetMap…) and, when its kind needs one, **a place in a hierarchy** (a commune is under its département). | *EuroVelo 9 – Baltic-Adriatic*, kind Route, `wikidata:Q3060539` |
| **Motif** | **What is looked for**: the way a source designates something, **the same wherever it is found**. It has a **source** (`post/tag`, `post/link`, `image/address`, `trace/start`…) and a **value whose structure depends on the source**. It is **what is assigned to a topic**. | the tag "eurovelo 9" (`post/tag\|term:post_tag:18`); the link to the page `wikipedia:fr:EuroVelo 9`; the chain *France > Provence > Vaucluse > Apt* given by the geolocation of a photograph |
| **Observation** | **The detection of a motif in a post**: one occurrence. A motif has as many observations as there are posts where it was found (one per post and motif, the places in the post being kept as `via`). | "the tag *eurovelo 9* is on the post 123" (61 posts: 61 observations) |
| **Assignment** | **The association of a topic with a motif.** **A topic may have several motifs, a motif has only one topic.** It is **automatic** (the system proposes it from the keys) **or by hand** (a person decides; a new reading never changes it). | the tag *eurovelo 9* → the topic *EuroVelo 9 – Baltic-Adriatic*, by hand |
| **Kind** | What **sort of thing** a topic is (a waterway, a commune, a route, a person, a species…). It is editable data, with a hierarchy (a commune is a third-level division, which is a place). **The word stays *kind*, not *type***: *type* is a word of WordPress (post type, taxonomy type). The root kind is called **Unspecified**: it is a topic whose kind is not known yet (not the kind *Other*). | Route, Commune, Waterway |

### The structure of the values

- **A motif**: **the structure of its value depends on its source** (a tag has the number of a term; an address, a chain of names of places; a link, a Wikipedia or Wikidata key). This is how it is stored (`key` and `evidence`).
- **A topic**: a **label**, a **kind**, **identifiers** and a **parent**. **Typically** the identifiers depend on the kind (OpenStreetMap for a place, a taxon for a species) and **the kind says whether there is a hierarchy** (the levels of a place); **today the identifiers are free and the kind does not constrain them**: it is an aim, not a rule of the code.

## What has no new name

- **Mention**: in the code, the statement *(post, topic)*. It is **derived**: the mentions of a post are its observations **grouped by the topic of their motif**. The word stays for the text of a book (a mark `data-index`), not for the data.
- **Entry**: **a line of a printed or displayed index**.
- **Merge, split**: **abandoned words**: there are **assignments** and screens to manage them.
- **Exception**: the assignment of **one observation** to another topic than the one of its motif. Rare, by hand, **not built**.
- **Signal** (code only): what a source **reads** in a post before it becomes an observation (`Source\Signal`). **A technical word of the code, not of the screens**: the word of the screens is *observation*.

## Not to be mistaken: a *term*

**A term of WordPress** is a tag or a category of the blog. It exists without this plugin. **A term is not a motif**: a **tag with a kind** (given on the screen *Terms*) is read by the source `post/tag` and becomes a **motif**; a tag with no kind is **not read at all** (see below).

Technically, **the topics and the motifs are themselves stored as terms of hidden taxonomies** (`index_topic`, `index_designation`): in the code, *term* can mean that. For a person only the first sense counts.

```
a tag of WordPress "eurovelo 9"      (it has a kind, otherwise nothing is read)
  → motif       post/tag|term:post_tag:18
      → observations : one per post that has this tag (61)
      → assignment   : to the topic EuroVelo 9 – Baltic-Adriatic
            → topic  : what the index lists, with its kind and its Wikidata identifier
```

## Where to see them

| Screen | What it shows |
|---|---|
| Otherguise → **Terms** | the tags and the categories, to give them a kind |
| Otherguise → **Assignments** | **one line per motif**, with its topic, who assigned it, its state and the number of posts that observe it (its observations); **Change topic** |
| Otherguise → **Topics** | the topics, their kind, their motifs |

## Open (not decided)

- **The tags and the categories that have no kind** are not read today. **Proposed** (Eric, 2026-11-25, not decided): read all of them as motifs, **a topic of kind Unspecified** for each, **created as a candidate** (outside the index until a person approves it, so that an editorial category does not get into it), and **exclude** a motif by **assigning it by hand to a reserved topic "None"** (a recorded decision that a new reading does not undo, which "no topic" would not be). Not built.
- **A topic read from a tag has no Wikipedia or Wikidata key**, so it would never be classified by the enrichment: classifying it from its label alone (homonyms) is **not decided**.

## What is not done: the renaming

**The code, the commands and the screens still say *designation*** (`index designations`, the hidden taxonomy `index_designation`, the classes `Designation\…`, the documents of slices 625 to 633). **Planned, in this order**: (1) the screens, the commands and the documents say *motif*; the names of the storage (the taxonomy, the meta) **may stay** (a migration of the stored data would cost and bring nothing to a user); no alias for the old commands (Eric, 2026-11-24); (2) the tags and the categories with no kind. **Until then, *designation* in a screen, a command or a document means *motif*.**
