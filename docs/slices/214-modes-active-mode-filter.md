<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 214 — Modes: the filter `modes_active_mode`, and the native links of Media Helper

Status: **done** (Eric, 2026-10-13: "le filtre 208 en slug"). Dependencies: slices 200 (the active mode), 210 (the Media method). It carries out **only the first filter** of [slice 208](208-modes-plugin-contract.md); `modes_option` and the per-mode options of 208 stay candidates.

## Why

The gallery block of Media Helper (`wp-media-helper/gallery`, its slice 043) asks which mode is active, to list the images of that mode, **without depending on Otherguise**. `modes_active_mode()` answers with an object (`ModeDefinition`, internal to Otherguise): a plugin that passes it to `wp_media_helper_get_attached_media()` as a context would not be understood (the context is a slug) and would get the images of every mode.

## What it does

- **`apply_filters( 'modes_active_mode', 'web' )`** answers with the **slug** of the mode of the request (`?mode=` or an alias such as `?print`, the default mode otherwise, the default mode when the modes are disabled). The argument is the answer without Otherguise. Module `Modes`, `Module::filter_active_mode`, priority 10.
- **`wp_media_helper_native_links_in_context`** (a filter of Media Helper, asked by its slice 043) is answered by the module `Media`, `Module::native_links_in_context`: before the migration, the images attached the WordPress way (`post_parent`) count in the **default mode** (the web keeps showing them), not in the others (print shows only the links that carry the mode print, hence the need for `wp otherguise media migrate`). An answer of true that Media Helper already gives is kept; with no context it is left to Media Helper.

## Verified

PHPUnit (405 tests): the hook of the filter and its answer (default mode, a default mode changed by `modes_default_mode`), the hook of the native-links filter and its answers (kept, other mode, default mode, no context, a context that is not a string). **Never tried on a real WordPress site** nor with the gallery block of Media Helper.

## Not in this slice

`modes_option`, the registration of options by other plugins, and the screen to set them (slice 208).

**Tried on a real site since:** the gallery block of Media Helper (`wp-media-helper/gallery`) uses the filter `modes_active_mode` as its context, and the native-links filter before the migration, on testing (2026-10-14), staging and production (rollout 003, 2026-10-15).
