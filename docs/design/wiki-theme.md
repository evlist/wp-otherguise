<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# The theme of the wiki: brief for the repository `evlist/wp-wikiguise-theme`

Status: **brief written on 2026-11-09** for a session that works in the new repository `evlist/wp-wikiguise-theme` (created by Eric, empty). Nothing of the theme exists yet. The points are marked **Decided** (said by Eric), **Proposed** (Claude's reading, **to be confirmed with Eric**) or **Open**; do not take a Proposed point for a decision.

**Read first**, in this repository (`evlist/wp-otherguise`, branch `wiki`): `docs/design/wiki.md` ("The instance and the theme", "The model"), the line `Wiki` of `docs/IA.md`, `docs/slices/README.md`; and, for the way the blog's own child theme is built, `evlist/wp-twenty-twenty-five-printable` (`README.md`: "Rules for the templates").

## Why a theme of its own

The module Wiki of the plugin Otherguise gives the pages of a WordPress site addresses made of exact titles (`/Biographie/1958-1968`), a tree of branches with rules, red links, incoming links and other angles. **It assumes no theme** (**Decided**, 2026-11-06: the wiki is on its own instance and its look is "the business of a theme of its own"). The wiki being migrated from MediaWiki (the SelfPedia, `wiki.vli.st`), Eric wants a presentation that **feels like a wiki**, **without copying MediaWiki** (**Decided**, 2026-11-08).

Two reference captures, both of the page *Accueil*, are in [`wiki-theme/`](wiki-theme/):

- [`reference-mediawiki-accueil.png`](wiki-theme/reference-mediawiki-accueil.png): the MediaWiki (skin Vector 2022): a header with the logo and a search field; a **left column** with the main menu and the **table of contents**; the **tabs of action** above the text (page, discussion; read, edit, history, and "Racine", the link up to the parent page); a **right column** of tools and appearance settings; compact typography, serif headings with a thin rule under the second level, blue links and **red links**, a language selector.
- [`reference-sb-twentytwentyfive-accueil.png`](wiki-theme/reference-sb-twentytwentyfive-accueil.png): the same page as it is today on `sb.vli.st`, with Twenty Twenty-Five: one narrow centred column, large type, a lot of air, a top navigation (Biographie, Encyclopédie, WikiVlist).

What is to be taken from MediaWiki is **the structure**, not the style (**Proposed**: see "To confirm").

## What is decided and what is proposed

| Point | Status |
|---|---|
| The repository is `evlist/wp-wikiguise-theme` | **Decided** (Eric) |
| **Print: the CSS rules first, no `-print` template** as long as the structure of the pages does not have to change (see "Print") | **Decided** (Eric, 2026-11-09) |
| The print version of a page goes through the **Modes of Otherguise** (`?mode=print`) | **Decided** (Eric) |
| A **standalone minimal block theme**, not a child of Twenty Twenty-Five | **Proposed** (Claude): the look is a three-column layout that Twenty Twenty-Five does not have, most of its templates and styles would be overridden, and the wiki is an instance of its own. Starting point: a blank block theme (the plugin *Create Block Theme* can generate one), not a hand-written skeleton. A child of Twenty Twenty-Five stays possible and reversible if Eric prefers the speed |
| **One responsive theme**, not a separate mobile theme | **Proposed** (Claude), after Eric pointed out that MediaWiki uses another skin for mobile: detecting the device to change the theme means two sets of templates, cache problems and two renderings for one address |
| The **structure** to keep from MediaWiki: left column (menu and table of contents), tabs of action, right column of tools | **Open**: Eric has not said which |

## What the plugin already gives (any block theme)

The theme styles these; it does not reimplement them. Names are **the contract**.

- **CSS classes** printed by the plugin, with no style of their own: `wiki-link` (a link to a page of the tree), `wiki-missing` (a red link: a path that enters the tree and stops, with a `title`), the offer of the 404 (`wiki-missing`, `wiki-missing__message`, `wiki-missing__name`, `wiki-missing__refusal`, `wiki-missing__create`, `wiki-missing__chain`, `wiki-missing__confirm`, `wiki-missing__button`, `wiki-similar`, `wiki-angles`, `wiki-angle`), `wiki-cited-by`, `wiki-branch-status`. A page has a **body class** set by the rules of its branch (`wiki_rules.class`).
- **Blocks** (server-rendered): `wiki/branch-status` (the line of status of the information of the branch: "Fact, source, interpretation"), `wiki/cited-by` (the pages that link to this one), `wiki/other-angles` (the same title elsewhere in the tree, and the pages declared the same subject), `wiki/missing-page` (the 404 of a path of the tree).
- **Templates by branch.** The rules of a branch name a template slug: the plugin makes WordPress try `<slug>.php` (block theme: the template of that slug) **before** the usual hierarchy for the pages of the branch, unless a page has its own template. So the theme ships the templates a branch may choose (for example one for the biography, one for the encyclopaedia), and Eric picks them in the panel "Branch rules" of the editor. The plugin also gives a new page of a branch the template of its branch.
- **A 404 template** `wiki-missing` (block themes) or a `missing-page.php` file (classic themes) is registered by the plugin; a theme may provide its own.
- **Footnotes**: the **core Footnotes block** (`core/footnotes`) is what the pages use for notes. It is inserted in the editor from the "more" menu of the toolbar ("Footnote"); its notes are in a post meta. The theme only styles it (**Open**: the migration of the SelfPedia writes for now the plain list that Pandoc produces; converting `<ref>` into core footnotes is planned).
- **The tree**: the pages are WordPress pages, the tree is `post_parent`, the address is the chain of the titles. A theme can use the core blocks on it (`core/page-list` with a parent, `core/navigation`, `core/post-title`, `core/post-content`, `core/post-date`).

**Blocks that do not exist yet** and that the theme will want (to be built **in the plugin**, in this repository, by the session that has its context; the theme must **work without them first**, with core blocks and room left for them): a **breadcrumb** of the tree (with a "Racine" link up), a **navigation of the branch** (children and siblings), a **table of contents** of the headings (WordPress has no such block), an **"Edit" button** shown to those who may edit.

## Responsive (Proposed)

One theme, one set of templates, a layout that adapts:

- **Wide screen**: three columns (menu and table of contents | text | tools).
- **Medium**: two columns, the tools move under the text.
- **Narrow (mobile)**: one column; the header is reduced to a menu button (the core Navigation block opens as an overlay); the table of contents and the navigation of the branch are **collapsible** at the top of the page; tables scroll sideways, images fit; touch targets are **at least 44 px**; nothing depends on hover.
- **Fluid typography** set in `theme.json`; compact type for a wiki (the reference is far denser than Twenty Twenty-Five).
- The collapsible parts need `<details>` with a state that follows the width (a small script, or the recent CSS support for the content of a closed `<details>`): **to settle at implementation**, checked in Chromium at three widths.

## Print (Decided: CSS rules first)

**Eric, 2026-11-09: as long as the structure of the presentation of the pages does not have to be changed, prefer CSS rules to avoid creating print templates.** So:

- The print version of a page is the same templates with **other CSS**. The Modes of Otherguise give the mode a body class (`modes-mode-<slug>`, for example `modes-mode-print`; the default mode is `web`) and enqueue **`modes/<mode>.css` of the theme** when the mode is active (the blog's theme does it this way: `modes/print.css`). Rules for the print mode go there, keyed by the mode, **not only** under `@media print`, because the page to print is meant to be seen in the browser in print mode (`?mode=print`), as on the blog; an `@media print` section may add the browser's own printing.
- In print: hide the header navigation, the columns, the tools and the edit button; one column of text; links as plain text or with their address (to choose); the notes of the page kept; red links not red.
- **A print template is created only when CSS cannot do it**: a different order or different blocks (for example a table of contents or a list of references that the web page does not show, a cover). The Modes of Otherguise then declare the variant as a relation between two templates (see `docs/design/modes.md`). Until then: no `-print` template.
- The blog's theme composes its print pages for a reference page declared in `settings.custom.otherguise.reference` of its `theme.json` (210 x 297 mm, margin 10 mm): a possible model for the wiki, **Open** (the wiki may not need paper at all at first).

## How to try it

The theme is tried on **`sb.vli.st`** (Eric's sandbox, a Docker WordPress with the plugin Otherguise on the branch `wiki`, the module Wiki enabled, and the migrated pages: `/Biographie/...`, `/Encyclopédie/...`, `/WikiVlist/...`). A session in the cloud has no access to it: it works on a **scratch WordPress**, as the session that wrote the plugin did:

1. MariaDB (`apt-get install mariadb-server`), a data directory of its own, started by hand on a port (`mariadbd --no-defaults --datadir=... --socket=... --port=3307 --bind-address=127.0.0.1`), root with an empty password through `mysql_native_password` (`ALTER USER ... IDENTIFIED VIA mysql_native_password USING PASSWORD('')`), a database for the site.
2. WordPress from `https://wordpress.org/latest.tar.gz` (the proxy lets it through), installed, served by the **PHP built-in server** with a small router script (it hides nginx's behaviour: no `PATH_INFO` there, and the plugin decodes the path itself); pretty permalinks `/%postname%/`.
3. The plugin: a symlink from `wp-content/plugins/otherguise` to the `plugin/` directory of this repository, branch `wiki`; the option `otherguise_modules` with `wiki` set to true (a switch on the overview of the menu Otherguise). The theme: a symlink in `wp-content/themes/`.
4. Pages: the plugin's `Tree\Pages::create( $parent, $title, $html, $status )` (the command `wp otherguise tree create` when WP-CLI is there), or the migration tool of `evlist/shared-room` (`tools/wiki-migration/`) with a JSON file of the wiki.
5. **Playwright and Chromium are installed in the cloud environment**: `require('playwright')`. Take captures at **three widths** (1440, 900 and 390 px), `deviceScaleFactor: 1` for checks, 2 for a capture that Eric will see.

**Never tried, to say plainly in the reports**: a real phone, Safari, nginx, a long page on a slow connection, the Site Editor.

## The rules of the repositories (Proposed for the new one)

The same as the other repositories of Eric:

- Talk to Eric in **French**; everything stored in the repository in **English** (templates, comments, documentation, commit messages), the titles of the templates being those of the site (French).
- Every file carries an **SPDX header** (GPL-3.0-or-later), or is covered by `REUSE.toml` (templates and `theme.json` cannot carry a comment: a comment at the top of a template becomes a block). Keep `reuse lint` clean.
- A template is changed **in the repository**, never in the Site Editor of a site that matters; a template saved in the Site Editor is stored in the database and **shadows the file**. A custom template has an entry in `theme.json` (`customTemplates`); **never rename a slug that posts use**. One file per slug. (From `wp-twenty-twenty-five-printable`; its rule against the `theme` attribute in template parts is for a **child** theme: in a standalone theme the attribute is the theme's own slug, or absent.)
- **Say what was only tried on a scratch WordPress and what was never tried** on the real site (`sb.vli.st`) or on a real device.
- Never ask for, store or commit tokens or passwords. **Do not create pull requests or push to another branch unless asked**; do not create repositories unless asked.
- No JavaScript framework: the theme is HTML templates, `theme.json` and CSS (a few lines of script only if a collapsible part needs it).

## To confirm with Eric

1. **Standalone minimal theme or child of Twenty Twenty-Five?** (Claude proposes standalone.)
2. **Which structure to keep** from MediaWiki: the left column (menu and table of contents), the tabs of action, the right column of tools?
3. The **look**: fonts (an open serif for the headings?), the colours of the links and of the red links, a dark mode or not, the density of the type.
4. The **search**: the WordPress search, a field in the header as in the reference.
5. Whether the wiki needs **paper** at first, and for which pages.
6. A **language selector**: none (the multilingual layer of the SelfPedia is out of scope).

## First steps proposed for the session

1. A **static mock-up** of the page *Accueil* with the structure retained, at three widths, as captures sent to Eric before coding the theme.
2. The blank theme (`theme.json`: width, typography, colours; templates `index`, `page`, `archive`, `search`, `404`; parts `header`, `footer`), the styles of the classes above.
3. A template per branch (the biography, the encyclopaedia), declared in `customTemplates`.
4. `modes/print.css` (CSS rules, see "Print").
5. Then, with the session that works on the plugin, the blocks that do not exist yet.

## A `CLAUDE.md` for the new repository (to adapt and commit there)

```markdown
# CLAUDE.md

Instructions for Claude Code sessions working in this repository (the theme of the wiki of Eric's SelfPedia, for the plugin Otherguise).
The brief is `docs/design/wiki-theme.md` of the repository `evlist/wp-otherguise` (branch `wiki`): read it first.

## Language
- Talk to Eric in **French**; everything stored in the repository is in **English** (the titles of the templates are those of the site).

## Rules
- Every file carries an SPDX header (GPL-3.0-or-later) or is covered by `REUSE.toml`; keep `reuse lint` clean.
- Change a template in this repository, never in the Site Editor of a site that matters; one file per slug, an entry in `theme.json` for every custom template, never rename a slug that posts use.
- The theme styles what the plugin Otherguise prints (classes `wiki-*`, blocks `wiki/*`); it reimplements none of it.
- Print: CSS rules in `modes/print.css` first; a print template only when CSS cannot do it.
- Say what was only tried on a scratch WordPress and what was never tried on the site or on a real device.
- Never ask for, store or commit tokens or passwords. Do not create repositories, pull requests or push to another branch unless asked.
```
