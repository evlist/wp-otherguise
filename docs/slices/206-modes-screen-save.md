<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 206 — Modes: edit the variants in place and save with a button

Status: **planned**, to confirm with Eric before any code. Dependencies: slices 202 (variants), 204 (the screen). Module: `Modes`.

## Goal

Two remarks of Eric on the screen **Settings → Otherguise modes**:

1. To change the variant of a row, or the modes it serves, one has to delete the row and create it again.
2. Each addition or removal is saved at once, which is surprising: the habit of WordPress settings screens is to change things, then press **Save**.

## Today (slice 204)

One `admin_post` request per click: *declare*, *Withdraw from a mode*, *Remove*. Each one writes to Triples and redirects with a notice. There is no way to change a variant.

## Proposal

**One form per screen, one button "Save changes".** Nothing is written until the button is pressed.

- Each existing relation is a row with:
  - the **template** (fixed text: it is the identity of the row);
  - the **variant**, a list of the templates of the theme (an editable choice);
  - the **modes**, one checkbox per mode other than the default one (a variant of the default mode is allowed, as today: every mode is offered, each with its checkbox);
  - a **Remove** checkbox, which marks the row for deletion (the row is struck through by a few lines of CSS only when JavaScript is present; the box works without it).
- One **empty row** ends each table, to add a relation (template, variant, modes). A small script adds more empty rows with a button **Add another**; without JavaScript, save, and a new empty row appears.
- The two tables (templates and template parts) are in the same form, with one **Save changes** at the bottom.
- The setting "Enable the modes" keeps its own form and its own button (Settings API): it is a different kind of change.

### What Save does

The server receives the whole desired state of the rows, **never trusts** it, and:

1. checks the capability and the nonce, then reads the rows (kind, template ids of the right shape, registered modes: the same checks as slice 204);
2. rebuilds the desired set (template, mode → variant) and applies the rules of slice 202 to the **whole** set (a template cannot be its own variant; one variant per template and per mode; templates and modes exist), naming the row of each refusal;
3. computes the **difference** with what is stored and applies it with `Variants::declare()`, `withdraw()` and `remove()` inside **one Triples transaction**: everything is saved or nothing is, and the screen shows the rows with the messages;
4. refuses a form built on an **old state** (a token of what was stored when the form was printed, from `last_changed` of the statements): "The relations changed since this page was opened; reload and redo your changes." Without it, a stale page would delete what another administrator added.

A row whose variant is changed is a withdrawal of the old relation and a declaration of the new one; a row whose modes are all unticked is a removal. The notice says how many relations were declared, changed and removed, or why nothing was saved.

### Not changed

The capability (`edit_theme_options`, filter `modes_admin_capability`), the names of the page and of the table of the modes (read-only), the rules of the variants, the data, the public functions. The three `admin_post_` handlers of slice 204 are replaced by one (`modes_save`); nothing else used them. No confirmation page (a change is undone by changing it back, the old state being visible before saving).

## Tests

- PHPUnit with a database: capability and nonce of the new handler; a save that changes only the variant, only the modes, both; a removal; an addition; a mixed form applied in one transaction; every refusal leaves **nothing** stored (a valid row next to an invalid one is not saved); a stale token refused; escaping; the page (rows, checkboxes, selects, the empty row, the token, one button).
- jsdom: the script that adds an empty row and marks a row removed.
- Real WordPress 7.1.x in Chromium: edit a variant and the modes of a row, save, see the front end change; add and remove in the same save; unsaved changes are not applied; a stale form refused after another change; the two settings forms side by side.

## Not verified (yet)

Large numbers of rows, keyboard use of the rows, other browsers, multisite.

## To confirm

1. One **Save changes** button for both tables, nothing written before it.
2. The template of a row is fixed (to change it, remove the row and add another); the variant and the modes are editable.
3. The default mode appears among the checkboxes like the others.
4. A stale page is refused with a message, not merged.
5. All or nothing: one invalid row stops the whole save.
6. A little JavaScript (add a row, strike a removed row) with a working form without it.
7. The setting "Enable the modes" keeps its own button.
