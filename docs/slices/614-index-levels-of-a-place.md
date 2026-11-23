<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 614 — Index: the levels of a place as kinds

Status: **coded on 2026-11-23**. Dependencies: 603 (the kinds), 606 (the chain of a place), 613 (the roll-up). Context: [`../design/index.md`](../design/index.md), "Places from coordinates".

## Why (Eric, 2026-11-23)

The roll-up (613) chooses the broader topics by **kind**, but every level of a place was of the kind `place`: asking for `kind:place` would give a line to the communes as well as to the countries. **The levels must be chosen without French words** (*région*, *département* are French) **and without counting from the country** (*country-1* is the region in France and the commune in Monaco). And **names repeat across levels**: *Paris* is a department and a commune, most Spanish provinces have the name of their capital, *Navarra* is a community and a province.

## Decided (Eric, 2026-11-23)

- **Levels by their meaning**, as sub-kinds of `place`: **`country`**, **`admin1`** (the first division below the country: a région, a Land, a canton, a voivodeship; the `state` of Media Helper), **`admin2`** (the second: a département, a province, a powiat; its `county`), **`commune`** (its `settlement`), **`site`** (the place below the commune: `site`, else `locality`, else `district`). The names are those of the international convention (admin 0, 1, 2: ISO 3166-2, GADM), **the labels are free** and editable in Otherguise > Index kinds ("Région", "Département" on a French site).
- **A level that a country does not have is absent**: no topic, no rule. A commune of a country with no intermediate level hangs from the country.
- **Two levels with the same name are two topics.** The identity of a topic is its **kind, its name and its parent** (the key `label:<parent>/<name>` already holds the parent): *Zaragoza* the province (under Aragón) and *Zaragoza* the city (under the province) do not meet. **The earlier rule "a name repeated is one level" is gone**; the only level that is skipped is a first division that repeats the name **of the country** (the answer fills it so for a country that has none).
- **Wikidata is not needed for this**: it can later **propose** that two topics are one (the resolver already joins topics that share a `wikidata:` key), with a person's confirmation; **the merge of topics is not done**.

## As coded

- **Kinds**: the five built-in kinds below `place` (`Kinds::builtins()`; the seed version goes to 2, so a site gets them at the next `init`: **nothing to run**).
- **`PlaceChain`**: each level of the answer (`country`, `state`, `county`, `settlement`, then the lowest of `site`, `locality`, `district`) is a topic of its kind; empty levels are absent.
- **`Resolver`**: a topic found by its keys **takes the more specific kind that the candidate says** (a `place` that is now known to be a `commune`) **unless a person chose its kind** (`kind_by_hand`); it is never made less precise nor changed to a kind that is not below its own.
- **For the topics already made** (kind `place`): **reading the photographs again** (`wp otherguise index pages start`, or `index photos run --post=<id>`) refines the kinds of the places that they name, and **moves a mention to its new lowest topic** when the chain changed (a place that was one level for *Paris* is now two: the old topic becomes the department, the commune is new and takes the mention). The places that only tags or links gave stay `place`.
- **The roll-up of the levels**: `rollup="kind:country kind:admin1 kind:admin2"`.

## What is not done

- The **merge of topics** (the same territory under two levels, *Navarra*; or two spellings, *Iturtzeta*/*Iturzaeta*).
- **Checking the levels in more countries**: Media Helper checked France, Italy and Poland; Hungary, Finland and Lithuania are not checked, and the meaning of `state` and `county` there is not known.
- **A place whose level is not known** (from a tag or a link) stays `place`.

## Tests

`IndexPhotoStopsTest` (the chain: kinds, Spain, Paris, a country with no intermediate level, the first division that repeats the country), `IndexResolverTest` (the kind is refined, not overwritten; a kind by hand stays; the province and the city), `IndexKindsTest` (the built-in kinds). Under PHPUnit, and on a scratch WordPress with a fake Media Helper (the seed gives the new kinds; the hamlet is a `site` under a `commune`). **Not tried with the real Media Helper nor on the blog.**
