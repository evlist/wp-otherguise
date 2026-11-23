<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 604 — Index: the Wikidata enrichment (identifier, label, kind)

Status: **coded (2026-11-21), on `main`; never run against the real services; see "What was built, and what differs from the plan" at the end.** Dependencies: 600 (entries, resolver, aliases), 602 (the source `page` makes the entries from links to Wikipedia), 603 (kinds with a hierarchy; the built-in kinds are what the table below points to). Context: [`../design/index.md`](../design/index.md), "Identifiers: Wikidata first" and "The kind of an entry". Inspiration: the classes of **whoBIRD** (`WikidataQuery`, `FileLockThrottle`, `SparqlUtils`), adapted, not depended upon.

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

## What was built, and what differs from the plan (2026-11-21)

Built as proposed: `Wikidata\Client` (the only door to the services, with the rules of good use), `Wikidata\TitleResolver`, `Wikidata\ItemReader`, `Wikidata\ClassTable`, `Wikidata\Options`, `Enrich\Enricher`, `Enrich\EnrichService`, `Cli\EnrichCommand`, the cron event `otherguise_index_enrich`, and the entries' new fields (`set_label`, `enrich_state`, `label_by_hand`). The source `page` makes candidates from the links to Wikidata **only when the enrichment is on**, and a mark `data-index="wikidata:Q…"` with no label is shown as `Q…` (what the enrichment looks for).

What differs or was decided while coding:

- **The commands are `wp otherguise index enrich enable|disable|start|run|status|stop|resume|duplicates|ambiguous|classes|map`**, not `kinds map`. `run --entry=<id>` looks one entry up again whatever its delay (it must still need the item or a label); `run --dry-run` makes the requests and prints, per entry: the state, the item, the label (`old => new`), the kind, the classes with their labels; `enrich classes` prints the table with the label of each class read from Wikidata, **so that every line can be checked by eye**.
- **The default table of classes is in the code and was not checked against Wikidata.** The slice said the identifiers would not be written before being checked; **no service could be reached to check them** (the egress address of the development machine was already rate limited: the service answered "you are making too many requests", and I did not insist). The 17 lines are the ones their author remembers: Q4022, Q47521, Q355304, Q23397, Q12284 → `waterway`; Q486972, Q8502, Q133056, Q39816, Q6256, Q56061 → `place`; Q16521 → `species`; Q5 → `person`; Q47461344, Q11424 → `work`; Q1656682 → `event`; Q2095 → `product`. **There is no line for `route` or `activity`.** **Run `enrich classes` first and correct the table with `enrich map`.**
- **`Job\PagesJob` is reused and generalised**: a unit may answer `stop` (the job stops, without a failure) and `processed` (a batch of 50 entries counts for 50). The units are batches of the queue, which shrinks as entries are done.
- **A failed batch writes nothing**: the three requests are made first, then the entries are written; a failure leaves them in the queue. A refusal that says "wait" pauses (the job stops; `resume` waits for the end of the pause); three other failures in a row stop it.
- **The ancestors are cached 90 days with a jitter**; the entries that were not resolved wait about 7 days (jitter 20 %); an entry that is `done` is not looked at again.
- **Uninstall** removes the options of the enrichment (the switch, the job, the contact, the table of classes, the cache, the counters).

### Verification

- **Under automated tests** (PHPUnit, 891 tests in the suite; entries and kinds in memory, the services answered by a double with **handwritten answers of the documented shape**): the User-Agent and the contact, the interval between two requests, 429/503 and `Retry-After`, `maxlag`, a rate limit that comes as text, the batches of 50, the titles (normalised, redirected, missing, disambiguation, no item), only identifiers in a query, the table and its cache and its expiry, the most specific kind, a batch end to end (item, label, kind, old label key kept), what a person decided kept, a duplicate not merged, a dry run, a pause, the back-off, three failures, a failed later request, no contact, the job.
- **On a scratch WordPress 7.1.4 (the real entries and options), with a fake HTTP answer**: an entry from a Wikipedia link got its item, its label (`Têt`) and its kind (`waterway`); a dry run wrote nothing; a second batch did not ask the ancestors again; the enrichment is off by default and the job refuses to work when it is off.
- **Never run against the real services**, so the shape of the answers, the queries and the table of classes are **unchecked**; and **WP-CLI was not run**. The first real run is Eric's, on the testing blog: `enrich enable`, set `index_wikidata_contact`, `enrich classes`, `enrich run --entry=<id> --dry-run`, then a batch, then everything.

## Amendment (2026-11-23): when the label of Wikidata is not taken

The label of Wikidata replaces the label of a topic **unless** it only changes the case (`Têt` and `TÊT`), **or** the topic has a qualifier in parentheses that Wikidata's label does not carry: *Moulins (Allier)* is not renamed *Moulins*, because the qualifier tells it from its namesakes (and from the duplicates finder's point of view it is what keeps two communes of one name apart). A label set by a person was never replaced. Tried under PHPUnit only; not on the blog.
