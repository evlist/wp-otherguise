<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# A "Wiki" module: the functions of a wiki, in WordPress

Status: **open**. Nothing here is designed, scheduled or coded. Points are marked **Decided** (Eric said so), **Proposed** (Claude's reading, not confirmed) or **Open**. The idea comes from the point 4 of [`ideas.md`](https://github.com/evlist/shared-room/blob/main/docs/ideas.md) of `shared-room` (SelfPedia under WordPress); **the analysis goes on here**, in the repository of the plugin, since what is built would be a module of Otherguise.

**The moratorium (Decided, Eric, 2026-10-31):** nothing starts before the ten pages of the autobiography of the SelfPedia are written (content first, no architecture work before). Until then the pages are written in a portable way: headings, links, categories, `<ref>` notes, no template with parameters, no parser function.

## What is wanted

**Decided (Eric, 2026-11-01):** it is **not** about rebuilding a wiki in WordPress; it is about implementing the **functions that Eric has used so far in MediaWiki**, which are three:

1. the **namespaces**, as **kinds of sections** of the site: the encyclopaedic parts, the opinions, the biographical part, the archive, each with a slightly different presentation (not the namespaces of MediaWiki as they are managed there);
2. **permalinks like those of a wiki**, **without necessarily following the conventions of MediaWiki**;
3. **linking a page by its name**, with the **red links** (a page to write), the list of the **wanted pages** (the `Special:WantedPages` that Eric uses for the autobiography) and the **incoming links** ("which pages cite this one").

Also decided on 2026-11-05: **the multilingual is left out of the first step** (the convention of the root and the leaves, `/fr` and `/en`, is kept in mind but not designed). Reasons to do it at all, from Eric: reuse what exists (the media panel of Media Helper and Otherguise Books, to publish the autobiography both as a linear book and on the site), one platform to manage, and more ease of use than MediaWiki.

## A space is not a mode

**Proposed (Claude, agreed in the conversation of 2026-11-01):** a **mode** (`?mode=print`) is chosen **by the request**: the same page under another form. A **space** is a **property of the content**: it decides the default presentation (the template, the style, a visible status label) and the address. They are independent and combine: a page of the space "Biography" can be shown in the mode `print`.

## The same title in several spaces

**Decided (Eric, 2026-11-05):** one of the interests of the spaces is to treat **the same subject from several angles** (encyclopaedic, subjective, autobiographical...). The same title therefore exists in several spaces: **the identity of a page is the pair (space, title)**, not the title. What follows:

- the **unicity is per space**, not global;
- the **link by name** has to name the space, or have a default one (**Open**: the current space, or a general one?): `Biography:Paris` for the explicit form, `Paris` alone resolved in the space of the page that holds the link;
- **Proposed:** a band "Other angles: Encyclopaedia · Opinion · Biography" at the top of a page, made by looking up the same title in the other spaces (the index of the titles is enough); for two different titles on one subject ("Paris" and "Paris (ville)") an explicit relation "same subject" would be needed (**Open**);
- **Proposed, optional:** the address with the title alone (`/paris`) lists the angles that exist, as a disambiguation page does;
- **moving a page from a space to another** can land on a page of the same title in the target space: the move must be refused, or ask for another title; WordPress alone renames in silence (see below);
- the search results show the space next to the title.

## Permalinks and the spaces

### What was checked (2026-11-05, a scratch WordPress 7.1.3, requests in PHP with the plain addresses)

- Two child pages with the same title under two different parents live together: `biography/paris` and `encyclopedia/paris`; a second "Paris" under the same parent becomes `paris-2`.
- **The accents are stripped from a slug**: "Ansò" and "Anso" both give `anso` (and the blog has the titles "Sant Llorenç de Morunys" and "Sant Llorenc de Morunys": the case is not theoretical).
- **A colon and a slash are stripped**: "Biography:1958-1968/fr" gives `biography1958-1968-fr`. The mapping from a MediaWiki address to a WordPress one has to be written, not inferred.
- **Moving a page under another parent can change its slug** without warning: "Paris" became `paris-3` where `paris` and `paris-2` existed.
- With a filter that **keeps** the accents in the slug, "Sant Llorenç de Morunys" gave `sant-llorenç-de-morunys` and "Sant Llorenc de Morunys" under the same parent gave `sant-llorenc-de-morunys-2`: **the database ignores the accents** when WordPress checks that a slug is free (collation `utf8mb4_unicode_520_ci`, the default of WordPress; the collation of the production database was not checked). Keeping the accents in the slugs does not remove the `-2`.

**Not checked**: the behaviour of WordPress with pretty permalinks (the redirect of an old address after a change of parent or of slug, `redirect_guess_404_permalink`), a hierarchical type with a prefix, the redirects from the old addresses of MediaWiki.

### Three ways to tie the space to the address

| | Way | Reading |
|---|---|---|
| A | **One content type per space**, each with its prefix (`/biography/...`) | Native templates per type (and `modes_default_templates`, slice 209), rights per type; the rewrite rules are declared again when a space is added; a move between spaces changes the type, hence the address. |
| B | **One taxonomy "space"** on a flat type | **Ruled out** by the same title in several spaces: the slugs of a flat type are unique in the whole type, the second "Paris" would become `paris-2`. |
| C | **The space is the root page** of one hierarchical type; the first segment of the address is the space | One type, nothing to declare again; the space carries its settings (template, style, label) and its descendants inherit them; the unicity per parent gives the unicity per space; moving a page is changing its parent. A root page is a post with a flag, not an identity registered in code. |

**Proposed (Claude):** C is the most natural for what Eric describes ("a kind of section"). A is not to be dismissed before a test.

### How much to depend on the slugs of WordPress

**Open (Eric, 2026-11-05: "are we obliged to use the slugs of WordPress in the URLs?")**. No. Three levels:

1. **The slugs of WordPress as they are.** Simple, compatible with every plugin. Two titles that differ only by an accent or the case end with a `-2`.
2. **The slugs of WordPress, with an index of the exact titles for the resolution of the names.** The addresses stay those of WordPress; the link by name (`Biography:Paris`), the red links and the wanted pages go through an index (space, exact title) => page, which tells an accent from another. A command lists the titles that clash into a `-2`. **Proposed (Claude) as the first step.**
3. **An address of our own**: computed from the space and the exact title, with our routing (a rewrite rule, a path resolved through the index, a technical `post_name`). It reproduces the old addresses (`Biography:1958-1968/fr`) literally, accents and case included; a move between spaces changes only the key, and the old keys give the redirects. The cost: the routing, the canonical redirect, the sitemaps and the SEO plugins, `get_page_by_path` that no longer works. The index of the level 2 is the same, so the step from 2 to 3 does not redo the pages.

What the address has to guarantee is **Open**: the same name always gives the same address (level 3), or a stable address once the page is created (level 2).

### Other points of the addresses (**Open**)

- A **prefix** (`/wiki/biography/...`) or the **root** (`/biography/...`): a prefix separates the module from the rest of the blog; at the root the pages mix with the ordinary pages of the site.
- The **old addresses** of the SelfPedia (`Biography:1958-1968/fr`) are redirected by a rule, with a table that a move between spaces also feeds.
- The **modes** stay a query argument: `/biography/1958-1968/?mode=print`.
- A **slash in a title** is a subpage in MediaWiki; in the hierarchy of C it is a child.

## Linking a page by its name

**Proposed (Claude):**

- **A shortcode** (or a block with a search in the editor, later) resolved **when the page is rendered**, not when it is saved: renaming an address does not break the links, which an ordinary link of WordPress would. The form is the one Eric knows: `[page "Biography:1958-1968"]`.
- **The red links and the list of the wanted pages**: a title that does not exist is shown in red, and an **index of the links** gives the list of the missing pages.
- **The incoming links**: a relation **"cites"** with the inverse **"cited by"** in the module `triples`, kept up to date when a page is saved. The module already stores one direction and reads both (`subjects_of`, the `inverse_label` of the predicates, as the relations of the sections and of the book projects do); the statement is made of the identifier of the pages, so it does not depend on the slugs.
- **In the printed book (Books)**: a link to an encyclopaedic page becomes a note or a reference; the pages of the merged section and the markers of slices 227 and 228 may help (**Open**, not analysed).

## Where it lives

**Proposed (Claude):** **a module of Otherguise**, the sixth (next to `triples`, `modes`, `media`, `books`, `access`), with clear boundaries (its tests, its settings, its uninstall) so that it can be lifted out later; it would depend on `triples` (the relation "cites") and `modes`, and use `access` for the drafts. The rules of [`packaging.md`](packaging.md) and of `docs/IA.md` ("Modules": one-way dependencies checked by `ArchitectureTest`, names that belong to the module) apply. Alternatively a plugin "Other Wiki"; the cost of a module is that Otherguise grows.

**A theme** that looks like a wiki (**Proposed**): with a one-way contract as for the print pages (CSS variables with a fallback, the plugin gives the data and the blocks, the theme dresses them), perhaps a variation of the child theme of Twenty Twenty-Five so that it keeps the print modes. Take the **grammar** of MediaWiki (a side navigation, the tabs read / edit / history, a table of contents, hatnotes, the footer of categories, the last-modified line) and **not a copy**: the logos and marks of Wikimedia are not reusable, the compatibility of the licence of the skins with the code of Eric is to check before reusing files, and the SelfPedia shows the **status of each piece of information** (fact, source, interpretation): a look identical to Wikipedia would suggest a neutrality that it does not claim.

## Slices that could come

**Proposed, not decided; the order depends on what the ten pages show.** The numbers are not taken (the 500s are free).

1. The **spaces**: the root pages with their settings; the address; the template and the style by space.
2. The **link by name** with the red links; the index (space, exact title).
3. The **index of the links**, the incoming links, the wanted pages; the band "other angles".
4. The **old addresses** and the **import** from MediaWiki (Pandoc, a script, a redirect map).
5. A tool to **move a page** from a space to another, with the checks above.
6. The wiki theme as a style variation.
7. The multilingual, last.

## Open questions (the list)

- The address: slugs of WordPress, an index of exact titles, or an address of our own (above)? What does the address guarantee?
- A prefix or the root? One type per space (A) or the space as a root page (C)?
- The default space of a link by name: the current one, or a general one?
- How to link two pages of different titles on the same subject?
- The behaviour of the pretty permalinks and the redirects (to check).
- The collation of the production database.
- A bounded test of the import (not a plugin): convert the page `Biography:1958-1968/fr` with Pandoc on the testing site and see what is lost (quotations, notes, categories) and how the media panel inserts photos from Nextcloud there.
- A middle way for the second phase (the editing of the autobiography): the wiki stays the memory base and the source; WordPress and Otherguise Books are the editing layer where chosen pages are imported. The same principle as Otherguise: one source, several forms.

## What was checked, what was not

Only the facts of "What was checked" above (a scratch WordPress with the plain addresses, requests in PHP). **Never tried**: any code of the module (there is none), pretty permalinks, the import of the pages of the wiki, the behaviour on the production blog. Claude knows the wiki from the notes of `infra` and from the summary only, **not from reading its pages**; the size of the wiki (the number of pages) is not known.
