<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 629 — Index: the doubts are designations, and the cleaning in the new vocabulary

Status: **coded on 2026-11-24** (1004 PHPUnit tests, 3 of them new, with a real database for the statements; the placeholders and the cleaning of the designations also tried on a **scratch WordPress** with the real stores; **never on the blog**). Dependencies: 625 to 628. Context: [`625-index-topics-and-observations.md`](625-index-topics-and-observations.md).

## What changed

- **An ambiguous candidate no longer makes a topic.** The resolver used to leave a **placeholder** (a topic of status `candidate` with a key `ambiguous:…`) so that something stood for the doubt. **The designation holds it now** (state `ambiguous`, no assignment, no mention, its observation kept): the queue *To resolve* of the screen of the topics is made of designations (slice 628). `Resolver::resolve()` takes a flag `$record`; the reading and the queue of the enrichment pass `false` when the designations are on. **Without the model of slice 625** (the older tests, a site that has not read its pages again) the placeholder is still made.
- **The placeholders that exist are taken away by the cleaning.** `TopicPruner` treats a topic of status `candidate` with a key `ambiguous:…` as **a doubt, not a topic**: `index prune`, `index reset` and the cleaning phase of the synchronisation delete it (it is never mentioned). **A candidate that waits for a decision, and a refused topic, stay.**
- **The cleaning phase of `index sync` also deletes the designations that the system proposed and that no post observes any more** (`Mentions::clean_designations()`): after a run, **what a post no longer shows leaves no trace**, and **what a person assigned stays**. The phase is still skipped when a post could not be read (nothing is deleted then).
- **`purge` and `sync --from-scratch` do not delete the designations first**: they drop the observations and the mentions, and the reading that follows **finds the designations again by their key, with what Wikidata said about them** (no second question to the services). The designations that nothing observes after that are the ones the cleaning phase deletes. (`index reset`, the complete purge of a test site, deletes them at once, as slice 626 said.)
- **The words**: `index purge` says that it drops the observations and the mentions and that the designations stay; the documents of the synchronisation, the purge and the reset say *observation* and *designation* where they said *mention*.

## What `index link` does now

`wp otherguise index link <key> <topic>` still adds the key to the topic (the sources find it there). The designation that carried the doubt is **resolved by the next reading** (the resolver finds the topic by the key), or **at once on the screen** (the view *To resolve*). The code that deletes the placeholder of the key stays for the sites that have some.

## Not done

- **A command that deletes the placeholders by itself** (outside the cleaning of the topics): `index prune` does it.
- **The old documents** (slices 600 to 624) still describe the queue of the ambiguous topics as made of candidates: their amendments and the design note say what replaced it.
- **Not tried on the blog**: the amount of placeholders that it holds is not known (the first `index sync` after the update takes them away).

## Tests

`IndexDesignationsDbTest`: **a doubt is a designation, not a topic** (no placeholder, state `ambiguous`, no mention, the observation kept); **the cleaning of the designations** (a count only, what is observed stays, what a person assigned stays). `IndexPruneDbTest`: **the placeholders go**, a waiting candidate and a refused topic stay. The earlier tests of the resolver, the pruner and the commands pass unchanged.
