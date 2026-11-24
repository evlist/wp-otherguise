<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 624 — Index: a worker that goes on, and `index reset`

Status: **coded on 2026-11-24** (PHPUnit; never tried on the blog). Dependencies: 621 (the synchronisation), 619 and 620 (prune and purge). Context: [`../design/index.md`](../design/index.md).

## Why (Eric, 2026-11-24)

*"I do not see why we use only 20 seconds per minute when indexing; launch it as close as possible to a continuous run."* The synchronisation ran **one tick of 20 seconds each time WP-Cron fired**, and Eric's cron fires once a minute (`* * * * * www-data . /etc/wp-cron.env; cd /var/www/html && wp cron event run --due-now`): **about a third of the time**, and a whole run of 634 posts took more than an hour.

## Decided and coded

- **The event `otherguise_index_sync` calls `SyncService::work()`**, not `tick()`. From the **command line** (the system cron), the worker **goes on, tick after tick, until the run is over** (or an hour: the next minute's cron takes over); from a **web request** it makes **one tick**, which stays short (a visitor must not wait).
- **One worker at a time.** A place (`index_sync_lock`: a token of the process and a time) is **taken at each tick** and given back at the end; another process that finds it held, and fresh (**less than 120 seconds** old), does nothing and leaves at once. So the cron of the next minute, which finds a worker going on, **costs nothing**; a worker that dies is **replaced after two minutes**. The place is **not atomic** (two processes that start in the same second may both work); the work can be done twice without harm, and the cron starts one process a minute.
- **`index sync start --foreground`** takes the same place (it tells when another worker has it) and keeps it while it works.
- **`wp otherguise index sync stop`** is seen by a worker that goes on: the state is read from the database at each tick, not from the cache of the process.

## `wp otherguise index reset [--apply] [--everything]`

The complete purge, for a test site: **one command instead of six**. In order:

1. stops the synchronisation that is going;
2. drops the mentions of **every source** (`post`, `image`, `trace`), as `index purge` does;
3. deletes the topics that nothing mentions any more, as `index prune --kind=any` does;
4. **forgets what the reads left behind**: what was read of each post (`_index_harvest`, the signals and the images counted), the files that wait for a place, the state of the readings, of the enrichment and of the synchronisation, the pending event; so that the next run reads **every** post.

**Nothing is changed without `--apply`.** What a person decided **stays** by default: the topics whose kind or label were chosen, the refused ones and the candidates, the mentions made by hand (and the topics they hold), the kinds, the list of the pairs that are not doubles. **`--everything`** deletes those too (every topic, every mention, the list of the pairs); the kinds always stay. Then `wp otherguise index sync start --from-scratch --foreground`.

## Tests

`IndexSyncTest::test_the_worker` (one tick from the web, the whole run from the command line, the place held by another worker, the place given back); `IndexPruneDbTest::test_the_reset` (a dry run changes nothing, the real run keeps what a person decided, `--everything` keeps nothing); the hooks of the module (`otherguise_index_sync` now calls `work`).

## Not tried

The lock and the worker on a real WordPress, with the cron of the containers; the deletion of the post meta on 600+ posts; `index reset` on the blog.
