<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 102 — Triples: storage of statements

Status: **planned** (waiting for Eric's confirmation of the points under "To confirm"). Dependencies: slices 000, 100 and 101. Module: `Triples`.

This plan replaces the first version (two tables, a fingerprint, a flag for repeated statements and a position column), withdrawn after Eric's decision of 2026-10-09: a qualification is a statement about a statement.

## Goal

Store statements in **one table** owned by the `Triples` module, create and migrate it safely, and give the rest of the module a low-level store to read and write it. The store knows how data is laid out, not what is allowed: checking a statement against the registry is slice 103.

Background: `evlist/shared-room`, `wp-otherguise/README.md` ("Reification: statements about statements").

## Table

Name: `{prefix}triples_statements` (one table per site on a multisite network).

| Column | Type | Meaning |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | identity of the statement (`statement:ID`) |
| `subject_type` | `VARCHAR(20)` | entity type of the subject (`post`, `statement`, ...) |
| `subject_id` | `VARBINARY(191)` | id of the subject |
| `predicate` | `VARCHAR(64)` | predicate slug (`owner/name`) |
| `object_type` | `VARCHAR(20)` | entity type of the object, or datatype name for a literal (`integer`, `string`, `boolean`) |
| `object_id` | `VARBINARY(191)` | id of the object, or normalized value of the literal |
| `created_gmt`, `updated_gmt` | `DATETIME` | timestamps (no `created_by` for now) |

Indexes: primary key on `id`; **unique** `(subject_type, subject_id, predicate, object_type, object_id)` on the full columns (no prefix: a prefix would make the uniqueness wrong); `(object_type, object_id, predicate)`; `(predicate)`. The unique index also serves lookups by subject, since the subject columns come first. Key sizes stay under 1000 bytes.

A literal is stored in the object columns, with the datatype as `object_type`; entity types and datatype names share one namespace (slice 101), so no extra column says which is which. A statement stays what it is whatever statements are added about it: it has no mutable column, so **statements are immutable** (changing a qualification is deleting a statement and creating another); there is no `update()`.

```
id  subject            predicate      object
41  post:12            illustrated-by attachment:88
42  statement:41       mode           mode:web
43  statement:41       mode           mode:print
44  statement:41       position       integer:5
45  statement:43       position       integer:1
```

### Choices behind the columns

- **Ids and values are binary (`VARBINARY`).** The default collations of WordPress are case- and accent-insensitive, which would make `ext:youtube:AbC` match `ext:youtube:abc` (YouTube ids are case-sensitive) and `é` match `e`. Binary columns compare bytes, whatever the collation of the site. Type and predicate slugs are lower-case ASCII and stay ordinary `VARCHAR`.
- **Length limits**: type and datatype names 20, predicate slug 64, id and literal 191 bytes, enforced by the registries and `EntityRef` (see slice 101 and "Changes to existing code").
- **Unique triple**: an index on the whole triple makes duplicates impossible even under concurrent requests, with no fingerprint to compute or migrate.
- No foreign key (WordPress does not use them): the store deletes dependent statements itself, in one transaction.

## Code

- `SchemaManager`: `SCHEMA_VERSION`, the `CREATE TABLE` statement written for `dbDelta`, `maybe_upgrade()` (creates or upgrades when the stored version is lower, runs the migration steps in version order, leaves the stored version unchanged if a step fails), the schema version kept in the option `triples_schema_version`. Steps are idempotent, as in `wp-i18nly`.
- `Statement`: immutable value object (id or null, subject `EntityRef`, predicate slug, object as an `EntityRef` or a typed literal, timestamps).
- `StatementStore`:
  - `insert( Statement )` returns the id, and throws `DuplicateStatementException` when the triple exists (the caller may then fetch it with `find_by_triple()`);
  - `find( $id )`, `find_by_triple( subject, predicate, object )`;
  - `query( StatementQuery )` and `count()`;
  - `qualifiers_of( ids )`: the statements whose subject is one of the given statements, in one query (one level; the caller recurses for nested qualifications);
  - `delete_with_dependents( $id )`: deletes a statement and, recursively, the statements about it, in one transaction;
  - `delete_by_entity( EntityRef )`: deletes the statements where the entity is subject or object, with their dependents (primitive used by slice 104).
- `StatementQuery`: criteria subject, object, predicates, `qualified( predicate, objects )` (EXISTS) and `unqualified( predicate )` (NOT EXISTS, for "no `mode` statement means all modes"), order and paging. It builds a prepared SQL string; the building is pure PHP and unit tested.
- Module wiring: `activate()` creates the schema, `boot()` calls `maybe_upgrade()` (a cheap option check) so that other sites of a multisite network create their table on their first request, `uninstall()` removes the table and the options (see below).

## Changes to existing code

- `ModuleInterface` gets `activate()`; `ModuleLoader::activate()` calls it dependencies first; `otherguise.php` registers it with `register_activation_hook`. `Modes` and `Books` get empty implementations.
- **Length limits** (with slice 101): the type slug and datatype name at 20 characters (`EntityRef`, `EntityType`, datatypes), the predicate slug at 64 (`PredicateDefinition`), each with tests.
- `uninstall()`: **data is kept unless the administrator asked for deletion** (statements are the author's work, like the translations of `wp-i18nly`). The request is the option `triples_settings['delete_data_on_uninstall']`; the screen to set it comes with the admin slice (until then it can be set with WP-CLI). When set, the table and options are removed on every site of a network.

## Tests

- Unit tests (no database): `Statement` validation, `StatementQuery` SQL and arguments (subject, object, predicates, `qualified`, `unqualified`, paging), the `CREATE TABLE` text follows the `dbDelta` conventions (two spaces after `PRIMARY KEY`, one column per line, `KEY` names), `SchemaManager` decisions (create, upgrade, failing step) on a double, `ModuleLoader::activate()` order, the new length limits.
- **Integration tests on a real MariaDB or MySQL**, not an in-memory double: `tests/phpunit/support/` gets a small stand-in for the part of `$wpdb` the store uses (`prepare`, `query`, `get_results`, `get_var`, `insert_id`, `last_error`, `prefix`, `get_charset_collate`), built on `mysqli`. They run when the environment variable `OTHERGUISE_TEST_DB` (DSN) is set and are reported as **skipped**, not passed, otherwise. They cover: table creation, insert and read back, a duplicate triple refused, two statements with the same subject and predicate and different objects accepted (the modes of the example above), case-sensitive ids (`AbC` and `abc` differ), accents, literals round trip, `qualifiers_of`, `qualified` and `unqualified` queries on the example above, recursive deletion with nested statements, `delete_by_entity`, rollback on failure.
- Architecture test still passes (the new code uses only the core and `Triples`).

## Not verified by this slice

`dbDelta` itself needs WordPress: the SQL is checked against its conventions and run directly on MariaDB, but the real `dbDelta` call, the activation hook and the multisite path are untested until the plugin runs in a real WordPress site. The object cache (statements will be read on most requests by `Modes`) is designed in slice 104, not here. Reads that combine a statement with its qualifications use joins or `EXISTS`; their performance on a large table is not measured.

## To confirm

1. Table name `triples_statements`, per site.
2. One table, unique on the whole triple; **statements are immutable** (no `update()`).
3. `VARBINARY(191)` for ids and literal values; the unique index on full columns.
4. Literals stored in the object columns with the datatype as `object_type`.
5. Integration tests on a real database through an environment variable, skipped when absent (a MariaDB 10.11 runs in the development session; the grafted CI provides a MariaDB service).
6. Uninstall keeps the data by default.
7. `activate()` added to `ModuleInterface`.
8. Timestamps `created_gmt` and `updated_gmt`, no `created_by` for now.

## Done when

PHPUnit (unit and integration, the latter run against MariaDB in the development session), phpcs and `reuse lint` pass; the architecture test passes; this document and `docs/IA.md` describe the delivered classes.
