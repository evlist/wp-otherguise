<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 208 — Modes: the contract for other plugins

Status: **planned**, to confirm with Eric before any code. Dependencies: slices 200 (the active mode), 204 and 207 (the screen). Module: `Modes`.

## Goal

Replace what `wp-pdf-helper` does **inside other plugins** without Otherguise knowing any of them. The hack hooks the GPX plugin's filter and tests `$_GET['print']`; the printable gallery tests `$_GET['print']` itself. Both need two things from Otherguise: *which mode is active* and *the parameters of that mode*. They should get them through **filters**, which cost nothing when Otherguise is absent, and the parameters should be **set on the modes screen**, not hard-coded in a plugin.

## What the plugins in question do today (read in their repositories, 2026-10-10)

- `wp-attached-gpx` (block `create-wp-attached-gpx/wp-attached-gpx`): builds the parameters of the WP GPX Maps shortcode (`gpx`, `attachments`) and passes them through its own filter **`wpagpx_shortcode_parameters`**. `wp-pdf-helper` hooks it in print mode to set `mheight`/`gheight` (200/150 px, or 600/300 px with `?gpxmap-size=large`), `attachments=false`, `download=false`.
- `wp-printable-gallery` (block `wpprg/wp-printable-gallery`): shows the attached images; when `$_GET['print']` exists it keeps only the images whose post meta `wpdfh.print` is set (the checkbox that `wp-pdf-helper` adds to the media library).

## The contract

**Reading the mode** (any plugin, no dependency, no `function_exists`):

```php
$mode = apply_filters( 'modes_active_mode', 'web' ); // the slug of the mode of the request; the argument is the answer without Otherguise.
```

**Reading a parameter of the mode of the request**:

```php
$height = apply_filters( 'modes_option', '600px', 'wp-attached-gpx/map_height' ); // the value set for the active mode, else the default given.
```

**Declaring the parameters a plugin understands**, so that the screen can show them (optional; a plugin that does not declare still gets its default):

```php
add_action( 'modes_register_options', function ( $options ) {
	$options->register( 'wp-attached-gpx/map_height', array( 'label' => __( 'Height of the map', 'wp-attached-gpx' ), 'type' => 'string', 'default' => '' ) );
} );
```

Types: `string` (at most 191 bytes), `integer`, `boolean`, `choice` (with `choices`). The key is namespaced by the plugin (`vendor/name`), like the predicates of Triples. The value of a mode is validated against the declaration before it is stored.

**The screen**: a section **Options** under the stylesheets of **Settings → Otherguise modes**: one table per plugin (the part of the key before the slash), a row per option, a column per mode, one **Save** button for the section (the habit of WordPress, as asked for the variants in slice 206). Nothing is shown when no plugin declares an option.

**Storage**: the WordPress option `modes_options` (`{ mode: { key: value } }`). These are settings, not relations, and nobody queries them by value; Triples is not needed. A value that is not set falls back to the default of the declaration, then to the one the caller passed.

## What changes in Eric's plugins (outside this repository)

- `wp-attached-gpx` declares four options (map height, chart height, show attachments, show download) and reads them with `modes_option` before it builds the parameters. The print values (200/150, no attachments, no download) are then set once on the screen; `?gpxmap-size=large` becomes a mode of its own, `print-large`, declared in code with the same variant template and the same stylesheets (a mode can have several).
- `wp-printable-gallery` replaces `array_key_exists( 'print', $_GET )` by an option `wp-printable-gallery/only_marked_images` (boolean, set for the mode `print`); it keeps the post meta for now.
- The per-image flag itself (`wpdfh.print`, a column of the media library with a handler without nonce) is the primitive form of a statement "this image is shown in this mode"; it moves to the integration with Media Helper ([`media-helper.md`](../design/media-helper.md)), not to this slice.

## Not in this slice

Options per variant or per template; options set by code only (a `modes_option` filter at another priority does that); the Media Helper integration; a JavaScript API for the modes (a plugin that needs the mode in the browser can read the class `modes-mode-{slug}` that the module puts on the body).

## Tests

PHPUnit: the filter `modes_active_mode` (default and print, aliases, disabled modes answer with the default mode), the registry of options (registration, refusals: bad key, bad type, duplicates), `modes_option` (value of the active mode, default of the declaration, default of the caller, a value that no longer matches its declaration), the handler of the section (capability, nonce, validation per type, everything or nothing, a mode or an option that is not declared), the section. On a real WordPress: two throw-away plugins, one that declares and reads options, one that only reads them; the front end in two modes; the screen.

## To confirm

1. Filters as the contract (`modes_active_mode`, `modes_option`), with an action to declare the options (`modes_register_options`).
2. Options stored in a WordPress option, not as statements.
3. One section **Options** on the modes screen, with its own **Save** button.
4. `?gpxmap-size=large` becomes a mode, not a parameter of the query string.
5. The marked images stay a matter of the Media Helper integration.
