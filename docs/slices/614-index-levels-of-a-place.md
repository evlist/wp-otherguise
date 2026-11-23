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

## Changed on 2026-11-23 (slice 617): the levels by their numbers, a third division, one photograph once

Found on Eric's blog (the photographs of San Román, Aragon): **Media Helper's `county` is not always the province**. In Aragon and in Catalonia it is the **comarca**, and the province (*Huesca*, *Lleida*) is only in `levels.admin.level6`:
`settlement` = Lascellas-Ponzano, `county` = Somontano de Barbastro, `state` = Aragon, `admin` = `level8` Lascellas-Ponzano, `level7` Somontano de Barbastro, `level6` **Huesca**, `level4` Aragon.

- **The levels come from the numbered administrative levels when the answer has them**: `admin1` = `level4`, `admin2` = `level6`, and a new built-in kind **`admin3`** = `level7` (a comarca in Spain, an arrondissement in France), put **between the second division and the commune**: *Espagne > Aragon > Huesca > Somontano de Barbastro > Lascellas-Ponzano > San Román*. A third division that **is the commune** (Poland, where the gmina is level 7), or repeats the province or the region, is not one. `admin3` has a line in an index **only when `rollup` asks for it**.
- **A numbered level that is missing is missing**: without `level6` the comarca of `county` is **not** taken for the province. **Only an answer with no numbered level at all** (an old answer of the cache) falls back to `state` and `county`.
- **A thumbnail and its original are one photograph**: same position, same time and the same file name once the size (`-1024x577`, `-scaled`) and the thumbnail directories are left out. A page shows both (the image and its link): the stop counted it twice (16 addresses were 15 photographs). The record keeps both addresses: the mention has **the `via` of each** and the pending list **both**.
- **The topics already made**: reading the photographs again makes the new chain; *Somontano de Barbastro* under *Aragon* (kind `admin2`) is **no longer mentioned** and stays in the list of topics: merge it into the new one with `wp otherguise index merge <new> <old>`, or leave it.
- **Not checked in other countries**: the numbers 4 and 6 are the convention of OpenStreetMap, which Media Helper reported for France, Spain, Italy and Poland; the others are to be seen with `index photos run --post=<id> --dry-run --verbose`.

## Added on 2026-11-23: a commune of France is a commune (Wikidata class table)

- On Eric's blog the real Wikidata gave *Moulins (Allier)* the class **Q484170 "commune française"**, and the table of classes (which knew only the settlement and the administrative entity) gave it the kind `place`. **`Q484170` is now in the default table with the kind `commune`** (the most specific kind wins over `place`). **It is the only line that was checked** on Wikidata (with `Q4022`, "fleuve ou rivière"); the others stay a proposal to check.
- **`wp otherguise index enrich classes --check=Q36784,Q6465,Q194203`** prints the label that Wikidata gives to identifiers, **without changing the table**, to check a class before adding it. The table that a site saved (the first `enrich map` writes the whole table in the option `index_wikidata_classes`) **is not changed by a new default**: use `enrich map` there.
