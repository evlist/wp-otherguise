<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 101 — Triples: storage of statements and qualifiers

Status: **planned** (waiting for the decisions marked "To confirm"). Dependencies: slices 000 and 100. Module: `Triples`.

## Goal

Store statements and their qualifiers in two tables owned by the `Triples` module, create and migrate them safely, and give the rest of the module a low-level store to read and write them. The store knows how data is laid out, not what is allowed: checking a statement against the predicate registry (types, limits, symmetry, qualifier values) is slice 102.

Background and decisions: `evlist/shared-room`, `wp-otherguise/README.md` ("Reification: statements with identity and qualifiers": two tables, a statement has an identity, qualifiers are typed key/value pairs).

## Tables

Names belong to the module and follow the site prefix (one pair of tables per site on a multisite network): `{prefix}triples_statements` and `{prefix}triples_qualifiers`.

### `triples_statements`

| Column | Type | Meaning |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | identity of the statement (`rel:ID`) |
| `subject_type` | `VARCHAR(20)` | entity type of the subject |
| `subject_id` | `VARBINARY(191)` | id of the subject |
| `predicate` | `VARCHAR(64)` | predicate slug (`owner/name`) |
| `object_type` | `VARCHAR(20)` | entity type of the object |
| `object_id` | `VARBINARY(191)` | id of the object |
| `position` | `INT UNSIGNED NULL` | place among the statements of the subject and predicate; `NULL` when the predicate is not ordered |
| `statement_key` | `CHAR(40)` | SHA-1 of the canonical form of the statement, see below |
| `created_gmt`, `updated_gmt` | `DATETIME` | timestamps |

Indexes: primary key on `id`; unique on `statement_key`; `(subject_type, subject_id(100), predicate)`; `(object_type, object_id(100), predicate)`; `(predicate)`.

### `triples_qualifiers`

| Column | Type | Meaning |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | row identifier |
| `statement_id` | `BIGINT UNSIGNED` | the statement qualified |
| `name` | `VARCHAR(40)` | qualifier name |
| `value` | `VARBINARY(1020)` | normalized value (a multiple qualifier has one row per value) |

Indexes: primary key on `id`; `(statement_id, name)`; `(name, value(191))` to find the statements with a given qualifier value (for example a given mode).

There is no foreign key (WordPress does not use them): the store deletes the qualifiers of a statement with the statement, in one transaction.

### Choices behind the columns

- **Ids and qualifier values are binary (`VARBINARY`).** The default collations of WordPress are case- and accent-insensitive, which would make `ext:youtube:AbC` match `ext:youtube:abc` (YouTube ids are case-sensitive) and `é` match `e`. Binary columns compare bytes, whatever the collation of the site. Type, predicate and qualifier names are lower-case ASCII slugs and stay ordinary `VARCHAR`.
- **`statement_key` is the unique key.** The same subject, predicate and object may or may not be stored twice depending on the predicate (`allow_repeats`), so a plain unique index on the three would be wrong, and a check followed by an insert would race. The key is the SHA-1 of `subject_type:subject_id|predicate|object_type:object_id`, followed, for a predicate that allows repeats, by the sorted qualifiers (`name=value` lines). The caller says which form to use. The unique index then guarantees, even under concurrent requests, that no two identical statements exist. For a symmetric predicate the caller gives the two ends in canonical order (slice 102).
- **Position is a column, not a qualifier.** A photo that is first on the web and third in print is two statements with different `mode` qualifiers, which `allow_repeats` permits. `position` stays a reserved qualifier name.
- **Index lengths.** Prefix indexes (100 and 191 bytes) keep every index under the limits of older MySQL and MariaDB row formats, as WordPress core does for `meta_key`.
- The length limits (type 20, predicate 64, qualifier name 40, id 191, qualifier value 255 characters) are enforced by the registries and `EntityRef` (see "Changes to slice 100").

## Code

