<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

Moved here from `evlist/shared-room` on 2026-10-09. Points are marked **Decided** (Eric accepted it), **Proposed** (suggested by Claude, not confirmed) or **Open**. The delivered state is in [`../IA.md`](../IA.md) and [`../slices/`](../slices/README.md); this document keeps the reasoning.

# 1. Triples

Eric's idea: the notion of a triple goes beyond templates and can describe other relations, for example a more general link than attachments between posts and media, or between posts themselves.

**Proposed** model:

- A **triple** is *(subject, predicate, object)* with an optional position (for ordering) and optional metadata.
- Subjects and objects are **typed identifiers**: `post:123`, `term:45`, `user:7`, `template:theme//slug`, `attachment:88`, `ext:youtube:ID` (an external resource such as a video).
- A **predicate registry**, declared like a post type: slug, label and inverse label, allowed subject and object types, cardinality (one or many), ordered or not, symmetric or not, behavior when a subject or object is deleted.
- **Storage** in a dedicated table rather than post meta, with indexes on *(subject, predicate)* and *(object, predicate)* so that lookups work in both directions, plus object caching.
- Later: REST API, `WP_Query` integration, an editor panel in the block editor, JSON export (JSON-LD is an option).

Examples of what it covers:

| Need | Triple |
|---|---|
| Print version of a template | `(template:foo, has-variant, template:bar)` qualified by `mode: print` |
| Photo illustrating a post (without the single-parent limit of `post_parent`) | `(post:12, illustrated-by, attachment:88)` |
| Video of a post | `(post:12, has-video, ext:youtube:abc123)` |
| Trip stages | `(post:12, next, post:13)` or `(post:12, part-of, post:2)` |
| Content of a book | `(post:book-2026, contains, post:12)` with a position |

## Reification: statements about statements

**Decided**, revised on 2026-10-09 by Eric (this replaces an earlier decision: qualifiers in a second table, with a fingerprint, a flag for repeated statements and a position column).

Eric's example: a photo attached to a post, where the attachment must say whether it concerns the web presentation, the print presentation, or both, and at which rank. That is an n-ary relation, so a plain triple is not enough. Options considered: one predicate per combination (unmanageable), quads with a context column (covers the mode but not the position), qualifiers stored apart from the triple, and **statements about statements**, which was chosen.

- Every statement has its own identity (its `id`), and `statement:ID` is a valid subject or object. **A qualification is just another statement** whose subject is the statement qualified. The unit is the triple; a statement stays the same whatever statements are later added about it.

```
statement:41: (post:12, illustrated-by, attachment:88)
statement:42: (statement:41, mode, mode:web)
statement:43: (statement:41, mode, mode:print)
statement:44: (statement:41, position, 5)
statement:45: (statement:43, position, 1)
```

- Here the photo is in the web and print versions; its rank is 5 for every mode (44), except in print where it is 1 (45): a statement about the print qualification. No splitting, merging or renumbering is ever needed to give a mode its own rank.
- **Reading rule** (decided on 2026-10-09, detailed in [slice 103](../slices/103-triples-statements.md)): **a statement with no statement of the scope predicate (here `mode`) belongs to no scope: on a site that uses modes it is shown in no mode.** Safe by default: nothing becomes visible by deleting something, and a mode declared later does not include old statements by surprise. Showing a statement in every mode without naming them is not supported; a specific predicate could be added if it is ever needed. Without a scope the lists are not filtered (a site that does not use modes). The order is computed on all the statements of the subject and predicate (natural order from the consumer, then the global pins), then filtered by scope, so that without any scope pin every mode shows the same order and only drops some items; a position on a mode statement is a scope pin: it takes the item out and puts it back at that rank in that mode only, and overrides the position on the statement itself.
- **One table.** Unique index on the whole triple: (subject type, subject, predicate, object type, object). A triple exists at most once; the index must include the object, otherwise statements 42 and 43 would clash.
- **Objects are entities or typed literals** (`5`, a string, a boolean). `mode:web` is an entity of type `mode`, registered by the Modes module, so that the validity of a mode is checked by its entity type. The type `statement` is an entity type of the Triples module.
- **A qualifier is a predicate** of the same registry, for example `modes/mode` or `triples/position`. A predicate declares which predicates may qualify its statements (`illustrated-by` accepts `modes/mode` and `triples/position`; `modes/mode` accepts `triples/position`), and the existing limits express "at most one position".
- **Repeated facts** need nothing special. "Worked at X from 2010 to 2012, then from 2015 to 2018" is `(1: A, worked-at, X)`, `(2: 1, from, "2010 to 2012")`, `(3: 1, from, "2015 to 2018")`: same subject and predicate, different objects, so the unique index accepts both. If each period has its own role, the role qualifies the statement of the period: `(2, role, engineer)`, `(3, role, manager)`.
- **Limits.** A qualifier has a single object: a structured value needs a typed literal (an interval) or an anonymous node (not planned). A literal string is limited to 191 bytes, which suits a mode, a rank or a short caption, not a long text.
- **Consequences.** Deleting a statement deletes, recursively, the statements about it (the same mechanism as the cleanup when a post or a media item is deleted). Reading a photo with its modes and ranks needs joins, which the indexes support and an object cache will absorb. This is also RDF reification with an identifier per triple, so the export is direct.

Not planned: named graphs, inference.

Risk: a generic relation engine can absorb all the development time. **Proposed** mitigation: deliver first only the two uses that serve the books (template modes and the ordered content of a book) behind a clean API; add media and post-to-post relations as real needs appear. The "Posts 2 Posts" plugin did something similar for posts and is no longer maintained, which shows both the need and the effort.


