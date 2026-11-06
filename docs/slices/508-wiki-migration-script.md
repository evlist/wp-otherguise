<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 508 — Wiki: the migration from the SelfPedia (a throw-away script outside the plugin)

Status: **proposed (2026-11-06; rewritten the same day), not coded**. Dependencies: slices 501 (`Names`), 502 (`Pages`), 503 (the addresses and the redirects of the aliases). Context: [`../design/wiki.md`](../design/wiki.md). **Eric, 2026-11-06:** the wiki has few pages, the migration can be semi-manual and stay as simple as possible; and he asked whether the plugin should be "cluttered" with the code of the migration.

## What changed from the first version of this slice

The first version put in the plugin a command `wiki alias <file>` and a function `Address::from_mediawiki()` (the links of MediaWiki turned into addresses of the site). **Both are migration code**: they depend on the shape of the SelfPedia, are used once, and have nothing to do in a product. They are **out of the plugin**.

The rule of `CLAUDE.md` ("a step of a migration that touches what a plugin is responsible for is a WP-CLI command that can be run twice, with `--dry-run`, a report and a way to check the result") is read as aimed at the migrations **of the production blog**. Here the conversion of foreign content stays outside; **only what writes in the data of the plugin goes through its public API**, and the script keeps the properties of the rule (run twice, `--dry-run`, report, check). **Eric decides** if the rule is to be applied as it stands (a command in the plugin).

## What the plugin gives (generic, useful without the migration)

- the services **`Names`** (claim, resolve, aliases: `Names::add_alias( $post_id, $space, $title )`, which `move` of slice 505 needs anyway) and **`Pages::create( $space, $title, $html, $status )`** (slice 502: the rules, the claim, the space, the template; the same service as the creation of the 404, slice 504, and of the command `wiki create`);
- the commands `wiki create`, `wiki names`, `wiki move`, `wiki rename-space`, `wiki relink` (slices 502 and 505).

Nothing else: **no command that imports, no function that knows MediaWiki.**

## The script (outside the plugin)

- **Where** (**decided by Eric, 2026-11-06**): the repository `evlist/shared-room`, `tools/wiki-migration/` (the tools of the blog live there), run with `wp eval-file` on the site; it is archived or deleted when the migration is done.
- **Scope** (**decided by Eric, 2026-11-06**): **every page of the wiki except the native MediaWiki pages** (the namespaces Special, MediaWiki, Help, Template, Category, File and the talk pages are not migrated as pages), not only the namespace `Biography`: the user namespaces of the wiki (`Biography`, `WikiVlist`...) **and the pages of the main namespace**, which were the **encyclopaedic pages** of the wiki.
- **The space of the pages without a namespace** (**decided by Eric, 2026-11-06**): they go to a **new space dedicated to them**; working name **`Encyclopédie`** (to confirm before the first run; the name is a line of the list, not code).
- **Input**: a **list** with one line per page (tab separated): `old name`, `new qualified name`, `file`; and a **directory of files**, the pages converted to HTML (Pandoc, by hand). The old name is a **title as the wiki gave it** (with its page id in the file name, see above).
- **The names, decided by Eric on 2026-11-06** (seen on the ten pages of `Biography`):
  - the **suffix `/fr` is removed** from titles and from link targets (`Biography:1958-1968/fr` becomes `Biography:1958-1968`): the multilingual layer of the wiki is out of scope, so there is **no alias** for the old name (no `Names::add_alias()` in the migration); the **redirect from the old host** has to strip the `/fr` (a rule of the old server, not code of the module);
  - the **root pages** (`Biography:11 septembre`, whose only content is `{{Langues}}`, the language selector) are **not migrated**; the template `{{Langues}}` is **removed** from the leaves;
  - a space after the colon in a link (`[[Biography: Jean|...]]`) is an **error of the wiki**: the script trims it;
  - a link without a namespace (`[[Title/fr]]`) goes to the space `Encyclopédie` with the `/fr` removed;
  - the **`/en` leaves are not migrated** (**decided by Eric, 2026-11-06**): they are real translations, but the multilingual layer is out of scope; the script lists them in the report as "language variants, not migrated";
  - the **redirects are ignored** (**decided by Eric, 2026-11-06**): no page, no alias (`Éric van der Vlist` to `Eric van der Vlist`, `SelfPedia/fr` to `WikiVlist:SelfPedia/fr`, `Internals/fr` to `WikiVlist:I18n Hack/fr`); the old names of the redirects are lost on purpose;
  - the **title shown** by the page: the template `{{Langues|fr=SelfPédia|en=SelfPedia}}` or `{{Langues|fr=vdV}}` gives the display title of the leaf (a title that the first-letter rule cannot be, such as `vdV`): the script uses the parameter of the language kept (`fr=`, or the first one) as the **title of the post** when there is one, and the title part of the name otherwise (**proposed**);
  - two pages that give **the same name** after the removal of `/fr` (or a name that already exists) stop the line with an **error in the report**: nothing is overwritten.
