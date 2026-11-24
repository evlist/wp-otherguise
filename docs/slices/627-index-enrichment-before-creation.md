<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 627 — Index: Wikidata before a topic is made

Status: **coded on 2026-11-24** (994 PHPUnit tests, 5 of them for this slice, with a real database for the statements and doubles for the services of Wikimedia; the path "the service asks to wait" also seen on a **scratch WordPress** against the **real** Wikipedia, which answered 429 from this container; **the happy path was never run against the real services**, nor on the blog). Dependencies: 625 (the design), 626 (the designations), 604 (the enrichment of the topics). Context: [`625-index-topics-and-observations.md`](625-index-topics-and-observations.md).

## Why (Eric, 2026-11-24)

*"There is an intermediate period during which topics have been detected and are not enriched. If a person works on these topics, the quality of the database is lowered more than it is improved."* The enrichment of slice 604 was **a second pass** over topics that already existed.

## What was built

- **The question is asked before the topic is made, once per designation.** In `Indexer::index_post`, the designations of a post that carry a link to Wikipedia (`wikipedia:<lang>:<Title>`) and **no** identifier of Wikidata are looked up **in one batch** (`DesignationEnricher::look_up`: the titles, then the items, then the ancestors of the classes; 50 designations at most per round). **The answer is kept with the designation** (`state` `done`, `missing`, `ambiguous` or `none`, `qid`, `label`, `kind`, `date`, `due`): **a link repeated in fifty posts costs one question.**
- **The candidate is completed, then resolved.** `DesignationEnricher::completed` puts `wikidata:Q…` **first** among the keys (the strongest), takes the kind that the classes give when the candidate has none, and the label of Wikidata **unless it would only change the case or drop a qualifier** (the rule of slice 604: *Moulins (Allier)* stays). The resolver then **finds the topic by its identifier** (a link in another language, or a spelling that differs, goes to the same topic) **or makes it complete**.
- **No topic is made unenriched.** When Wikidata has not answered (**the service asks to wait**, or a request failed), the designation is `pending`: **the observation is kept, no topic is made, no mention is written**: the index is **incomplete for a while and never degraded**. The report of the reading says `pending: n`.
- **The queue.** `DesignationRetry` takes the `pending` designations, looks them up, resolves them **with what Wikidata said**, assigns them, and **makes the mentions of the posts that observe them (`Mentions::derive_post`): the pages are not read again.** It is the first thing that the phase *enrich* of `index sync` does (then the enrichment of the topics, which stays as a fallback), and the job counts the waiting designations in its progress.
- **What a person assigned is never asked about** and never enriched: the resolver is not asked either.
- **A "not found" is a final answer** for the reading: the topic is made as the link says it, without an identifier, and **the topic-level enrichment (slice 604) comes back to it after its delay** (7 days, ±20 %).
- **The same failures and the same pause for both** (`Enrich\Failures`: a refusal that says "wait" pauses until the date given, never less than an exponential delay, three failures stop it). **`Enrich\Lookup`** holds the questions to the services that the enrichment of the topics (`Enricher`) used to hold alone: **the tests of slice 604 are unchanged and pass.**
- **Off unless the enrichment is on** (`index_enrich`): without it, a link makes its topic as before.

## What it changes for the blog

- **A first reading is slower**: one round of requests per post that has links not yet looked up (the client waits a second between requests). A designation is looked up **once for the whole site**, so it is a one-off cost that the posts share.
- **Topics that exist already** without an identifier are completed by the topic-level enrichment as before, **or** when their post is read again (the resolver finds them by their Wikipedia key and adds the identifier).

## Not done

- **The screens** (slice 628): the pending designations are seen with `wp otherguise index designations list --state=pending`.
- **Marks** that name a Wikipedia page are enriched like links; **photographs, tags and categories are not** (they carry no Wikipedia link).
- **Never tried against the real services from here**: from this container the real Wikipedia answered **429** (the address is shared), which **exercised on a real WordPress the path "the service asks to wait"**: the designation stayed `pending`, no topic was made, the pause was set. **The happy path and the quality of the answers (labels, kinds) are known only through the doubles and the real answers of slice 604** that Eric checked on the blog.

## Tests

`IndexEnrichBeforeCreationDbTest`: the topic made complete (identifier, kind, label with its qualifier), **a second post does not ask again**; **the identifier finds the topic that exists**; **a missing page** (final, no second question before its delay); **a service that asks to wait** (no topic, no mention, the observation kept, nothing asked during the pause, then the queue resolves it and **the mention appears with no reading of the page**); **a decision of a person is not asked about**. `IndexEnrichTest` (slice 604) is unchanged.
