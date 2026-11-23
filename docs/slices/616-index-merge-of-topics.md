<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 616 — Index: the merge of topics

Status: **coded on 2026-11-23**. Dependencies: 600 (the resolver, the mentions), 615 (the kinds). Context: [`../design/index.md`](../design/index.md), "Linking is adding a key to the aliases of the topic".

## Why (Eric, 2026-11-23)

Doubles appear that nothing joins: *Aragon* (a place, made from a photograph) and *Aragon (communauté autonome)* (made from a link to Wikipedia), *Iturtzeta* and *Iturzaeta* (two spellings of one place). The resolver joins two candidates only through a **key** that they share (a Wikidata identifier, or the same label **under the same parent**); two different labels never meet, and Media Helper gives the Wikidata item of the place found only, not of the levels above it. **A person must be able to say "these two are one".**

## Decided (Eric, 2026-11-23)

Code the merge. (Wikidata may later *propose* a pair: the resolver already joins topics that share a `wikidata:` key.)

## As coded

- **`wp otherguise index merge <keep> <go>... [--dry-run] [--force]`**. The first topic stays; each next one goes away after giving the first:
  - **its mentions** (`Mentions::move_topic`): the post that mentioned the one that goes now mentions the one that stays, with its **sources, `via`, visibility, strength, role and anchor**. A post that mentioned both has **one** mention with the union of what they said, **the strongest strength**, and **a source that showed a mention in every mode keeps showing it in every mode** (a union of modes with "every" is "every"). The mention that went is deleted with its qualifiers;
  - **its keys** (so that a source that names the one that went away finds the one that stayed: **the resolver makes no new double**), and **the key of its label under the parent of the one that stays**;
  - **its children** (`Topics::set_parent`): they go under the topic that stays, **with the key that a source makes for them there** (`label:<keep>/<label>`); **two children with the same label are merged in turn**;
  - **its kind**, when the topic that stays is **not classified** (`any`) **or less precise** and **no person chose its kind**; two topics whose kinds are not one a sort of the other are **refused** unless `--force`; a topic that stays **below** the one that goes is refused.
- **`--dry-run`** says what would be done and changes nothing; **run twice** it says that a topic does not exist any more.
- **`wp otherguise index duplicates [--limit=<n>]`** lists, **for a person to look at and nothing merged**, the pairs of approved topics with **the same label once what is in parentheses is left out**, or **labels a letter or two apart under one parent** (seven letters or more). **Not offered**: **homonyms under two different parents** (*Beaufort* in the Savoie and in the Jura), kinds that cannot be one (*Zaragoza* the province and the commune), a topic and its own child, the candidates.

## Not done

- **Undoing a merge** (the topic that went is deleted; its keys live on in the topic that stays, which is what keeps the doubles from coming back). Back up the database before a merge of many topics, and use `--dry-run`.
- **An administration screen** for the merge; **proposals from Wikidata**; the **posts' mentions that a source made again** after a merge are those of the topic that stays (the resolver finds it by the keys it took).

## Tests

`IndexMergeDbTest` (real statements, topics in memory): the mentions, keys, kind and children follow; a dry run changes nothing and the table loses rows after a merge (no orphan qualifier); the visibility rule; two children merged and the resolver finds the moved child; what is refused; the duplicates offered and not offered; the commands. On a scratch WordPress with the real storage of the topics (terms): the mention, the child, the alias key and the kind follow. **Not tried on the blog.**
