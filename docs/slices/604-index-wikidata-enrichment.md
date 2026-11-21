<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 604 — Index: the Wikidata enrichment (identifier, label, kind)

Status: **proposed (2026-11-21), not coded.** Dependencies: 600 (entries, resolver, aliases), 602 (the source `page` makes the entries from links to Wikipedia), 603 (kinds with a hierarchy; the built-in kinds are what the table below points to). Context: [`../design/index.md`](../design/index.md), "Identifiers: Wikidata first" and "The kind of an entry". Inspiration: the classes of **whoBIRD** (`WikidataQuery`, `FileLockThrottle`, `SparqlUtils`), adapted, not depended upon.

## Why

On the test blog, a rebuild of the section 58840 gives 964 entries of the kind `other`, almost all of them from links to Wikipedia, with labels such as *Têt (fleuve)* or *Col de la Perche (Pyrénées-Orientales)*. The entry has **no identifier** (only `wikipedia:fr:<title>`), so two languages, or a Wikipedia link and a whoBIRD mark, make two entries; and its **kind** is unknown. Wikidata gives all three: an **identifier** (`Q…`), a **clean label** in the language of the site, and the **classes** (*instance of*, P31) from which the kind is deduced.

## Decided (Eric, earlier)

Wikidata rather than Wikipedia for identifiers; the kind of an entry is **deduced automatically from "instance of", through an editable table, default `other`, corrected in bulk, and a kind set by hand is never overwritten**. Eric asked that this slice **respect the rules of good use** of the Wikimedia services.

## The rules of good use (read on 2026-11-21; see "Checked" below)

What the pages say, and what the design does about it:

| Rule | Design |
|---|---|
| **A User-Agent that identifies the client and gives a way to contact its operator**: `<client>/<version> (<contact>) <library>/<version>`; a generic or browser User-Agent is refused or blocked, "without notice". | `Otherguise-Index/<version> (<contact>) WordPress/<version>`. The **contact is an option of the site** (`index_wikidata_contact`: an email or an address), **never written in the code**; when it is empty the address of the site is used. The job **does not start** without one of them. |
| **Requests in series, not in parallel**; "no hard speed limit on reads, but be considerate". | **One request at a time**, with **at least one second between two requests** (a stored time, so that two processes agree), one job at a time. |
| **Batch**: several titles or ids per request (the page: `titles=A\|B\|C`; at most **50** values, more for clients with higher limits). | **50 titles** per request to Wikipedia, **50 items** per request to Wikidata. |
| **`maxlag`** for a job that nobody waits for; **exponential back-off** on a refusal. | `maxlag=5` on the Action API; on a `maxlag` or `ratelimited` answer, **wait** (the delay doubles, 5 minutes at most) and **put the entries back**. |
| **429 / `Retry-After`**: "stop sending further requests for a while"; a client that goes on "can be temporarily banned". | On 429 or 503 the job **stops, records the date given by `Retry-After`** and **resumes only after it**. After **three consecutive failures** it **pauses itself** with the reason (it never retries for ever). |
| **GET** when possible (cacheable); **`Accept-Encoding: gzip`**; JSON. | GET everywhere (the lists of values are short); WordPress sends `gzip` itself. |
| **Cache** what is asked again. | Everything is **kept on the entry** and in a **table of classes**; an entry is not asked again (below). |
| The query service: **60 seconds of a query at most**, a budget of processing time per client, **5 parallel queries at most**, errors counted. *(From a summary of the manual, not quoted: to read again at coding time. The page says the limits may change, and announces a new backend and new endpoints in 2026–2027.)* | Light queries only (`VALUES` of at most 50 items, no scan); one at a time; the endpoint address is **a setting with a default**, so that the change of backend does not need a release. |
| **Licence**: the structured data of Wikidata is **CC0**; attribution is not required but "greatly appreciated". | Only identifiers, labels and classes are kept (no text of Wikipedia). The documentation and the readme **say that the data come from Wikidata**. |

Also: **only the background job and the command call the services** (never a web request, never the save of a post); **only public titles and identifiers leave the site** (nothing about visitors, nothing about unpublished posts: only entries of published posts are enriched); the **identifiers are validated** (`^Q[1-9][0-9]*$`) and **are the only thing put in a query** (titles travel as encoded parameters, never inside a SPARQL text: no injection, which is the point of `SparqlUtils` in whoBIRD); TLS is verified; every request has a timeout.

## What it does

### 1. Which entries (the queue)

