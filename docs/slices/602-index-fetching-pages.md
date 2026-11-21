<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 602 — Index: fetching the pages in the background

Status: **coded (2026-11-21), on `main`; see "What was built, and what differs from the plan" at the end.** Dependencies: slice 600 (module, resolver, mentions), slice 601 (the reader of a page). Context: [`../design/index.md`](../design/index.md), "Reading the rendered page". Eric's order: after the categories and the tags, **the links to Wikipedia and the names of birds**; this slice brings the pages to the reader and turns what it reads into mentions. The names of birds still wait for the `data-index` change in whoBIRD (another repository).

## What it does

A **background job** asks the site for the web page of each published post (a request of the site to itself, no `?print`), reads it with the reader of 601, **stores the signals** on the post, and brings the mentions of the new source **`page`** in line. The source is **opt-in** (`wp otherguise index source page on`): a site that did not turn it on makes no request.

### Decided (Eric) / Proposed

- **Decided:** the rendered web page is read, in a background job with progress, stop and resume, and a report; a page that cannot be reached is reported, never silently skipped; published posts only at first.
- **Proposed:** the pieces below.

### The pieces

- **`Harvest\PageFetcher`**: the address is `get_permalink()`; an optional **internal address** (`index_site_address`, option or filter `index_site_address`) replaces the scheme, host and port when the public address does not resolve from the server (the same idea as slice 225 of Books). An ordinary `wp_remote_get` with a timeout; a status other than 200, a content type that is not HTML, or a transport error is a **failure with a reason**.
  - **Deviation from the design note:** the design said to *reuse* the two settings of Books (private link, internal address). The Index module depends on Triples only (`ArchitectureTest`), so it **does not read the settings of Books**; it has its own optional setting and the filter, and an adapter in Books can answer the filter later. The private link for drafts is **not done** (published posts only).
- **`Harvest\SignalStore`**: the signals of a post are kept as post meta `_index_signals` (JSON) with the time of the harvest and a hash. **The `image` signals are not kept, only their number** (`_index_images`): a page of the blog holds up to 150 of them and the photographs slice will read the page again.
- **`Source\Page`** (source `page`, a database source: it reads the stored signals):
  - an **entry mark** `data-index` becomes a candidate: an identifier (`wikidata:Q…`) is its key, the label is `data-index-label` or the identifier; a path `A > B` makes the broader candidate; the kind is `data-index-kind` or `other`; `data-index-main` makes the mention `main`;
  - a **link to Wikipedia** becomes a candidate with the key `wikipedia:<language>:<title>`, the title as label, the kind `other` (the kind is set in bulk later, then deduced from Wikidata in a later slice), strength `passing`;
  - **not yet turned into mentions**: links to Wikidata and to OpenStreetMap objects (they have no label until an enricher reads them), GPX files and images (the photographs slice). They are stored (except images) so that nothing is read twice.
  - The source is **sure**: its new entries are `approved`. Eric corrects kinds and duplicates by hand (the resolver and `index link`).
- **`Job\PagesJob`**: the state (status `running`, `stopped`, `done`, the position, the counts, the failures with their reason, a short log) is in the option `index_pages_job`. A **tick** (WP-Cron event `otherguise_index_pages`) works for a time budget (20 seconds) or a batch, then schedules the next tick. `stop` and `resume` work between two posts. A post that fails is recorded and the job goes on.
- **When a post is saved**: a single-post event `otherguise_index_page` is scheduled 30 seconds later (the page is not fetched during the save, and a cache plugin cannot hide it: it is a request of its own). Only when the source is on and the post is published.
- **WP-CLI** `wp otherguise index pages start|run|status|stop|resume [--post=<id>]` and `index source <slug> on|off`. `run` does the same work **in the foreground** without WP-Cron (the blog runs with `DISABLE_WP_CRON`).

### Names (Proposed)

Options `index_pages_job`, `index_site_address`; filter `index_site_address`; cron events `otherguise_index_pages` and `otherguise_index_page`; post meta `_index_signals`, `_index_images`, `_index_harvest` (time and hash).

## Tests (planned)

Without WordPress: the address rewriting, the fetcher with a double of the HTTP request (200, 404, a transport error, a content type that is not HTML), the store (JSON round trip, the images counted not kept), the source (mark with identifier, label, path, Wikipedia link, ignored types), the job (a tick within a budget, stop and resume, a failure that does not stop the job, the end, a second start), the command. With a real database: signals → resolver → mentions for the source `page`, twice, with a link that disappears from the page.

## Not done

The private link for a draft; reading the pages again **when what a plugin shows has changed without the post changing** (a periodic check by the hash is the simplest: **Open**); the anchors of the passages (`index/anchor`); the enrichers (Wikidata, kinds); the whoBIRD change; the loopback behind a firewall or a cache of the real blog: **never tried**.

## What was built, and what differs from the plan (2026-11-21)

Built as planned: `Harvest\Address`, `Harvest\PageFetcher`, `Harvest\SignalStore`, `Source\Page`, `Job\PagesJob` (state, budget of 20 seconds, stop and resume between two posts, failures with their reason, a log of the last 50 lines), `Job\PagesService` (the WordPress side: reading one post, the job on the option and the cron, the event scheduled 30 seconds after a save, the switch of the source), and the commands `wp otherguise index pages enable|disable|start|run|status|stop|resume` (`run --post=<id>` reads one post now, `run --resume` goes on from where the job stopped).

What differs or was decided while coding:

- **The switch of the source is `pages enable|disable`** (not a separate `source` command). The option `index_sources` lists the sources that are on; `page` is **off until enabled**, so a site that did not ask makes no request.
- **`start` hands the job to WP-Cron; `run` does it in the foreground** with a job that schedules nothing, because the blog runs with `DISABLE_WP_CRON`. `status` shows the failures (the last 20) and the log.
- **Reading a page that did not change** only updates the time of the harvest (`touch`); the mentions are brought in line each time anyway (cheap, and a source of entries may have changed).
- **A stop asked from another request is seen between two posts** (the state is read again without the object cache).
- `wp otherguise index pages` is a **nested command**: it exists next to `wp otherguise index`. **Never run under real WP-CLI**: the commands are tested with doubles.
- **Uninstall** deletes the options, the three post metas and the scheduled events of the module.
- **`index/anchor`** is still not written (a passage anchor is a later refinement).

### Verification

- **Under automated tests** (PHPUnit, 840 tests in the suite): the address rewriting, the fetcher (200, 404, a transport error, a content type that is not HTML), the store (JSON round trip, the images counted, an unchanged harvest), the source, the job (end, failure that does not stop it, budget, stop and resume, a stop from another request), the command, and, **with a real database**, the whole chain from a page to the mentions (a link in the contents and a mark become mentions, a link in a header does not; reading twice writes nothing; a link that disappears withdraws its mention; two posts share an entry; failures and a source that is off are reported; the event scheduled on save).
- **On a scratch WordPress 7.1.4 with a real HTTP server** (`php -S`, the site asking itself by its own address): the plugin read a post it had just created, stored 3 signals, made two mentions (the Wikipedia link and the `data-index` mark with its label), read it again with no new entry, and the job ran in the foreground. **Two requests timed out after 30 seconds on the first run** (the single-threaded development server was busy); the failure was reported with its reason and the job went on, and the second run read all three posts. So **the failure path was seen for real**, but **the loopback was never tried on the blog, behind its proxy, its cache or its firewall**, and **never with a few hundred posts** (the time is still to measure).
