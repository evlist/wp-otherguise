<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 200 — Modes: declared modes, the mode of the request, and access to the services

Status: **done**, planned and coded in one go at Eric's request ("passe directement à la tranche 200 en l'incluant"); the choices below were taken from the recommendations made when the slices were proposed and are **to confirm**. Dependencies: slices 100 to 105 (Triples). Module: `Modes`, plus a small addition to the core and to Triples.

## Goal

1. **Declare modes** (`web`, `print`, and others that a plugin or a theme adds), find the **mode of the current request** (`?mode=print`, alias `?print`), and tell the rest of WordPress (a function, a class on the body).
2. **Register in Triples** the entity type `mode` and the predicate `modes/mode`, the qualifier that says in which mode a statement is shown (slice 103's reading rule uses it).
3. **Let a module or a plugin reach a service of another module.** The run on a real site (after slice 105) showed that nothing gave another plugin the `Statements` service of the booted Triples module. This slice adds the access, and Modes is its first user.

No template changes yet: that is slices 202 and 203, on the hook decided in [slice 201](201-modes-template-hooks.md).

## The modes

- `ModeDefinition( $slug, $label, $alias = null )`: a slug (lower case letters, digits, underscores, 20 characters at most, starting with a letter: the same shape as an entity type, since it is the id of an entity of the type `mode`), a label, and an optional **alias**, a query string key that selects the mode by its presence. An alias must look like a slug and cannot be `mode` or a query variable of WordPress (a short list: `p`, `page_id`, `s`, `cat`, `tag`, `feed`, `preview`...).
- `ModeRegistry`: filled lazily at the first read with two built-in modes, then the action **`modes_register_modes`** (argument: the registry; call `register( new ModeDefinition( 'cover', 'Cover' ) )`). Duplicate slugs and duplicate aliases are refused.
  - **`web`**: the default mode, the one of a request that asks for none;
  - **`print`**: alias `print`, so that `?print` (the hack of `wp-pdf-helper`) keeps working.
- The default mode can be changed by the filter **`modes_default_mode`** (a slug; an unknown slug gives the first mode registered).

## The mode of the request

`ActiveMode`, once per request:

1. `?mode=<slug>` with a registered slug selects it;
2. else the presence of the alias of a mode (`?print`, with or without a value) selects it; among several aliases the mode registered first wins;
3. else the default mode.

Everything else is ignored: an unknown slug, `mode[]=print`, `mode=../x`, upper case. Only registered slugs and aliases are ever read, so the query string never designates a file or anything but a declared mode. The query string is read with `filter_input_array()` for the keys `mode` and the registered aliases only (no superglobal).

Public API: `modes_active_mode()` (a `ModeDefinition`, or null before the module is available) and `modes_is_active( $slug )`; `Module::active()->is_explicit()` says whether the request asked for its mode. The body gets the class **`modes-mode-{slug}`** (filter `body_class`, priority 10) on every page, the default mode included.

## What the modes register in Triples

`TriplesIntegration`, hooked to `triples_register_entity_types` and `triples_register_predicates`:

- the entity type **`mode`**: ids are the slugs of the registered modes (that is also what "exists" means), a `ModeDefinition` object **stands for** its mode when given to the statements API, `resolve()` gives the `ModeDefinition` back, `describe()` gives its label;
- the predicate **`modes/mode`**: subject type `statement`, object type `mode`, no limit.

So `$statements->triple( $link, 'modes/mode', $print_mode )` (a `ModeDefinition`) and `new EntityRef( 'mode', 'print' )` mean the same, and `listing()` takes either as the scope value.

## Access to the services

- `Otherguise\Core\Modules`: holds the loader of the request (registered by `otherguise_bootstrap()` **before** the modules boot) and gives an enabled module by its identifier, or null. It names no module.
- `ModuleLoader::module( $id )`.
- **`triples_statements()`** (file `modules/triples/functions.php`, loaded by `Triples\Module::boot()`): the `Statements` service, or null when the plugin or the module is not enabled. This is the entry for another plugin; the names are prefixed by the module (rule 4).
- Inside the plugin a module that depends on Triples may also use `Modules::get( 'triples' )` and check the class.

Other plugins still register entity types, datatypes and predicates with the three actions of Triples; they get the service with `triples_statements()`.

## Other changes

- `.vscode/phpcs.xml`: the prefixes `modes` and `books` and the text domain `modes` are accepted (module rule 4).
- `Modes\Module` has the optional constructor arguments `$do_action`, `$add_action`, `$query` and `$apply_filters` so that the tests run without WordPress; `load_textdomain()` loads the text domain `modes` from `modules/modes/languages/` (no translation yet).
- The scratch predicates of `tests/real-wordpress/admin-screen/og-predicates.php` no longer declare `modes/mode` nor the type `mode`: they come from the module.

## Tests

- Unit, no database: `ModeDefinition` (valid and invalid slugs, labels, aliases), `ModeRegistry` (lazy, built-ins, duplicates, default and fallback, a callback reading the registry while it fills it), `ActiveMode` (default, `mode`, alias, priorities, every kind of invalid input, a reader that returns garbage, once per request), `Module` (hooks and priorities, built-in modes, the registration action, the filter of the default mode, the body class, `read_query()` outside a request), the public functions through `Modules` (not booted, booted, a module that is not enabled), `Modules` and `ModuleLoader::module()`.
- Integration: with the Triples module on a real database, a mode given as an object and as a reference is the same statement; unknown modes, malformed ids, a mode on a post, a bare string refused; a listing scoped to a mode object.
- On a real WordPress 7.1.3 (the scratch site of the earlier runs): `triples_statements()` and `modes_active_mode()` work from WP-CLI, the body class follows `?mode=print`, `?print`, `?mode=ghost` (ignored) and `?mode[]=print` (ignored), and the 32 checks of the administration screen still pass with the entity type and predicate registered by the module.

## Not verified

- Coexistence with themes or plugins that also filter `body_class`, and with caches that ignore the query string (slice 207).
- `filter_input_array( INPUT_GET )` on servers where it is known to misbehave (some FastCGI set-ups); only the PHP built-in server was used.
- Multisite; language packs.
- The built-in labels are translatable but no translation exists.

## To confirm

1. The modes are declared in code (action `modes_register_modes`); an administration screen comes later.
2. Two built-in modes: `web` (default) and `print` (alias `print`); the default is filterable.
3. `mode` wins over an alias; an unknown value is ignored (default mode), not an error.
4. The class `modes-mode-{slug}` on the body, always.
5. The access through `Core\Modules` and the function `triples_statements()`; a function per module, not a global container.
6. The `ModeDefinition` object stands for its mode in the statements API.
