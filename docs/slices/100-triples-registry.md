<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 100 — Triples: entity types and predicate registry

Status: **planned** (waiting for the decisions marked "To confirm"). Dependencies: slice 000. Module: `Triples`.

## Goal

Describe, in memory and with strict validation, *what a statement may relate*: the kinds of things that can be a subject or an object (entity types), the kinds of relations (predicates) and the qualifiers a predicate may carry. Nothing is stored yet (slice 101) and no statement exists yet (slice 102). The slice is pure PHP, testable without WordPress, and it fixes the vocabulary used by every later slice and by the other modules.

Background and decisions: `evlist/shared-room`, `wp-otherguise/README.md` ("Triples", "Reification: statements with identity and qualifiers", "RDF import and export").

## Scope

### Entity references

`EntityRef`: an immutable pair *(type, id)* written `type:id` (`post:123`, `term:45`, `user:7`, `attachment:88`, `template:theme//slug`, `ext:youtube:abc`). Parsed and formatted by one class; the id is an opaque string whose format is checked by its entity type. Equality by value.

### Entity types

`EntityType` and `EntityTypeRegistry`. A type has a slug (`[a-z][a-z0-9_]*`), a label, a check of the id format, and an **optional IRI resolver** (a callable returning an IRI or null for an id). Built-in types: `post`, `attachment`, `term`, `user` (positive integer ids). Other types are registered by the modules that own them (`template` by Modes, `ext` by the integrations). Existence in the database is **not** checked here (slice 102).

### Qualifier types

`QualifierTypeInterface` (`name()`, `datatype()`, `validate( $value )`, `normalize( $value )`) and `QualifierTypeRegistry`. Built-in types: `string`, `integer`, `boolean`, `enum` (allowed values come from the qualifier definition). `datatype()` returns the matching XSD datatype name so that values map to RDF later. The core knows nothing about modes: the Modes module will register a `mode` type (a slug that must be a declared mode) in its own slice.

### Predicates

`PredicateDefinition` (immutable, validated when built) with:

- `slug`: `owner/name`, lower case, for example `modes/has-variant`, `books/contains`;
- `label` and `inverse_label` (display only: one direction is stored, both directions are queried);
- `subject_types`, `object_types`: lists of entity type slugs (empty list = any registered type);
- `max_objects_per_subject`, `max_subjects_per_object`: `null` (many) or a positive integer; enforced in slice 102;
- `allow_repeats`: whether several statements may share the same *(subject, predicate, object)* when their qualifiers differ (default `false`);
- `ordered`: whether the statements of a *(subject, predicate)* carry a position;
- `symmetric`: whether *(a, p, b)* implies *(b, p, a)*;
- `on_delete`: `remove` (default) or `keep` when the subject or object disappears;
- `iri`: optional IRI of the predicate (preparation for RDF export);
- `qualifiers`: list of `QualifierDefinition` (`name`, `type`, `multiple`, `required`, allowed `values` for `enum`).

`PredicateRegistry`: `register()`, `has()`, `get()`, `all()`. Registration validates the definition against the entity and qualifier type registries (unknown type, duplicate slug, duplicate qualifier name, invalid IRI, contradictory options) and throws a descriptive `InvalidArgumentException`.

### Registration hooks

The registries are filled through actions named after the module: `triples_register_entity_types`, `triples_register_qualifier_types`, `triples_register_predicates`. Each action runs once, when the registry is first used, with the registry as argument. Modules add their callbacks in `boot()`.

## Out of scope

Storage and schema (slice 101), statements and their enforcement of cardinality, repeats, symmetry and order (102), cascade on deletion (103), admin screen (104), JSON export and import (105), REST (later), RDF export (later). No user-facing string: exception messages are developer messages in English, not translated.

## To confirm

1. **Predicate slugs are `owner/name`** (the owner is the module or the vendor that registers it), as an application of "names belong to the module". Alternative: bare names, with a risk of collision between modules and third parties.
2. **Cardinality** is expressed with the two limits above. Cases such as "one variant per mode for a given template" need the limit to apply per value of a qualifier; this is **not** in this slice: it is noted for slice 102, where the semantics of uniqueness with qualifiers is settled together with `allow_repeats`.
3. **`attachment` is its own entity type** (validated as an id; the check that the post is an attachment belongs to slice 102), instead of a `post` subtype.
4. **Text domain.** The rules say that the text domain belongs to the module, while WordPress loads one domain per plugin by default. Nothing is translated in this slice; the choice (a domain per module loaded by the plugin, or the plugin domain) is to be made with the first user-facing string, in slice 104.

## Tests (written first)

- `EntityRef`: parse and format, invalid forms, equality, ids containing colons (`ext:youtube:abc`, `template:theme//slug`).
- Entity types: built-ins accept and reject ids, duplicate registration, IRI resolver optional.
- Qualifier types: each built-in validates and normalizes; `enum` rejects values outside its list; datatype names.
- `PredicateDefinition`: every validation error (bad slug, unknown entity or qualifier type, duplicate qualifier, invalid IRI, limits that are not positive integers, `symmetric` with different subject and object types, `ordered` without a way to order), and a valid complete definition.
- `PredicateRegistry`: registration, duplicates, lookup, `all()` in registration order, the registration actions run once and only when needed.
- Architecture: the new code uses only the core and the `Triples` namespace (`ArchitectureTest`).

## Done when

PHPUnit, phpcs and `reuse lint` pass, the architecture test still passes, and this document and `docs/IA.md` describe the delivered classes.