An approved entry that has **a `wikipedia:<lang>:<title>` key and no `wikidata:` key**, **or** a `wikidata:` key and **no label of its own** (a mark or a link that gave only `Q42`). Its state is kept on the entry (`_index_enrich`: `state`, `date`, `retry_after`, `note`): `pending` (default), `done`, `missing` (no page), `ambiguous` (a disambiguation page), `none` (a page with no item), `duplicate` (see below), `failed`. A state other than `pending` is **not asked again** before **30 days** (`done`) or **7 days** (the others), **each with a random jitter of ±20 %** (the idea of whoBIRD's cache: expirations spread so that they do not come due together).

### 2. The two lookups (`Wikidata\TitleResolver`, `Wikidata\ItemReader`)

1. **Title → item**, per language, on the Wikipedia of that language: `action=query & prop=pageprops & ppprop=wikibase_item|disambiguation & redirects=1 & titles=A|B|…` (50 titles). The answer gives, per title, the item or the fact that the page is missing or is a disambiguation page; the maps `normalized` and `redirects` bring the answer back to the title asked.
2. **Item → label and classes**, one query to the query service for 50 items: `SELECT ?item ?itemLabel ?class WHERE { VALUES ?item { wd:Q… } OPTIONAL { ?item wdt:P31 ?class } SERVICE wikibase:label { bd:serviceParam wikibase:language "<lang>,en". } }`. **Which of the two services reads the classes is to be measured** (the Action API `wbgetentities` with `props=claims` returns every claim of an item, which is heavy for a city: the query service returns only P31); the choice is made when coding, with the same interface.
3. **Class → ancestors** (`wdt:P279*`), for the classes **not yet in the table of classes**, in batches; kept for **90 days** (jitter).

### 3. What is written on the entry

- The key **`wikidata:Q…`** is added to its aliases (the key `wikipedia:…` stays: a later source finds the entry by either).
- If **another entry already holds that `wikidata:` key** the entry is **not merged and not changed**: its state is `duplicate` with the id of the other one, and it is **listed** (`wp otherguise index enrich --duplicates`). The merge of two entries (the union of their mentions and keys) is **not in this slice**.
- The **label** becomes the label of Wikidata in the language of the site (then English), **unless a person changed it** (a flag on the entry); the old label key **stays an alias**, so nothing that finds the entry today stops finding it.
- The **kind** is deduced from the classes (below) **unless a person set it** (`_index_kind_set_by_hand`, slice 600) **or the entry has a kind that is not `other`** (a source that knew better, such as a category with a kind).

### 4. The table of classes (`Wikidata\ClassTable`)

An option `index_wikidata_classes`: **Wikidata class → kind**. The entry's classes, **and their ancestors**, are looked up in it; **the most specific kind wins** (the deepest in the hierarchy of 603), and on a tie the order of the table. The default table is built from the built-in kinds and holds the classes of Wikidata that they stand for: for example *river*, *lake*, *canal*, *stream* → `waterway`; *human settlement*, *mountain*, *mountain pass*, *valley*, *country*, *administrative territorial entity* → `place`; *taxon* → `species`; *human* → `person`; *written work*, *film* → `work`; *event* → `event`; *hiking trail* → `route`; *food* → `product`. **The identifiers of these classes are not written here on purpose: they are to be checked one by one on Wikidata when the table is made** (a wrong identifier would put entries in a wrong kind, silently). `wp otherguise index kinds map <class> <kind>` adds or changes a line; `map --list` prints the table with the labels fetched once.

### 5. The job and the commands

- **`Job\PagesJob`** is reused as it is (state in the option `index_enrich_job`, a time budget per tick, stop and resume, failures with their reason); the unit of work is **a batch of 50 entries**. Cron event `otherguise_index_enrich`.
- **`wp otherguise index enrich start|run|status|stop|resume`**: `run` works in the foreground (the blog has `DISABLE_WP_CRON`); `--entry=<id>` and `--dry-run` (prints what would be written: the item, the label, the classes, the kind, **no write**, but the requests are made, which is said); `--duplicates` and `--ambiguous` list the entries that need a person.
- **The source `page` also turns the links to Wikidata** (`wikidata-link`, 601) **into candidates** (label = the identifier, until this slice gives them a label); today they are stored and ignored.
- The enrichment is **off until enabled** (`index enrich enable`), like the source `page`.

## Tests (planned)

Without the network (the HTTP request is a double returning **recorded answers**; the fixtures are **handwritten from the documented shape of the answers and marked as such**: they prove the code, not the services): the User-Agent (contact from the option, the address of the site, none: refusal), the batches of 50, the throttle, `maxlag` and `ratelimited`, 429 with `Retry-After`, three failures and the pause, the states (done, missing, ambiguous, none, duplicate), the expirations and their jitter, the kind from the classes and their ancestors (the most specific kind, a kind by hand not overwritten, a kind that is not `other` not overwritten), the label (changed, kept when set by hand, the old key kept as an alias), the identifiers validated, nothing sent for a post that is not published, the commands, the job on the entries.

## Verification (planned)

**Never against the real services from this machine.** Eric first runs `wp otherguise index enrich run --entry=<id> --dry-run` on the testing blog for one or two entries, then a batch, then the 964 entries (about 20 requests to each service, a few minutes with the one-second interval); the result is read with `kinds list` and `entries`. The queries and the shape of the answers are checked on that first real run, and the fixtures corrected.

## Checked (2026-11-21)

Pages read: the User-Agent policy of the Wikimedia Foundation (last edited 27 March 2026), `API:Etiquette` (13 May 2026), `Wikidata:Data access`, `Wikidata:Licensing`, `API:Query`, and the manual of the query service. **The manual was truncated: its figures (the budget of processing time, 30 errors a minute, 5 parallel queries, 429 and `Retry-After`) come from a summary, not from the text.** **No page gave a limit of identifiers for `wbgetentities`**: the "50 entities per request" is the general rule of the Action API. Not read: `Manual:Maxlag_parameter` (the handling of a `maxlag` answer is the usual one: wait and retry), the global rate limits of the Wikimedia APIs, `Wikidata:Bots`.

## Not done

Merging two entries; images, coordinates and other properties (P18, P625: the places are Media Helper's); other languages than the site's for the labels; an editor screen for the table of classes (the command is enough at first); using Wikipedia for the text of an entry (none is kept, by choice).
