<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 203 — Modes: applying the variants

Status: **done**, planned and coded in one go at Eric's request; the choices below are **to confirm**. Dependencies: slices 200 (the mode of the request), 201 (the hooks) and 202 (the variants). Module: `Modes`.

## Goal

Make WordPress display the variant of a template, and of a template part, in the mode of the request: `?mode=print` or `?print` shows what `modes/has-variant` and `modes/has-part-variant` declare. This is the slice that replaces the hack of `wp-pdf-helper`.

## How

`VariantApplier` follows the decision of [slice 201](201-modes-template-hooks.md):

- **Templates**: a filter on `{$type}_template_hierarchy` for each type that core documents (18), added on `init` at priority 20 so that a plugin can add its own types with the filter **`modes_template_types`**. The filters run at priority **90** (late: they see what other plugins add). Just before each candidate that has a variant in the mode of the request, the applier inserts the variant, with the same suffix (`single-print.php`, `.html` or none). WordPress then finds it like any other candidate: in the database, in the files of a block theme, or among the PHP files of a classic theme. A variant that does not exist is ignored by WordPress and the template is used.
- **Template parts**: a filter on `render_block_data` (priority 10) replaces the `slug` of a `core/template-part` block of the active theme by the slug of its variant. It works for the parts of the templates and, since it acts on every block, for those of a variant template.
- **Which variants**: `Variants::map( $mode, $kind )` of slice 202, called **once per request and kind** (two reads each, cached by the object cache of Triples), for the mode of the request, the default mode included: a variant declared for `web` applies to a request that asks for no mode. Relations stored for another theme, or with malformed ids, are ignored.
- **Only on the front**: nothing is read or replaced in the administration or in REST requests (the site editor, the block renderer), where the filters are inactive.
- **Without Triples** or without any variant, the hierarchy and the blocks are returned unchanged.

What a variant means (decided in 201): a variant declared for `single` applies when `single` is the template WordPress would have chosen. If a more specific template exists (`single-post`), it is used, with or without the mode, until a variant is declared for it.

## Changes to existing code

`Modes\Module` gets `register_variant_filters()` (action `init`, priority 20), `variants_of_the_request()`, `applier()`, and an optional constructor argument `$is_front` (the default tests `is_admin()` and `REST_REQUEST`).

## Tests

- Unit, no database: the transformation of a hierarchy (suffixes, position, specific templates untouched, shared variants, no duplicate, other themes and bad ids ignored), one read per kind and request, nothing outside the front, garbage returned as it is, the blocks (replaced, other themes, other blocks, missing slug), the registration (19 filters, priorities, invalid type names skipped).
- Integration, with Triples on a database: print and default mode, a withdrawn variant, no Triples, the types filter, the cost of a request (at most 4 queries however many times the hooks run).
- **On a real WordPress 7.1.3** ([`tests/real-wordpress/apply-variants/`](../../tests/real-wordpress/apply-variants/run.sh), 29 checks, 0 failures; a first version of the REST checks passed for the wrong reason, application passwords being refused over HTTP, and was fixed with `WP_ENVIRONMENT_TYPE=local` and a check of the HTTP code): the front end in a browser-less run with curl: default, `?mode=print`, `?print`, an unknown mode, `mode[]=print`, a database variant, a theme file variant, a variant for the default mode, a more specific template, parts replaced (database and file) in the template and in a variant template, a variant that was deleted (HTTP 200, the normal template), REST untouched (the list of templates and the block renderer with an application password), a classic theme, and the return to the block theme.

## Not verified

- Coexistence with other plugins or themes that filter the same hooks; page caches that ignore the query string (slice 207); Apache or nginx; multisite; child themes; templates registered by plugins; hybrid themes.
- The cost on a large site: two reads per kind and request, cached only if an object cache is persistent.
- Print links, the propagation of the mode in links, mode styles: slices 205 and 206.

## To confirm

1. The variants of the default mode apply to requests without a mode.
2. The application stops outside the front: the administration and REST show the normal templates.
3. `modes_template_types` to add types; priority 90 for the hierarchy filters.
4. A variant that does not exist is silently ignored (the normal template is shown).