- **The templates** (seen on the real wiki, 2026-11-06): a small table of known templates, and **any other template stops the line with an error naming it** (nothing is dropped silently, which is what Pandoc does):
  - `{{Langues}}` (language selector, with optional parameters): removed **only when it stands alone on its own line**; elsewhere it is an error, because one page (`WikiVlist:I18n Hack`) names the template **in running text** and a blind removal would damage it;
  - `{{Source privée|<date>}}` becomes `<small class="source-privee">(Source : Information fournie par le sujet via communication privée, <date>)</small>`: the text is what the wiki renders (seen on one page); the grey style is the theme's business;
  - `{{Infobox Personnalité|...}}` (one page): **not scripted**, it is rebuilt by hand in the editor (image, caption and a table); it appears in the report as an error until the line is marked as handled.
- **The other things of MediaWiki** that the script handles or reports: the **interwiki links** `[[wikipedia:fr:Title|label]]` and `[[wikipedia:en:Title|label]]` become `https://<lang>.wikipedia.org/wiki/<Title>` (the other prefixes come from `meta=siteinfo&siprop=interwikimap`, an unknown prefix is an error); `[[Category:...]]` is removed (categories are not migrated, as below); `[[:Category:...|label]]` (a link to a category) becomes its label as plain text and is listed in the report (**proposed**); the **root pages** (a page whose text is only `{{Langues}}` and categories) are skipped.
- **To look at on the first conversions** (Pandoc has not been run on any real page of the wiki yet): `<syntaxhighlight>`, definition lists (`;` and `:`), `<pre>` blocks with drawing characters, tables with `style`, `<ref>` with a malformed `<nowiki>` inside, a `<p>` closed inside a `<blockquote>`, a section "Références" with no `<references />` (the wiki may add the list itself). **Open**: how the notes are rendered in WordPress (the core Footnotes block, or a plain list).
- **What it does**, for each line, **in this order**:
  1. reads the file and **rewrites its links** (a pure function of the script: `Biography:1958-1968/fr`, `/Biography:1958-1968/fr` (the short address of this wiki, seen in the rendered HTML), `index.php?title=Biography:Paris` become the **addresses of this site**, with the rules above; a link of MediaWiki with the class `new` or `action=edit&redlink=1` becomes an ordinary link, **a red link here** until the page is made; a link to another site is left alone), so that they are the links of slice 505 from the first day;
  2. **creates the page** with `Pages::create()` (a page whose new name exists is **skipped**, so the script can be run twice).
- **`--dry-run`** writes nothing and reports; **the report** counts what was created and skipped, and lists the **errors by line**: a name that the rules refuse, an old name held by **another** page, a missing file; **`--check`** reads the list again and checks that **every new name resolves** (`Names::resolve()`) to a page.
- A **small test of the pure function** (the forms of the links above) next to the script; the rest is tried by the dry-run and on the test site.

## The procedure (done by hand)

1. List the pages of the SelfPedia worth keeping (MediaWiki: *Special:AllPages*, by hand).
2. Convert each (Pandoc, from the export of the wiki or from the page itself) to HTML; **look at the result**: what is lost (the quotations, the notes, the categories, the templates) is **written down** (this is the bounded test of the design note).
3. Write the list (the names that changed their name or their space) and run the script, `--dry-run` then for real, then `--check`.
4. **The media**: the panel of Media Helper inserts the photos from Nextcloud (not part of this slice).

## Fetching the pages from the wiki (decided by Eric, 2026-11-06: in bulk, as JSON)

