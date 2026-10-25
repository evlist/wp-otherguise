<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 401 — Access: a settings page

Status: **proposed (2026-10-25)**, nothing coded. Dependencies: slice 400 (module `Access`). Slices 402 and 403 read the settings of this one, so it comes first.

## Goal

Not every site wants a module that shows drafts through a link. Let the administrator decide **whether Access exposes its functioning**, as slice 400 does by default (panel in the editor, REST routes, WP-CLI, the `?shared=` argument).

## Proposed settings (not decided)

1. **Private links enabled** (default: yes). Off: `Module::boot` registers none of the hooks of slice 400 (`parse_request`, `posts_pre_query`, the panel, the routes); the tokens stay in the database but nothing answers to them. `access_url()` returns an empty string.
2. **Who may create a link** (default: who may edit the post, as today). The capability is a setting, checked on the server in the REST routes and the CLI, never trusted from the browser.
3. **Unlisted state enabled** (default: yes), read by slice 402.
4. *(Room left in the schema, not coded.)* A lifetime for the tokens (default: unlimited, as decided on 2026-10-25).

## Shape

- A screen under Settings, like the screens of Modes: capability `manage_options`, nonce, translatable strings, options prefixed `access_`, removed by `uninstall`.
- The settings are read **before** any `add_action`, so that a disabled feature leaves no hook.
- A filter lets another plugin force a value (priority set explicitly and documented).

## Tests (planned)

PHPUnit: each setting off leaves the hooks unregistered; the capability setting is enforced on the three entry points (screen, REST, CLI); `uninstall` removes the options. jsdom for the screen if it has a script.

## To check on a real site

That an off switch really leaves no trace in the editor and in the REST index; the default of a site that never opened the screen.
