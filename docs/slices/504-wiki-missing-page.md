<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 504 — Wiki: the page that does not exist (the 404 that offers to create it)

Status: **proposed (2026-11-06), not coded**. Dependencies: slices 503 (the 404 keeps the space and the exact title), 501 (`similar`, `elsewhere`), 502 (the creation of a page). Idea of Eric (2026-11-06): the page "404" proposes to create a page that does not exist when the address matches a page of a space. Context: [`../design/wiki.md`](../design/wiki.md).

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
- **The red link of slice 505 points to this address**, so the same page serves both ways to arrive.

## To look at

How the module gives its template to the 404 of a block theme that has none: `register_block_template()` (WordPress 6.7, to check on the version of the site) or a filter on the template of the 404 request.

## Tests (planned)

The state of the request (a registered space and a free name; an unknown space is an ordinary 404; a refused title); who sees the form (may create, may not, logged out); the nonce (valid, invalid, tied to another title); the creation (a draft, the title, the space, the template, the claim, the redirect to the editor; the name taken in the meantime; a refused title posted by hand); the lists (other angles, similar pages; a page that the user cannot see is not listed; an unlisted page, slice 402, is not listed); the block and the minimal output; the filter `wiki_may_create`. A Chromium run on a scratch WordPress with pretty permalinks: type an address, see the offer, create, land in the editor.

## Not done

The link by name and its red link (505); the rights per space; the title alone as an address (`/Paris`: a list of the angles).
