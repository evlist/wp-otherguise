<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 104 — Triples: cleanup, actions and cache

Status: **done** (the seven points under "To confirm" were confirmed by Eric on 2026-10-09). Dependencies: slices 100 to 103. Module: `Triples`.

## Goal

Make the statements follow the life of the things they relate, tell the rest of WordPress when they change, and keep the reads cheap:

1. **Cleanup.** When a post, a media item, a term or a user is deleted, the statements that involve it go with it (unless the predicate says `keep`), with the statements about them.
2. **Actions.** Other modules and plugins can react when a statement is created or deleted (an index to rebuild, a cache to purge, a log).
3. **Cache.** The reads of the service go through the WordPress object cache, with a cheap and safe invalidation.

Background: [`design/triples.md`](../design/triples.md), slice 103 for the service.

## 1. Cleanup

### What is listened to

`Module::boot()` adds callbacks to the WordPress deletion actions. Each one calls the new **`Statements::forget( $entity )`**, which is also the public entry for the entity types registered by other modules (templates, modes...): they call it from their own deletion hooks.

| WordPress action | Entities forgotten |
|---|---|
| `deleted_post` (fires for every post type, attachments included) | `post:ID` and `attachment:ID` (an id belongs to one of them; the post type is no longer known once the row is gone) |
| `deleted_term` | `term:ID` |
| `deleted_user` | `user:ID` (whatever the `reassign` argument: a statement about a user is not content that moves with the user's posts) |

A **trashed** post is not deleted: its statements stay, so that restoring it loses nothing. Revisions and autosaves are posts too: their deletion costs one indexed query that finds nothing.

### What `forget()` does

1. Takes the predicates of the registry whose `on_delete` is `remove` (the default) and that can involve the type of the entity (subject type, object type, or an empty list). Statements of `keep` predicates stay: they now point to something that no longer exists, which `resolve()` reports as `null`; this is for histories and logs, and the caller of a `keep` predicate is told so in the registry documentation.
2. Deletes the matching statements **with the statements about them, recursively** (`StatementStore::delete_by_entity( $entity, $predicates )` of slice 102, which already does this), in one transaction.
3. Fires the deletion actions below for every statement removed, the dependents included.
4. Returns the number of statements deleted.

Deleting a statement through `delete()` or `remove()` already deletes what is said about it; no hook is needed for the type `statement`.

### Not covered

Deleting a whole site of a network, and deleting a user from the network admin (the action fires once, on the site where it runs): not handled, documented, and never tried on a multisite. Data left behind by a plugin deactivated at the time of the deletion is not found afterwards; slice 105 lists statements whose ends no longer exist so that an administrator can delete them.

## 2. Actions

| Action | Arguments | When |
|---|---|---|
| `triples_statement_created` | `Statement $statement` | After a statement has been stored and committed. Not fired when `triple()` finds an existing one. |
| `triples_statement_deleted` | `Statement $statement` | After a statement has been deleted and the deletion committed; once per statement, the ones deleted as dependents included. The object is the statement as it was stored. |

Rules:

- **Fired after the commit.** The service keeps the events of a transaction in a queue: they are fired when the outermost transaction commits, in the order they happened, and dropped when it rolls back. Outside a transaction a call fires its events at the end. A callback therefore never sees a state that was rolled back.
- **A callback that throws** does not undo the statement (it is already committed): the exception is not caught, it reaches the caller, and the events not yet fired for that call are lost. Documented: callbacks must not throw.
- The hook names carry the module prefix (module rule 4). The actions are the only extension point added: no filter on the values, no `before_` action (a refusal is `InvalidStatementException`; a plugin that wants to forbid something registers a predicate with limits).
- `StatementStore::delete_with_dependents()` and `delete_by_entity()` also return the statements they deleted (a new `deleted()` result next to the count, or a separate method that reads them before the delete), so that the events carry real statements.
- `Module` gets an optional third constructor argument, `$add_action`, like `$do_action`, so that the tests record the registered callbacks without WordPress.

## 3. Cache

### Design

The pattern of WordPress core for queries: a cache group `triples`, a **`last_changed` token** kept in the same group, and cached results whose key contains the token. Every write changes the token (`microtime`-based value, as in `wp_cache_set_last_changed()`), which invalidates everything at once; no key is purged one by one.

- Cached: the rows returned by `StatementStore::find()`, `find_by_triple()`, `query()`, `count()` and `about()`, keyed by the token and a hash of the query (the SQL and its arguments). Statements are rebuilt from the rows on each read: cheap, and the cache never holds an object that someone could mutate.
- Bumped by every write of the store: `insert()`, `delete_with_dependents()`, `delete_by_entity()`, and by a **rollback** (what was read between the write and the rollback may have been cached from an uncommitted state): `Transaction` tells the cache through the `Database`, which already counts the depth.
- A new class `Storage\Cache` holds the group, the token and the three callables (`get`, `set`, `incr`/token) that default to `wp_cache_get()`, `wp_cache_set()` and `wp_cache_get_last_changed()`-like code, so that the tests use an array. Without a persistent object cache plugin WordPress keeps the cache for the current request only: the gain is then limited to a request that reads the same thing twice (a listing reads the same qualifications for each mode of a page); with Redis or Memcached it spans requests.
- `triples_settings['object_cache']` is **not** added: the cache is always used, and `wp_suspend_cache_addition()` / `WP_CACHE` settings of WordPress apply as to any other cache user.
- Per-site isolation on a network: the group is not global, so WordPress prefixes the keys with the blog id.

### What it does not do

It does not cache the result of `listing()` (it depends on the comparator, a closure), nor the existence checks of WordPress objects (WordPress has its own caches). It does not protect against another process changing the table without going through the module (direct SQL): the token is not bumped then. Documented.

## Changes to existing code

- `StatementStore` calls the `Cache`; `delete_*` return what they deleted.
- `Statements` queues and fires the events; new `forget()`.
- `Database` tells the `Cache` when a transaction rolls back (or `Transaction` does, if the `Database` should stay free of the cache; decided with the code).
- `Module::boot()` registers the three deletion callbacks and builds the cache; `Module` gets `$add_action`.
- New classes: `Storage\Cache`, `Service\EventQueue`, `Service\StatementEraser` (deletion, events, selection of the predicates to forget) and `Service\WordPressCleanup` (the three WordPress callbacks).

### As delivered

- **Transaction listeners.** `Database::listen()` lets a listener follow the transactions (`begin`, `commit`, `rollback`, with the level, 1 for the outermost); `Transaction` sends the notifications. The `Database` knows nothing about the cache or the events: `StatementStore` listens for the cache (bump on every rollback and on the outermost commit) and `EventQueue` listens for the events (a mark per transaction; flush at the outermost commit; truncation to the mark on rollback). The cache is bumped on commit as well as on write, so that a result cached by another process between our write and our commit does not survive it.
- **Store.** `StatementStore` takes an optional `Cache` (`Module` always gives one) and reads through it; new public methods `ids_for_entity()`, `ids_with_dependents()`, `find_many()` and `delete_ids()` (the first three read, the last one deletes without looking for dependents). `delete_with_dependents()` and `delete_by_entity()` are unchanged for callers.
- **Events.** `triples_statement_created` and `triples_statement_deleted` are fired through the `$do_action` of `Module`, with a `Statement`. `remove()`, `delete()`, `replace()` and `forget()` all go through `StatementEraser::erase()`, which reads the statements (dependents included) before deleting them.
- **Cache.** One `Cache` object, the group `triples`, the `last_changed` token; it stores the rows of `find`, `find_by_triple`, `query` and `about`, and the counts. The tests use stubs of `wp_cache_get()` and `wp_cache_set()`.
- **Module.** `Module` takes `$add_action` as a third constructor argument; `boot()` registers the three callbacks at priority 10.
- `StatementStore.php` is now 460 lines (the limit that warns is 400): splitting the reads and the deletions into two classes is a candidate for a later slice.

## Out of scope

Admin screens and the list of orphan statements (105); JSON export and import (106); REST routes; capability checks, which belong to the screens and routes that call the API; a persistent queue or asynchronous events.

## Tests

- **Unit, no database:** the queue of events (fired in order at the end of a call, held while a transaction is open, fired once at the outermost commit, dropped on rollback, a nested rollback drops only its own events); the selection of the predicates to forget (`remove` or `keep`, by entity type, empty lists, a type used only as object); the registration of the three callbacks by `Module::boot()` through `$add_action`, and what each one forgets (post and attachment for `deleted_post`, term, user); the `Cache` with an array backend (hit, miss, token change, group).
- **Integration on a real database:** deleting a post removes the statements where it is subject or object, the statements about them, and nothing else; a `keep` predicate stays and `resolve()` returns null; `forget()` of an entity type registered by a test; the events of a `delete()` with dependents (one per statement, the dependents included, each with the statement as stored); `triple()` on an existing triple fires nothing; a rolled-back transaction fires nothing and leaves nothing in the cache; the number of queries (the log of the test `wpdb`): the second identical read costs none, a write brings the queries back, a rollback too, and two cached reads on different tables of different test prefixes do not meet.
- The architecture test still passes.

## Not verified by this slice

The deletion actions of WordPress are exercised through the recorded callbacks only (their arguments are those documented by WordPress, not observed on a site); behaviour on a multisite network; a persistent object cache (Redis, Memcached): only the array backend of the tests; the cost of the cleanup when thousands of posts are deleted at once.

## To confirm

1. Cleanup on `deleted_post`, `deleted_term` and `deleted_user`; trash does not delete; the user's `reassign` argument is ignored.
2. `on_delete = keep` leaves the statement in place, dangling; `resolve()` then gives `null`.
3. `Statements::forget( $entity )` is the public entry for the types of other modules.
4. Two actions only, `triples_statement_created` and `triples_statement_deleted`, with a `Statement`; no `before_` action and no filter.
5. Events are fired after the outermost commit, queued during a transaction and dropped on rollback; a callback that throws is not caught.
6. Cache of the store's reads with a `last_changed` token in the group `triples`; invalidation by every write and every rollback; no option to turn it off; no cache for `listing()` itself.
7. Orphans (statements left by a deactivated plugin or a direct SQL deletion) are not cleaned here: slice 105 lists them.

## Done when

PHPUnit (unit and integration), phpcs and `reuse lint` pass; the architecture test passes; this document, `docs/design/triples.md` and `docs/IA.md` describe the delivered classes and hooks.
