<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 101 — Triples: storage of statements and qualifiers

Status: **planned, decisions confirmed by Eric (2026-10-09); code not started**. Dependencies: slices 000 and 100. Module: `Triples`.

## Goal

Store statements and their qualifiers in two tables owned by the `Triples` module, create and migrate them safely, and give the rest of the module a low-level store to read and write them. The store knows how data is laid out, not what is allowed: checking a statement against the predicate registry (types, limits, symmetry, qualifier values, overlapping scopes) is slice 102.

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
| `position` | `INT UNSIGNED NULL` | explicit rank ("pin"), see "Position"; `NULL` when none |
| `dedupe_key` | `CHAR(43)` | fingerprint of the statement, see "The fingerprint" |
| `created_gmt`, `updated_gmt` | `DATETIME` | timestamps (no `created_by` for now) |

Indexes: primary key on `id`; unique on `dedupe_key`; `(subject_type, subject_id(100), predicate)`; `(object_type, object_id(100), predicate)`; `(predicate)`.

### `triples_qualifiers`

| Column | Type | Meaning |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | row identifier |
| `statement_id` | `BIGINT UNSIGNED` | the statement qualified |
| `name` | `VARCHAR(40)` | qualifier name |
| `value` | `VARBINARY(1020)` | normalized value |

A statement can carry any number of qualifiers (different names), and a qualifier can have several values (one row per value). Indexes: primary key on `id`; `(statement_id, name)`; `(name, value(191))` to find the statements with a given qualifier value (for example a given mode).

```
statement 41: (post:12, illustrated-by, attachment:88), position NULL
  qualifiers:  modes = web   |  modes = print   |  caption = "Le col de Somport"
```

There is no foreign key (WordPress does not use them): the store deletes the qualifiers of a statement with the statement, in one transaction.

### Choices behind the columns

- **Ids and qualifier values are binary (`VARBINARY`).** The default collations of WordPress are case- and accent-insensitive, which would make `ext:youtube:AbC` match `ext:youtube:abc` (YouTube ids are case-sensitive) and `é` match `e`. Binary columns compare bytes, whatever the collation of the site. Type, predicate and qualifier names are lower-case ASCII slugs and stay ordinary `VARCHAR`.
- **Index lengths.** Prefix indexes (100 and 191 bytes) keep every index under the limits of older MySQL and MariaDB row formats, as WordPress core does for `meta_key`.
- The length limits (type 20, predicate 64, qualifier name 40, id 191, qualifier value 255 characters) are enforced by the registries and `EntityRef` (see "Changes to slice 100").

## The fingerprint (`dedupe_key`)

The fingerprint only prevents storing the same statement twice, even when two requests arrive at the same time. It never limits the qualifiers. A plain unique index on (subject, predicate, object) would be wrong because some predicates allow the same triple several times (`allow_repeats`), and a check followed by an insert would race.

It is `"v1|" + sha256`, written in base64url without padding (43 characters) of a canonical text, in one of two forms chosen by the caller from the predicate:

- **predicate without repeats (the default):** the triple only, `subject_type:subject_id|predicate|object_type:object_id`. At most one statement per triple, with as many qualifiers as needed; changing its qualifiers does not change the fingerprint;
- **predicate with `allow_repeats`:** the triple followed by **all** the qualifiers, sorted by name then by value (`name=value` lines). Several statements may share a triple provided their qualifiers differ; changing a qualifier changes the fingerprint. The order of the values of a multiple qualifier does not matter.

```
(post:12, illustrated-by, attachment:88)  modes=web    position 1   ┐ allowed together
(post:12, illustrated-by, attachment:88)  modes=print  position 3   ┘ (allow_repeats)
```

Rules:

- `update()` recomputes the fingerprint in the same transaction as the qualifiers; if the new fingerprint exists, the unique index refuses the change and nothing is modified (`DuplicateStatementException`). A modification cannot create an exact duplicate.
- The fingerprint is derived data, never the source of truth. The `v1|` prefix names the algorithm, so that the format can change later; a migration step can recompute every fingerprint.
- **Changing `allow_repeats` of a predicate is a migration.** The form of the fingerprint depends on a setting that lives in the code of a module, not in the database. When a predicate switches, a migration step recomputes the fingerprints of its statements and reports the conflicts it cannot resolve. This is a rule for authors of predicates.
- For a symmetric predicate the caller gives the two ends in canonical order (slice 102).
- The fingerprint catches exact duplicates only. Two statements for the same triple whose scopes overlap (`modes=web` and `modes=web,print`) have different fingerprints and are both accepted here; whether and how to refuse them is a question of meaning, settled in slice 102.

## Position

`position` is an optional explicit rank, a "pin", not the main ordering.

- Eric's use: photos are ordered by date and time, computed from the photos, not stored. Pinning a photo to a rank is sometimes useful. The module `Triples` knows nothing about dates; the consumer (Media Helper, the gallery) provides the natural order.
- A small pure function, `PinnedOrder::merge()`, takes the naturally sorted list and the pinned ranks and returns the final order: the unpinned items keep their natural order and fill the free ranks; pinned items sit at their rank (a rank beyond the end goes last; two items pinned to the same rank are ordered by statement id).
- The order is computed on **all** the statements of the subject and predicate, **then** filtered by mode. The relative order is therefore the same in every mode, and a mode only drops some items. Moving one photo only changes or removes one pin; there is no renumbering of the list.
- Different orders in different modes are not supported by this design. If one is needed, use a separate ordered list for that mode (the principle of a book, which is an ordered list of posts); this needs no schema change.

