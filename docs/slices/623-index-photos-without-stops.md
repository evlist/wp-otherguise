<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 623 — Index: the photographs without stops

Status: **coded on 2026-11-24** (PHPUnit with a real database for the statements; never tried on the blog). Dependencies: 606 (the photographs and the tracks), 617 (the levels of a place), 622 (the screen). Context: [`../design/index.md`](../design/index.md).

## Why (Eric, 2026-11-24)

The stops were made **to avoid asking Media Helper for too many positions**. Media Helper now places **every** photograph, so the stops serve no purpose, and they **hide** concepts: a first photograph of a village on page 1 and a second one on page 2 were not found, because a stop of one photograph (under `photos_min` photographs and `minutes_min` minutes) gave no place.

## Decided (Eric, 2026-11-24): the notion of stop is removed altogether

- **Every photograph that has a place is an observation of it**: one mention of the source `image/address` on the lowest place of its chain (every level above it is a topic too, as before), with **one `via` per photograph** (a short hash of its address, never the address). A lone photograph counts.
- **What stays**: a thumbnail and its original are still **one photograph** (same position, time and name without the size), with the `via` of each address; a photograph with **no place yet** waits for the second pass; the tracks (start and end) are unchanged.
- **What goes**: `Photo\Stops` (the chain rule, the minimums), the settings `photos_min`, `minutes_min` and `chain_metres` (and their fields on the screen of slice 622; the option `index_photos_settings` is only deleted at the uninstall), the counters `small_stop` and `no_precise_time`, the line "too small: no topic" of `index photos --post=<id> --dry-run`.
- **The photographs of a place are grouped** only to resolve its chain once: the analysis gives `places` (the place and its photographs), where it gave `stops`. The mention is the same.
- **Noise** (a photograph taken while passing through a village) is no longer filtered when the pages are read: **the number of photographs of a place in a post is kept** (the number of `via` of the mention), so that a threshold can be applied **when the index is built**, with no need to read the pages again. No such option is made yet.

## Not done

- **The page of each photograph**: a photograph has no marker in the PDF (slice 612 does it for the links and the marks), so the locator of a place seen on photographs is **the page of the post**, not the page of the photograph. A marker per photograph is the next step if it is wanted.
- The thresholds at the build.

## Tests

`IndexPhotosDbTest` (a real database for the statements): the photographs of one place, a lone photograph of another, the day-only one, the start and the end of a track; the via of each photograph; the command prints the places; the thumbnail and its original. `IndexPlaceChainTest` (renamed from `IndexPhotoStopsTest`): the chain of a place.
