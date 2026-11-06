<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 504 — Wiki: the page that does not exist (the 404 that offers to create it)

Status: **coded on the branch `wiki` (2026-11-06)**, not merged into `main`. Dependencies: slices 503 (the 404 keeps the space and the exact title), 501 (`similar`, `elsewhere`), 502 (the creation of a page). Idea of Eric (2026-11-06): the page "404" proposes to create a page that does not exist when the address matches a page of a space. Context: [`../design/wiki.md`](../design/wiki.md).

## What it does

When the address is `/<Space>:<Title>` for a **registered space** and **no page has that name**, the answer is a **404** (the right status for a page that does not exist) that **knows what was asked** and offers to create the page.

- **What the 404 knows** (from slice 503): the registered space, and the exact title after the rules of slice 501 (with the accents: the address is the title). A title the rules refuse (a forbidden character, too long) is an ordinary 404 with the reason, and no offer.
- **What it shows**, by a **block `wiki/missing-page`** for the template `404` of a block theme, and **a minimal output of its own** when the theme has no such block (so that the module works with any theme, the wiki being probably not on the instance of the blog):
  - "This page does not exist: `Biography:Sant Llorenç de Morunys`";
  - **if the user may create it**: a form with a button "Create this page" (a POST, a nonce);
  - **the other angles**: the pages of the same title in the other spaces (`Names::elsewhere()`), as links;
  - **similar pages**: the pages of the same space whose folded name is the same (`Names::similar()`): "a page of this space has a similar name: `Sant Llorenc de Morunys`" (an address typed without the accent).
- **Who may create**: a logged-in user who may create content of the type `wiki_page` (`current_user_can( $type->cap->create_posts )`), with a filter `wiki_may_create( $allowed, $space, $user )` for the rights per space (the reflection of [`../design/capabilities.md`](../design/capabilities.md) is open: one right for all the spaces to begin with). Anybody else gets the normal 404 with the other angles and the similar pages, **no form**, and no way to make a page by asking for addresses (a crawler asks for thousands).
- **The creation**: a POST handled on `admin-post.php` (`action=wiki_create`, the nonce tied to the pair space and title; the capability checked again; the space looked up again): the name is put through the rules and **claimed** (slice 501: if it was taken in the meantime the user is sent to that page); the page is created as a **draft** of the type `wiki_page`, with the template of the space (slice 500), the title **as it was in the address**, the meta `wiki_space`; the user lands in the editor. The address that led there is the address of the page.
- **Where the logic and where the look**: the logic (what the address means, who may create, the creation, the lists) is the module's; the look is the theme's: the block and the minimal output give the markup with classes (`wiki-missing`, `wiki-angles`, `wiki-similar`), no style of their own beyond a few CSS variables with a fallback.
- **No chain of parents to create**: with the level 3 there is no hierarchy, `1958-1968/fr` is one title (this closes the question of the design note about the missing parents).
- **A red link of slice 505 points to this address** (it is an ordinary link to the address of a page that does not exist yet), so the same page serves both ways to arrive.

## Coded on 2026-11-06 (branch `wiki`)

- `Missing\MissingPage` (the logic and the markup): `asked()` (the space and the title that slice 503 keeps on a 404, nothing for another request), `view()` (the name as the rules make it, the angles from `Names::elsewhere()`, the similar pages from `Names::similar()`, the right to create, or the reason when the title is refused) and `render()` (a `div.wiki-missing`: "This page does not exist: <name>", the form, `div.wiki-angles`, `div.wiki-similar`; no style). The pages that the user may not see are left out of the lists. `allowed_html()` gives the tags for `wp_kses()`; a test checks that the markup holds nothing else.
- **Who may create**: `MissingPage::may_create()`, the capability `create_posts` of the type `wiki_page` and the filter `wiki_may_create( $allowed, $space, $user )`. The form is **shown only to such a user**; the others get the 404, the angles and the similar pages, and nothing that writes.
- `Missing\CreateAction` on `admin-post.php` (`action=wiki_create`, hooked on `admin_post_wiki_create` **only**, so a logged-out POST does nothing): the nonce **tied to the pair** (`wiki_create:<space>:<title>`), the right checked again, then `Pages::create()` as a **draft** with the title of the address and the template of the space; the user lands in the **editor**. A name **taken in the meantime** sends the user to that page; a refused title or an unknown space stops with a message (400), a bad nonce or no right with 403. Nothing is made in those cases.
- `Missing\Presentation`: the **block `wiki/missing-page`** (registered from PHP with `autoRegister`, so no editor script; in the editor it shows a note), a **template `wiki-missing`** for block themes (`register_block_template()`, header, the block, footer) put first in the hierarchy of the 404 **of a wiki address only** (`404_template_hierarchy`), and `templates/missing-page.php` for classic themes (`template_include`, late). The status stays **404**.
- Differences with the text above: the form is a plain `POST` to `admin-post.php`; the title of the offer is the title of the **name** (`Biography:Elisabeth`, the first letter made a capital), not the raw text of the address; the question "how the module gives its template to the 404 of a block theme" is answered by `register_block_template()` (WordPress 6.7) and the hierarchy filter.

## Tests

`WikiMissingDbTest` (9, with the real statements): what was asked, the view (angles, similar pages, rights, hidden pages), a refused title, the markup, the markup against `allowed_html()`, the creation, what stops it (nonce, nonce tied to another title, no right), a name taken in the meantime and the refusals, the presentation (block, template, hierarchy, classic theme). `WikiModuleTest` expects the hooks. The whole suite passes with the database (756 tests, none skipped), `phpcs` is clean.

## Verification

- **Tried** on the scratch WordPress 7.1.x (a block theme: Twenty Twenty-Five and its child), pretty permalinks, with `curl` and Chromium: `/Biography:Nobody` is a **404** with the offer (no form for a visitor); `/Biography:Elisabeth` offers `Biography:Élisabeth` as a similar page; `/Biography:Rome` lists `Opinion:Rome` as the same title in another space; a title with a forbidden character gives the reason and no offer; an unknown space is an ordinary 404 (no offer). Logged in as an administrator: the form appears, **a click creates a draft** (title, space, name) and **lands in the block editor**; a form whose title was changed by hand is refused (**403**, nothing made); a **logged-out POST** to `admin-post.php` makes nothing. The template of the plugin shows the header and the footer of the theme around the offer.
- **Never tried**: a **classic theme** (the template file and `get_header()`), a block theme with its own 404 that already holds the block, `register_block_template()` on a WordPress older than 6.7 (the module falls back to nothing: no offer), the block in the site editor, a user who may create posts but not pages, `wiki_may_create` with a real rule per space, the page seen from a crawler (the 404 is the same status and holds no form).

## Not done

The links and the red links (505); the rights per space; the title alone as an address (`/Paris`: a list of the angles).
