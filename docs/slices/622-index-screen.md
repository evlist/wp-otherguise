<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 622 — Index: one screen for the state of the synchronisation and for what is on

Status: **designed and coded on 2026-11-23** (tried under PHPUnit only; never on the blog). Dependencies: 621 (the synchronisation), 602, 604, 606 (the sources), 603 (the kinds screen is the model of the screen). Context: [`../design/index.md`](../design/index.md).

## Why (Eric, 2026-11-23)

*"Isn't there an admin screen to select the features that are on?"* No: the sources, the enrichment, the contact of Wikidata, the night run and the thresholds of the photographs were set by **commands**, each its own (`pages enable`, `photos enable`, `enrich enable`, `sync auto`), and nothing showed what was on. Slice 621 simplified the **running**; this one simplifies the **configuration**.

## Decided (Eric, 2026-11-23: "write the design slice and code it right after")

- **One screen, Otherguise → Index**, with two parts: **the state** (what the synchronisation is doing) and **what is on** (the features). It is the dashboard and the settings screen announced by slice 621, **in one page**: both are about the same job.
- It **adds no logic**: it reads and writes the options that the commands write, and calls the methods of `SyncService`. A command and the screen always agree.

## The state

- The phase (collect, enrich, cleanup, or idle / stopped / done), whether it is **from scratch**, the progress of the reading (`n/total`, read, failed) and of the enrichment, the last run (date, posts read, topics deleted) and **the log** (the last lines).
- Buttons: **Update** (a run of what changed: `SyncService::start( false )`), **Start again from scratch** (`start( true )`, with a warning that it drops what the sources said, never what a person made, and reads every post), **Stop** (`stop()`). A run that is already going is not started twice (the service says so; the screen tells it).
- No automatic refresh in this slice: a link "Refresh". (A refresh by script is a later improvement.)

## What is on (the options that the commands also write)

| Setting | Option | Command that did it |
|---|---|---|
| Source: categories | `index_sources` (slug `categories`) | none (default on) |
| Source: tags | `index_sources` (slug `tags`) | none (default on) |
| Source: the pages (links, marks) | `index_sources` (slug `page`) | `index pages enable` |
| Source: the photographs and tracks | `index_sources` (slug `photos`) | `index photos enable` |
| The Wikidata enrichment | `index_enrich` (`1`/`0`) | `index enrich enable` |
| The contact sent to Wikidata (the User-Agent) | `index_wikidata_contact` | `wp option update index_wikidata_contact …` |
| The night run | `index_sync_auto` (`1`/`0`, default on) | `index sync auto on\|off` |
| Photographs: `photos_min`, `minutes_min`, `chain_metres` | `index_photos_settings` | `wp option update index_photos_settings … --format=json` |

- **The photographs need the pages** (they come from the page that is read): turning the photographs on turns the pages on too, and the screen says so. A source that the screen does not know (added by a plugin through the filter `index_sources_registry`) **is kept** as it is.
- **The enrichment without a contact** is allowed (the address of the site is used, as before) but the screen shows a note: Wikimedia asks for a way to reach the operator.
- The thresholds of the photographs are **not calibrated** (Eric's list of places is awaited): the screen says so next to them.
- Saving the night run calls `SyncService::ensure_scheduled()` so that the event is planned or cancelled at once, not at the next page load.
- A saved setting **does not change what was already read**: the screen says that to see a new source in the index, one **updates** (the posts that changed are read again) or **starts again from scratch**.

## Rules

- Capability `index_capability` (default `edit_others_posts`), a nonce on each form, every string translatable (text domain `otherguise`), every value read from the request sanitised: the checkboxes are booleans, the contact a text field, the thresholds integers with a floor of 1.
- The two forms post to `admin-post.php` (`otherguise_index_save`, `otherguise_index_run`), as the screens of the kinds and of the duplicates do. The run form takes `op` = `start`, `scratch` or `stop`; **anything else is refused**.
- The screen is declared to the overview of the menu (filter `otherguise_admin_screens`, group `settings`).

## Tests (`IndexScreenTest`, no database)

The screen with doubles for the request, the nonce, the capability, the redirection and the two stores: the page shows the state and the features; the capability and the nonce are checked on each form; the settings are saved (sources, enrichment, contact, night run, thresholds) and what was unknown is kept; the photographs turn the pages on; the run form starts, restarts from scratch and stops, and refuses another operation; a run that is going is not started twice; the night run change plans or cancels the event.

## Not tried

Nothing of this screen was tried on a real WordPress site, nor on the blog. The wiring (`IndexScreenWiring`) that reads and writes the real options is only tried by its test through the same doubles.

## Amendment (2026-11-24)

The thresholds of the photographs (`photos_min`, `minutes_min`, `chain_metres`) are **gone from the screen**: slice 623 removed the stops.
