<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 638 — Index: the topics that no motif is assigned to are lines of the table

Status: **coded on 2026-11-25** (1045 PHPUnit tests, with a real database; the table runs on a **scratch WordPress** with the **real** `WP_List_Table`; **never in a browser, never on the blog**). Dependencies: 630, 635, 637.

## Why (Eric, 2026-11-25)

*I expected a motif for each mention in the index.* In the index of the blog, *Aragon (Espagne)* and *Aragon (communauté autonome)* are two entries; searching the motifs *aragon* (36 of them) shows none that is assigned to *Aragon (Espagne)*. The cause: **a mention is made for the topic that a motif is assigned to**; the broader levels of a place (*Aragon* above *Ansó*) are topics that **no motif is assigned to**, and **the index adds up the pages of the narrower topics under them** (the roll-up, at the time the index is built). Eric: *the table should show the topics that have no assignment, always, with a view to reach only those; and then the motifs with no topic are as easy to see* (the filter *Topic status = No topic* of slice 637).

## What was built

- **The table lists motifs and topics**: a line is **a motif** (as before) or **a topic that no motif is assigned to** (*Aragon*, *Espagne*, a topic made by hand, a topic that a sync left empty). The lines of the topics are in the view **All** (and in its count) and in **their own view, *Topics with no motif (N)***, in **no other view**. The number of a line of a topic is **negative** (minus the number of the topic), so that it is not taken for a motif.
- **A line of a topic** shows its **Topic** (the label, its kind and the topics above it: *Aragon · First-level division, Espagne*), its **Topic status**, *No motif* in the column of the motif with the action **Open the topic** (the screen *Topics*), and *—* in Source, Posts, By and State. It is ticked with `topic[]`.
- **The filters**: *Topic* and *Topic status* reach the lines of the topics; **the filters of the columns of the motifs (Motif, Source, Posts, By, State) leave them out** (a topic has none). The search over every column finds a topic by its label, its identifier of Wikidata and its status.
- **The default sort is by the topic** (the first column), then by the motif; **a motif with no topic comes last** (it came first by the topic before: *No topic* is now at the end, which is where the lines with nothing to show go).
- **The action *Approve the topic***: it approves **the topics of the ticked lines of topics** and **the topic of each motif that is ticked**, which is the way to put the 81 candidate topics of the tags in the index (the filter *Topic status = candidate*, tick, approve). Only a candidate changes; the notice says how many were approved and how many were left.
- **Where the count comes from**: the topics that no motif is assigned to are found from the assignments that the table reads anyway (**one query**) and the list of the topics (**already read for the labels**): no further pass.
- The bulk actions moved to a trait (`AssignmentsBulk`) so that the screen stays under the size limit of a file.

## What this does not do

- **No action on a motif works on a line of a topic** (change the topic, exclude, delete…): the actions of the motifs skip them; **only *Approve the topic*** applies. To move the motifs of a duplicate topic to another, use *Change topic* on the lines of its motifs, or the action of the screen *Topics* that moves all the motifs of a topic.
- **The posts of a line of a topic are not counted** (*—*); the roll-up that the index shows is **not computed here**.
- **Never in a browser or on the blog**: look at the number of lines of the view *All* (6,698 motifs plus the topics with no motif) and at the time of the page.
- The screen is still called *Assignments*; Eric will find a name that fits a tool for the motifs **and** the topics (and the view *Topics with no motif* and the filter *No topic* are the beginning of it).
