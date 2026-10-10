<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 209 — Modes: the default template of new posts

Status: **done** (confirmed by Eric, who asked whether it really depends on the modes: **it does not**, see below). Dependencies: slice 204 (the screen). Module: `Modes`, for lack of a better home.

## Goal

Eric's custom template `publication-randonnee` has to be chosen by hand in the "Template" panel of every new post, with a risk of forgetting it. A new post of a given type should start with the template the site has chosen for it; the other modes reach their own version through the variants (`publication-randonnee-print`).

## Checked in WordPress 7.1.3 (2026-10-10)

**There is no setting, option or filter for it.** What was looked at, in core and in the code of the editor:

- The template of a post is the post meta `_wp_page_template`; the editor shows "Default template" when it is empty (`template` field of the REST API). Nothing chooses another one for a new post.
- Near misses that are **not** this: the argument `template` of `register_post_type()` is the default *blocks* of the content (a pattern), not the template of the page; `customTemplates` in `theme.json` and `register_block_template()` (with `post_types`) only *declare* templates that can be chosen; the filter `default_template_types` lists the types of templates of core.
- The filter `default_post_metadata` could answer for `_wp_page_template`, but it would change **every** post that has no value, old ones included.

**What works** (tried on a scratch site, with a throw-away mu-plugin, then removed): an action on `wp_insert_post` that sets `_wp_page_template` when the post is an **auto-draft** of the chosen type and has no template yet.

- The editor creates an *auto-draft* when a new post is opened (`post-new.php`); the hook gives it the template, and the editor opens with the template already selected (`template = og-rando` in `core/editor`).
- Saving and publishing keep it; **the author who chooses "Default template" and saves keeps that choice** (the hook runs only at creation, never on updates).
- A post created by WP-CLI, the REST API, an import or XML-RPC is **not** an auto-draft, so it is not touched. Restricting the hook to the auto-drafts is what limits the feature to "a new post opened in the editor".

## Proposal

- A setting **Template of new posts**, one choice per post type (the types of the site that support the editor), on the screen of the modes: a list of the templates of the active theme (with "None", the default). Stored in the option `modes_settings` (key `default_templates`, a map from post type to the slug of a template).
- The action on `wp_insert_post` above, for an auto-draft of a type that has a template and has none yet. **It does not depend on the modes**: the switch "Enable the modes" does not govern it (changed from the first plan, after Eric's question). The feature lives in the Modes module only because the screen, the settings and the lookup of templates are there; the classes (`Settings\DefaultTemplates`, `Template\NewPostTemplate`, `Admin\DefaultTemplatesScreen`) know nothing of modes and could move to a module of their own, or to a general settings page of Otherguise, without change.
- The template is checked when the setting is saved (it exists for the active theme) and again when it is applied (a template that is gone is ignored: the post keeps the default template).
- Not done for **existing** posts. To give a template to the posts that already exist, one command does it, outside the plugin: `wp post list --post_type=post --format=ids | xargs -n1 -I{} wp post meta update {} _wp_page_template publication-randonnee`, to be tried on a copy first.

## What was built

- `Settings\DefaultTemplates` (the option `modes_default_templates`, a map from type to slug; `sanitize()` keeps only known types and templates that exist), `Template\NewPostTemplate` (the hook, on `wp_insert_post`, injectable meta functions), `Admin\DefaultTemplatesScreen` (the section **Template of new posts**, one list per type, its own Settings API group and capability filter), `TemplateLookup::post_types()`; the option is removed on uninstall.

## Verified

- PHPUnit (`ModesDefaultTemplatesTest`, 7 tests, no database): nothing by default whatever the option holds; existence of the template (block template, file of a classic theme, refused shapes); sanitizing; the auto-draft gets the template; updates, other statuses, other types, a post that has one, a template that is gone, no setting and a value that is not a post: nothing; the section (lists, default first, selection, Settings API fields, escaping). Plus the hooks of the module and of the screen.
- **Real WordPress 7.1.3 in Chromium** ([`tests/real-wordpress/default-template/`](../../tests/real-wordpress/default-template/flow.js), 16 checks, **the modes disabled throughout**): a new post has no template before any setting; the setting saved from the screen (`{"post":"og209-rando"}`); a new post opens with the template selected; publishing keeps it; the author choosing "Default template" keeps that choice; a page, and a post created by WP-CLI, are not affected; a template that is gone is ignored; choosing the default template removes the setting.

## Not verified

A classic theme, a custom post type with its own template names, several authors editing at the same time, and the case of a template chosen for a type whose editor is the classic one (the hook gives the same meta, but the classic editor was not tried).

## Not in this slice

A template per category or per term; a template chosen by the mode (the variants already do that); a bulk screen for existing posts.

## Tests

See "Verified".

## To confirm

1. The setting is **per post type**, on the screen of the modes, not tied to a mode.
2. Only for **new posts opened in the editor** (auto-drafts), not for posts created by other means.
3. Nothing for existing posts (the command above, outside the plugin).
4. ~~Applied only when the modes are enabled.~~ Changed: independent of the modes (answer to Eric's doubt). Its own option, `modes_default_templates`, and its own form on the screen, since the form of the switch posts only the switch.