- **In bulk through the API**, not page after page: `api.php?action=query&generator=allpages&gapnamespace=0&gapprefix=Biography&gaplimit=50&prop=revisions&rvprop=content&rvslots=main&format=json`, following the `continue` field until the end; **one local file per batch**, so a run can resume without downloading again. The alternatives are `Special:Export` (one XML file for a list of titles) and `maintenance/dumpBackup.php --current` (needs shell access on the wiki server, not known).
- **The trap of a namespace declared after the pages** (seen on the real wiki on 2026-11-06, from Eric's screenshots and answers): the namespace `Biography` (id 3002, subpages enabled) exists, but the old pages are still **in the main namespace under the literal title** `Biography:...`. Today MediaWiki reads that prefix as the namespace, so `index.php?title=Biography:1958-1968/fr` shows an **empty page of the namespace** ("no text on this page"), and `Special:AllPages` on the namespace Biography is **empty**. Consequences: fetch by **page id** (the generator above returns `pages` indexed by id; `index.php?curid=<id>&action=raw` for a single page), **never by title**; the API refuses `apprefix=Biography:` in the main namespace (`Bad title`): use the prefix **without** the colon and drop false matches. The script does not repair the wiki (`maintenance/namespaceDupes.php` can move such pages, options to check with `--help`, backup first; optional and not needed here).
- **Wikitext is not enough for the HTML**: Pandoc drops the templates. For pages that contain templates, call `action=parse&pageid=<id>&prop=text` (the wiki itself expands them) or turn the templates into plain HTML before Pandoc. **Not known yet** whether the pages of `Biography` contain templates.
- **To handle in the script**: redirects (an alias to keep, no content of its own), empty or deleted pages (no article), and the images (the text only names the files; the files are fetched apart, `list=allimages`, and are the media panel's business).
- **Not tried**: the bulk request was written by Claude and **has not been confirmed on the real wiki** (Eric had not yet run it when this was written).

## What the real wiki contains (seen on 2026-11-06 through the JSON API, Eric's outputs; structure only)

Sixteen pages are candidates, **counted by Claude from those outputs**: four in the main namespace (`Accueil`, `Eric van der Vlist`, `Vdv`, `Crise du logement après la Seconde Guerre mondiale en France`, all `/fr`), nine in `Biography` (one long chapter, one medium page, seven short notes) and three in `WikiVlist` (`I18n Hack/fr`, `Instructions IA/fr`, `SelfPedia/fr`). The rest are roots (only `{{Langues}}`), `/en` leaves and redirects, **not migrated**. The other namespaces (user, project, help, template, category) were **not looked at**.

Two points to settle by hand, in the list (not in code): **`WikiVlist:I18n Hack` and `WikiVlist:Instructions IA` document MediaWiki** (the multilingual hack that this migration retires, and instructions for an AI assistant of the wiki), so whether they are migrated, kept as an archive or dropped is **Eric's call** (**open**); `Accueil` is a portal (a two-column table and links to categories that do not exist here), probably to be redone by hand. Eric knows the content; Claude knows it only from these outputs.

## What Pandoc does with a page of MediaWiki (checked on 2026-11-06)

Pandoc 3.1.3, `pandoc -f mediawiki -t html5 --wrap=none`, on **a sample page that Claude wrote** (templates, a note, categories, an image, links of every form), **not on a page of the SelfPedia**:

- **a link** `[[Biography:1958-1968/fr|the period]]` becomes `<a href="Biography:1958-1968/fr" title="wikilink">`: a **relative address with no leading slash, the underscore for the space** (`Une_page_absente`), and the marker `title="wikilink"` that the script can use to tell the internal links from the others; **a category** becomes an ordinary link `Category:...` (to remove);
- **a template is dropped without a word**: `{{Source|text|origin}}` and `{{Citation|...}}` leave **nothing** in the output (the content is lost);
- **a note** `<ref>` becomes a footnote (a list at the end of the document, with its back links), and a `<references />` stays in the text as a literal tag (to remove);
- **an image** `[[File:...|thumb|caption]]` becomes a `<figure>` with the **file name as a relative address** (the files are the media panel's business);
- the headings and the lists are kept (`==` gives an `h2`).

The two ways to get a page, to compare on one page: the **wikitext** (`index.php?title=<page>&action=raw`) through Pandoc, which loses the templates; or the **page rendered by MediaWiki** (`index.php?title=<page>&action=render`), an HTML fragment **with the templates expanded** and the notes rendered, but links that are `/wiki/...`, the classes of MediaWiki and no WordPress footnotes. A template such as `{{Source|text|origin}}` (the template of the documentary blocks of the SelfPedia) can also be **turned into plain HTML in the wikitext before Pandoc** (a `<blockquote class="source">` with a caption: the portable form that `ideas.md` suggests).

## Where it is tried

**`sb.vli.st`** (Eric, 2026-11-06): a new WordPress with Media Helper, Otherguise and the other plugins, **without the module Wiki** (nothing of it is coded, and the moratorium of the SelfPedia applies): the **first test** is therefore the conversion of a page with Pandoc and its pasting into an ordinary page, and the insertion of photos by the media panel; the script itself comes after slices 500 to 503 are coded. Never tried on a real site.

## What is not the module's business

The redirect **from the old host** (`wiki.vli.st`, a MediaWiki that has its own address form: `/wiki/Title`, `index.php?title=`...) **to the new site** is a rule of the old server or of its front proxy, written once. Whether the new wiki keeps the old host is **open** (Eric).

## Not done

Any import of the history of the revisions; the categories of MediaWiki (a taxonomy of the content, a subject of its own); a Markdown conversion (the pages are given as HTML).
