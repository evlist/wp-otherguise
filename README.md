<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# ⚠️ Otherguise (Work In Progress)

**Otherguise** is a WordPress plugin that lets a site present the same content in another guise: on the web, in print, as a book.

> [!NOTE]
> **On the name:** *otherguise* blends *otherwise* and *guise*. The same content, in another guise.

### 🎯 Objective

One body of content (a blog) published in several forms for several audiences. Today the form is chosen by a hack that selects a `-print` template when the query string contains `?print`; Otherguise replaces it with declared relations, and helps assemble books (table of contents, index, PDF) from posts. Background: [Techniques come and go, but ideas last](https://evlist.github.io/balisage-2026/) (Balisage 2026).

### 🧩 Modules

The plugin is a single plugin made of three modules with one-way dependencies, built so that it can be split into three plugins later:

| Module | Role | Depends on |
|---|---|---|
| `Triples` | Registry of predicates and storage of *(subject, predicate, object)* statements with identity and qualifiers. | nothing |
| `Modes` | Modes selected through the query string (`?mode=print`) and relations between templates. | `Triples` |
| `Books` | Composition of books, table of contents, index, PDF output. | `Triples`, `Modes` |

### 📂 Repository Structure

* `plugin/`: the distributable WordPress plugin folder.
* `plugin/includes/Core/`: the module loader and the autoloader.
* `plugin/modules/<module>/src/`: the code of each module, one namespace per module (`Otherguise\Triples\`, ...).
* `tests/phpunit/`: PHPUnit tests, including the architecture tests that enforce the dependency rules.
* `docs/IA.md`: architecture and current state, the handover note for contributors and AI assistants.
* `docs/slices/`: one document per slice of work, with an index.
* `scripts/`: build and generation scripts (none yet).

### 📚 Where the thinking lives

The reasoning behind the decisions is in [`docs/design/`](docs/design/README.md); the slices of work are in [`docs/slices/`](docs/slices/README.md). The context shared with Eric's other projects is in [`evlist/shared-room`](https://github.com/evlist/shared-room).

### 🔧 Local Activation (Development)

1. Copy or symlink the `plugin/` directory into your WordPress `wp-content/plugins/` directory.
2. Activate **Otherguise** in the WordPress admin Plugins screen.

### ✅ Validation

See "Validation" in [`docs/IA.md`](docs/IA.md).

---
*Otherguise — The same content in another guise.*
