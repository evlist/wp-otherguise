<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 631 — Index: choosing among the topics that a level hesitated between

Status: **coded on 2026-11-24** (1019 PHPUnit tests, 8 of them new, with a real database for the statements; the choice, the list of the table and the resolver run on a **scratch WordPress** with the **real** stores and the **real** `WP_List_Table`; **never on the blog**, so **the 778 designations "To resolve" of the blog have no candidates recorded until the resolution is run again**). Dependencies: 626, 629, 630. Context: [`625-index-topics-and-observations.md`](625-index-topics-and-observations.md).

## Why (Eric, 2026-11-24, from the screen of the assignments)

*A designation that is ambiguous shows no topic to choose: "I suppose that, being ambiguous, no topic was made. In that case we should instead let the person choose the topic in a list, if possible in the table itself."* He was right: the designation kept **that** it was ambiguous and its key, **not the topics that the resolver hesitated between**, so the screen had nothing to propose, and the field of search only finds topics that exist.

## What was built

- **The resolver says where it hesitated** (`Resolver::resolve()` returns an `ambiguity`): the **level** (its label, **its key**) and **the topics** that it could not tell apart, **whichever level of the chain it is** (a place under an ambiguous *Fisterra* is itself ambiguous: the doubt is *Fisterra*'s). The designation keeps it in its proposal.
- **The evidence keeps every level** (`Designation\Evidence`: label, kind and keys of each level, the broadest first), so that the chain can be made again **without reading the page**. A designation read before that has only the labels of the broader levels, which come back as levels of the kind `any`: **the doubts that came from a kind are not found again, the others are**.
- **In the table, a list in the column "Assigned to"** for a designation that hesitated: *Which "Fisterra"?* and the candidates, each with its kind and the topics above it (to tell the namesakes apart). A designation with no candidate shows nothing to choose.
- **The bulk action "Apply the choices"**: **a line with a choice counts, ticked or not** (a form inside the form of the table is not possible, so the lists belong to it). For each choice (`Designation\Reresolver::choose`):
  1. the topic is **pinned to the level that hesitated** (`Topics::pin`, a term meta `_index_pin`): **the next designation that hesitates in the same way gets it too**, with no second question;
  2. the designation is **resolved again from its evidence**: **the levels that are missing under the topic chosen are made** (*A Atalaia* under the right *Fisterra*);
  3. it is **assigned by hand** (a reading never changes it), and **the mentions of its posts are made**;
  4. a topic that is **not** one of the candidates is refused and said; if **another level** hesitates after the first choice, it is said and a new list appears.
- **"Or a new topic…" in the action *Assign*** (label, kind, topic above): the confirmation says *a new topic*, the second step **makes it** (its kind is a decision of a person) **and assigns**; an assignment that is refused (a conflict of kinds) **does not leave the topic behind**.
- **`wp otherguise index designations resolve [--limit=] [--apply]`**: resolves the ambiguous designations again from their evidence: **it records the candidates** (the lists of the screen) and settles the ones that the topics made since have cleared up. **Run it once after the update**; a dry run says how many would settle.

## A find while trying it

**WordPress refuses a term whose name is already one of its siblings'** (accents and case ignored): `wp_insert_term` fails, the store returned 0, and **the designation stayed `pending` for ever**. The resolver now **takes the sibling that is there** (it adds its keys, refines its kind); **of another kind, it is a doubt** with this one topic as the candidate. **This may explain part of the 778 designations "To resolve" of the blog**; `index designations resolve` will tell.

## Not done

- **No "apply to every designation that shares this doubt"**: the pin makes the **next readings** and the **next `resolve`** follow the choice, but the designations already ambiguous are settled by `resolve --apply` (or one choice each).
- **No way to unpin** a choice (a person who chose wrongly assigns the designation again; the pin of the level stays). A command to list and release pins would come with the screen of the topics.
- **No script**: the lists are plain `<select>`s; a long list of candidates is not searchable.
- **Never tried on the blog**: the effect of `resolve` on the 778 designations is unknown (how many settle, how many hesitate between which topics).

## Tests

`IndexDesignationsDbTest`: **an ambiguous level records its candidates** (the level that hesitated, its key, the topics; no placeholder; the evidence makes the chain again, and the old evidence too); **a choice makes the levels below** (pinned, assigned by hand, the mentions, a topic that is not a candidate refused, **the next designation resolved by the pin**); **resolving again** (a dry run changes nothing, the candidates are recorded, a designation that cleared up is settled); **a topic that WordPress refuses is the one that is there**. `IndexAssignmentsDbTest`: **the list of the choices** (kind and topics above tell the namesakes apart; nothing to choose shows nothing); **applying the choices** (ticked or not; nothing chosen, a topic that is not a candidate, the levels made under the one chosen, the pin); **a new topic** in the assignment (the confirmation, the creation, the refusal that leaves nothing behind).

## Amendment (slice 632)

A single topic of the right label but of another kind is **not an ambiguity**: it is an *incompatibility* (`ambiguity.type`), shown as such, without the fictitious candidate that this slice added by mistake. See [`632-index-incompatible-and-commune.md`](632-index-incompatible-and-commune.md).
