<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 232 — Books: the format of the site follows the theme

Status: **proposed (2026-10-28)**, not coded. Dependencies: slice 230 (the reference declared by the theme in `theme.json`, the book projects), slices 219 and 220 (the preview panel and the PDF job), slice 221 (the sizes of the images). Context: Eric's questions of 2026-10-28 about the setting "The format of the page of a book" (Settings → Otherguise → Books).

## Why

The setting `books_page_format` (width, height, margins) has its own default, an A5 of 148 × 210 mm with margins of 10 mm, which has nothing to do with the theme. It is still used: by the preview of the editor and by the PDF for a post that **no book project covers**, by the sizes of the images of the print (slice 221, `wp otherguise books sizes`), and as the reference of the factor `--books-k` when the theme declares nothing. Since slice 230 the theme declares the page its templates are composed for (`settings.custom.otherguise.reference` of its `theme.json`): that is the natural default for the site. The help text of the setting, which says that the print stylesheet does not read it, is also out of date for the posts that a project covers (Books writes their `@page` rules from the profile).

## What it does

Decided with Eric on 2026-10-28:

1. **The values of the theme are the defaults of the site**, unless they were changed on that screen: width, height and margin come from the theme's reference when the user has not changed them.
2. **When the values of the theme change, or the theme changes, the values saved by the user are kept** and a **warning** is shown in the admin until the user confirms either that the saved values are kept or that the new values of the theme are adopted.
3. **The screen shows the values of the theme** and a **button to restore them**.
4. **The preview window offers a choice between the projects that cover the post (already the case) and the saved values of the site** ("Site format").

Choices made from the recommendations that Eric validated:

- **Field by field.** Only the fields that differ from the theme are stored; the others follow the theme. Saving the screen stores a field only when it differs from the theme's value at that moment; a field emptied or equal to the theme's goes back to following the theme.
- **Order for each field**: the value saved on the screen, else the value of the theme, else the built-in default (148 × 210 mm, margin 10 mm).
- **Only width, height and margin** are taken from the theme; its gutter and its safety zone have no field on that screen.
- **The sizes of the images** (slice 221) keep following the format of the site (the effective values), as today. Whether they should follow the reference of the theme or the project is a question of slice 230 that this slice does not change.
- **The factor is not changed**: the reference of the theme still comes before the format of the site (slice 230).

## Knowing that the theme changed

- Books remembers the reference of the theme **that the user has confirmed**: the active theme (its stylesheet) and the three values (option `books_theme_reference_seen`, new, removed by the uninstall).
- When the active theme or its reference differs from the one confirmed, a **warning** says which values changed (for instance "The theme Twenty Twenty-Five Printable now gives 210 × 297 mm, margins 10 mm; your site keeps 148 × 210 mm, margins 10 mm"), and offers two buttons: **Keep my values** (the new reference is confirmed, nothing else changes) and **Adopt the values of the theme** (the values saved on the screen are removed and the site follows the theme). The same actions are available on the screen of the setting.
- When **nothing is saved** and the format of the site follows a theme that changed, the notice is **informative** (the format used for the posts without a project, and the sizes of the images, are about to change); it asks for the same confirmation.
- **First run**: when the option does not exist yet (the update of the plugin), the reference now in force is recorded without a notice, so that the update does not start with a warning for something nobody changed.
- **Where it is shown**: the screen of the setting, the screens of the printer profiles and of the book projects, and **the preview panel of the editor** (Eric: an admin who previews should be told of the change). Only for users who can change the setting (the capability of the screen); the others do not see it, since they cannot answer it. The actions are posted with a nonce and the capability is checked again.
- **WP-CLI** (each entry point has a test): `wp otherguise books format show` (the effective values, where each comes from, the reference of the theme, the change waiting for confirmation), `format keep` and `format adopt`; both can be run twice and take `--dry-run`.

## The choice "Site format" in the preview and for the PDF

- The menu of the panel lists the projects that cover the post **and** the entry **Site format**; it is shown as soon as there are two entries (a post with one project has two). A post without a project shows nothing, as today. The default stays the first project that covers the post.
- Today the server cannot be asked for "no project": `0` means "the default project". The request carries a new value, `-1`, meaning "the site format, no project": `books_project=-1` in the address of the page, `project=-1` for the routes `pdf/<id>`, `/file` and `/download` and for the event of the cron. For that value `Projects::for_post()` returns null, `PrintStyles` adds no rules and the PDF job uses the format of the site; its key is the one of a post without a project (the PDF files already made keep their key).
- The page frame, the verdict (fits / overflows) and the PDF use the format of the site for that entry.

## Shape

- `Settings\PageFormat` receives the theme's values (a callable returning `Reference::declared()`, new; null when the theme declares nothing): `get()` returns the effective values, a new `overrides()` the fields saved, `source( $field )` `saved`, `theme` or `default`; `sanitize()` keeps only the fields that differ from the theme's.
- A service of the change of reference (new class in `Settings`): compares the reference of the theme with the one confirmed, `pending()`, `keep()`, `adopt()`.
- `Admin\SettingsScreen`: the values of the theme under the fields, the button to restore them, the notice; the notice on the other screens through one function.
- `Preview\EditorPreview` and `editor/preview/panel.js`: the "Site format" entry, the notice for those who can change the setting.
- Names that belong to the module (`books_` options, `books/` REST namespace); no new table; one new option.

## Tests planned

- **PHPUnit**: each field's order (saved, theme, default), the saving of only the fields that differ, a theme with nothing or something not valid, the first run (no notice), a change of values and a change of theme (notice, keep, adopt, both twice), the informative notice when nothing is saved, who sees the notice, nonce and capability of the actions, the `-1` project (rules, address, key of the PDF and event), the commands with `--dry-run`, the uninstall of the new option.
- **jsdom**: the "Site format" entry in the menu (shown with one project, absent without), the requests carrying `-1`, the notice in the panel for those who can change the setting.

## Not decided

- The exact texts of the notices and of the buttons.
- Whether a user who can edit posts but not the setting should see a neutral line in the panel.
- Multisite (one reference per site or per network): not looked at.

## Verified

Nothing yet: this is a proposal.
