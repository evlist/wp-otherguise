<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 403 — Access and Books: previewing unpublished sections and posts

Status: **coded in part (2026-10-25)**: the PDF job and the preview flag are done; the **links of a section page are not**, see "Left for later". Dependencies: slice 400 (links), slice 401 (setting); the dependency goes from Books to Access, through functions only (`access_url()`, `access_links_available()`), never the other way, and Books works without Access.

## Goal

Count the pages of, and proofread, a post that is **not published**, with the PDF job of slice 220. This is the "use by the PDF job" that slice 400 left out. It is a **preview**, never the version to print (see the limits).

## What it does

- **The job converts a post that is not published** (draft, pending, private, future) through its **private link** (`?shared=<token>&mode=<slug>`): `WordPressPosts::info( $id, $create )` gives `preview`, `available` (the Access module is there, the links are on, the post can have one: `access_links_available()`) and the address, which is the link. A published post is converted by its permalink as before.
- **The link is made only by what asks for the conversion** (the request, and the job when it runs, in case the link was revoked in between), **never by the polling** of the state, which would otherwise make a token every few seconds.
- **Refusals**: a post that cannot have a link keeps the error `books_pdf_not_published` (its message now says why); a link that could not be made is `books_pdf_no_link`; a job that finds no link fails with a message.
- **The PDF says that it is a preview**: the state and the file answer carry `preview`, the file is named `<slug>-preview.pdf`, the cache key includes it (so the PDF made before publication is not served after it), and the panel says: "Preview of a post that is not published: the QR code and the addresses are not final, so this is not the version to print."
- **Who may ask** (decided by Eric, 2026-10-25): `edit_post` on the post (the routes of slice 220) **and the capability that the settings of the Access module ask for to make a link** (slice 401). Otherwise the state, the request and the file answer `books_pdf_link_forbidden` (403), and no link is made. The job run by WP-Cron has no user, so it does not look at this: it was checked when the conversion was asked for. Books asks `access_user_may_share()`, a function of the Access module.

## Limits

- **Not for the final printed version**: the QR code of an unpublished post carries `?p=<id>`, see slice 400. Preview and page counting only.
- The page counted is the page of the print mode at the time of the request: the cache key follows the last change of the post.
- A leaked link gives read access to the draft: the same risk as slice 400, judged low by Eric.

## Left for later (not coded)

The part of the proposal about the **page of a section**: opened with a valid link, the section would show its units, each unpublished one with **its own** token (none for the section itself, none without a valid link). It needs something to show the units on the front end, and **nothing does yet**: the loop block of `books.md` (point 1 of the proposals of 2026-10-25) has not been built, and its first experiment (a variation of the Query Loop block) has not been made. Until then there is no link to add a token to. Questions kept for that slice: whether the section page lists unpublished units at all (proposed: yes, only with a valid link for the section), and the cost of making the tokens of a long list (on demand, when the link is followed).

## Tests

`BooksPdfTest`: reading a post through its link (the link made only when asked, none when none can exist), a draft converted as a preview (state, request, job, file, name, polling), a draft without a link (all the refusals and the failed jobs), the capability of the settings (reading with and without it, the three routes refused); `books-preview-pdf.test.js`: the notice of the panel, and none for a published post.

## Verified

On a scratch WordPress 7.1.3, through the real REST routes in a logged-in editor (Chromium), with a **stand-in for Gotenberg** written for the occasion (a few lines of PHP: it records the address it is asked for and answers a small file): the status of a draft before the request (`preview`, no token made), the request, the job run by WP-Cron, `done` with 3 pages (the stand-in's answer), the file named `post-323-preview.pdf` with `preview`. The address received by the server was the link with `&mode=print`, and that address, fetched by hand as an anonymous visitor, shows the draft.

The capability of the settings, with a real editor user: refused with `books_pdf_link_forbidden` when the setting asks for administrators, accepted when it asks for editors.

**Not verified**: a **real Gotenberg** (so nothing about how it renders the page), the stand-in did not fetch the page itself (the PHP development server of the scratch site serves one request at a time, so the job could not call it back: not a problem of the plugin, but it means that no conversion of the real page was seen), the panel's notice in the real editor, the blog's sites.