# RDF import and export

**Proposed**, **not a priority**. Eric's motivation: partly nostalgia (he worked on RDF in the 2000s) and, more seriously, the possibility of using semantic web tools if the need arises.

Why it fits:

- The core model is already a set of *(subject, predicate, object)* statements. Typed identifiers map to IRIs, and predicates of the registry can carry an optional IRI.
- Statements about statements map to RDF through classic reification (a statement identifier as subject), or RDF-star / RDF 1.2 annotations (more elegant, less well supported by tools; the current state of PHP library support was not checked).
- Serializations: **Turtle** for humans and diffs (N3 is a superset with rules, which are not needed), **N-Triples** for streaming, **JSON-LD** as the most natural for WordPress (it is JSON, close to the REST API, and needs no heavy parser to produce).

What it would bring: no lock-in, existing tools (SPARQL, SHACL, visualization), bulk editing of relations as text under version control, and links to external authorities (Wikidata, GeoNames for places, taxonomies for species) that would also give authority to the index and allow `schema.org` JSON-LD in pages.

Costs and pitfalls:

- **Stable IRIs.** Eric's remark: only media and posts have natural URIs. Public terms do have archive URLs, but they change with slugs and the permalink structure, and templates have none. A site-controlled IRI base independent of permalinks is needed, with a resolver per entity type.
- **Import is much harder than export.** A Turtle serializer is about a hundred lines; import needs a parser (a dependency, whereas the plugin has none), blank nodes, typed literals, mapping IRIs back to local identifiers, validation and size limits for untrusted input.
- **Out of scope:** an internal RDF store, a SPARQL endpoint, inference.

Direction:

1. JSON stays the main exchange format (exact round trip, statements about statements included), as decided.
2. Later, add a JSON-LD export, then Turtle, as a function of the `Triples` module, not a new module.
3. Consider RDF import only if a real need appears (for example seeding places from Wikidata).
4. Prepare the ground now at almost no cost: an optional IRI per predicate, an IRI resolver interface per entity type, stable statement identifiers, and typed literals (string, integer, boolean) so that they map to RDF datatypes.

# Prior art and reuse

Checked on 2026-10-09, after the question "are we reinventing things?". **Decided**: no dependency for now; reuse the vocabulary, and a library for RDF import and export if and when that is built.

- **The W3C "RDF API"** (<https://www.w3.org/TR/rdf-api/>) is a Working Group Note of 5 July 2012, unfinished, with no known implementation: a browser-oriented API (projections of one subject, templates, a parser callback). It defines no triple, graph, literal or store, and nothing about persistence or validation. Nothing to reuse.
- **The data model that matters today** is RDF 1.2 (triple terms, which is our "statement about a statement", and the annotation syntax), and, for APIs, the *RDF/JS* style of interfaces (terms, quads, a dataset with `add`, `delete`, `has` and `match( subject, predicate, object )` where a missing part is a wildcard; this is from memory and was not re-checked). One concept is worth borrowing cheaply: **a single `match()` read with wildcards**, which `objects_of` and `subjects_of` become special cases of (slice 103).
- **PHP libraries** found: [`pietercolpaert/hardf`](https://github.com/pietercolpaert/hardf) (MIT; parses Turtle, TriG, N-Triples and N-Quads, writes them with streaming output, supports the RDF 1.2 features above; triples are plain associative arrays; "PHP 7.1+"; no release listed on the page, so the version and the maintenance have to be checked on Packagist before adopting it); the [`sweetrdf`](https://github.com/sweetrdf/rdfInterface) family (`rdfInterface` interfaces, `quickRdf` implementation, `quickRdfIo` parsers and serializers, a SPARQL client "in early development"; MIT; PHP requirement and releases not shown); [EasyRdf](https://packagist.org/packages/easyrdf) (old, a community fork keeps it running on PHP 8; Wikidata keeps a trimmed copy); ARC2 (its current line reportedly needs PHP 8.4, above the 8.1 of this plugin, and has its own MySQL triple store). These give in-memory terms, parsers and serializers, and some SPARQL. **None gives** what the plugin needs: a table integrated with the WordPress lifecycle, types checked against WordPress objects, a registry of predicates with limits and qualification rules, the reading rule for scopes and positions.
- **WordPress plugins** that publish RDF exist ([wp-linked-data](https://wordpress.org/plugins/wp-linked-data/), [LH RDF](https://wordpress.org/plugins/lh-rdf), [wp-data-triplify](https://cdn.jsdelivr.net/wp/data-triplify/trunk/README.md), the SIOC family), but they expose existing data as RDF; none manages relations, and all look unmaintained (LH RDF, the one that maps Posts 2 Posts relations, has a changelog from 2015).
- **Where we do overlap with RDF**, and what to do about it: the predicate registry is a small schema language (domain and range = `subject_types` and `object_types`, `max_*` = SHACL `maxCount`, `symmetric` = `owl:SymmetricProperty`, the inverse label = `owl:inverseOf`); the optional IRI per predicate links it to real vocabularies and the registry could be exported as RDFS or SHACL later. We keep it minimal and do not adopt a schema language, whose validators are heavy and not available in PHP as far as we know.
- **Dependencies in a WordPress plugin** have a price: two plugins bundling different versions of the same library clash unless the code is prefixed with a tool such as PHP-Scoper. A library is therefore adopted for the RDF export and import only, behind an adapter (statement to quad through the IRI resolvers), when that feature is built; the core stays free of dependencies.

