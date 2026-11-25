<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 639 — Index: a topic is shown as the index prints it

Status: **coded on 2026-11-25** (1046 PHPUnit tests, with a real database; the script under jsdom; **never in a browser, never on the blog**). Dependencies: 619, 630, 633, 637, 638.

## Why (Eric, 2026-11-25)

*It would be better to find the labels when a topic is shown, in the table or in a list of topics: the index prints* **Aragon (Espagne)**, *the screen printed* **Aragon (First-level division, Espagne)**. *Adding "First-level division" brings information, but it should come after the label.*

## What was built

- **One rule for the index and the screens**: `Designation\TopicLabel` gives **the label of a topic as the index prints it**: the label, then **the broader topic in parentheses**, the nearest one **that is not of a skipped kind** (by default the third divisions: *Ansó (Huesca)*, not *Ansó (Sobrarbe)*), else the direct one; the filter `index_context_skipped_kinds` changes the kinds. A topic that has no broader topic shows its label alone (*Aragon (communauté autonome)* keeps the parentheses of its own label). **The builder of the index uses the same class**: the rule was written once and is not copied.
- **On the screens**: the column **Topic** of the table shows the label of the index then the **kind** (*Aragon (Espagne)* · *First-level division*); the **lists of the topics** (the choice among the candidates, the close topics of the quick edit, the search of the field that chooses a topic) show **the label of the index, then the kind, then the number** (*Aragon (Espagne) · First-level division #25393*), and the box shows the label of the index once a topic is chosen; the quick edit changes the line in place with the same text; the confirmation of an assignment names the topic the same way.
- **The chain of the topics above is no longer printed there** (the label of the index holds the nearest one): the number tells apart two topics that have the same label, context and kind.
- **The search of the topics** (`GET index/v1/topics`) and **the answer of the quick edit** carry a field **`display`** (the label of the index) beside `label`, `kind` and `chain`, which stay.

## Not done

- The screen *Topics* (the hand-written lists) keeps its columns *Label*, *Kind* and *Under*: it is to be rewritten.
- The full chain of a topic is no more shown in the lists of the table: **to look at** if it is missed for deep chains.
