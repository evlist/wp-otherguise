<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 618 — Index: the screen of the duplicates

Status: **coded on 2026-11-23**. Dependencies: 616 (the merge). Context: [`../design/index.md`](../design/index.md).

## Why (Eric, 2026-11-23)

`wp otherguise index duplicates` gave **400 pairs** on the blog: not manageable on the command line. Many were **noise** (*EuroVelo 5* / *EuroVelo 8*, *Route nationale 1 (Italie)* / *(Albanie)*), and the same topic came out **three times** (*Pise* the loose topic, the province and the commune).

## Decided (Eric, 2026-11-23)

- **An administration screen**, with **a preview and then one click** to merge.
- **"These are not doubles"** is **kept** (the pair is not offered again), **with means to lift the ambiguity to be added "as we use it"**.

## As coded

- **Fewer false pairs** (also for the command): labels that differ **only by their numbers** are not offered; two labels that have **each a qualifier in parentheses and the qualifiers differ** are not offered (*Route nationale 1 (Italie)* / *(Albanie)*); the pairs that a person kept are left out.
- **Groups, not pairs** (`DuplicateFinder::groups()`): the topics linked by a pair are **one group**; *Pise* the loose topic, the province and the commune are one group of three. A group is **safe** when **one** topic has a place (a parent) and a kind and **all the others** are unclassified (`any`) topics with no parent: they can go into it with no doubt.
- **The screen Otherguise > Index duplicates** (`Admin\DuplicatesScreen`, capability `edit_others_posts`, a nonce on every action, saved through `admin-post.php`):
  - **the list**: one table per group (who stays, who goes in it, the topic, its kind, **its place** in the hierarchy, **the number of posts** that mention it), a search by name, pages of 20 groups; the topic with a place is the one that stays by default;
  - **the preview**: for each topic that goes, **the mentions moved, those joined to one that was there, the children moved, the keys added and the kind it will have**; the merge is a second step (a button); **kinds that cannot be one** are shown and need a box to merge anyway; **a merge cannot be undone** (it says so);
  - **the safe merges**: one list, **checked by default**, with the number of mentions that move, merged in **one click**; the server **only does what the finder still calls safe**, whatever was posted;
  - **"These are not doubles"**: the pairs of the group are kept in the option `index_not_duplicates` (`IgnoredPairs`) and not offered again.
- The merge itself is the one of slice 616 (the command `index merge` and this screen do the same).

## Not done

- **Lifting the ambiguity** when two topics are really two (the province and the commune of Pise) beyond "not doubles": renaming one of them, a qualifier, or a note. **To be decided as the screen is used.**
- **Undo**; a **filter by kind**; **proposals from Wikidata**.
- **Not tried in a browser**: the pages were printed under PHPUnit and with the real functions of WordPress on a scratch site, never looked at in the administration, never on the blog.

## Tests

`IndexDuplicatesScreenTest`: the groups and what is not offered (the numbers, the qualifiers, the pairs kept, a pair offered again), safe or not; the list; the preview that changes nothing; the merge (capability, nonce, what is refused, the force); "not doubles"; the safe merges and the view that lists none after.

## Changed on 2026-11-23 (after the first use on the blog)

- **The kind has a column of its own**: it was printed in parentheses after the name (*Alès (Third-level division)*, *Aarhus (Commune)*), which reads as a qualifier of the name; it is **a kind**, not part of the label. The label in a printed index is the name with its **broader topic** (*Aarhus (Jutland central)*, the comarca and the other third divisions skipped: slice 619).
- **Where a topic comes from**: two columns, **"Made by"** (the sources of the mentions: `post/link`, `image/address`…) and **"Keys"** (its identifiers and the keys of its sources, `wikipedia:fr:Alès`, `term:category:9`, the keys made of its label left out); the safe merges show the sources of both sides.
- **A parent and its child of the same name make a merge unsafe**: *Alès* the arrondissement (a third division of the Gard, level 7) and *Alès* the commune below it are shown **with the loose topic *Alès*** (a link: it may be either); the group is **not safe**, a person chooses. Before, the finder did not see the commune (a parent and its child are not a double) and offered to put the link into the arrondissement. Two such topics alone are not a double: **a group needs a real pair**.
- **Known limit**: in some countries **level 7 is the municipality** (Denmark: *Aarhus Kommune*, Sweden: *Arboga kommun*, Poland: the gmina), and **a third division is then the commune seen at a higher level**: a setting by country is to be decided.
