<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 626 — Index: the designations, the observations and the assignments

Status: **coded on 2026-11-24** (989 PHPUnit tests, 10 of them for this slice, with a real database for the statements; the designations also tried on a **scratch WordPress** with a real taxonomy; **never on the blog**). Dependencies: 625 (the design, validated by Eric on 2026-11-24), 600 to 624. What follows: 627 (enrichment before creation), 628 (the screen and the vocabulary), 629 (purge, prune and reset in the new vocabulary). Context: [`625-index-topics-and-observations.md`](625-index-topics-and-observations.md).

## What was built

| Piece | What | Where |
|---|---|---|
| **Designation** | A term of the hidden flat taxonomy `index_designation`; its slug is the hash of its **key**; source, evidence, state and proposal are term meta | `Designations` (interface), `TermDesignations` (WordPress), `Otherguise_Test_Memory_Designations` (tests) |
| **Key of a designation** | The source and the strongest identity of the candidate (Wikidata, Wikipedia, OSM, term), else the **chain of its labels**; `hand\|topic:<id>` for a mention made by hand | `Designation\Keys` (a pure function, 180 characters at most) |
| **Observation** | The statement `(post, index/observes, designation)`, qualified by `index/source` (the leaf), `index/strength`, `index/via` and `index/visible` | `Observations` |
| **Assignment** | The statement `(designation, index/designates, topic)`, qualified by `index/by` (`system` or `hand`) | `Observations::assign()`, `assignment()`, `designations_of_topic()` |
| **The reading** | For each candidate: the designation is found by its key or created; **an assignment made by hand is kept and the resolver is not asked**; otherwise the resolver proposes a topic and the assignment follows it (state `assigned`, or `ambiguous`); the observations are brought in line with the post | `Indexer::designate()` |
| **The assignment by a person** | One or many designations, or **all those of a topic** (its keys then follow them), to a topic; a preview first; a refusal across kinds that exclude each other unless forced; **the mentions of the posts that observe them follow at once, with no reading** | `Designation\Assigner`, `Mentions::derive_post()` |
| **Cleaning** | Deletes the designations that the system proposed and that no post observes; **a designation that a person assigned stays** | `Designation\Cleaner` |
| **The mentions made by hand** | A designation `hand` assigned by hand, one observation each; `add_by_hand` and `remove_by_hand` keep it; the conversion of the old ones is `index designations migrate` | `Mentions`, `Designation\Migration` |
| **The predicates** | `index/observes`, `index/designates`, `index/by`; the qualifiers `source`, `strength`, `via`, `visible` now qualify both the mentions and the observations; the registration moved out of `Module.php` | `Predicates` |

**Commands** (`wp otherguise index designations`): `list [--topic=] [--source=] [--state=] [--unobserved] [--limit=]`, `assign --topic=<id> (--designation=<ids> | --from-topic=<id>) [--apply] [--force]`, `clean [--everything] [--apply]`, `migrate [--apply]`, **`measure`** (the numbers and the time of the operations of the thresholds). **`wp otherguise index reset`** now also deletes the designations that the system proposed (all of them with `--everything`).

## What is *not* as slice 625 said (and why)

1. **One observation per designation and per post, not per occurrence.** Triples keeps **one statement per (subject, predicate, object)**: a link seen twice in a post, or thirty photographs of one commune, are **one observation whose `via` has several values** (one per file). The number of photographs of a place in a post is the number of its `via` values, as slice 623 wanted; **the position of each occurrence is not kept** (the page of each photograph is a later step, as slice 623 noted).
2. **The mentions are still stored; they are not a virtual facade.** The mentions of a post are **written in the same pass as the observations** (from the same candidates), and **made again from the observations** when a person assigns a designation (`Mentions::derive_post`). So the **builder, the books and every command that reads mentions change nothing**, and the designations are **added** to the module instead of replacing its core in one step. The cost: **assigning a designation rewrites the mentions of the posts that observe it** (hundreds for a large designation), not one statement. If the measure says it matters, the facade of slice 625 becomes the next step.
3. **A mention made before the model has no designation** until its post is read again (a source) or `index designations migrate` is run (by hand). Reading a post replaces the mentions of the leaf with the new ones, so **the index converts post by post as the pages are read**, and `index sync start --from-scratch` converts all.
4. **No exception on an observation yet** (assigning *one* observation elsewhere), **no state `pending`** (it comes with the enrichment before creation, slice 627), **no screen** (slice 628): the assignment is made by the command.
5. **The ambiguous candidates still make a placeholder topic** (`status: candidate`, a marker key): slice 629 removes them. The designation, itself, is in state `ambiguous`.

## Rules kept from slice 625

A reading **never changes an assignment made by a person**. A designation that a person assigned **stays without observation** (`clean` leaves it; `clean --everything` and `reset --everything` delete it). The topic of a decision is **held**: the cleaning of the topics that nobody mentions (`prune`, `reset`) does not delete a topic that a decision of a person points at. **No URL, no file name and no precise coordinate** is kept in the evidence (slice 606).

## Tests

`IndexDesignationsDbTest` (a real database for the statements, topics and designations in memory): the key of a designation; **one designation, many observations** (two posts, a link twice, reading again changes nothing); **an assignment by hand is kept** by a reading and the resolver is not asked; dropping a source and cleaning; a move of topic assigns the designations; the mention made by hand; **the assigner** (preview, kind conflict, the mentions follow, a reading does not undo it); **the migration** of the mentions made by hand (run twice); the commands; **the reset and the designations**. `IndexPruneDbTest` and `IndexScreenAndModuleTest` follow the new messages and the new taxonomy. **Tried on a scratch WordPress** (a script of the session, not a test of the repository): the taxonomy, the creation and the lookup of a designation, the assignment statements on real terms, the mentions that follow an assignment, a reading that does not undo it, the drop of a source and the cleaning.

## To do on the blog (never tried)

1. `gpull`, then **`wp otherguise index sync start --from-scratch --foreground`**: this makes the designations and the observations of every post.
2. **`wp otherguise index designations measure`**: the numbers (designations, observations) and the time of the operations. **Send the output**: it decides whether Triples is enough (the thresholds of slice 625). The index of the largest section is timed by `time wp otherguise index build --section=<id>`.
3. `wp otherguise index designations list --limit=30`, then try **`assign`** (a preview first) on a double that you know.

## Open

The thresholds are **not met or missed yet**: nothing was measured on the blog. The cost of `derive_post` for a designation seen in hundreds of posts is **an estimate**.
