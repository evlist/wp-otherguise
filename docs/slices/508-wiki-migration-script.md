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
- **Input**: a **list** with one line per page (tab separated): `old name`, `new qualified name`, `file`; and a **directory of files**, the pages converted to HTML (Pandoc, by hand).
- **What it does**, for each line, **in this order**:
  1. reads the file and **rewrites its links** (a pure function of the script: `Biography:1958-1968/fr`, `/wiki/Biography:1958-1968/fr`, `../Biography:Paris`, `index.php?title=Biography:Paris` become the **addresses of this site**; a link of MediaWiki with the class `new` or `action=edit&redlink=1` becomes an ordinary link, **a red link here** until the page is made; a link to another site is left alone), so that they are the links of slice 505 from the first day;
  2. **creates the page** with `Pages::create()` (a page whose new name exists is **skipped**, so the script can be run twice);
  3. adds **the old name as an alias** when it differs from the new one (`Names::add_alias()`; an old name that is the same as the new one is the same address: nothing to add, which is the point of the level 3).
- **`--dry-run`** writes nothing and reports; **the report** counts what was created, skipped, aliased, and lists the **errors by line**: a name that the rules refuse, an old name held by **another** page, a missing file; **`--check`** reads the list again and checks that **every old name resolves** (`Names::resolve()`) and every new one is a page.
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