- `SchemaManager`: `SCHEMA_VERSION`, the `CREATE TABLE` statements written for `dbDelta`, `maybe_upgrade()` (creates or upgrades when the stored version is lower, runs the migration steps in version order, leaves the stored version unchanged if a step fails), the schema version kept in the option `triples_schema_version`. Steps are idempotent, as in `wp-i18nly`.
- `Statement`: immutable value object (id or null, subject and object `EntityRef`, predicate slug, position, qualifiers as `name => string[]`, timestamps).
- `StatementKey`: computes the key.
- `StatementStore`: `insert()` (returns the id, throws `DuplicateStatementException` on a duplicate key), `update()` (position and qualifiers, recomputing the key), `delete()`, `find( $id )`, `query( StatementQuery )` and `count()`, `delete_by_entity()` (primitive used by slice 103); statement and qualifiers are written in one transaction.
- `StatementQuery`: criteria (subject, object, predicate or predicates, qualifier name and values), order (position, then id) and paging. It builds a prepared SQL string; the building is pure PHP and unit tested.
- Module wiring: `activate()` creates the schema, `boot()` calls `maybe_upgrade()` (a cheap option check) so that other sites of a multisite network create their tables on their first request, `uninstall()` removes tables and options (see below).

## Changes to existing code

- `ModuleInterface` gets `activate()`; `ModuleLoader::activate()` calls it dependencies first; `otherguise.php` registers it with `register_activation_hook`. `Modes` and `Books` get empty implementations.
- **Changes to slice 100.** `EntityRef` and `EntityType` limit the type slug to 20 characters, `PredicateDefinition` limits the predicate slug to 64 and `QualifierDefinition` the name to 40, each with tests.
- `uninstall()`: **data is kept unless the administrator asked for deletion** (statements are the author's work, like the translations of `wp-i18nly`). The request is the option `triples_settings['delete_data_on_uninstall']`; the screen to set it comes with slice 104 (until then it can be set with WP-CLI). When set, tables and options are removed on every site of a network.

## Tests

- Unit tests (no database): `StatementKey` (same statement, same key; case matters; with and without qualifiers; qualifier order does not matter), `Statement` validation, `StatementQuery` SQL and arguments, the `CREATE TABLE` text follows the `dbDelta` conventions (two spaces after `PRIMARY KEY`, one column per line, `KEY` names), `SchemaManager` decisions (create, upgrade, failing step) on a double, `ModuleLoader::activate()` order, the new length limits.
- **Integration tests on a real MariaDB or MySQL**, not an in-memory double: `tests/phpunit/support/` gets a small stand-in for the part of `$wpdb` the store uses (`prepare`, `query`, `get_results`, `get_var`, `insert_id`, `last_error`, `prefix`, `get_charset_collate`), built on `mysqli`. These tests run when the environment variable `OTHERGUISE_TEST_DB` (DSN) is set and are reported as **skipped**, not passed, otherwise. They cover: creation of the tables, insert and read back, duplicate keys, case-sensitive ids (`AbC` and `abc` are different), accents, positions and ordering, qualifier filters, delete with qualifiers, transactions rolling back on failure, binary values round trip.
- Architecture test still passes (the new code uses only the core and `Triples`).

## Not verified by this slice

`dbDelta` itself needs WordPress: the SQL is checked against its conventions and run directly on MariaDB, but the real `dbDelta` call, the activation hook and the multisite path are untested until the plugin runs in a real WordPress site. The object cache (statements will be read on most requests by `Modes`) is designed in slices 102 and 103, not here.

## To confirm

1. **Table names** `triples_statements` and `triples_qualifiers`, per site.
2. **Position is a column**, `NULL` for unordered predicates; different positions per mode use separate statements.
3. **Uniqueness by `statement_key`** (SHA-1 in a unique index), with qualifiers in the key only for predicates that allow repeats.
4. **`VARBINARY` for ids and qualifier values**, so that matching is exact whatever the collation of the site.
5. **Length limits added to slice 100**: type slug 20, predicate slug 64, qualifier name 40.
6. **Integration tests on a real database** through an environment variable, skipped when absent. A MariaDB 10.11 was started successfully in the development session; the grafted CI provides a MariaDB service.
7. **Uninstall keeps the data by default**, deletion only when the administrator has asked for it.
8. **`activate()` added to `ModuleInterface`.**
9. **Timestamps** `created_gmt` and `updated_gmt`, but no `created_by` for now.

## Done when

PHPUnit (unit and integration, the latter run against MariaDB in the development session), phpcs and `reuse lint` pass; the architecture test passes; this document and `docs/IA.md` describe the delivered classes.
