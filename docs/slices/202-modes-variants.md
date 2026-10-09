<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 202 — Modes: variants of templates and template parts

Status: **done**, planned and coded in one go at Eric's request; the choices below are **to confirm**. Dependencies: slices 100 to 105 (Triples), 200 (modes) and 201 (the hook). Module: `Modes`.

## Goal

Say, and store, "template `bar` is the `print` version of template `foo`", and the same for template parts, so that slice 203 can apply it. No template is changed by this slice: `Variants::map()` is what slice 203 reads.

## Entities

- **`template`** and **`template_part`**: ids `stylesheet//slug` (`twentytwentyfive//single`), the form WordPress uses for block templates whether they are database posts, files of a block theme or (here) PHP files of a classic theme. Two types, because a template and a template part can have the same slug.
  - A `WP_Block_Template` object stands for its entity (told apart by its `type`, `wp_template` or `wp_template_part`).
  - **Exists**: WordPress finds a block template of that kind (`get_block_template()`), or, for a template, the active theme has the PHP file `slug.php`.
  - **Describe**: the title and the link that opens it in the site editor (`site-editor.php?postType=…&postId=…`), or the slug when there is no block template.
  - `TemplateRef` builds references (`template( 'single', $theme )`) and reads ids.
- `TemplateLookup` is the one class that calls the WordPress functions (`get_stylesheet()`, `get_block_template()`, `locate_template()`, `admin_url()`); the tests replace it.

## Predicates

- **`modes/has-variant`**: template → template; **`modes/has-part-variant`**: template part → template part. Both are qualified by `modes/mode`: the mode statement says in which mode the variant replaces the template. Two predicates and not one, so that a template cannot be the variant of a template part.

```
1: (template:theme//single, modes/has-variant, template:theme//single-print)
2: (statement:1, modes/mode, mode:print)
```

## The service

`$module->variants()` (`Modes\Variant\Variants`; the Triples module must be enabled):

| Call | Effect |
|---|---|
| `template( $slug )`, `part( $slug )` | The reference to a template or part of the active theme. |
| `declare( $source, $mode, $variant )` | Stores the relation and its mode, in one transaction; idempotent; returns the statement of the relation. |
| `withdraw( $source, $mode, $variant )` | Removes the mode; deletes the relation too when it serves no other mode. Returns the number of statements deleted. |
| `remove( $source, $variant )` | Deletes the relation in every mode. |
| `variant_of( $source, $mode )` | The variant in a mode, or null. |
| `map( $mode, $kind )` | **Every** variant of a mode, template id → variant id, with two reads (the relations, then what is said about them). |

Arguments are what the caller holds: `WP_Block_Template` objects, `ModeDefinition` objects, or references. Rules, with a stable code in `VariantException`: `not_a_template`, `kind_mismatch` (a template and a part), `same_template`, `theme_mismatch` (the variant belongs to the theme of the template), `not_a_mode`, `mode_already_served` (the template already has another variant in this mode: withdraw it first). What Triples refuses (a template or mode that does not exist, an unknown entity) is an `InvalidStatementException`. The existence of both templates is checked by Triples, after the rules of the variants.

## Other changes

- `Statements::entity( $value )` (Triples): the way a module reads its arguments with the same rules as the service.
- `Modes\Module` takes two more optional arguments, `$statements` (the service, by default the Triples module booted in the request, through `Core\Modules`) and `$lookup`; `variants()` throws a `LogicException` when Triples is not enabled.

## Tests

- Unit: ids and references; the two entity types (recognition of block template objects by exactly one type, existence for block templates and PHP files, the part not being a template, descriptions, loading); the predicates.
- Integration (database): declare (objects and references, idempotence), several modes and templates, the map in two reads, template parts, every rule, withdraw, replacing, remove, the service without Triples.
- **On a real WordPress 7.1.3** ([`tests/real-wordpress/variants/run.php`](../../tests/real-wordpress/variants/run.php), 25 checks): real `WP_Block_Template` objects, templates and parts from the database and from theme files, the editor link, the map, the rules, withdraw and remove. The 32 checks of the administration screen still pass.

## What the real run found

`locate_template( 'header.php' )` returns the file `wp-includes/theme-compat/header.php` when the theme has none (and likewise for `footer`, `sidebar` and `comments`), so a template part `header` looked like an existing PHP template. `TemplateLookup::php_template_exists()` now accepts only files inside the directories of the active theme and its parent. Doubles in the unit tests could not have shown it.

## Known gap

A relation whose template or variant is later **deleted** stays in the table (WordPress does not tell the module that a template went, and the statements about templates are not cleaned like those about posts). It is harmless for slice 203 (an id that matches no template is ignored by WordPress), and the maintenance tab of the administration screen lists such statements as orphans because both entity types can check existence. A cleanup on `deleted_post` for `wp_template` and `wp_template_part` posts (the database templates) can be added in a later slice; theme files that disappear cannot be detected.

## Not verified

Child themes (a variant in the child theme for a template of the parent), templates registered by plugins with `register_block_template()`, a theme switch with relations stored under the old theme (they do not apply: the ids carry the theme), hybrid themes, the site editor opening the link, multisite.

## To confirm

1. Two entity types, `template` and `template_part`, with ids `stylesheet//slug`.
2. Two predicates, `modes/has-variant` and `modes/has-part-variant`, qualified by `modes/mode`.
3. One variant per template and mode, enforced by the service (`mode_already_served`) because the registry cannot express it; a variant can serve several templates and several modes.
4. The variant belongs to the same theme as the template.
5. A template that is deleted leaves its relations (to clean up later).
6. A classic theme's PHP templates count as existing templates (same ids), for the active theme only.
