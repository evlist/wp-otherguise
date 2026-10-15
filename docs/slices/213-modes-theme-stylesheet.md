<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 213 — Modes: the stylesheet of a mode from the theme

Status: **done** (Eric: option A, to retire wp-pdf-helper). Dependencies: slice 207 (the stylesheets of the modes).

## Goal

The print format of the blog has its own CSS, which must load **only in print mode** (it is not `@media print`). It belongs to the theme, which is versioned and rolled out; the Media Library stylesheets of slice 207 are for what the editor uploads.

## The convention

When the modes are enabled and a mode is active, if the theme contains `modes/<slug>.css` (the child theme first, then the parent: `get_theme_file_path`), it is enqueued with the handle `modes-theme-<slug>` and the modification time as version. It comes **before** the Media Library stylesheets of the mode, at the same priority (`modes_stylesheet_priority`, 100 by default), so that an uploaded stylesheet can override it. It does not depend on the Triples module. No setting, no screen.

## Verified

PHPUnit (`ModesStylesheetsDbTest`): the file of the theme comes first, even without the Media Library service; nothing when the modes are disabled.

## Not verified

On a real WordPress site: `find_theme_file` (the core functions `get_theme_file_path` and `get_theme_file_uri` on a child theme) and the rendering of the print format. To be tried on staging (rollout 003).

## Not in this slice

Showing the theme file read-only in the Stylesheets screen.

**Tried on a real site since:** the print stylesheet of the theme loads in the mode print (rollout 002 on staging and production, rollout 003).
