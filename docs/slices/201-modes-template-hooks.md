<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 201 — Modes: where to hook the choice of a template

Status: **done** (a verification slice: no product code). Dependencies: none. It decides how slices 202 and 203 will work. Module: `Modes`.

## Goal

Answer the question left open in [`design/modes.md`](../design/modes.md): where can the plugin hook the choice of a template so that "template `bar` is the `print` version of template `foo`" works for **block themes** (templates in files and templates stored in the database by the site editor), for **template parts**, and, if possible, for **classic themes**, without the flaws of the hook of the current hack (`get_block_templates`)?

## Method

1. **Reading the source of WordPress core** (`WordPress/wordpress-develop`, branches `6.6`, `6.9` and `7.1`): `template-loader.php`, `template.php`, `block-template.php`, `block-template-utils.php`, `blocks/template-part.php`, `class-wp-block.php`. The functions that matter were compared between the branches with `diff`.
2. **Trying it on a real site**: WordPress 7.1.3 (the release downloaded on 2026-10-09), Twenty Twenty-Five, PHP 8.3.6, MariaDB 10.11.14, served by the PHP built-in web server, driven by WP-CLI. The experiment is in [`tests/real-wordpress/201-template-hooks/`](../../tests/real-wordpress/201-template-hooks/): a throw-away must-use plugin (`og201.php`) that implements four strategies, and `run.sh`, which creates its fixtures, makes the requests and checks the answers (32 checks). It can be run again on any scratch site.

## How WordPress chooses a template (checked in 6.6, 6.9 and 7.1)

For a page request, `template-loader.php` goes through the conditional tags (`is_single`, `is_page`, `is_home`...) and calls the matching getter, which ends in `get_query_template( $type, $templates )`:

1. the filter **`{$type}_template_hierarchy`** receives the list of candidates in decreasing order of specificity (`single-post-hello-world.php`, `single-post.php`, `single.php`);
2. `locate_template()` looks for those files in the child and parent themes (PHP templates);
3. **`locate_block_template()`**, called directly (it is not a hook), looks for a block template: `resolve_block_template()` makes **one** call `get_block_templates( array( 'slug__in' => $slugs ) )`, sorts the result by position in the hierarchy and keeps the first. The templates found come from the database (`wp_template` posts of the theme) and from the files of the theme. The chosen template is communicated through two **globals**, `$_wp_current_template_id` and `$_wp_current_template_content`, and the "template" that goes on is the path of `template-canvas.php`;
4. the filter `{$type}_template` therefore receives the path of the canvas, not the block template; then `template_include`.

A **template part** is different: the block `core/template-part` renders by itself. It reads the `wp_template_part` post of the theme with a `WP_Query`, or the theme file through `get_block_file_template()`. It does **not** call `get_block_templates()`.

These functions are identical in the three branches (`get_query_template`, `resolve_block_template`, the selection of the template part); the only difference seen in `locate_block_template` is how an empty template is displayed.

## The four ways tried

| | Hook | Block themes: templates | Template parts | Classic themes | Side effects |
|---|---|---|---|---|---|
| **A** | filter `get_block_templates` (the hack of `wp-pdf-helper`) | works | **does not work** (the block never calls it) | no | rewrites **every** call: a plain `get_block_templates()` (site editor, REST, other plugins) comes back with its first template replaced |
| **B** | filter **`{$type}_template_hierarchy`**: put the slug of the variant just before the slug of the template | **works**: database variant, file variant, a missing variant is ignored by core | not applicable | **works**: the candidates are file names, `single-print.php` is found | none outside the front-end resolution: not called by the editor, REST or CLI |
| **C** | filter `template_include`, then replace the two private globals | works | no | no | depends on two globals that core documents as internal |
| **D** | filter **`render_block_data`** on `core/template-part`: replace the `slug` attribute | not applicable | **works**: database variant and file variant, also inside a variant template | not applicable | none outside the rendering of the block |

Other observations on 7.1.3:

