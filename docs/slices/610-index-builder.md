<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 610 — Index: the builder, and `wp otherguise index build`

Status: **coded (2026-11-21), on `main`; see "What was built, and what differs from the plan" at the end.** Dependencies: slices 600 (entries, mentions), 603 (kinds). Context: [`../design/index.md`](../design/index.md), "Consumers". The first slice of the **consumers** of the index: it turns the mentions of a set of posts into **the lines of an index** and prints them, before any layout. The block of a book (page numbers) and the web block are the next slices; they use the same builder.

## Decided (Eric, 2026-11-21)

- Start with **`wp otherguise index build --section=<id> --format=json|csv|html`**, which replaces the SQL queries of the first book.
- The block that will use it takes **`include` and `exclude`** attributes of kinds; **by default every kind** is in.
- **A flat index** at least at first; the classes are **one per kind** (slice 603); the block is **a block only** (no shortcode).

## Proposed

### The builder (`Index\Build\IndexBuilder`)

`build( array $post_ids, array $options ): IndexModel`. The posts are given **in the order of the book**: that is the order of the locators.

- For each post, its **mentions** (slice 600) give its entries. Only **approved** entries are kept (a candidate waiting for a person, a refused entry, stay out).
- **`include` / `exclude`** are lists of kinds; **a kind stands for its sub-kinds too** (slice 603). Empty `include` means every kind. `exclude` wins over `include`.
- **Flat**: one line per entry, whatever its depth; no roll-up of the mentions of the sub-entries into their parent (**not done**, the tree comes later). By default a line shows the broader entry in parentheses when there is one, so that a hamlet is not alone (`Les Arberons (Beaufort)`); the option `context=false` leaves it out.
- **A line** has the entry, its label, its kind, **its classes** (`index-kind-…` of the kind and of its ancestors), and its **locators**: the posts that mention it, in the order given, each **main** (bold in a book) or **passing**. An entry appears **once**.
- **Sorting follows the language**: `Collator` (intl) with the locale of the site when it is there, otherwise the folded label (no accents, lower case). **Not done**: an explicit sort key (`_index_sort`) and ignoring a leading article.
- **Letter groups**: the first letter of the folded label in capitals; digits and signs go under `#`.

### The output (`Index\Build\IndexRenderer`)

- **JSON**: the groups and their lines, with the classes and the locators (the post id and the strength).
- **CSV**: `letter, label, kind, posts, main` (the ids of the posts separated by spaces; `main` the ones that are main). What a spreadsheet needs, as the first book did.
- **HTML**: `div.index` > `section.index-group` > `h3.index-letter` + `ul.index-entries` > `li.index-entry.index-kind-…` with `span.index-label` and `span.index-locators`; the locators are **given by a function** (the consumer decides: a link to the post here; a page number in a book, a later slice).

### The command

`wp otherguise index build (--section=<id> | --posts=<id,id,…>) [--include=<kinds>] [--exclude=<kinds>] [--format=json|csv|html] [--no-context]`.

- **`--posts`**: the ids, in the order given.
- **`--section`**: the posts of a section of Books, flattened (a section inside a section is followed, depth 6, a cycle stops), only the **published** ones without a password, as the merge does. The Index module **does not know Books** (`ArchitectureTest`): it asks the filter **`index_section_posts( $ids, $section )`**, and an adapter in **Books** answers (`Books\Section\SectionPosts`). With no adapter the command says so.
- Reads and writes nothing; can be run twice.

## Tests (planned)

The builder with entries in memory and real mentions (kinds, sub-kinds, include/exclude, one line per entry, the order of the locators, main and passing, the context, the letters, sorting with and without `Collator`); the renderer (JSON, CSV with quotes and accents, HTML escaped); the command (the options, the errors); the adapter of Books (a section with a section inside, a draft, a password, a cycle).

## Not done

The tree (roll-up, sub-entries), the block of Books with page numbers (611), the web block, "see" and "see also", a sort key, ranges of pages, the passages (`index/anchor`).

## What was built, and what differs from the plan (2026-11-21)

Built as proposed: `Build\IndexBuilder`, `Build\IndexModel`, `Build\IndexRenderer` (data, CSV, HTML with a locator function), the command `wp otherguise index build (--section | --posts) [--include] [--exclude] [--format] [--no-context]`, and, in **Books**, the adapter that answers the filter `index_section_posts` (`Section\SectionPosts`, `Section\SectionPostsFilter`; the section and its sections inside, depth 6, a cycle stops, published posts without a password only).

What differs or was decided while coding:

- **The label of a line is the label of the entry, then the broader entry in parentheses** (`Les Arberons (Beaufort)`); **the letter is the one of the entry's own label** (L), not of the parenthesis.
- **Nothing is rolled up**: a country or a commune that no post mentions by itself has **no line** (the flat index of the first book). A line appears only for an entry that is **approved** and mentioned by a post of the list.
- **The sort is the folded label** (no accents, lower case) compared with `Collator` of the locale of the site when intl is there, `strcmp` otherwise (**the fallback is not tested with a real `Collator`**); the explicit sort key and the leading articles are **not done**.
- **The `html` locator of the command is a link to the post** (its title), bold when the mention is main; the book will give page numbers (slice 611).
- **The Books adapter is a small class of its own** (`SectionPostsFilter`) because the module of Books was at the limit of the length of a file.

### Verification

- **Under automated tests** (PHPUnit, 865 tests in the suite; the statements are real, the entries and the kinds in memory): one line per entry, the order of the locators, the candidate and the refused entries left out, `include` with sub-kinds, `exclude`, the classes with the ancestors, the context, the letters, a comparison that is given, the three outputs (quotes and accents in the CSV, escaping in the HTML), the command and its errors, and the adapter of Books (a section inside a section, a draft, a password, a cycle, the depth).
- **On a scratch WordPress 7.1.4**: three posts with categories and a tag that have kinds, a real Books section holding them, the filter gave the posts in order, and the builder printed the CSV and the HTML (a waterway found under `include=place`, its two classes, the accent in `É` filed under `E`). **WP-CLI was not run** (the command class is tested with doubles); **nothing was tried on the real blog**, with its sections of 184 units, nor with a `Collator` of a real locale.
