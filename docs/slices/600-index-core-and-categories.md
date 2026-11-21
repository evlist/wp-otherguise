<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 600 — Index: the core, and the categories and the tags as the first sources

Status: **proposed (2026-11-21), not coded.** Dependencies: slice 000 (the module loader), the module `triples`. Context: [`../design/index.md`](../design/index.md) (read it first: the model, the decisions of Eric, what was read in the other plugins). Eric's order of work (2026-11-21): **start with what the blog already holds, the categories, then the tags, then (slice 601 and after) the links to Wikipedia and the names of birds; the photographs are for later.**

Points are marked **Decided** (said by Eric) or **Proposed** (to confirm).

## What it does

A new module `index` (`Otherguise\Index\`, `plugin/modules/index/src/`), **disabled by default** (**Proposed**: the marker `DisabledByDefault`, as for the module Wiki; enabled by the switch of the overview), which depends on `triples`. It gives the **entries**, the **mentions**, the **resolver** with its **aliases**, and **two sources read from the database**: the categories and the tags that **have a kind**. No page is read, no network is used, nothing is printed: the output (web blocks, the index of a book) comes in later slices.

### The names it owns (Proposed)

| What | Name |
|---|---|
| module | `index`; capability filter `index_capability` (default `edit_others_posts`) |
| entries | taxonomy **`index_entry`**: hierarchical, **not public**, no rewrite, `show_ui` false (the screens are the module's own) |
| data of an entry (term meta, protected) | `_index_kind`, `_index_kind_set_by_hand`, `_index_sort`, `_index_aliases` (a list of keys, JSON), `_index_ids` (identifiers: `wikidata`, `osm`, `geonames`, JSON), `_index_status` (`approved`, `candidate`, `refused`) |
| data of a category or a tag (term meta) | `_index_kind` (the kind **of the term**: empty = not indexed) |
| mentions | the predicate **`index/mentions`** of Triples (a post, an entry term) with the qualifiers `index/source`, `index/strength`, `index/role`, `index/anchor` (all `qualifies` `index/mentions`; **to check**: that a qualifier predicate may hold several values on one statement, for several sources) |
| options | `index_kinds`, `index_sources` (which sources are on) |
| WP-CLI | `wp otherguise index rebuild|kind|entries|show|link` |
| screen | **Otherguise → Index** (declared to the overview, `otherguise_admin_screens`) |
| text domain | `otherguise` (all the plugin) |

### The kinds

A short list, **translatable and editable** (`index_kinds`, and the filter `index_kinds`): `place`, `species`, `route`, `person`, `work`, `product`, `event`, `other`. A kind has a label and a CSS class for the typography. The list is only a starting point: **Decided (Eric)** that the kinds are few and editable.

### An entry

A term of `index_entry`. Its **parent** is the broader entry (a place under its municipality). Its **identity** is the pair (parent, label key) where the label key is the label with the accents and the case folded (`Normalizer` of `intl` when it is there, `remove_accents()` otherwise; **to check** the availability of `intl` on the server of the blog) so that two homonyms with two parents stay two entries. An entry has **keys** (its identifiers, its label key, the keys local to a source such as `term:post_tag:123`) kept in `_index_aliases`: **the resolver looks a candidate up among the keys of the entries**.

### The resolver (`Index\Resolver`)

A **candidate** (label, kind, parent, keys, strength, source) is resolved by its keys: **one entry**: the mention is made; **none**: an entry is created (`approved` for a source that is sure, which is the case of the two sources of this slice); **several entries**, or a label whose entry has another identifier, or a conflict of kind: the candidate is **ambiguous**, recorded as `candidate` with its keys and **not mentioned** until it is linked. **Linking is adding a key to the aliases of an entry** (`wp otherguise index link <key> <entry>`), which is permanent: the source does not ask again. In this slice the ambiguous cases are **listed by the command** (`index entries --status=candidate`); the screen of the queue comes later.

### The sources of this slice

- **`categories`** and **`tags`** (`Index\Source\Terms`, a harvester **of the database**, no page): for a post, each of its terms of the taxonomy that has a **kind** gives a candidate. Its **key** is `term:<taxonomy>:<id>` (stable when the term is renamed; the label of the entry follows the name of the term); its **parent**, for a hierarchical taxonomy, is the entry of the parent category **when that one has a kind too**, flat otherwise. **A term with no kind stays out of the index** (Decided, `books.md`: *repos*, *étape*, *cyclotourisme*). The **mention** has the source `categories` or `tags`, the strength `passing` by default (**Proposed**).
- **Setting the kinds** (Decided by Eric: in bulk): a screen **Otherguise → Index → Terms** that lists the categories and the tags with their number of posts and a **kind selector** per row (a bulk action "set the kind"), saved through `admin-post.php` with a nonce and the capability; and `wp otherguise index kind <taxonomy> <term> <kind>`. **Eric's blog has several thousand tags: the list must be paged and filterable** (by name, by "no kind yet", by number of posts).

### What is kept up to date, and when

- **The mentions of a source are brought in line with the post** (`Index\Mentions::sync( $post_id, $source, $candidates )`): the missing ones are added, the ones of **that source** that no longer apply are removed, **the ones of another source or made by hand are not touched**. Nothing is written when nothing changed.
- **When**: on `wp_after_insert_post` (and when the terms of a post change), **only for a published post** (a draft has no public mention), and by `wp otherguise index rebuild [--source=] [--post=] [--dry-run]`, which reads every published post, reports (`entries created`, `mentions added`, `removed`, `ambiguous`) and can be run twice. A change of the **kind of a term** brings the mentions of its posts in line (a job in the background when the term has many posts: **to check** the volume).

### Reading (the API for the later slices)

`Index\Entries::all( $filters )` (by kind, by parent, by source), `::of_post( $post_id )`, and `::mentions_of( $entry, $rolled_up )` where **`rolled_up` adds the mentions of the descendants** (computed when read, **not stored twice**: Decided). The entries are **sorted according to the language** (`Collator` when `intl` is there; **a fallback to be written and tested**). **Nothing of the visibility of a post is stored**: a reader filters the posts the visitor may see, as the wiki does for "Cited by".

### Uninstall, capability, nonce

`uninstall` deletes the options of the module; **the terms and the statements stay with their tables** (Triples keeps or deletes its data by its own setting, `delete_data_on_uninstall`) and can be rebuilt. Every screen and command **checks the capability**, every form carries a **nonce**, every string is translatable (rules of the repository).

## Tests (planned)

PHPUnit **with a database** (the real Triples): the resolver (one entry, none, several, the aliases, the identity with the parent, the folding of the accents), `Mentions::sync` (added, removed, another source untouched, nothing written when unchanged), the source `Terms` (a term with a kind, with none, a renamed term, a hierarchical category, a draft), `rebuild` and its report, `--dry-run`, the rolled-up mentions; the module **disabled by default with none of its hooks registered** (the test that the wiki got); the kinds screen (capability, nonce, paging, the bulk action) **without WordPress**, as the other screens; `ArchitectureTest` (the module depends on Triples only).

## Verification (planned)

On a scratch WordPress with a copy of **a few hundred categories and tags of the blog** (names only, no personal data): the kinds set in bulk, `rebuild` on a few hundred posts, the time it takes, the rolled-up entry of a category. **Never tried on the real blog.**

## Not done (later slices)

The reading of a page and the links to Wikipedia (**601**); the web blocks and the entry pages; the integration into Books; the queue **screen**; the Wikidata client and the automatic kind; the geolocation and the photographs; the marks `data-index` and their button.

## To check

Several values of a qualifier on one statement in Triples; `intl`; the taxonomy of entries with term meta and a hierarchy of tens of thousands of terms (the cost of a lookup by key: **a table of keys of its own may be needed** if the term meta is too slow; to measure before choosing).
