<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 101 — Triples: registry revision for statements about statements

Status: **planned** (waiting for Eric's confirmation of the points under "To confirm"). Dependencies: slice 100. Module: `Triples`.

## Why

Slice 100 gave statements a list of typed qualifiers stored with the statement. Eric's decision of 2026-10-09 is simpler: **a qualification is another statement whose subject is the statement qualified**.

```
statement:41: (post:12, illustrated-by, attachment:88)
statement:42: (statement:41, mode, mode:web)
statement:43: (statement:41, mode, mode:print)
statement:44: (statement:41, position, 5)
statement:45: (statement:43, position, 1)
```

So a qualifier is a predicate of the same registry, its value is the object of a statement (an entity or a typed literal), and the registry must say which predicates may qualify which. This slice aligns the registry of slice 100 with that model. Nothing is stored yet (slice 102) and slice 100 has not been released, so there is no data to migrate.

Background: `evlist/shared-room`, `wp-otherguise/README.md` ("Reification: statements about statements").

## Scope

### Removed

`QualifierDefinition`, `QualifierTypeInterface`, `QualifierTypeRegistry`, `AbstractScalarType`, `EnumType`, the action `triples_register_qualifier_types`, and the predicate keys `qualifiers`, `ordered` and `allow_repeats`. A triple now exists at most once; "ordered" is expressed by accepting the `triples/position` qualifier; enumerations are entity types (for example `mode`, registered by Modes).

### Datatypes (literals)

`DatatypeInterface` (`name()`, `datatype()` the XSD name, `validate( $value )`, `normalize( $value )`) with `StringDatatype`, `IntegerDatatype` and `BooleanDatatype`, taken from the scalar types of slice 100 without options. A string is limited to **191 bytes** (the storage column of slice 102). `DatatypeRegistry` with the built-ins and the action `triples_register_datatypes`, run on first use. Datatype names and entity type slugs share one namespace: registering one under the name of the other is refused.

### Entity types

New built-in type **`statement`** (ids are positive integers, as for `post`). That a statement exists is checked when a statement about it is created (slice 103). The type `mode` is not here: Modes registers it.

### Predicates

`PredicateDefinition` keys: `slug`, `label`, `inverse_label`, `subject_types`, `object_types` (entity type slugs **or datatype names**; empty list = any entity type, literals must be named), `max_objects_per_subject`, `max_subjects_per_object`, `symmetric`, `on_delete`, `iri`, and two new keys:

- `qualified_by`: slugs of predicates that may qualify the statements of this predicate (`illustrated-by` accepts `modes/mode` and `triples/position`);
- `qualifies`: slugs of predicates whose statements this predicate may qualify, or `'*'` for any (`triples/position`). This lets a module declare a qualifier for predicates of modules it depends on without the reverse dependency. A predicate X may qualify a statement of predicate P when P lists X in `qualified_by`, or X lists P or `'*'` in `qualifies`.

Rules checked by `PredicateRegistry`: types exist (entity or datatype); a symmetric predicate has the same subject and object types and no literal type; a predicate named in `qualified_by` or `qualifies` exists, and a qualifier accepts a `statement` subject (type `statement` listed, or empty list). Because modules register in any order, the references are checked **once all predicates are registered**, when the registry is first read, with a descriptive `InvalidArgumentException`. The registry offers `can_qualify( $qualifier, $target )`.

Single-valued or multiple-valued qualifiers use the existing limits: `max_objects_per_subject` of 1 means "at most one position per statement"; null allows several modes.

### Built-in predicate

`triples/position`: subject type `statement`, object datatype `integer`, `max_objects_per_subject` 1, `qualifies` `'*'`. Registered by the Triples module before the registration action runs.

### Module

The three registries become: entity types, datatypes, predicates; actions `triples_register_entity_types`, `triples_register_datatypes`, `triples_register_predicates`.

## Out of scope

Storage (102), creating statements and enforcing limits, symmetry and qualification rules on data (103), cascade and cache (104). Reading rules (no `mode` statement means all modes; a position on a mode statement overrides the one on the statement) belong to the consumers and to slice 103.

## Tests

Removed or rewritten: the qualifier and `ordered` tests of slice 100. New or changed: the datatypes (validation, normalization, 191-byte limit, XSD names), the `statement` built-in, the shared namespace of entity types and datatypes, `qualified_by` and `qualifies` resolution (both directions, `'*'`), reference errors found when the registry is first read, symmetric with literal types refused, `triples/position` registered with its limits, the module wiring and the three actions.

## To confirm

1. The two keys `qualified_by` and `qualifies`, and `'*'` for generic qualifiers.
2. The qualifier-reference check is done when the registry is first read (all predicates registered), not at each registration.
3. Entity types and datatypes share one namespace.
4. Literal strings limited to 191 bytes.
5. `triples/position` is built in; `modes/mode` and the `mode` entity type come with the Modes module.

## Done when

PHPUnit, phpcs and `reuse lint` pass; the architecture test passes; this document, slice 100 and `docs/IA.md` describe the delivered classes.
