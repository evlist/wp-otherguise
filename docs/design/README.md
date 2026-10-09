<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Design notes

The reasoning behind the decisions of Otherguise: what was considered, what was decided and why, what is still open. Points are marked **Decided** (Eric accepted it), **Proposed** (suggested by Claude, not confirmed) or **Open**. The delivered state is in [`../IA.md`](../IA.md) and [`../slices/`](../slices/README.md); this document keeps the reasoning.

| Document | Subject |
|---|---|
| [triples.md](triples.md) | The `Triples` module: statements about statements, the registry, RDF import and export |
| [modes.md](modes.md) | The `Modes` module: modes and relations between templates, and the analysis of the current `?print` hack |
| [books.md](books.md) | The `Books` module: composing a book, the manual finishing chain, table of contents, index, PDF output |
| [media-helper.md](media-helper.md) | Integration with Media Helper |
| [usage-examples.md](usage-examples.md) | Usage examples of the planned API: templates, photos, modes, books |
| [packaging.md](packaging.md) | One plugin with three modules: reasons and the rules that keep a later split cheap |

## Goal

One body of content (a WordPress travel blog) published in several forms for several audiences: web, print, monthly photo books, a printed blog with videos. WordPress serves HTML and CSS media queries cannot change the *structure* of the content, hence the use of different templates per form. Today the choice is a hack selecting a `-print` template when the query string contains `?print`. The goal is to make this a proper, declared mechanism, and to help build books from the resulting PDFs.

## Three functional domains

**Decided** (Eric's framing): the project has three distinct domains, with one-way dependencies.

| # | Domain | Role | Depends on |
|---|---|---|---|
| 1 | Triples | Registry of predicates, storage and API for *(subject, predicate, object)* relations. Knows nothing about templates or books. | nothing |
| 2 | Multiple templates | Modes and relations between templates, selected through the query string. | 1 |
| 3 | Books | Compose a book from posts, assemble and export PDFs. | 1, 2 |

## Open questions

As of 2026-10-09 (the decided points are in the documents above).

- **Template selection hook.** Where to hook the choice of a template so that it covers block themes (files and templates stored in the database by the site editor) and classic themes: to verify in the WordPress source. See [modes.md](modes.md).
- **Media Helper.** Its attachment model and extension points, and whether the change belongs in Media Helper, in this plugin, or both. See [media-helper.md](media-helper.md).
- **Reading rules for modes and positions** on stored statements (no `mode` statement means all modes; a position on a mode statement overrides the one on the statement): to settle with the statements slice (103).
- **PDF output.** Which renderer backends to build first (the HTML and CSS book page printed from a browser is the baseline) and whether any lives in this project. See [books.md](books.md).
- **Index.** Kinds of terms, the tags to include, presentation. See [books.md](books.md).
- **Private repositories on the Gitea server**, if they are needed: requires allowing the domain in the network settings of the environment and a read-only token stored as a secret, never pasted in a chat.
- **Publication.** If the plugin is submitted to WordPress.org, check again that the slug `otherguise` is free (it was on 2026-10-09).

## Next steps

The slices are in [`../slices/README.md`](../slices/README.md): the storage of statements (102) is next, then the creation and reading of statements (103).
