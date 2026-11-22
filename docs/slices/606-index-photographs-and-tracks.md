<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 606 — Index: the photographs and the GPX tracks as sources

Status: **proposed (2026-11-22), not coded.** Dependencies: 600 (resolver, mentions), 602 (the job that reads a page), 603 (kinds), 604 (Wikidata, for the identifier of a place when the answer has one), 605 (the sources `image/address`, `trace/start`, `trace/end`, the qualifiers `via` and `visible`). **On the side of Media Helper: its slice 052** (the positions, the places, the filter). Context: [`../design/index.md`](../design/index.md), "Places from coordinates" and "Who geolocates".

## Decided (Eric, 2026-11-22)

- **The URLs are not kept.** (**Vocabulary: a *URL* is the web address of an image or of a GPX file; a *place* is a geographic position, a commune, a hamlet.**) The job reads the page, asks Media Helper **at once**, **in PHP, through its filter**, and keeps **only what it derived** (the topics and the mentions). A URL is kept **only for a photograph whose place is not known yet**.
- **A channel of data attributes on the `<img>`** (Media Helper writing the facts in the page, **on demand with a parameter, authenticated**) is an **open track, not built now and not asked of Media Helper**. **Secrecy of the coordinates is not a criterion for Eric's blog** (the published photographs keep their GPS in the EXIF); **the cost is the weight of the page and the risk that a cache keeps the answer**. Media Helper's own default stays: **never print coordinates**.
- The choice of the level, the rule of "no lodging" and the hierarchy *country > state > county > commune > place* are those of the design note.

## Proposed

### The pipeline (one post, in the job of slice 602)

1. **Read the page** (slice 601): the `image` and `gpx-file` signals with their URLs.
2. **Ask Media Helper** for each URL: `apply_filters( 'wp_media_helper_file_geo', null, $url )` (a batch function, if Media Helper offers it, is used instead: see "Asked of Media Helper"). **`null` means no data** (the file is not in its index, or the plugin is not there): the photograph is **left out, not an error**. A URL that is **not of the site** (the Wikimedia thumbnails of whoBIRD) is not asked.
3. **A photograph** gives `state`, `lat` and `lon` (only when `positioned`), `taken_at` with its **precision and source** (a day only is **noon**: such a photograph has **no usable time**), and `place` (`levels`; **`null` while the cell was not looked for**).
4. **Group the photographs of the post into stops.** Sorted by time **when the times are precise**, **consecutive photographs within 50 m of the previous one** form a stop (the **chain rule** measured on Eric's day: 32 groups for 66 photographs); a photograph with **no precise time** is grouped **by place only** (same `site` and `settlement`). **The duration of a stop** is the time between its first and last photograph. **A stop gives a place** when it has **at least `photos_min` photographs or lasts at least `minutes_min`** (settings; **the defaults are to be calibrated on Eric's list of places for 2026-10-01**, which is still to come: the design note measured 66 photographs → 35 cells → 15 places, and found the duration to discriminate better than the count).
5. **The topic of a stop** is **the lowest place that the answer holds** (`levels.site`, else `settlement` when there is no site, else the highest level that exists: about 10 % of the places have no commune), **under** its upper levels (`settlement`, `county`, `state`, `country`) **created as topics too** (decided: every level is a topic), of kind **`place`**. **Keys**: the label **and its parent** (the resolver); **a `wikidata:` key only when the Wikidata item of the answer is the item of that very place**, i.e. when `levels.kind` starts with `place ` (the item belongs to **the object found**, which can be a road or a house: **never to the commune**). The resolver and the manual linking settle the doubles.
6. **The mention** is on the **most specific** topic: subject the **post**, source **`image/address`**, **`index/via` = `image:` + a short hash of the URL** (enough to find the element again for slice 612; the URL itself is not kept), role **`photo`**, strength passing. **A topic whose mentions all come from sources that are out of the selection is not listed**, as for every source (slice 605). **The upper topics list the mentions of their descendants only when an index asks for the roll-up** (not built: slice 610 is flat).
7. **A GPX track** (`gpx-file`): the filter gives `start` and `end`, **each with its time in UTC and its place**; each becomes a topic by the same rule (5), with the sources **`trace/start`** and **`trace/end`** and the roles **`start`** and **`end`** (**the end of a day is where one slept**, so an index of the nights is the topics with the role `end`). The times are put in the **time zone of the site** for the comparison with the photographs of the same day.
8. **Attribution**: a place read from OpenStreetMap makes the index **show the notice of slice 611** (`auto`): the topic is flagged **`attribution: osm`**.

### What is kept for the places that are not known yet

A photograph with **`place = null`** (or `state = unread`) has **no topic yet**. The post keeps **`_index_geo_pending`**: a **short list of the URLs concerned**, capped, with the date. A **second pass** (`otherguise_index_photos`, **no network**: the filter and the database only) takes the posts that have pending addresses, asks again, and brings the mentions of the sources `image/*` and `trace/*` in line. It runs **when Media Helper says that places were added** (a signal asked of it) and, **failing that, hourly while some post has pending URLs**. **A post with nothing pending keeps no URL.**

### Commands and settings

`wp otherguise index photos run [--post=<id>] [--dry-run]`, `status` (how many posts have pending URLs, how many photographs were left out and why: no data, no position, no precise time, not of the site, no place yet). **`--dry-run` prints, for a post, the stops, their durations, the place chosen and why** (the calibration tool). Settings `photos_min`, `minutes_min`, `chain_metres` (50); **the source is off until enabled** (`index pages enable` already turns the job on; a switch `photos enable`).

## Asked of Media Helper (the message, approved by Eric on 2026-11-22 after removing the fourth point)

1. **A batch**: `wp_media_helper_get_files_geo( array $urls )` → `url => answer`, one query for the 150 images of a page instead of 150 calls.
2. **A signal** when places were added: an action `wp_media_helper_places_updated` (with the number of cells), after a run of the places job that stored at least one answer, and when positions were read.
3. **To confirm the mapping of URLs to files**: thumbnails of the cache, links to the originals, NextGEN with its query string (`…jpg?t=…`) and its `thumbs/thumbs-…` files, names with percent-encoding, `http` and `https`, another host (a CDN) of the same site, and a URL of another site (`null`).

## Tests (planned)

Without WordPress (the filter is a double): the grouping (the chain, the precision, noon, the minimums), the choice of the level (site, settlement, no commune, no place), the topics and their parents, the keys (a `wikidata` key only for a place object), the mentions (source, via, role), the traces (start, end, time zones), the pending addresses and the second pass, `null` and addresses of another site, the attribution flag, the commands. **A real database** for the mentions; **a scratch WordPress** with the filter answered by a fake Media Helper. **Never tried**: the real filter, the real library, the real blog.

## Not done

The **roll-up** (the commune listing the pages of its hamlets: a later option of the builder); **HEIC** (not read by PHP); the **data-attribute channel**; the **visibility by mode** (default: every mode); **calibrating the minimums** (Eric's list for 2026-10-01 is awaited); the markers of the photographs in the PDF (**612**).
