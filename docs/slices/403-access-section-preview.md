<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 403 — Access and Books: previewing unpublished sections and posts

Status: **proposed (2026-10-25)**, nothing coded. Dependencies: slice 400 (links), slice 401 (setting), slices 223 and 224 (sections). The dependency goes from Books to Access through `access_url()`, never the other way.

## Goal

Proofread a section, and have the PDF job convert it, while the section or some of its posts are **not published**. This is the "use by the PDF job" that slice 400 left out.

## Proposal (not decided)

- **No token for a section.** Slice 400 has already refused it. One token per post, created on demand.
- The page of a section opened with **its own valid token** shows its units with their links; for each unit that is not published, the link carries **that unit's token** (created on demand through `access_url( $id, array( 'create' => true ) )`). A section opened without a token adds no token, so sharing a published section reveals nothing about drafts.
- The PDF job asks `access_url()` for a post that is not published and keeps the mode (`print`).
- The panel of the section shows that links exist and lets the editor revoke them.

## Limits written in advance

- **Not for the final printed version**: the QR code of an unpublished post carries `?p=<id>`, see slice 400. Preview and page counting only.
- A leaked section link gives read access to the unpublished units it lists: the same risk as slice 400, judged low by Eric.

## Open points

Whether the section page should list unpublished units at all for a visitor with a token (proposed: yes, only with a valid token for the section); the cost of creating tokens for a long list (create on demand, when the link is followed or the job runs).

## Tests (planned)

PHPUnit for the rule "token on the section, tokens on the units, none without"; jsdom for the panel; one try on a real WordPress with a draft unit, anonymous.
