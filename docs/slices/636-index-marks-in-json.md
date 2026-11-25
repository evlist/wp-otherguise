<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 636 — Index: the marks in JSON, a mark that says its source

Status: **coded on 2026-11-25** (1041 PHPUnit tests, 8 of them new or changed, with a real database for the mentions; **never tried on a page of the blog**). Dependencies: 601, 605, 635. The commit `f4bee4a` of `evlist/wp-whobird` writes the text form; the JSON form is asked of that plugin (see the end).

## Why (Eric, 2026-11-25)

The mark `data-index` is the generic way to tell the index what a page is about (the editor, a theme, a plugin: whoBIRD is one of them). *Is it not better to have one attribute `data-index` with a JSON value than to multiply the attributes? And the source should appear on the screen of the administration.*

## What was built

- **Two forms of a mark**, read by `Harvest/PageReader`:
  - **The text form** (unchanged, and still the easiest by hand): `data-index="wikidata:Q9"`, `data-index="Beaufort > Les Arberons | Doron"`, with its companion attributes `data-index-kind`, `-label`, `-sort`, `-main`, `-modes` and the new **`data-index-source`**.
  - **The JSON form**: a value that starts with `{` (an object: one mark) or `[` (a list of objects). **`topic`** (or **`id`**) holds what the text form holds (an identifier, a path, several topics with `|`); the other keys are the companions: `kind`, `label`, `sort`, `main` (`true`), `modes` (a string or a list), **`source`**. Example: `{"topic":"wikidata:Q25422","kind":"species","label":"Pinson des arbres","source":"whobird"}`.
  - **In the JSON form the keys win over the companions**, which fill what it lacks. A JSON that does not parse **says nothing** (the mark is ignored). The fields are cleaned by **one function** (the same for both forms): a new field is added in one place.
- **A mark that says its source** (`source`, a slug of letters, digits and hyphens): the motif is of the source **`post/mark/<source>`** when that source is **registered below the marks** (a source that nobody declared is `post/mark` itself: nothing is lost). **`post/mark/whobird`** is registered ("An observation of whoBIRD (a mark that says so)"; it replaces the top-level entry `whobird`, which nothing used); **another plugin adds its own with the filter `index_sources_registry`** (`post/mark/<its name>`). The motifs of the source `post/mark/whobird` are **distinct** from the ones of `post/mark` (the source is in the key) and go to the same topic through the identifier; `source:post/mark` in an index **includes them** (a source stands for its descendants).
- **`Source\Page` reads the sources below the marks** (`mention_sources()`), so that **the mentions go when the mark goes**.
- **The screen *What is on*** (the sources): the labels were out of date since slice 635 (*"those that have a kind"*): **"The categories / The tags (those that have no kind give candidate topics)"**, and the page source says what it reads: **"The pages: the marks (`data-index`, in the text or in JSON) and the links to Wikipedia"**. It is **one switch**: the marks and the links come from the same reading of the page, and turning the links off alone would read the page for nothing.

## To do on the other side

**`evlist/wp-whobird`** writes today `data-index="wikidata:Q…"`, `data-index-kind="species"` and `data-index-label="…"` (commit `f4bee4a`, still valid). It is asked to write **the JSON form with `source: "whobird"`** (one attribute, `esc_attr( wp_json_encode( … ) )`); both forms are read, so nothing breaks in between.

## Not done, and what to look at

- **Never tried on a real page** of the blog: update wp-whobird (JSON form), read an article with a whoBIRD block (`wp otherguise index rebuild --source=…` is for the terms; the pages are read by the synchronisation, or `wp otherguise index read <url>` for the signals), and look at the source `post/mark/whobird` in Assignments.
- The screen *Sources* of the administration lists the registry, so `post/mark/whobird` shows there; **no switch per source below the marks** (a source below the marks is read whenever the pages are).
- A mark written by hand in JSON in the editor is subject to the filtering of attributes of WordPress for the roles with no `unfiltered_html`: **to check** (the text form has the same question).