- **What a variant means.** With B, a variant declared for `single` applies when `single` is the template core would have used. If a more specific template exists (`single-post`), core uses it, with or without the mode, and the variant of `single` is not used; a variant must be declared for `single-post` to be used there. This is exactly "`bar` is the print version of `foo`".
- **Fallback is free.** A variant that does not exist is ignored by `slug__in`: HTTP 200 with the normal template.
- **Types.** The requests for the front page, the home page, a search, a category, an author, a date, a page and a 404 reach `{$type}_template_hierarchy` with the types `frontpage`, `home`, `search`, `category`, `archive`, `author`, `date`, `page` and `404`. The filter must be added for each type that core documents (the 18 listed in `template.php`: `404`, `archive`, `attachment`, `author`, `category`, `date`, `embed`, `frontpage`, `home`, `index`, `page`, `paged`, `privacypolicy`, `search`, `single`, `singular`, `tag`, `taxonomy`); a plugin that calls `get_query_template( 'custom' )` has a type of its own.
- **The query string.** `mode` is not a query variable of WordPress and is ignored by the main query; `redirect_canonical` keeps `?mode=print` and the alias `?print` when it redirects.
- **The two checks that failed first** were errors in the experiment, not in WordPress: the template-part filter was left active during the run for strategy A, and a first listing test did not use the first template of the list. Both were fixed and the script run again from a clean state: 32 checks, 0 failures.

## Decision (proposed; slices 202 and 203 follow it)

1. **Templates: strategy B.** Add a callback to `{$type}_template_hierarchy` for each documented type (plus the types added through a filter of the module), at an **explicit late priority** (90, documented) so that it sees what other plugins add. It inserts, before each candidate that has a variant in the active mode, the file name of the variant (`single-print.php`). One call of the module per type, not per candidate.
2. **Template parts: strategy D.** A callback on `render_block_data` that, for the block `core/template-part` of the active theme, replaces the `slug` by the slug of the variant.
3. **Not A.** Its flaws are the ones of the hack: it rewrites calls that have nothing to do with the front end and it does not cover template parts. **Not C** either: it works only for block themes and relies on globals that are not an API.
4. **Classic themes come for free** with B (the variants are PHP files with the same slugs). Slice 208 of the candidates list becomes a matter of entity types and of the screen, not of another hook.
5. **The relations are between slugs of the active theme.** The entity type `template` has ids `stylesheet//slug`; `WP_Block_Template` objects are recognized by it; another type `template_part` has the same shape for parts (the objects are told apart by their `type`, `wp_template` or `wp_template_part`). A relation stored with the id of a theme does not apply after a switch of theme.
6. **One read of the variants per request**: at the first call the module reads all the variants of the active mode in one query (`listing()` with the scope of the mode, then cached for the request), so that the cost does not grow with the number of candidates.

## What this slice did not verify

- **Coexistence.** Only core and the experiment were present. Other plugins or themes filtering `{$type}_template_hierarchy`, `render_block_data` or `template_include` were not tried; nothing was measured on the cost of the hooks.
- **Child themes** (a variant in the child theme for a template of the parent, `theme` attributes), templates registered by plugins with `register_block_template()`, patterns that contain a template part, the site editor and the "edit template" link of the admin bar (they probably point to the variant, which is a good thing, but this was not looked at), embeds and feeds.
- **Other web servers and PHP versions**: only the PHP built-in server and PHP 8.3.6. Other WordPress versions than 7.1.3 were **read** (6.6, 6.9) but not run.
- **Page caches**: a `?mode=print` URL must not be served from the cache of the normal page; this depends on the cache plugin and the server, not on WordPress (slice 207).
- **Hybrid themes** (a classic theme with `add_theme_support( 'block-templates' )`).
- The experiment is not product code and not under the quality rules of the plugin (`phpcs:ignoreFile` in `og201.php`).

## To confirm

1. Strategy B for templates, at priority 90, on the documented types plus a filter to add types.
2. Strategy D for template parts.
3. The hack's hook (A) and the globals (C) are dropped.
4. Classic themes are supported by the same hook (their slice becomes small).
5. Two entity types, `template` and `template_part`, with ids `stylesheet//slug`.
6. The variants of the mode are read once per request.
