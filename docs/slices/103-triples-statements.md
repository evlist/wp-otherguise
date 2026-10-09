<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 103 — Triples: creating and reading statements

Status: **planned** (waiting for Eric's confirmation of the points under "To confirm"). Dependencies: slices 100, 101 and 102. Module: `Triples`.

## Goal

Put the registry and the store together: a service that **creates statements only if they are valid** (types, existence, limits, symmetry, qualification rules) and **reads them the way the other modules need** (both directions, the statements about a statement, ordered and scoped lists). After this slice a module can relate things through a small, safe API; slice 104 adds the cleanup on deletion, the hooks and the cache.

Background: [`design/triples.md`](../design/triples.md).

## The service

`Otherguise\Triples\Statements`, obtained from `Module::statements()`. (`Module::store()` returns the low-level `StatementStore` that `statements()` returned in slice 102.)

### Writing

| Method | Behavior |
|---|---|
| `create( EntityRef $subject, $predicate, $object, array $qualifications = [] )` | Checks, then stores the statement and its qualifications **in one transaction**. Throws `DuplicateStatementException` when the triple exists. Returns the `Statement`. |
| `ensure( $subject, $predicate, $object )` | Idempotent: returns the statement that holds the triple, creating it when needed. Does not touch its qualifications. |
| `replace( $subject, $predicate, $object )` | In one transaction, deletes the other statements of this subject and predicate (with what is said about them) and ensures this one. For single-valued predicates such as `triples/position`. |
| `delete( $id )` | Deletes a statement and, recursively, the statements about it. |
| `check( $subject, $predicate, $object )` | The checks only, nothing written; for screens that validate before saving. |

`$object` is an `EntityRef`, a `Literal`, or a **PHP scalar**, read as a literal of the only datatype the predicate accepts (`5` for `triples/position` becomes `integer:5`); a scalar is an error when the predicate accepts no datatype or several.

`$qualifications` is a list of `array( predicate, object, nested qualifications )`:

```php
$statements->create(
    EntityRef::parse( 'post:12' ), 'media/illustrated-by', EntityRef::parse( 'attachment:88' ),
    array(
        array( 'modes/mode', EntityRef::parse( 'mode:web' ) ),
        array( 'modes/mode', EntityRef::parse( 'mode:print' ), array( array( 'triples/position', 1 ) ) ),
        array( 'triples/position', 5 ),
    )
);
```

### The checks

Each failure throws `InvalidStatementException` (a subclass of `InvalidArgumentException`) with a code that screens and the REST API can map to a message: `unknown_predicate`, `unknown_type`, `invalid_id`, `subject_type_not_allowed`, `object_type_not_allowed`, `invalid_value`, `ambiguous_literal`, `subject_missing`, `object_missing`, `too_many_objects`, `too_many_subjects`, `qualification_not_allowed`. In this order:

1. The predicate is registered.
2. The subject is an entity of a registered type, its id is well formed for the type, and the predicate accepts the type (an empty list accepts any entity type).
3. The object is either an entity (same checks) or a literal: the datatype is registered, accepted by the predicate (literals must be named in `object_types`), and the value is valid for it; the value stored is the **normalized** one.
4. **Existence.** Subject and object exist. An entity type may carry an existence check; the types without one are not checked. Built-ins (WordPress functions): `post`, `attachment` (a post of type `attachment`), `term`, `user`, and `statement` (the store). Modes will give `mode` its own.
5. **Qualification.** When the subject is a statement, the predicate of the statement must accept the new predicate as a qualifier (`PredicateRegistry::can_qualify()`). Every statement about a statement goes through this rule.
6. **Duplicate.** The triple is not already stored (`DuplicateStatementException`, with the statement that holds it).
7. **Limits.** `max_objects_per_subject`: the subject has fewer than N statements with this predicate; `max_subjects_per_object`: likewise for the object. For a **symmetric** predicate only the first limit is used and it counts the statements involving the entity on either side.

**Symmetric predicates.** A statement is stored once, with its two ends in canonical order (the smaller of `type:key` compared byte by byte as subject), so that `(a, p, b)` and `(b, p, a)` are the same statement and the duplicate check sees it. Reads look at both ends.

**Concurrency.** The limits are checked before the insert without a database lock: two simultaneous requests can both pass a limit of 1. The unique index still makes a duplicate triple impossible. Acceptable for a blog edited by a few people; documented, not hidden.

### Reading

- `find( $id )`, `find_by_triple( $subject, $predicate, $object )`.
- `match( ?EntityRef $subject, ?string $predicate, ?NodeInterface $object )`: the statements that fit the parts given, a missing part being a wildcard (the pattern read of RDF dataset APIs), symmetric predicates included. `objects_of( $subject, $predicate = null )` and `subjects_of( $object, $predicate = null )` are shortcuts for `match()`.
- `qualifications_of( array $statements )`: the statements about each statement, **in one query** per level: `statement id => predicate => Statement[]`.
- `listing( $subject, $predicate, array $options )`: the ordered, scoped list described below.

### Scopes and positions: the reading rule

A **scope** is a pair *(scope predicate, value)*, for example `(modes/mode, mode:print)`. Triples knows nothing about modes; the consumer names the scope. The rule, applied by `listing()`:

1. Read the statements of the subject and predicate (**all** of them) with their qualifications.
2. Put them in the **natural order** given by the consumer (a comparator on the statements; photos: date and time of the photo; default: by id), then apply the **global pins**: the `triples/position` statements about the statements themselves.
3. Keep the statements that apply to the scope: those with **no** statement of the scope predicate (they apply to every scope) and those with one whose object is the scope value. Their relative order is that of step 2.
4. If some of them have a **scope pin** (a `triples/position` statement about their statement of the scope), take those out and put them back at their rank in the scoped list. A scope pin overrides the global pin.

So without any scope pin, every scope shows the same order and only drops some items; a scope pin changes the order for that scope only. This settles an ambiguity in the design notes, which said both that the order is the same in every mode and that a position on a mode statement overrides the other one (the notes are corrected with this slice).

`PinnedOrder::merge( array $ordered_ids, array $pins )` is the pure function behind steps 2 and 4: the items without a pin keep their order and fill the free ranks; a pinned item sits at its rank (ranks start at 1); a rank beyond the end puts the item last; two items pinned to the same rank are ordered by id.

## Changes to existing code

- `EntityType` gets an optional **existence check** (`exists( $id )`: true when there is none); `EntityTypeRegistry::with_builtins()` accepts the checks of the built-in types; the WordPress ones are given by `Module`, so the registries stay free of WordPress calls.
- `StatementQuery` gets `involving( EntityRef )` (subject or object), used for symmetric predicates.
- `Module::statements()` returns the service; `Module::store()` the store.
- New classes: `Statements`, `InvalidStatementException`, `PinnedOrder`.

## Out of scope

Deleting what depends on a post, term, media item or user that disappears (`on_delete`), the actions fired when statements change, the object cache (slice 104); screens (105); JSON export and import (106).

## Tests

- **Unit, no database:** `PinnedOrder` (no pin, one, several, rank beyond the end, equal ranks, empty list, pinned ids unknown to the list); the checks that fail before reaching the store (every error code: unknown predicate or type, malformed id, type not allowed, scalar read as the only datatype and the ambiguous cases, invalid value, missing subject or object through the existence checks, a literal as subject); the existence checks of the built-in types against WordPress stubs; the canonical order of a symmetric pair.
- **Integration on a real database:** a statement with nested qualifications stored in one transaction and a failure rolling everything back; duplicates (strict and idempotent); both limits; a symmetric predicate stored once whichever way it is given and read from both ends; the qualification rule (a statement about a statement accepted or refused); `replace()` on a single-valued predicate; `qualifications_of` in one query; `listing()` on the example below, for the web scope, the print scope, a scope without any mode statement, with a global pin and with a scope pin.
- The architecture test still passes.

Example for `listing()`: the photos A, B, C of a post, natural order A, B, C. A has no mode statement; B is `web`; C is `web` and `print`. Global pin: C at rank 1. Scope web: C, A, B. Scope print: C, A. Scope pin of C in print at rank 2: print shows A, C.

## Not verified by this slice

The existence checks use WordPress functions (`get_post`, `term_exists`, `get_userdata`), exercised here through stubs only. The limits have the race described above. The cost of `listing()` on a very long list is not measured.

## To confirm

1. The writing API: `create` (strict, with nested qualifications in one transaction), `ensure`, `replace`, `delete`, `check`.
2. Existence checks carried by entity types, with WordPress-based checks for the built-ins; a type without a check is not checked.
3. A PHP scalar as object is read as a literal of the only datatype the predicate accepts.
4. Every statement whose subject is a statement must be authorised by `can_qualify()`.
5. The limits are checked without a database lock (documented race), duplicates are guaranteed by the unique index.
6. A symmetric predicate is stored once in canonical order; only `max_objects_per_subject` applies and it counts both ends.
7. `InvalidStatementException` with a code per failure.
8. The reading rule above (global order first, filter by scope, scope pins re-merged in the scoped list; no statement of the scope predicate means every scope), and `PinnedOrder::merge()` with ranks starting at 1.
9. The natural order is a comparator supplied by the consumer; the default is the id.
10. `Module::statements()` becomes the service and `Module::store()` the store.
11. Actions and cache are left to slice 104.
12. `match()` with wildcards as the main read (see the "Prior art and reuse" section of `design/triples.md`); no RDF library in the core.

## Done when

PHPUnit (unit and integration, the latter run against MariaDB in the development session), phpcs and `reuse lint` pass; the architecture test passes; this document, `docs/design/triples.md` and `docs/IA.md` describe the delivered classes.
