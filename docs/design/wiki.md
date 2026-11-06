<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# A "Wiki" module: the functions of a wiki, in WordPress

Status: **open**. Nothing here is designed, scheduled or coded. Points are marked **Decided** (Eric said so), **Proposed** (Claude's reading, not confirmed) or **Open**. The idea comes from the point 4 of [`ideas.md`](https://github.com/evlist/shared-room/blob/main/docs/ideas.md) of `shared-room` (SelfPedia under WordPress); **the analysis goes on here**, in the repository of the plugin, since what is built would be a module of Otherguise.

**The moratorium (Decided, Eric, 2026-10-31; lifted by Eric on 2026-11-06):** nothing was to start before the ten pages of the autobiography of the SelfPedia were written (content first, no architecture work before). Eric lifted it on 2026-11-06 **to validate the feasibility of the migration**; the module is developed on the **branch `wiki`**, away from the development of the blog. The advice for the pages still stands: write them in a portable way (headings, links, categories, `<ref>` notes, no template with parameters, no parser function).

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
- the **link by name** has to name the space, or have a default one (**Open**, see "The unqualified names" below): `Biography:Paris` for the explicit form;
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
2. **The slugs of WordPress, with an index of the exact titles for the resolution of the names.** The addresses stay those of WordPress; the link by name (`Biography:Paris`), the red links and the wanted pages go through an index (space, exact title) => page, which tells an accent from another. A command lists the titles that clash into a `-2`. **Proposed (Claude) as the first step, before the arguments listed under the level 3 below; they make the level 3 from the start worth considering.**
3. **An address of our own**: computed from the space and the exact title, with our routing (a rewrite rule, a path resolved through the index, a technical `post_name`). It reproduces the old addresses (`Biography:1958-1968/fr`) literally, accents and case included; a move between spaces changes only the key, and the old keys give the redirects. The cost: the routing, the canonical redirect, the sitemaps and the SEO plugins, `get_page_by_path` that no longer works. The index of the level 2 is the same, so the step from 2 to 3 does not redo the pages.

**The arguments for the level 3 have grown** (**Eric leans to it, 2026-11-06**, not decided): (1) the old addresses of the SelfPedia reproduced as they are, and a link of the wikitext imported unchanged; (2) **no `-2`** for two titles that differ by an accent or the case, whatever the collation of the database; (3) **the 404 that proposes to create a page knows the exact title** (above), where a slug has lost it; (4) the **colon in the address**, the form of MediaWiki: a link imported from the wiki becomes an address of this site and **the address is the name**, so an ordinary link is a link by name (see "Linking a page by its name"); (5) a move between spaces changes a key, and the old keys give the redirects. Against: the routing (a rewrite rule, a path resolved through the index), the canonical redirect that WordPress would try on its own, the sitemaps and the SEO plugins (they read the addresses through `get_permalink()`, so a filter on the address of the content should be enough: to check), `get_page_by_path()` that is no longer the way to find a page.

**What "exact" means is to write** (**Open**): MediaWiki does not tell an underscore from a space and does not tell the **first letter** in capitals from the same in small ones, and nothing else (the case and the accents tell two titles apart); the same rules would be the starting point, and the identity of a page is the pair (space, title after these rules). The index (space, exact title) => page is unique on that pair.

What the address has to guarantee is **Open**: the same name always gives the same address (level 3), or a stable address once the page is created (level 2).

### Other points of the addresses (**Open**)

- A **prefix** (`/wiki/biography/...`) or the **root** (`/biography/...`): a prefix separates the module from the rest of the blog; at the root the pages mix with the ordinary pages of the site.
- The **old addresses** of the SelfPedia (`Biography:1958-1968/fr`) are redirected by a rule, with a table that a move between spaces also feeds.
- The **modes** stay a query argument: `/biography/1958-1968/?mode=print`.
- A **slash in a title** is a subpage in MediaWiki; in the hierarchy of C it is a child.

## Linking a page by its name

**Decided (Eric, 2026-11-06): there is no shortcode and no syntax.** The pages are edited with **Gutenberg**, where typing a syntax (`[page "…"]`, or the double bracket of MediaWiki, which is attractive in a Markdown editor) is far from the easiest way to make a link; the native tool (`Ctrl+K` and its search) is. With the level 3 **the address of a page is its name**, so **an ordinary link to that address is a link by name**; a rename or a move leaves the old address as an alias that redirects (slice 503) and is shown with the current address (slice 505). What was tried before this decision (kept as a record):

- shortcode names: WordPress accepts a one-character tag (`w`, and `$ @ ~ * + . ? ! % ^ - : # | (`), refuses `= / [` and the space; `wptexturize` leaves the attributes of a registered shortcode alone;
- the double bracket `[[Biography:Paris]]` is left untouched by the core, but `wptexturize` **does** change its content (`d'Arlas` becomes `d&#8217;Arlas`, `--` an em dash), so a filter for it must run before the priority 10;
- the unqualified names (`Paris` with no space) and their three rules (the current space, a main space, refused) were decided as **refused**; in Gutenberg the question **does not arise**, since an address always has its space.

**What the links need** ([slice 505](../slices/505-wiki-links.md), 506):

- **The test "is this address a page of the wiki, and does it exist?" is made very often** (Eric): `Address::name_of( $href )` turns an address into a qualified name (the host, the prefix, a registered space, the rules of the names), **no address is stored** (an address is a pure function of a name; a stored URL would have to follow every rename and change of host or prefix, would exceed the 191 bytes of a statement once the accents are percent-encoded, and would not spare the normalisation of a link typed by hand). What costs is the **lookup**, so: one indexed query per distinct name, a cache of the request and a persistent cache with a **version number** bumped by every claim and release (an exact invalidation); a batch lookup only if it measures slow.
- **The red links**: at render, an anchor whose address stands for a name that no page has gets the class `wiki-missing` and leads to the offer to create it (slice 504); an alias is replaced by the current address on the fly.
- **In the editor**: the native link tool already finds the pages (checked on 2026-11-06 on a scratch WordPress: the search of the REST API lists the `wiki_page` posts with their address and ignores the accents) but **shows two pages of the same title in two spaces both as "Paris"**: the space has to be added to the result (the hook is **to find**); creating a missing page from that tool and typing the address of a page that does not exist yet are **to check**.
- **An improvement for later (Eric, optional)**: a **tool to insert a link aimed at the wiki** instead of the generic `Ctrl+K`: a format of the text in the toolbar with a search by name over the spaces (labelled by space), the state of each result (exists or missing), the creation of a missing page, the canonical address inserted. Not designed.
- **The incoming links and the wanted pages** (slice 506): a relation **"cites"** with the inverse **"cited by"** in `triples` (the module already stores one direction and reads both: `subjects_of`, `inverse_label`), kept up to date when a page is saved by reading its `<a href>`; a link that goes to a name that does not exist is a `wiki/wants` statement, the list of the wanted pages.
- **In the printed book (Books)**: a link to an encyclopaedic page becomes a note or a reference; the pages of the merged section and the markers of slices 227 and 228 may help (**Open**, not analysed).

### The page that does not exist: red links and the 404 (idea of Eric, 2026-11-06; Open)

**Idea (Eric):** push the parallel with a wiki: **the page "404" proposes to create a page that does not exist when the address matches a page of a space**.

Reading by Claude, **Proposed**, nothing checked in code:

- **Two ways to arrive on a missing page**, which do not know the same things **if the addresses are the slugs of WordPress** (the levels 1 and 2 above): a **red link made from a name** (the module knows the title) knows the **exact title**; an **address typed or followed from outside** (`/biography/sant-llorenc-de-morunys`) only knows a **slug**, which has lost its accents (see "What was checked"): the creation then proposes a title made of the slug (hyphens to spaces) that the user corrects, and creates the page **with the slug that was asked for**. **With an address of our own (the level 3) this difference goes away** (Eric, 2026-11-06: "it is one more argument for the option 3"): the address **is** the exact title (`/Biography:Sant%20Llorenç%20de%20Morunys`), so every way to a missing page knows what to create, with no guess from a slug. An address typed by hand can still be written without the accent: it is then a page that does not exist, and the **index of the folded titles** (below the exact ones, used for suggestions only, not for the identity) offers "a similar page exists".
- **What the red link points to**: the address the page would have (as in MediaWiki, where a red link leads to the editing of the page). That address answers **404** (the status code stays the right one for a page that does not exist) with the offer to create it.
- **Who may create**: only a logged-in user with the right to create content in that space, with a nonce; for anybody else the normal 404 (a crawler that asks for addresses must not make pages). **The rights per space are the open reflection of [`capabilities.md`](capabilities.md)**: the first value can be one right for all the spaces. The page is created as a **draft** and the editor opens on it.
- **The address must match a space**: its first segment is the root page of a space (a flagged page). The module never creates a space from a 404 (a new space is a deliberate act). If the parents of a page that is asked for do not exist (`/biography/1958-1968/fr` with no `1958-1968`) the offer is to create the chain, or it is refused: **Open**.
- **What the 404 can also say**: that a page of the **same title exists in another space** ("Other angles: Encyclopaedia"), and that a **similar page exists** (the same title but for an accent or the case: the `-2` of the slugs). Both come from the index of the exact titles.
- **Where it is written**: the **logic** (what the address means, who may create, the creation, the other angles) belongs to the module; the **look** belongs to the theme. As for the print pages the contract is one way: the module gives **a block** (for the template `404` of a block theme) and a function, and a **minimal output of its own** when the theme has no such block; classic themes are not a first target.

### The instance and the theme (**Decided by Eric, 2026-11-06**, as it stands today)

**The wiki will probably not be hosted on the same WordPress instance as the blog**, and the theme of the blog is not necessarily the theme that it will use. What follows, **Proposed (Claude)**:

- the module must work with **any block theme**: it gives blocks, data and CSS variables with a fallback, and assumes nothing of the child theme of the blog (`wp-twenty-twenty-five-printable`); the wiki look is the business of **a theme of its own** (a theme or a child theme in a repository of its own), as the idea 4 of `ideas.md` already says;
- the plugin is **one plugin with modules** (`docs/IA.md`): each instance enables the modules it uses (the filter `otherguise_enabled_modules`); the wiki would enable `triples`, `modes`, `wiki` and, to publish the autobiography as a book, `books` (with the print templates **in the theme of the wiki**, not in that of the blog), `media` if the media panel of Media Helper is installed there;
- the rollouts, the staging and the testing of that instance are **its own** (`shared-room`, `docs/design/rollout.md` is written for the blog);
- **the links between the two instances are ordinary links**: an address of the wiki is resolved on its own instance, and the index of the incoming links does **not** see a link written in a post of the blog (**Open**: whether a link from the blog to a page of the wiki is wanted as such, and how it would be known on the other side).

## Where it lives

**Proposed (Claude):** **a module of Otherguise**, the sixth (next to `triples`, `modes`, `media`, `books`, `access`), with clear boundaries (its tests, its settings, its uninstall) so that it can be lifted out later; it would depend on `triples` (the relation "cites") and `modes`, and use `access` for the drafts. The rules of [`packaging.md`](packaging.md) and of `docs/IA.md` ("Modules": one-way dependencies checked by `ArchitectureTest`, names that belong to the module) apply. Alternatively a plugin "Other Wiki"; the cost of a module is that Otherguise grows.

**A theme** that looks like a wiki (**Proposed**): with a one-way contract as for the print pages (CSS variables with a fallback, the plugin gives the data and the blocks, the theme dresses them), perhaps a variation of the child theme of Twenty Twenty-Five so that it keeps the print modes. Take the **grammar** of MediaWiki (a side navigation, the tabs read / edit / history, a table of contents, hatnotes, the footer of categories, the last-modified line) and **not a copy**: the logos and marks of Wikimedia are not reusable, the compatibility of the licence of the skins with the code of Eric is to check before reusing files, and the SelfPedia shows the **status of each piece of information** (fact, source, interpretation): a look identical to Wikipedia would suggest a neutrality that it does not claim.

## The level 3 in short, and the slices

**Decided (Eric, 2026-11-06):** the work goes on **on the level 3** (an address of our own) and the first slices are prepared; **the migration from the SelfPedia is semi-manual and stays as simple as possible** (the wiki has few pages): the plugin gives its API and a command that creates a page from a file of HTML (slice 502); **the code that knows MediaWiki (the conversion of the links, the aliases made from a list) is a throw-away script outside the plugin** (slice 508, Eric's question of 2026-11-06 about "cluttering" the plugin), tried first on a new test site (`sb.vli.st`, Media Helper and Otherguise, without the module Wiki).

**Proposed (Claude)**, the model that the slices write down:

- **The spaces** are an option `wiki_spaces` set on a screen (a handful of records: the name as it is in the address, a label, the template of a new page, a CSS class, a status text).
- **The pages** are one content type `wiki_page`, not hierarchical, **with no rewrite rule of its own**; a page has a space (a meta, chosen at its creation) and a title.
- **The names** are **statements of the module `triples`** (a first version of the slices had a table of its own; Eric asked on 2026-11-06 why, and there was no good reason): `wiki/name` (the current qualified name `Space:Title`, one per page, **one page per name**), `wiki/former-name` (the aliases: redirects and history), `wiki/folded-name` (the name without accents or case, for the suggestions), `wiki/space`. The identity of a page is **the pair (space, exact title)**, the same string as the address. Triples already stores a literal in a binary column (compared byte for byte whatever the collation of the database), indexes it, has `max_subjects_per_object`, and deletes the statements of a deleted post. **The costs, accepted:** the qualified name is **at most 191 bytes in UTF-8** (the `string` of Triples); **no atomic unicity** (Triples counts before it inserts: enough for one author, a named lock closes it); three or four statements per page; the module depends on `triples` from the start. The rules that make a title a name are MediaWiki's, not its code: NFC, the underscore is a space, the first letter in capitals, nothing else changed.
- **The address** is `/<Space>:<Title>` (the underscore for the space, the slash kept, no trailing slash), resolved by one rewrite rule at the top and our own canonical redirect; the module turns off the `redirect_canonical` of WordPress for these requests.

**What a throw-away test showed (2026-11-06, scratch WordPress with pretty permalinks)**: the rule `^([^/:]+):(.+?)/?$` reaches the two query variables with the title **decoded** (UTF-8), the slash kept; WordPress's own canonical redirect would add a trailing slash and write the percent-encoding in lower case; the rule matches any `word:word`, so the space is checked by the handler. Details in [slice 503](../slices/503-wiki-addresses.md); **not checked**: the loading of the page from the main query, the filter on the address, the sitemaps and the SEO plugins, `ModeUrl`.

The slices (all **proposed**, nothing coded; the numbers are those of [`../slices/README.md`](../slices/README.md)): **500** the module and the spaces, **501** the names, **502** the pages, **503** the addresses and the routing; then **504** the page that does not exist, **505** the links (ordinary links, red links) and the move between spaces, **506** the incoming links and the wanted pages, **507** the other angles, **508** the migration from the SelfPedia (a script outside the plugin) (**500 to 508 are all written, the 505 rewritten the same day**, see the index of the slices; nothing is coded). The wiki theme and the multilingual stay as above.

## Open questions (the list)

- The address: slugs of WordPress, an index of exact titles, or an address of our own (above)? What does the address guarantee?
- A prefix or the root? One type per space (A) or the space as a root page (C)?
- The links are ordinary links (**decided**: no syntax, no shortcode). To find out: the hook that shows the space in the results of the native link tool, and whether that tool can create a page of our type.
- The 404 that proposes to create the page ([slice 504](../slices/504-wiki-missing-page.md)): who may create (the rights per space). The missing parents and the title taken from a slug that lost its accents are **closed by the level 3**: no hierarchy, and the address is the exact title.
- Links between the wiki and the blog, if they are two instances.
- How to link two pages of different titles on the same subject?
- The behaviour of the pretty permalinks and the redirects (to check).
- The collation of the production database.
- A bounded test of the import (not a plugin): convert the page `Biography:1958-1968/fr` with Pandoc on the testing site and see what is lost (quotations, notes, categories) and how the media panel inserts photos from Nextcloud there.
- A middle way for the second phase (the editing of the autobiography): the wiki stays the memory base and the source; WordPress and Otherguise Books are the editing layer where chosen pages are imported. The same principle as Otherguise: one source, several forms.
- A namespace declared **after** its pages (seen on the real wiki, 2026-11-06): the `Biography` namespace exists but the old pages are still in the main namespace under the literal title `Biography:...`, which MediaWiki now reads as an empty page of the namespace. The migration fetches by page id, in bulk through the JSON API ([slice 508](../slices/508-wiki-migration-script.md)); whether the wiki is repaired with `namespaceDupes.php` is Eric's choice and not needed for the migration.

- **Decided (Eric, 2026-11-06), for the migration** ([slice 508](../slices/508-wiki-migration-script.md)): all the pages of the wiki except the native MediaWiki ones are migrated; the pages of the main namespace (the encyclopaedic pages) go to a space of their own, working name `Encyclopédie` (to confirm); the suffix `/fr` is removed from names and links, with no alias (the multilingual layer is out of scope: the root pages with the language selector, the `/en` leaves and the redirects are not migrated; `WikiVlist:I18n Hack` is not migrated, `Instructions IA` and `Accueil` are kept as a starting base; the notes are tried with the Footnotes block of WordPress).

## What was checked, what was not

Only the facts of "What was checked" above (a scratch WordPress with the plain addresses, requests in PHP). **Never tried**: any code of the module (there is none), pretty permalinks, the import of the pages of the wiki, the behaviour on the production blog. Claude knows the wiki from the notes of `infra` and from the summary only, **not from reading its pages**; the size of the wiki (the number of pages) is not known.
