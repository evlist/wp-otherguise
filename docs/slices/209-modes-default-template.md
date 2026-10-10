<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 209 — Modes: the default template of new posts

Status: **planned**, to confirm with Eric before any code. Dependencies: slices 202 to 204 (the screen, the variants), 206 if it comes first. Module: `Modes`.

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
- The action on `wp_insert_post` above, only when the modes are enabled and a template is set for the type, only for an auto-draft with no template.
- The template is checked when the setting is saved (it exists for the active theme) and again when it is applied (a template that is gone is ignored: the post keeps the default template).
- Not done for **existing** posts. To give a template to the posts that already exist, one command does it, outside the plugin: `wp post list --post_type=post --format=ids | xargs -n1 -I{} wp post meta update {} _wp_page_template publication-randonnee`, to be tried on a copy first.

## Not in this slice

A template per category or per term; a template chosen by the mode (the variants already do that); a bulk screen for existing posts.

## Tests

PHPUnit: the hook (an auto-draft of the type gets the template; an update, another status, another type, a post that has one, disabled modes, a template that is gone: nothing), the setting (validation, sanitizing, the screen). On a real WordPress: a new post opened in Chromium has the template selected and keeps it, the author's choice of "Default template" is kept, a post created by WP-CLI is untouched.

## To confirm

1. The setting is **per post type**, on the screen of the modes, not tied to a mode.
2. Only for **new posts opened in the editor** (auto-drafts), not for posts created by other means.
3. Nothing for existing posts (the command above, outside the plugin).
4. Applied only when the modes are enabled.