## Code

- `SchemaManager`: `SCHEMA_VERSION`, the `CREATE TABLE` statements written for `dbDelta`, `maybe_upgrade()` (creates or upgrades when the stored version is lower, runs the migration steps in version order, leaves the stored version unchanged if a step fails), the schema version kept in the option `triples_schema_version`. Steps are idempotent, as in `wp-i18nly`.
- `Statement`: immutable value object (id or null, subject and object `EntityRef`, predicate slug, position or null, qualifiers as `name => string[]`, timestamps).
- `StatementKey`: computes the fingerprint in its two forms.
- `PinnedOrder`: the pure merge described above.
- `StatementStore`: `insert()` (returns the id, throws `DuplicateStatementException` on a duplicate fingerprint), `update()` (position and qualifiers, recomputing the fingerprint), `delete()`, `find( $id )`, `query( StatementQuery )` and `count()`, `delete_by_entity()` (primitive used by slice 103); a statement and its qualifiers are written in one transaction.
- `StatementQuery`: criteria (subject, object, predicate or predicates, qualifier name and values), order (by id, or by position for pinned ones) and paging. It builds a prepared SQL string; the building is pure PHP and unit tested.
- Module wiring: `activate()` creates the schema, `boot()` calls `maybe_upgrade()` (a cheap option check) so that other sites of a multisite network create their tables on their first request, `uninstall()` removes tables and options (see below).

## Changes to existing code

- `ModuleInterface` gets `activate()`; `ModuleLoader::activate()` calls it dependencies first; `otherguise.php` registers it with `register_activation_hook`. `Modes` and `Books` get empty implementations.
- **Changes to slice 100.**
  - Length limits: `EntityRef` and `EntityType` limit the type slug to 20 characters, `PredicateDefinition` limits the predicate slug to 64 and `QualifierDefinition` the name to 40.
  - `ordered` (boolean) becomes `position`, with three values: `none` (default), `optional` (a pin may be set; photos) and `required` (every statement has a position; the contents of a book). `is_ordered()` becomes `position_mode()`. A symmetric predicate must have `position` `none`.
  - Each change comes with tests; `docs/slices/100-triples-registry.md` is updated.
- `uninstall()`: **data is kept unless the administrator asked for deletion** (statements are the author's work, like the translations of `wp-i18nly`). The request is the option `triples_settings['delete_data_on_uninstall']`; the screen to set it comes with slice 104 (until then it can be set with WP-CLI). When set, tables and options are removed on every site of a network.

## Tests

- Unit tests (no database): `StatementKey` (same statement, same key; case matters; both forms; qualifier and value order does not matter; the `v1|` prefix), `Statement` validation, `PinnedOrder` (no pin, one pin, several pins, rank beyond the end, equal ranks, empty list), `StatementQuery` SQL and arguments, the `CREATE TABLE` text follows the `dbDelta` conventions (two spaces after `PRIMARY KEY`, one column per line, `KEY` names), `SchemaManager` decisions (create, upgrade, failing step) on a double, `ModuleLoader::activate()` order, the new length limits and the `position` values.
- **Integration tests on a real MariaDB or MySQL**, not an in-memory double: `tests/phpunit/support/` gets a small stand-in for the part of `$wpdb` the store uses (`prepare`, `query`, `get_results`, `get_var`, `insert_id`, `last_error`, `prefix`, `get_charset_collate`), built on `mysqli`. These tests run when the environment variable `OTHERGUISE_TEST_DB` (DSN) is set and are reported as **skipped**, not passed, otherwise. They cover: creation of the tables, insert and read back, duplicate fingerprints (both forms), a rejected update leaving the data unchanged, case-sensitive ids (`AbC` and `abc` are different), accents, pins and ordering, qualifier filters, several qualifiers and several values, delete with qualifiers, transactions rolling back on failure, binary values round trip.
- Architecture test still passes (the new code uses only the core and `Triples`).

## Not verified by this slice

`dbDelta` itself needs WordPress: the SQL is checked against its conventions and run directly on MariaDB, but the real `dbDelta` call, the activation hook and the multisite path are untested until the plugin runs in a real WordPress site. The object cache (statements will be read on most requests by `Modes`) is designed in slices 102 and 103, not here.

## Decisions (confirmed by Eric, 2026-10-09)

1. Table names `triples_statements` and `triples_qualifiers`, per site.
2. `position` is a column and an optional pin; the default order is computed by the consumer (photos: date and time); the order is computed on all statements, then filtered by mode.
3. Uniqueness by a fingerprint in a unique index, now called `dedupe_key`, with qualifiers in the fingerprint only for predicates that allow repeats; algorithm version in the fingerprint; changing `allow_repeats` is a migration.
4. `VARBINARY` for ids and qualifier values.
5. Length limits added to slice 100: type slug 20, predicate slug 64, qualifier name 40.
6. Integration tests on a real database through an environment variable, skipped when absent.
7. Uninstall keeps the data by default.
8. `activate()` added to `ModuleInterface`.
9. Timestamps `created_gmt` and `updated_gmt`, no `created_by` for now.
10. `ordered` becomes `position` (`none`, `optional`, `required`).

## Done when

PHPUnit (unit and integration, the latter run against MariaDB in the development session), phpcs and `reuse lint` pass; the architecture test passes; this document, slice 100 and `docs/IA.md` describe the delivered classes.
