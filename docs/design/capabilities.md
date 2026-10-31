<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rights of access: an overall reflection to come

Status: **Open** (Eric, 2026-10-31). Nothing here is decided. Eric's remark, when the rights of the book projects were looked at: the rights of Otherguise go **a little beyond the standard frames of WordPress**, and a reflection on all of them should be made one day, rather than deciding them screen by screen. This note records the state of the code on that day and the questions that the reflection has to answer.

## What the code does today (read on 2026-10-31)

| What | Who may | Where it is set |
|---|---|---|
| The menu "Otherguise" and its overview | `edit_others_posts`; each line of the overview is shown for the capability of its screen | `Core\AdminMenu` |
| Printer profiles and book projects (menu, list, creation, edition, publication, trash, deletion) | `edit_others_posts` for every capability of the two types (editors and administrators); the form revalidates `edit_post` and a nonce | `Project\PostTypes`, `Project\MetaBoxes` |
| Settings of Books (page format, PDF server) | `manage_options`, changeable with the filter `books_admin_capability` | `Admin\SettingsScreen` of Books |
| Settings of Access | `manage_options`, changeable with `access_admin_capability` | `Admin\SettingsScreen` of Access |
| Who may make a private link or set a post "unlisted" | a setting of Access: whoever may edit the post (`edit_post`, the default), editors and administrators, or administrators only; the filter `access_link_capability` | `Settings\Settings` of Access |
| Screen of the modes (variants, stylesheets) | `edit_theme_options`, changeable with `modes_admin_capability`; the files of the stylesheets also ask `upload_files` | `Admin\Environment` of Modes |
| Screen of the relations (Triples) | `manage_options`, changeable with `triples_admin_capability` | `Admin\Environment` of Triples |
| Routes of the PDF, of the sections, preview panel of the editor | `edit_post` of the post (`edit_posts` to search for posts to add to a section) | `Rest\PdfRoutes`, `Rest\SectionRoutes`, `Preview\EditorPreview` |
| Print page of a section with all its units | `edit_post` of the section, or the signed token of the PDF job (one section, one hour) | `Merge\MergeAccess` |
| A post that is not published, seen by a private link | whoever has the token of the link, read only, until the link is revoked | `Access\Link` |

## Why it goes beyond the usual frame

- **Several levels of rights for one workflow.** Preparing a book touches the editors (the sections, the projects, the profiles), the administrators (the format, the PDF server, the relations) and the theme (the modes): a role that can do one step may not see the next.
- **Rights that are not roles.** The private links and the token of the merge give access to a post or to a whole section to somebody who is not logged in. The setting of Access chooses a capability and not a role.
- **Contents that are not posts.** The printer profiles and the book projects use the machinery of the posts (capabilities of `post`, `map_meta_cap`) but are never shown on the site; all their capabilities are mapped to one, `edit_others_posts`, so there is no difference between seeing, editing and deleting, and no "own" or "others'".
- **One filter per module** (`books_admin_capability`, `access_admin_capability`, `modes_admin_capability`, `triples_admin_capability`) and one setting for Access, but no overall place to say who may do what.
- **No rights on what is related**: a project can point to any section, and the editor panel shows the title and the format of the projects that cover a post to whoever can edit that post.

## Questions for the reflection (open)

1. A **model of capabilities of its own** (for instance one to manage the settings, one to manage the books, one to publish a PDF), given to roles, rather than reusing `manage_options`, `edit_theme_options` and `edit_others_posts` where they happen to fit? With a screen of the settings to say which role has which, or the roles of WordPress only?
2. Should a **book project or a printer profile** have an owner, or rights per section or per book (somebody who may prepare one book and not the others)?
3. How do the **tokens** (private link, merge) fit: who may make them, for how long, who may see or revoke them, and what is logged?
4. **Multisite**: one set of rights for the network or one per site?
5. What the **menu** should show to a role that cannot open most of its screens (an editor sees an overview with a few lines).
6. How the **WP-CLI** commands and the **REST routes** apply the same rules as the screens (the commands run without a user today).

## Tried and not tried

Only the administrator was tried on a real WordPress (a scratch site, in Chromium); the other roles are covered by unit tests of the capability that each screen asks for.
