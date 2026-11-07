<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# A "Wiki" module: the functions of a wiki, in WordPress

Status: **open; partly coded on the branch `wiki`, and the model is being changed.** Points are marked **Decided** (Eric said so), **Proposed** (Claude's reading, not confirmed) or **Open**. The idea comes from the point 4 of [`ideas.md`](https://github.com/evlist/shared-room/blob/main/docs/ideas.md) of `shared-room` (SelfPedia under WordPress); the analysis goes on here, in the repository of the plugin.

**Read this first (2026-11-07).** The code of the slices 500 to 504 on the branch `wiki` implements a **first model**: a flat content type `wiki_page`, **spaces** registered in an option, and addresses of the form `/Space:Title`. After Eric tried it on a test site (`sb.vli.st`), the model was questioned and a **second one**, written below, is the **direction chosen by Eric**: the **hierarchy of the pages of WordPress**, a **branch** of the tree instead of a space, and **rules that apply to a branch**. **The code has not been reworked yet**; the pages of the test site are in the first model. The first model is kept at the end as a record ("The first model").

**The moratorium (Decided, Eric, 2026-10-31; lifted by Eric on 2026-11-06):** nothing was to start before the ten pages of the autobiography of the SelfPedia were written. Eric lifted it on 2026-11-06 **to validate the feasibility of the migration**; the module is developed on the **branch `wiki`**, away from the development of the blog.

## What is wanted

**Decided (Eric, 2026-11-01):** it is **not** about rebuilding a wiki in WordPress; it is about implementing the **functions that Eric has used so far in MediaWiki**, which are three, read now as follows:

1. **kinds of sections** of the site (the encyclopaedic parts, the opinions, the biographical part, the archive), each with a slightly different presentation: in the second model, **a branch of a tree with rules**, not a namespace;
2. **permalinks like those of a wiki**, **without necessarily following the conventions of MediaWiki**: the address is made of the **exact titles**;
3. **linking a page by its name**, with the **red links** (a page to write), the list of the **wanted pages** (the `Special:WantedPages` that Eric uses for the autobiography) and the **incoming links** ("which pages cite this one").

Also decided on 2026-11-05: **the multilingual is left out of the first step** (the convention of the root and the leaves, `/fr` and `/en`, is kept in mind but not designed; in a tree a language leaf would be an ordinary child page). Reasons to do it at all, from Eric: reuse what exists (the media panel of Media Helper and Otherguise Books, to publish the autobiography both as a linear book and on the site), one platform to manage, and more ease of use than MediaWiki.

## How the idea moved (2026-11-05 to 2026-11-07)

1. **Spaces and a colon** (the first model): `Biography:Paris`, the form of MediaWiki, an address of our own resolved through an index of the exact titles. It was coded (slices 500 to 504) and tried on `sb.vli.st`.
2. **Eric's questions after the test** (2026-11-07): why a new content type and not the pages of WordPress, on a site that uses no ordinary page? Why a colon, why not the main directory of the address? Then the reason that matters (Eric): **how far the module diverges from the standard behaviour of WordPress and fights against its features**, which complicates the writing, **complicates the maintenance** a lot, and is never safe from a coming version of WordPress. The only thing that bothered in the hierarchy of WordPress was **its slugs** (unique among the siblings, and the accents removed), and **an index of the exact titles lifts that difficulty**.
3. **Eric's preference on the address** (2026-11-07): the address in the address bar has to be **the real address**, served as it is, **not redirected** to another form (the equivalent of a rewrite, not of a redirect).
4. **A throw-away test** (2026-11-07, below) showed that the routing through the hierarchy of WordPress holds.

## A branch is not a mode

**Proposed (Claude, agreed in the conversation of 2026-11-01):** a **mode** (`?mode=print`) is chosen **by the request**: the same page under another form. A **branch** carries rules that decide the **default presentation** (the template, the style, a visible status label) of its pages; it is a property of the **content**. They are independent and combine: a page under `Biography` can be shown in the mode `print`.

## The model (the second one)

**Direction chosen by Eric, 2026-11-07, to confirm before the code is reworked.** The points marked **Decided** inside it are the ones Eric stated.

### The tree

- The content type is **`page`**, the hierarchical type of WordPress, **as it is**: `post_parent` is the tree, to any depth. On a site that uses no ordinary page there is no reason to keep a type that does nothing next to a type of our own, and **everything that WordPress does for `page` is kept**: the static front page, the list and the editor of the pages with the selector of the parent, the template `page`, the sitemaps. **No new content type** (**Proposed**; the choice was Eric's: "no distinction between page and wiki page").
- **The first level is the first directory of the address.** What a space was is now **a node of the first level that carries rules**; there is no other notion of space, no option of spaces, no meta "space". A node may have children that are plain pages, or children that carry their own rules (a "sub-space"): **it is the same thing**.
- The page that belongs to no branch is simply **a node of the first level without rules** (what the discussion of 2026-11-07 called the main space, or "a page without space"). The unqualified pages of the SelfPedia land there.

### The rules of a branch

- A **rule** is a small set of settings put on **any node**: the template a new page starts with and that is used to show it, a CSS class for the body, a status line shown on every page ("fact, source, interpretation"), and later the rights (who may create or edit below). It applies to **the node and to all its descendants**.
- The **effective** rule of a page, key by key, is the one of **the nearest node, itself included**, that sets the key: **a nearer node overrides a farther one, key by key** (**Decided by Eric, 2026-11-07**, "yes to the suggestions").
- WordPress has **no inheritance of settings along the tree**: the module has to compute it, by walking the ancestors (`get_post_ancestors`, cached), for the template, the class and the status line, and for the rights by `map_meta_cap`. This is **code of our own**, the part of the model that is not native.
- The rules are stored as a **post meta on the node**. The panel of the editor shows the effective rule and **where each value comes from** (**Decided by Eric, 2026-11-07**).

### The titles, the identity, the address

- A page is identified by **(its parent, its exact title)**. The rules that make a title a name (**Proposed**, but for the two points decided below): Unicode normal form C, the underscore is a space, **nothing else changed**.
- **The first letter is not made a capital** (**Decided by Eric, 2026-11-07**): the titles are **case-sensitive**, `Paris` and `paris` are **two different pages** (the rule of MediaWiki is dropped). The folded key (below) still says "a similar page exists".
- **A slash cannot be in a title** (**Decided by Eric, 2026-11-07**: it is the separator of the levels; **during the migration a slash in a title is replaced by `-`**, and two titles that clash after the replacement are an error of the report). A colon is an **ordinary character**: with no namespaces the trap of the titles that look like one disappears.
- **The address** is `/<Title>/<Title>/…`: the exact titles of the ancestors and of the page, **the underscore for the space**, every other character percent-encoded in UTF-8 in **upper case** except a few that stay readable (`: ( ) , ' ! * @`), **no trailing slash**, no prefix (the wiki is at the root of its instance). Examples: `/Biography/Sant_Llorenç_de_Morunys` (the accent encoded on the wire), `/Biography/1958-1968/Chapitre_premier/Souvenirs:_la_tour_(Saint-Just)`.
- **The slug of WordPress stays in the database** (`post_name`, with its `-2` for two siblings that differ only by an accent) **and is not shown anywhere**: it is technical.
- **No stored URL**: an address is a pure function of the tree and of the titles.

### The index

- Kept in `triples`, as in the first model, but **one entry per node, not per path**: the literal is `(parent id, exact title)` (**Proposed** form: the id of the parent, a separator, the title; **191 bytes at most for one title**, so **the depth is not limited**). One page per key; **former keys** (aliases) and a **folded key** (without accents or case, for suggestions only, never an identity) as before.
- **A path is resolved segment by segment**: one indexed lookup per level. **Moving or renaming a node changes only its own key**; the descendants follow without any change, and **the old address of a descendant keeps working** because the old key of the ancestor is an alias: the resolution passes through it and ends in a 301 to the current address. (The path as a single string would limit the depth to 191 bytes and would need an alias per descendant at every move.)
- Two siblings whose titles differ by an accent or the case are **two pages**; the index tells them apart. The folded key only serves to say "a similar page exists".

### The routing

- **No rewrite rule of our own.** The module acts on **`parse_request`**, after WordPress has chosen its rule. WordPress produces, for an address that looks like a page, one of **`pagename`**, **`name`**, **`attachment`** or **the error 404**: the module acts on those shapes only and leaves the specific routes of WordPress (`/feed/`, `/wp-json/`, the archives by category, by author, by year, `/search/`) **untouched, so they always win**. If the path resolves, the query is replaced by the page (`page_id`); otherwise WordPress goes on.
- Why those four variables (**checked**, see below): with the permalink structure `/%postname%/` the page rule of WordPress is **not** a catch-all: it only matches a path that is an existing slug; an unknown path of two segments falls on the **attachment** rule, one of a single segment on the rule of the **posts** (`name`), and three or more segments have **no rule** (error 404). With a structure such as `/%year%/%monthnum%/%postname%/` the page rule is the last one and gives `pagename` for everything. The hook is the same for both.
- **The canonical address is ours** (**Decided by Eric, 2026-11-07**, "I think we can keep the cut of the canonical": the `redirect_canonical` of WordPress is **turned off** for these requests, **no trailing slash**). It is served **as it is**, with no redirect. A **301** is sent only for what is the same address in another form: an **alias** (the old key of a renamed or moved node), an encoding in lower case, a trailing slash; the query string is kept. **There is no slug door** (**Decided by Eric, 2026-11-07**): the address made of the **slugs** of WordPress (`/biography/paris/`) **is not an address of the wiki**, and a title typed in another case or without its accents is not either: both are a **404** that says "a page of a similar name exists" and shows the approaching paths. Since WordPress itself finds a page by its slug, the module has to **override that result** with the 404 when the exact resolution fails and the page that WordPress found is of the wiki. Without turning `redirect_canonical` off, WordPress adds a trailing slash and our own redirect removes it: **a loop** (checked). The other way, a trailing slash as the canonical form, works without turning anything off (checked) and was **not retained**.
- **The permalink**: the filter `page_link` gives the address. **The static front page is served at its own address too** (**Decided by Eric, 2026-11-07**), as well as at `/`: no redirect from one to the other.
- **A title made only of digits is forbidden at the first level** (**Decided by Eric, 2026-11-07**): `/2026` is the archive by year, which WordPress serves first.

### The titles of the first level that nothing could reach

Since the first letter is no longer a capital, a title of the first level can be written like **a route that WordPress or the web server serves before the module** (`feed`, `category`, `wp-json`, `wp-admin`, `wp-login.php`, `page`, `author`, `search`, a file or a directory at the root of the site): the page would exist and **could never be shown**. **Proposed**: such a title is **refused at the creation of a page of the first level**. The test is made on the exact address: it matches a **specific rewrite rule of WordPress** (every rule but the three that produce `pagename`, `name` or `attachment`), or a **reserved list** (`wp-*`, `index.php`, `xmlrpc.php`, `robots.txt`, the feeds), or **a file or a directory exists at that place of the site**, or it is **only digits** (decided above). **Open**: the exact list and whether the test is dynamic (read from the rules) or static. A title below the first level is never concerned.

### The page that does not exist

- A path that does not resolve is a **404** and the module **knows where it stopped**: the deepest node that exists and the titles that remain (checked). The offer to create the page, the other angles and the similar names are those of the first model ([slice 504](../slices/504-wiki-missing-page.md)).
- **When one title remains**, the offer is to create the page below the deepest existing node. **When several remain** (`/Biography/1958-1968/Nobody/Deeper` with no `Nobody`) **the module first looks for approaching paths** (**Decided by Eric, 2026-11-07**: "after a search for approaching paths") and shows them; **Proposed**: only then is the creation of the chain offered, **listing every page that would be made**, with an explicit confirmation. **To confirm with Eric**: that this is what "after a search for approaching paths" means. What an approaching path is, is **Open**; **Proposed** first version: the same path read **with the folded keys** (an accent or a case that differs, at any level), the **same last title elsewhere** (the angles), and the **children of the deepest existing node whose title is close** (the same folded start). The module never makes a branch **with rules** from a 404.
- **Who may create**: a logged-in user with the right (a nonce), the others get the plain 404 with no form (a crawler that asks for addresses must not make pages). **The rights per branch** are the rule of a branch (open reflection of [`capabilities.md`](capabilities.md)).

### Linking a page by its name

**Decided (Eric, 2026-11-06): there is no shortcode and no syntax.** The pages are edited with **Gutenberg**; the native tool (`Ctrl+K` and its search) makes the links. **An ordinary link to the address of a page is a link by name**; a rename or a move leaves the old key as an alias that redirects. What the links need ([slice 505](../slices/505-wiki-links.md), 506):

- **The test "is this address a page of the wiki, and does it exist?" is made very often**: the address is turned into a path of titles and resolved segment by segment; **no address is stored**; one indexed query per distinct segment, a cache of the request and a persistent cache with a **version number** bumped by every change of a key (an exact invalidation).
- **The red links**: at render, an anchor whose path stops before its last title gets the class `wiki-missing` and leads to the offer to create it (the address the page would have, as in MediaWiki).
- **In the editor**: the native link tool already finds the pages of the type `page` and shows their address; two pages of the same title in two branches both read "Paris": showing the parent in the result is **to find** (a hook), and creating a missing page from that tool is **to check**.
- **An improvement for later (Eric, optional)**: a tool to insert a link aimed at the wiki (search by name, state of each result, creation of a missing page). Not designed.
- **The incoming links and the wanted pages** (slice 506): a relation "cites" with the inverse "cited by" in `triples`, kept up to date when a page is saved by reading its `<a href>`; a link to a path that does not resolve is a `wiki/wants` statement, the list of the wanted pages.
- **In the printed book (Books)**: a link to an encyclopaedic page becomes a note or a reference (**Open**, not analysed).

### The same subject under several angles

**Decided (Eric, 2026-11-05):** one of the interests of the sections is to treat **the same subject from several angles** (encyclopaedic, subjective, autobiographical). In the tree the same title lives **under several branches** (`Biography/Paris`, `Opinion/Paris`): the identity is the pair (parent, title). **Decided (Eric, 2026-11-07): the other angles of a page are the pages that have the same title elsewhere in the hierarchy**, at any level. **Proposed**: that needs an entry for **the exact title alone** (a fifth statement in `triples`, **many pages for one title**, bounded in the list shown), next to the key `(parent, title)`; the band "Other angles" is made from it. For two different titles on one subject ("Paris" and "Paris (ville)") an explicit relation "same subject" would be needed (**Open**).

### The migration of the SelfPedia

(See [slice 508](../slices/508-wiki-migration-script.md); the decisions of 2026-11-06 stand, with these changes of form, **Proposed**.) `Biography:X` becomes `/Biography/X` below a node `Biography` that carries its rules; the pages **without namespace** of MediaWiki become **nodes of the first level** (`/Accueil`), with no space `Encyclopédie`; `WikiVlist:X` becomes `/WikiVlist/X`; the `/fr` suffix is removed with no alias, **a slash in any other title becomes `-`** (decided 2026-11-07), the root pages, the `/en` leaves and the redirects are not migrated, `WikiVlist:I18n Hack` is not migrated and `Instructions IA` and `Accueil` are kept; the links are rewritten into paths; the script stays **outside** the plugin (`shared-room/tools/wiki-migration/`). The redirection from the old host (`wiki.vli.st`) to the new addresses is a rule of the old server.

## The instance and the theme (**Decided by Eric, 2026-11-06**, as it stands today)

**The wiki will probably not be hosted on the same WordPress instance as the blog**, and the theme of the blog is not necessarily the theme that it will use. What follows, **Proposed (Claude)**:

- the module must work with **any block theme**: it gives blocks, data and CSS variables with a fallback, and assumes nothing of the child theme of the blog (`wp-twenty-twenty-five-printable`); the wiki look is the business of **a theme of its own** (a theme or a child theme in a repository of its own), as the idea 4 of `ideas.md` already says;
- the plugin is **one plugin with modules** (`docs/IA.md`): each instance enables the modules it uses (the filter `otherguise_enabled_modules`); **the module `wiki` is disabled by default** (**Decided by Eric, 2026-11-07**: it gives the pages of the site addresses of exact titles, which must never happen by surprise on the instance of the blog). **An instance enables it by a switch per module on the overview of the menu Otherguise** (**Decided by Eric, 2026-11-07**), a setting of the core, the filter still having the last word, since the owner of an instance does not write a must-use plugin; its exact form is **Open**. The default of the filter is now `null` = every module, which has to change for a module that is off by default. The wiki would enable `triples`, `modes`, `wiki` and, to publish the autobiography as a book, `books` (with the print templates **in the theme of the wiki**, not in that of the blog), `media` if the media panel of Media Helper is installed there;
- the rollouts, the staging and the testing of that instance are **its own** (`shared-room`, `docs/design/rollout.md` is written for the blog);
- **the links between the two instances are ordinary links**: an address of the wiki is resolved on its own instance, and the index of the incoming links does **not** see a link written in a post of the blog (**Open**: whether a link from the blog to a page of the wiki is wanted as such, and how it would be known on the other side).

## What the throw-away test showed (2026-11-07)

A scratch WordPress 7.1.x with pretty permalinks and a **throw-away `mu-plugin`** (removed afterwards, nothing in the repository), pages of the type `page` in a tree (`Biography` and `Opinion` at the first level; `Paris` under both; `Ansò` and `Anso` under `Biography`; a path of four levels; the roots `Feed` and `Category`; a draft). The request was made like nginx makes it (no `PATH_INFO`). **Checked**:

- **The resolution segment by segment works at any depth.** `/Biography/Paris` and `/Opinion/Paris` are two pages; **`Ansò` and `Anso` are told apart** (their slugs are `anso` and `anso-2`, never shown); a title with a colon and parentheses at the fourth level is served.
- **The routes of WordPress still win**: `/feed/`, `/wp-json/`, `/2026/`, a post (`/hello-world/`); a root page titled `Feed` is served at `/Feed` while `/feed/` stays the feed.
- **With the canonical address ours** (the `redirect_canonical` off for our requests) the page is **served with no redirect**; with it **on** and no trailing slash: a **loop**; with a **trailing slash** as the canonical form: no loop, no need to turn anything off (the parentheses case included).
- **The slug door** can be closed by a 301 (`/biography/paris/` and `/biography/anso-2/` to the exact address, `/sample-page/` to `/Sample_Page`); a post is not touched. **Not retained** (decided 2026-11-07): those addresses are a 404; the override of the result of WordPress is **not tried yet**.
- **A draft** is a 404 for a visitor; **an unknown path** is a 404 that knows its deepest existing node and the remaining titles.
- **The static front page** works (`/` shows it; its own address `/Accueil` is served too, which is now **the decided behaviour**).
- **The permalink structures**: `/%postname%/` and `/%year%/%monthnum%/%postname%/` both work with the same condition (the four variables above).
- **The Footnotes block**: its meta is registered for the type `page`.
- **The cost**: one query per level of the path, for the addresses of the shapes above only (4 for four levels, 1 for `/hello-world/`).

**Not checked**: a real nginx (the absence of `PATH_INFO` was simulated), the **speed at scale** (the test looked the title up in the table of the posts by a `BINARY` comparison, which the index of WordPress does not serve: it scans the pages of the type; the index of `triples` with a binary indexed literal is what is meant, **not measured**), the page list of the **Site Editor**, an SEO plugin, a site of several thousands of pages, the behaviour with the module enabled on a site that has ordinary pages, the rights per branch.

## What stays of the code, what changes

The code on the branch `wiki` follows the first model. **Proposed** (nothing reworked yet):

| Slice | What it did | In the second model |
|---|---|---|
| 500 | the module, the spaces (option, screen) | the module stays and is **disabled by default**; the **spaces and their screen are replaced by the rules of a branch** (a panel on a node) |
| 501 | the names (rules, four predicates, `Names`) | the **rules of the names change**: **no capital first letter**, the slash is refused, the colon is free; the **index becomes per node** `(parent, title)` with former keys, a folded key and the exact title alone (for the angles); `claim`, `resolve` walk the path |
| 502 | the type `wiki_page`, the space (meta), the claim at saving, `Pages::create`, the panel, the list | **the type is `page`**; no meta "space", the parent is the selector of WordPress; the claim at saving checks `(parent, title)`; `Pages::create( $parent, $title, … )` |
| 503 | the rule `Space:Title`, the resolution, the canonical form | **no rule**: the resolution on `parse_request`; the canonical form and the 301 stay (the decoding of the percent-encoded values, the redirects), the slug door is added |
| 504 | the 404 and the offer | stays, with "where the path stopped" instead of a space; the creation chain is Open |
| 505 to 508 | (proposed) links, incoming links, angles, migration | written for the first model: to rewrite |

The tests of the first model are the starting point of the new ones; the work on the checks at saving, the claim, the nonce of the creation, the block and the template of the 404, and the decoding are reusable.

## The sitemap, and a cached map of the addresses

**No SEO plugin is used** (Eric, 2026-11-07), so the addresses reach the search engines through the **sitemap of the core** (`/wp-sitemap.xml`) and the **`<link rel="canonical">`** that the core prints: both read `get_permalink()`, hence **the filter `page_link`**; **the sitemap has to list our addresses** (**Decided**: it must use them). In the first model the sitemap of the module's own type listed the exact addresses (checked, 2026-11-06); **for the type `page` and the new filter it is to check**, as is the `rel="canonical"`.

**An idea of Eric (2026-11-07): a cached version of the sitemap, in a format to define, could speed the plugin up.** Claude's reading, **Proposed**:

- The part that costs is not the XML of the sitemap (a crawler reads it rarely) but **the address of a page**: with the second model it is the chain of the titles of its ancestors, so **every `get_permalink()` needs its ancestors** (`get_post_ancestors`: queries, or the post cache), and a page that lists a hundred links, a menu, a sitemap or the test "does this link exist?" of the red links pays it a hundred times.
- A **map of the addresses** kept ready answers that: **page id → canonical path**, **path → page id** (the current ones and the **aliases**, to resolve and redirect), for the whole tree. Loaded once per request (or kept in the persistent object cache when there is one), it makes the permalink, the existence test, the red links, the sitemap and the list of the wanted pages **a lookup in memory**, and a resolution of a path **no query at all** for the pages that are in it.
- **Where and in which format are Open.** Three candidates: a **PHP file** written in the uploads (`return array( … )`, read through **opcache**, the fastest, written to a temporary file and renamed so that a reader never sees half of it); a **JSON** file (portable, slower to read); the **persistent object cache** when Redis or Memcached is there, with a **transient** as the fallback. **Proposed**: the PHP file, with the object cache first when it exists.
- **It is a cache, never the truth**: the truth is the index in `triples`. A **version number** is bumped by every change of a key (a rename, a move, a creation, a deletion, a change of parent) and compared on load; if the map is missing or stale the module **falls back on the index** and rebuilds it (in the background when it is long). The size is **not known**: for a few thousand pages it is a few hundred kilobytes, **not measured**.
- Since the cache is an optimisation, it can come **after** the first working version; the index per node alone is correct, only slower.

## Maintenance: what the module hooks into WordPress

**The reason for the change** (Eric, 2026-11-07) is to depend as little as possible on what WordPress may change. **What the second model uses**, all **documented extension points**: the action `parse_request`, the filters `redirect_canonical` and `page_link`, the hierarchical type `page` as it is, `get_post_ancestors`, the REST and the block editor for the pages, `register_block_template` (6.7, for the 404 of a block theme only; without it there is no offer, nothing breaks). **What the first model also needed**: a rewrite rule at the top, a second content type, filters on the post meta to lock the space, hooks on the REST of a custom type. **What remains ours**: the resolution of the exact titles, the canonical address (the `redirect_canonical` turned off is the one hook that **fights** a behaviour of the core, on purpose), the inheritance of the rules along the branch.

## Open questions (the list)

- **Decided on 2026-11-07, no longer open**: the first letter is not made a capital (case-sensitive titles); a slash is refused in a title (replaced by `-` in the migration); the module is disabled by default, **enabled by a switch per module on the overview of the menu Otherguise**; the slug address and a title typed in another case are a **404** with the similar pages, no 301; a title made only of digits is forbidden at the first level; the front page is served at its own address; the angles are the pages of the same title elsewhere in the hierarchy; the effective rule of a branch is the nearest node, key by key, with a panel that shows where a value comes from; no SEO plugin is used.
- **The titles of the first level that nothing could reach**: the exact test and the list (above).
- **The approaching paths** of a 404 with several missing titles (a first version above) and the confirmation of the creation of a chain.
- **The switch per module** (decided, below) and the change of the default of `otherguise_enabled_modules`: its form.
- **The map of the addresses** (below) and the speed at scale of the index per node.
- **The Site Editor** and the **rights per branch**.
- Links between the wiki and the blog if they are two instances; how to link two pages of different titles on the same subject; the **collation** of the production database.
- A middle way for the second phase (the editing of the autobiography): the wiki stays the memory base and the source; WordPress and Otherguise Books are the editing layer where chosen pages are imported.
- **The multilingual**, later: a language leaf is an ordinary child page (`/Title/fr`).
- A namespace declared **after** its pages (seen on the real wiki, 2026-11-06): the pages are still in the main namespace under a literal title `Biography:...`; the migration fetches by page id through the JSON API ([slice 508](../slices/508-wiki-migration-script.md)).
- **Decided (Eric, 2026-11-06), for the migration**: all the pages except the native ones; the `/fr` suffix removed with no alias; the roots, the `/en` leaves and the redirects not migrated; `WikiVlist:I18n Hack` not migrated, `Instructions IA` and `Accueil` kept; the notes tried with the Footnotes block of WordPress.

## Where it lives

**Proposed (Claude):** **a module of Otherguise**, the sixth (next to `triples`, `modes`, `media`, `books`, `access`), with clear boundaries (its tests, its settings, its uninstall) so that it can be lifted out later; it would depend on `triples` (the relation "cites") and `modes`, and use `access` for the drafts. The rules of [`packaging.md`](packaging.md) and of `docs/IA.md` ("Modules": one-way dependencies checked by `ArchitectureTest`, names that belong to the module) apply. Alternatively a plugin "Other Wiki"; the cost of a module is that Otherguise grows.

**A theme** that looks like a wiki (**Proposed**): with a one-way contract as for the print pages (CSS variables with a fallback, the plugin gives the data and the blocks, the theme dresses them), perhaps a variation of the child theme of Twenty Twenty-Five so that it keeps the print modes. Take the **grammar** of MediaWiki (a side navigation, the tabs read / edit / history, a table of contents, hatnotes, the footer of categories, the last-modified line) and **not a copy**: the logos and marks of Wikimedia are not reusable, the compatibility of the licence of the skins with the code of Eric is to check before reusing files, and the SelfPedia shows the **status of each piece of information** (fact, source, interpretation): a look identical to Wikipedia would suggest a neutrality that it does not claim.

## The first model (kept as a record)

Coded on the branch `wiki` (slices 500 to 504), tried on `sb.vli.st`. **Spaces** registered in an option `wiki_spaces` (name, label, template, class, status line, order); one flat type `wiki_page` with no rewrite rule; **names as statements of `triples`** (`wiki/name` the qualified name `Space:Title`, `wiki/former-name`, `wiki/folded-name`, `wiki/space`; the identity of a page is the pair (space, exact title), 191 bytes at most); the address `/<Space>:<Title>` resolved by **one rewrite rule at the top** (`^([^/:]+):(.+?)/?$`) and our own canonical redirect. What it taught: a server that gives `PATH_INFO` (the built-in server of PHP) **hides** that nginx hands the rule a still percent-encoded value (an accented space name was a 404 on `sb.vli.st`, found by the first test there); the stored rewrite rules may **lack the rule** although the version of the rule was recorded as flushed; the colon has the advantage that **no slug of WordPress has one**, so there is no collision. The arguments of the choice of **an address of our own** over the slugs (the old addresses reproduced, **no `-2`** for two titles that differ by an accent, the 404 that knows the exact title, the address that **is** the name) stand in the second model, where they are obtained through the index.

The three ways that were weighed to tie a space to an address: **A** one content type per space with its prefix; **B** a taxonomy (ruled out: the slugs of a flat type are unique in the whole type); **C** the space as the root page of one hierarchical type, **which is the second model**. What was checked on 2026-11-05 on slugs: two child pages of the same title under two parents live together; **a slug loses the accents** ("Ansò" and "Anso" give `anso`; a second title under the same parent becomes `-2`); a colon and a slash are stripped from a slug; moving a page under another parent can change its slug; the database ignores the accents when it checks that a slug is free (collation `utf8mb4_unicode_520_ci`), so keeping the accents in the slugs does not remove the `-2`.

## What was checked, what was not

**Checked**: the facts of "What the throw-away test showed" and, for the first model, those of its slices (a scratch WordPress; `curl`; Chromium on the block editor; on `sb.vli.st`, by Eric, the first model). **Never tried**: the second model **as code** (there is none), a real nginx for it, the speed at scale, the Site Editor, the module on a site that has ordinary pages, the import of the whole SelfPedia. Claude knows the wiki **from the pages that Eric sent through the JSON API** (the ten pages of `Biography`, the pages of the main namespace and of `WikiVlist`), not from reading the whole wiki; the other namespaces were not looked at.
