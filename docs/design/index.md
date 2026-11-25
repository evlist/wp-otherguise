<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# The Index module: entries, mentions and sources

Status: **design, not coded (started 2026-11-21)**. Nothing of it exists in the plugin. Points are marked **Decided** (said by Eric), **Proposed** (Claude's reading, **to be confirmed**) or **Open**; a Proposed point is not a decision. What was **read in the code of other plugins** is marked as such; what comes from memory is marked **from memory, not checked**.

Context: the index of the printed books was designed in [`books.md`](books.md) ("Table of contents and index"), as a part of the module Books. Eric's remarks of 2026-11-20 and 2026-11-21 widen it: the same index serves **the web** (a general index of the blog) and **the books**, from **several sources**. This note takes over what `books.md` says about the *entries*; the *page numbers* stay in Books.

## What is wanted

- **Decided (Eric)**: a module **of its own** (not a part of Books), used by Books and also usable in web mode: a general index of the blog, a page per entry, the entries of a post.
- **Decided (Eric)**: **first for his travel blog** (printed travel books are mostly photographs, with very little printed text).
- **Decided (Eric)**: **the locator of an entry is the post**, not a passage: most terms to index are **not in the printed text** and must behave **like tags**. A passage (an `data-index` mark on a word) only refines, when it is printed.
- **Decided (Eric)**: the entries come from **several kinds of sources**, abstracted behind **one interface**: tags and categories; marks in the text (`data-index`); full-text searches; the start and the end of the GPX track of a post; the photos of a post (their coordinates give place names); the links, **above all the links to Wikipedia** (a link is a sure sign that there is something to index); the birds observed (WhoBIRD).
- **Decided (Eric, 2026-11-21): the index reads the page as WordPress renders it**, not the post as it is saved in the database. Plugins and themes add what they want to a post (Eric's blog puts the WhoBIRD block **in the templates**, so it is not in the contents of the post at all). Instead of learning how each plugin works, **the plugins put semantic information in their HTML** (data attributes), and the module reads it. See "Reading the rendered page".
- **Decided (Eric)**: **Wikidata rather than Wikipedia for the identifiers**, with an exception to discuss for the places (OpenStreetMap or Wikidata).
- **Decided (Eric)**: a **manual mechanism** to link entries that were **not identified automatically or are ambiguous**.

## What was read in the other plugins (2026-11-21, shallow clones, nothing run)

- **WhoBIRD** (`evlist/wp-whobird`): the observations come from **an export of the app (SQLite)**, table `BirdObservations` (`BirdNET_ID`, `SpeciesName`, `TimeInMillis`); the block shows those of **the day, the week or the month of the publication date of the post or page** (**no observation is stored per post**). The plugin keeps a **mapping table** (`birdnet_id`, `scientific_name`, **`wikidata_qid`**) built from BirdNET, eBird and Wikidata lists, queries Wikidata by SPARQL with a cache and a throttle, and takes the names in the user's language. **So the birds already have Wikidata identifiers**; a source "birds" would read the observations of the date of the post and give `wikidata:Q…` (kind: species).
- **Trackserver** (a fork, `evlist/wp-plugin-trackserver`): the tracks are in the tables `ts_tracks` and `ts_locations`; **Eric does not use it to put the tracks in the posts** (he uses several plugins, at present **`wp-gpx-maps`**, a plugin of his in a **private** Gitea repository, `gitea.dyomedea.com/Dyomedea/wp-gpx-maps`: the server answers an anonymous request with a 404, so the code was **not read**; **Eric says that it shows a link to download the GPX**, which puts the **address of the file in the HTML of the page**). With the decision above its tables do not matter to the index: the **file** that the page names is what counts.
- **Media Helper** (`evlist/wp-media-helper`): it knows **GPS files** as a type of media (`gpx`, `kml`, `kmz`, `fit`, `tcx`) that it inserts in a post, and reads the **dates** embedded in the files (`EmbeddedDates`); it **does not read the coordinates of the photos**. The core of WordPress does not keep them either (**from memory, not checked**: its `image_meta` has no GPS keys). **Reading the EXIF of a photo (`exif_read_data`, an optional PHP extension) would be new code.** The thumbnails of the blog **keep the GPS EXIF** (checked on 8 of 8 samples of one post, see below), so the images named in the page can be read as they are, without finding the originals.

## The model (Proposed)

Three notions, stored with what the module Triples already gives.

1. **Entry**: a term of a **non-public hierarchical taxonomy** `index_entry` (the hierarchy gives the sub-entries). Its data: a **kind** (place, route, species, person, work, incident…), a **sort key**, **aliases**, **identifiers** (below), a **status** (candidate, approved, refused). The kinds are a short list, with the typography that goes with each.
2. **Mention**: the statement `(post, index/mentions, entry)` of Triples, qualified by its **source**, its **strength** (main or passing) and, optionally, the **anchor** of a passage. One mention per (post, entry), with **several sources** possible (**to check** in Triples: several qualifications of one kind on a statement).
3. **Signal and candidate**: a **signal** is what is found in a page or in the database (a data attribute, a link to Wikipedia, the address of a `.gpx` file, an image, a tag); a **candidate** is what an entry would be made of (label, kind, identifiers, strength, anchor, evidence, confidence). Two stages, both extensible through an action (`index_register_…`, the pattern of `triples_register_predicates`):

```php
interface IndexHarvester {      // reads a page (a string of HTML, no network) or the database
	public function id();                      // 'attributes', 'wikipedia-links', 'gpx-files', 'images', 'tags'
	public function signals( $html, $post_id ); // Signal[]: type, value, element id (anchor), evidence
}
interface IndexEnricher {       // turns signals into candidates (may call a service)
	public function handles( $signal );        // 'wikipedia-link', 'gpx-file', 'image', ...
	public function candidates( $signal );     // Candidate[]
}
```

The module **depends on none of the plugins**; a plugin that wants to be indexed only **writes the convention** in its HTML (below), without calling the module.

**The flow.** When a post is saved (or when a page changes without the post changing: new observations, a replaced file), the page is rendered, the harvesters read it, the enrichers make candidates, the **resolver** (below) ties each to an entry; the mentions **of that harvester** that no longer apply are removed and those made by hand are kept. `wp otherguise index rebuild [--source=] [--dry-run]` does it for the whole site, as a **background job** with a report when it is long (the rules of the repository: progress, stop and resume).

## Reading the rendered page (Decided by Eric; the details are Proposed)

**Why.** What a visitor sees is **the contents of the post, the templates, the blocks and the shortcodes** of the theme and of every plugin. Reading the post in the database would mean knowing what each plugin adds and how it is configured; and **some of it is not in the post at all** (the WhoBIRD block of Eric's blog is in the template of the posts, and shows the observations of the date of the post). The page is the one place where everything has been put together.

**How a page is obtained** (Proposed):

- **A request of the site to itself** (a loopback) for the address of the post, in a **background job** (WP-Cron, with progress, stop and resume, a report: the rules of the repository). Books already does the same thing for its PDF: a rendering server asks the site for the page, with **a private link** for a post that is not published (slice 403) and **an internal address of the site** when the public one does not resolve from the server (slice 225): the module reuses those two settings and does not invent others.
- **The web rendering** (not the print mode): it holds what the print mode hides, and an entry that is not printed must count (the locator is the post).
- **Not at the moment a visitor loads the page**: a cache plugin may serve the page without PHP, so nothing would run. A job is predictable; its price is time (**to measure**: a few seconds a post, a few hundred posts).
- **When to render again**: when the post is saved, on demand (`wp otherguise index rebuild`), and **when what a plugin shows has changed without the post changing** (new observations, a replaced file): **Open** (a periodic check by the hash of what was harvested is the simplest).
- Only **published** posts at first; a draft through its private link is a later option (the Access module).
- The extraction itself is **a pure function of a string of HTML** (and of the identifier of the post): it is tested without a site and without a network; fetching the page is a separate piece.

**What is read in the page.** The module trusts **explicit markers** everywhere in the page; for what has **no marker** (the generic harvesters) it reads **only the contents of the post** (the region of `core/post-content`, **to check** on the theme of the blog: a block theme wraps it in `wp-block-post-content`), so that a link in the footer, a widget "recent posts" or a menu is not taken for a mention. A list template (a section, an archive) is **not harvested**: it repeats the contents of other posts.

**The convention: `data-index` attributes** (Proposed; a plugin writes them, the module reads them, **neither depends on the other**):

| Attribute | Meaning |
|---|---|
| `data-index="wikidata:Q25327"` | this element **mentions that entry**; the value is **an identifier** (`wikidata:`, `osm:node/123`, `geonames:`) **or a label** (`Santiago de Compostela`; `Espagne > Galice` for a sub-entry; `a \| b` for several) |
| `data-index-kind="species"` | the kind of the entry (place, route, species, person, work, incident) |
| `data-index-label="Troglodyte mignon"` | the label to show when the value is an identifier (the language of the page) |
| `data-index-sort`, `data-index-main` | a sort key; a main reference |
| the `id` of the element | **the anchor of the passage**, when there is one |

- **WhoBIRD** (a plugin of Eric's): the block writes `data-index="wikidata:Q…"` on each observed species, from the `wikidata_qid` it already has. Whatever the block's parameters, **its output carries the identifier**.
- **A mark made by hand** in the editor is the same attribute on a `span` (see "Marks in the text").
- **A plugin that is not Eric's** (GPX Maps, a theme) can get the attributes from **a small adapter** of the module on the filters `render_block` or `do_shortcode_tag`, **or** be read by a generic harvester without any adapter:
  - **`.gpx` files**: every address ending in `.gpx` in an attribute of the page (a `src`, an `href`, a `data-…` of the map). **For `wp-gpx-maps` it is the link to download the file** (Eric, 2026-11-21), so no adapter is needed. The file is then read (its first and last points, the waypoints) by an enricher. **The scope of this harvester is the whole `main` element of the page, not only the contents of the post**: if the plugin is used in a template, as the WhoBIRD block is, its link is outside the contents. **To check on a real page of the blog**: where the link is, and whether the file is in the uploads (to be read from disk) or elsewhere.
  - **Links to Wikipedia and to Wikidata** in the contents of the post.
  - **The images of the post** (their addresses, resolved to the original files in the uploads): for the places of the photos (EXIF, new code).
  - **The tags and categories**: from the database, not the page (a theme does not always show them).

**What is lost, said plainly**: what a script draws in the browser (a map built by JavaScript from a file whose address is **not** in the HTML), what is behind a login or a consent screen, and what a cache or a protection of the site (a firewall that refuses the loopback) hides from the request. A page that is **not reachable** is reported, not silently skipped.

## What a real page contains (checked on 2026-11-21: one post of the blog, read from the public address, nothing run on the site)

The post is *Étroubles (Italie) ⇾ Beaufort (France)* of 2026-10-01 (`e.vli.st/2026/10/01/…`), a block theme; the page weighs 175 KB.

- **The GPX file is named in the page**, and **outside the contents of the post**: `wp-gpx-maps` writes `<div id='wpgpxmaps_downloadfile'>Download file: <a href='…/uploads/cumulus/eric/2026/10/01/20261001-vanlife.gpx' target='_new' download>` **before** the element `wp-block-post-content`, inside `<main>`: the map is in the template. **This confirms that the scope of the harvester of files is `<main>`, and that the post in the database would not have shown it.** (A second plugin, `wp-attached-gpx`, adds CSS to the page; it plays no part here.)
- **The GPX file** (public, 1 MB): an export of **PhoneTrack** (Nextcloud), **3,225 points, one track, no waypoint**, in **chronological order**; the **first point** (06:04, 45.8189 N 7.2297 E) is the start (Étroubles) and the **last** (15:17, 45.7173 N 6.5746 E) the end (Beaufort). The start and the end are to be taken **by the time of the points**, not by the order of the file, and **checked** (the first `<time>` of a GPX is often that of the export).
- **The images**: 66 distinct addresses in `<main>`, **all thumbnails** in `uploads/thumbnails/cumulus/eric/…/20261001_134828-1024x577.jpg`. **They keep the GPS EXIF**: 8 of 8 samples (480 and 1,024 px) had their coordinates and their date. So the module can read the images **as the page names them**. One post has dozens of photos, **over a distance of tens of kilometres**: places must be **aggregated** (the PHP extensions `exif` and `intl` are there on the machine of this session; **to check on the server of the blog**).
- **The tags are in the page** too (`rel="tag"`): the category *Vanlife* and the tags *france*, *italie*, *suisse*; the database stays the source (term identifiers).
- **92 links** in `<main>`, **one to Wikipedia**: `fr.wikipedia.org/wiki/Beaufort_(fromage)`, **the cheese, not the village** that the title names, with the text "Wikipédia". Two lessons: **the label comes from the address, never from the text of the link**, and a link to Wikipedia **is not always a mention of the place of the post**: the kind (a product) comes from Wikidata ("instance of"), and the entry *Beaufort (fromage)* must not be confused with *Beaufort (Savoie)*: this is the case of the queue. The other external links (tourist sites, a press article) are **not** a sure sign and are not harvested.
- **A link to OpenStreetMap is noise**: the only one is the **attribution of the Leaflet map** (`openstreetmap.org/copyright`). A harvester of OpenStreetMap links must accept only addresses of an **object** (`/node/…`, `/way/…`, `/relation/…`).
- **No WhoBIRD output** in this page (only its CSS file): no observation that day, or the block is not in this template. **Nothing is known yet of its markup**: a page with observations is needed.
- **The title of this post has a convention** (`Départ (Pays) ⇾ Arrivée (Pays)`), **but it is not general** (Eric, 2026-11-21: the blog was written over several years and **the titles follow no pattern**; the post of 2026-05-26 is *Plateau de l'Hers – col de Barrancq – …*). **So the title is not a source.**

**A second post, with observations** (*Plateau de l'Hers – col de Barrancq*, 2026-05-26, 206 KB; the page found by Eric):

- **The WhoBIRD block is in the template, after the contents of the post**: the element `wp-block-post-content` ends and `<div class="wpwbd_observations">` follows at once, inside `<main>`; the GPX link (`wpgpxmaps_downloadfile`, a file `20260526-rando.gpx`) is **before** the contents. **Both plugins write outside the contents of the post.**
- **The markup of an observation** (seven species on this page): `<li class="wpwbd-bird-entry" data-recordings="…wav,…wav">` with `div.common-name` (the name as the app gave it: *Grimpereau des jardins*, but also *pinson des arbres* and *pouillot véloce* **in lower case**), `div.latin-name` (*Certhia brachydactyla*), a **link to Wikipedia** (`fr.wikipedia.org/wiki/Grimpereau_des_jardins`, `class="bird-wikipedia-link"`, an icon and no text) and a picture from Wikimedia Commons. **There is no Wikidata identifier in the HTML**, although the plugin has it.
- **What a harvester would find without any change**: a link to Wikipedia and a Latin name per bird, **but not in the contents of the post** (the generic harvester of links reads only the contents): it would see nothing. This is why **the convention matters**: an explicit marker is trusted wherever it is.
- **The change in WhoBIRD** (Eric's plugin; **made on 2026-11-25**, commit `f4bee4a` of `evlist/wp-whobird` (the text form), then `fe9ec53` (**the JSON form with `source: "whobird"`**, the one in use), slices 001 and 002 of that repository, by its own session at the request of this one; **the reader of this module understands it**, see `test_the_whobird_entries_as_the_plugin_writes_them`; **under automated tests only on both sides, never on a real page of the blog**): the proposal below, as it was written before: in `BirdListItemRenderer::render()`, add to the `<li>` `data-index="wikidata:Q…"`, `data-index-kind="species"` and `data-index-label` (the common name from Wikidata, which is already in `$birdData`), the identifier coming from `getMappingByBirdnetId( $this->birdnetId )['wikidata_qid']`, when there is one. A few lines; the names of the birds then come out **with the case of Wikidata**, not of the app.
- **The contents of the post** (this one has a note that **a text was generated by an AI** from the video, the GPX file and documents) **has three links to Wikipedia**: *Chemin de la Mâture* (a path), *Lathraea clandestina* (a plant) and *Pinguicula* (a genus): kinds of entries that are **not** birds, found by the generic harvester **in the contents** and giving the Wikidata item by the API. A first sign that links to Wikipedia **are frequent** in the posts of this kind.
- **97 images** in `<main>`; tags *Randonnettes*, *france*, *pyrénées atlantique (64)*.

**Two older posts** (Eric's choice, to complete the analysis; read the same way, 2026-11-21):

| | *À Miranda et cap Ortegal* (2025-07-21) | *Forcalquier – Gréoux les Bains : vers le Sud* (2024-03-16) |
|---|---|---|
| templates | the same as the posts above | a block theme as well, but **the photos are NextGEN Gallery** (76 images, 315 mentions of the plugin in the page) |
| contents of the post | **very short** (2 KB in a page of 257 KB): the page is made by the templates | long (80 KB: the galleries are inside it) |
| GPX link | `wpgpxmaps_downloadfile`, **before** the contents (template); file in `uploads/cumulus/eric/2025/07/21/` | the same plugin, **inside** the contents (a shortcode); file in `uploads/2024/03/16-mars-2024.gpx` |
| WhoBIRD | **one species** (*Picus sharpei*, with a link to Wikipedia), **after** the contents | none |
| links to Wikipedia | *Cap Ortegal* in the contents; the bird after them | none |
| images | **151**, thumbnails of `cumulus/eric/…` **and `cumulus/gisele/…`** (photos by two people) | **77**: the NextGEN thumbnails `gallery/16-mars-2024/thumbs/thumbs-….jpg`, **each in a link to the original** (`a.ngg-simplelightbox`, `href=…/gallery/16-mars-2024/20240316_091413.jpg`) |
| tags | *Direction Espagne à pied 2025*, *camino del mar*, *espagne*, *galice*, *repos* | *Autour de la Méditerranée en vélo 2024*, *alpes de haute provence (04)*, *cyclotourisme*, *étape*, *eurovelo 8*, *france* |

What it shows:

- **The generic harvesters work on the three generations of pages** with the same rules: a `.gpx` address in a link anywhere in `<main>` (before, inside or after the contents, whatever the folder of the file); the images named in the page; the tags. **The index does not wait for the migration of the old posts to the web and print templates** (the long-term wish of Eric, with the removal of NextGEN Gallery: needed to make a book from such a post, **not** to index it); and **an index read from the rendered page survives that migration** as long as the page still shows a GPX link, its images and its tags.
- **NextGEN thumbnails keep the GPS EXIF too** (the thumbnail and the original both had the coordinates and the date, on the one image tried), and **the original is named** in the page by the link of the lightbox. The harvester of images must therefore read **`<img src>` and the `<a href>` that point to an image file** (jpg, jpeg, png, heic…), keeping one address per photograph (the original when it is named, else the thumbnail).
- **The tags are of mixed kinds**, so **the kind of a tag has to be set** (the decision already taken: a term with no kind stays out of the index): a **trip or series** (*Direction Espagne à pied 2025*, *Autour de la Méditerranée en vélo 2024*: what **defines a book**, not an entry of its index), a **route** (*camino del mar*, *eurovelo 8*), a **place** (*espagne*, *galice*, *france*, *alpes de haute provence (04)*), and **topics or types** that are not for an index (*repos*, *étape*, *cyclotourisme*). With a few thousand tags, assigning kinds **in bulk** is a screen to design.
- **Photos by two people** (`cumulus/eric`, `cumulus/gisele`): the place of a photograph does not depend on who took it; **the author may be worth keeping** (the folder) if an index "by photographer" is ever wanted: **Open**, not needed.
- **WhoBIRD with a single species** (*Pic de Sharpe*): a bird is **a mention of the post at the level of the day**, as for the others.

## Places from coordinates: which level, what is stored (decided in part, 2026-11-21)

**Decided (Eric)**: the purpose of the index of places is above all to **find again the places one remembers, above all where one slept** (the start and the end of the day). **There is no rule about what one remembers**: sometimes the name of the hamlet, sometimes the name of the municipality. So **the entry is the lowest level that the service proposes, the hamlet**, and **the municipality is found in the index as well**.

**What the service really returns at its lowest level** (four calls to Nominatim, zoom 18, 2026-11-21; two ends of the track and two photographs):

| Point | Object found | Address chain |
|---|---|---|
| start, Étroubles (Italy) | **`tourism=camp_site`, "Camping Tunnel International"** | road *Rue Chevrières*; **hamlet *Le Créton***; village and city *Étroubles*; state; country |
| end, Beaufort (France) | **`place=house`, no name** | road *Rue Saint-Maxime*; **hamlet *La Glirettaz***; village *Beaufort*; `municipality` *Albertville*; county *Savoie* |
| photograph 1 | **a road** (`highway=service`, *Chemin des Velars*) | hamlet *Les Arberons*; village *Argentière*; town *Chamonix-Mont-Blanc*; `municipality` *Bonneville* |
| photograph 2 | **a road** (`highway=primary`) | village *Les Valettes*; county *Martigny*; state *Valais* |

What follows from it:

- **"The lowest level" is not the object found**: it is a **road** or a **nameless house** most of the time. The rule has to be: **the lowest *place-like* level of the address chain** (hamlet, isolated dwelling, locality, village, neighbourhood…), **never a road or a bare building**.
- **A named place to stay** (the first point is on a camp site that has a name, *Camping Tunnel International*) **is not taken as an entry** (**Decided, Eric**: it is complicated, and in a van one may as well sleep in a car park). The entry is the **place-like level of the address**, whatever the object found.
- **The names of the levels cannot be trusted** (**solved on 2026-11-22 by the contract of Media Helper, which asks for `geocodejson` and gives `levels.settlement`, `site`, `county`…; what follows is what was seen**): the key `municipality` is **not** the municipality in France (*Albertville* and *Bonneville* are arrondissements or groups of municipalities, and *Beaufort* is a `village`); *Étroubles* is both `village` and `city`; *Chamonix-Mont-Blanc* is a `town` and *Argentière* a `village` inside it. **The municipality must be recognised by its administrative level and by Wikidata ("instance of" municipality), not by the name of the key.** Only two countries were looked at.
- **A hamlet has no identifier in the answer**: none of the four objects carried a `wikidata` tag, and the chain gives **names without OpenStreetMap numbers**. The identifier of the municipality (and of the hamlet, if there is one) needs **another call** (a search by name and area, or a reverse at the right zoom); two zooms of the same point gave **different** nearest objects (*Combe* at zoom 14, *Le Créton* at zoom 18). Until there is an identifier, the entry is identified by **its label, its municipality and its country** (a key among its aliases: the manual linking of the section above covers the mistakes).

**Proposed model** (to confirm):

0. **Decided (Eric, 2026-11-21)**: **no rule of its own for the hamlet** (it does not exist everywhere): **the entry is the lowest place-like level that the answer holds**, hamlet or village or town; **the photographs follow the same rule** as the ends of the track; **no lodging**.
1. **A cache of the whole answer** (derived data, not in Triples, rebuildable): the chain with its names, the object found, the date, the service, keyed by the coordinates rounded to about 100 m, the level asked and the language. It lets the rule change **without calling the service again** (a rate limit), keeps the index **reproducible** when OpenStreetMap changes, and **explains** an entry.
2. **Entries in a hierarchy**: *municipality > hamlet* (the hamlet only when the answer has one). **The mention is attached to the most specific entry**; **the municipality lists the mentions of its descendants** (computed when the index is read, not stored twice), so that **one finds the place under the hamlet and under the municipality**.
3. **Decided (Eric, 2026-11-21): every geographic level of the answer becomes an entry**, **the departments, the regions and the countries too**, in the hierarchy *country > state > county > municipality > hamlet*. **Why**: the tags (*france*, *galice*…) **are not systematic**, so **the geolocation is the main source of the places** and has to give the whole chain; the **duplicates** with the places that tags or links already gave are **resolved by hand** (the queue and the aliases of the sections above: the tag *france* is linked once to the entry *France*, and the next posts follow). This **replaces** the earlier proposal to keep the upper levels as qualifiers only. The mention is on the **most specific** entry, **the upper entries list the mentions of their descendants** (computed at reading). An entry is identified **with its parent** (*Beaufort* under *Savoie* is not *Beaufort* under another county), which keeps homonyms apart. **To handle** (**the stable names of the contract of Media Helper now cover most of it**): the **names of the levels of the answer are not reliable** (the key `municipality` is an arrondissement in France, `village` and `city` both name *Étroubles*): the levels are **typed by their administrative level or by Wikidata ("instance of")**, an entry that appears twice in one answer with the same name and parent is **one** entry, and the queue catches the rest.
4. **The mention keeps only its provenance**: the source, the coordinates rounded, the level, the key of the cache; and a **role** (`start`, `end`, `photo`): **the end of a day is where one slept**, so an index of "nights" is the entries with the role `end` (and the start of the next day).

**Open**: the **minimum number of photographs** for a place to count (see the measure below); the rules by country (France, Italy, Switzerland and Spain are the ones of the blog); the **order of the keys** of the address that count as place-like (`isolated_dwelling`, `hamlet`, `locality`, `neighbourhood`, `quarter`, `suburb`, `village`, `town`, `city`: a first list, taken from the answers seen); online or offline for the volume (below).


**The photographs of one day, measured** (the post *Étroubles ⇾ Beaufort*, 2026-10-01; reverse geocoding at zoom 18, the lowest place-like level of each answer, nothing else tried):

- **66 photographs, all with GPS** (the thumbnails of the blog keep it) fall in **35 cells of about 100 m**, which give **15 distinct places** (no photograph without a place-like level; **14 hamlets** and 1 village or town). The same day, after a threshold:

| minimum of photographs for a place | places kept |
|---|---|
| 1 | 15 |
| 2 | 9 |
| 3 | 7 |
| 5 | 5 |

- The places with the most photographs are **the ones one remembers**: *Col de la Forclaz* (12), *Les Arberons* in Chamonix (12), *Le Tronchet* (9) and *L'Hospitalet* (8) at Bourg-Saint-Pierre, *La Glirettaz* in Beaufort (8). Six places have one photograph only (*La Villaz*, *Le Plagnolet*…): **noise or a passage**.
- **What the threshold was for** (Claude's first idea): to **ignore a place where one photograph only was taken**, which is probably a place passed through. It is a rule about **whether a place matters**.
- **Eric's idea (2026-11-21): group the photographs by time and by distance** (the photographs are ordered by the time they were taken, and **a new group starts when a photograph is more than about 50 m from the previous one**). It is a rule about **redundancy and about the visit**: it gives, for each group, **the number of photographs and how long one stayed**, and **one call to the service per group** instead of one per photograph. The two are **complementary**, not alternatives.

**The same day, ordered by time** (the same 66 photographs; a group starts when a photograph is more than 50, 100 or 200 m from the previous one):

| new group beyond | groups | groups of one photograph |
|---|---|---|
| 50 m | 32 | 23 |
| 100 m | 27 | 17 |
| 200 m | 20 | 9 |

- **It saves few calls on this day** (32 groups for 66 photographs, against 35 cells of 100 m): the day is a journey and its photographs are **spread along the road**; a **day on foot with long stops** should gain more (**not measured**). What spares the calls is **the cache by cell and the grouping by name after the answer** (*Le Tronchet* is seen in 7 groups, *Les Valettes* in 4, and they are **one place**).
- **The duration of a stay is a better sign than the number of photographs**: *Les Arberons* **56 minutes** (12 photographs), *Col de la Forclaz* 6 minutes (12), *La Glirettaz* 3 minutes (8, the arrival), *L'Hospitalet* 1 minute (8); *Le Tronchet* has 9 photographs in **under a minute** and *Les Valettes* 4 photographs in a minute: **photographs taken while moving**, which a count alone cannot tell from a stop.
- **The chain rule drifts** on a slow walk (each photograph is within 50 m of the previous one, the group goes on for hundreds of metres): to be measured on a walking day; the distance to the **first** photograph of the group is the alternative.
- **To calibrate with Eric, who knows the day**: which of the **14 places** of the list does he want to find in the index? The rule (a number of photographs, a duration, a number of visits, or a combination) is **taken from his answer**, not guessed.

- **The volume would have decided online or offline** (settled below: the work is done as the photographs arrive): **35 calls for one post's photographs**, at one call a second, make about 40 seconds. **A book of 180 days is about two hours of calls; the whole blog, with a few hundred or a thousand posts, is many hours**. The public service of OpenStreetMap asks for no heavy use (**from memory, not checked**: at most one request a second, bulk geocoding discouraged). The cache of the whole answer, shared by the cells that several posts have in common, **softens** it; **an offline file or a service of one's own** is the solution for the photographs; **the ends of the track** (two calls a post) can stay online. **Settled by what follows (Eric, 2026-11-21).**

**What the geocoding gave** (two calls to Nominatim, zoom 14, on the first and the last point; one call to the search of Wikidata; the courtesy asked by the services was respected):

- the **nearest named object**, not the municipality: *Combe* (an isolated dwelling, in Cerisey, Étroubles) and *La Glirettaz* (a hamlet of Beaufort), with **the chain of the address** (hamlet, municipality, department, region, country) in the answer. The track starts and ends where a van is parked, so **a hamlet is what is found**; **the municipality is a level of the chain to be chosen by a rule**, the hamlet being a **sub-entry** if wanted (`Beaufort > La Glirettaz`).
- **neither object had a `wikidata` tag**: for the small places OpenStreetMap is indeed **the only identifier** there is, as the rule "Wikidata first, OpenStreetMap as a fallback" supposed.
- the search of Wikidata for "Étroubles" gives **Q35036, "Italian municipality in the Aosta Valley"**, with a description: **what the queue needs to show to choose**.


## Who geolocates: Media Helper, as the files arrive (Decided by Eric, 2026-11-21; built by the Media Helper session the same day)

**The photographs arrive from Nextcloud during the day**, and **Media Helper** already discovers the files as they come. **Eric's point: the geolocation is done by Media Helper, file by file, as they arrive**, and the module Index does **no geocoding** of photographs. The brief was sent to the session of Media Helper, which **built it as its slice 052** (`docs/slices/052-gps-and-places.md` of `evlist/wp-media-helper`, commits 223b64e and 061da9f on `main`; **read in that repository on 2026-11-21, nothing of it run by this module, and the session says it was tried only under unit tests**).

**The split** (Decided): **Media Helper keeps the facts, Index applies the policy.**

| Media Helper (facts about a file) | Index (what is worth indexing) |
|---|---|
| the **coordinates** of a photograph (EXIF GPS), the **capture time**, for a **GPX file** the **start and end points by time**; the **answer of the reverse geocoding**, kept whole, in a cache by cell | the **grouping** by time and distance (visits), the **duration of a stay**, the minimum for a place to count, the **choice of the level** (the lowest place-like one), the **roles** (start, end, photo), the **entries** and their hierarchy |
| a **filter** that answers "where is this file?" for an address or a path | the harvesters of the page, the resolver, the queue |

**What exists in Media Helper** (as reported and read in its slice):

- **Reading** (phase A, no network): the index of the files has the columns `gps_lat`, `gps_lon`, `gps_end_lat`, `gps_end_lon`, `track_start_at`, `track_end_at`, `geo_state` (0 not read, 1 with a position, 2 none) and `place_state` (schema version 4). A **pass of its own** (cron hook `wp_media_helper_geo_run`, its own lock, 15 seconds) reads them, **not the scan of the directories**; a file is read again only when its **size or time** changed. **Photos `jpg`, `jpeg`, `tif`, `tiff` (`exif_read_data`); HEIC is not read.** A GPX track: the start is the point of the **smallest `<time>`** and the end the **largest**, `trkpt` first, then `rtept`, then `wpt`; with no time at all, the first and last points of the file; read with `XMLReader`, no entities. A position of exactly 0, 0 or out of range is refused. Commands `wp media-helper geo status` and `geo run [--limit] [--reset] [--source]`.
- **The places** (phase B): **off until a service address is set**, so **nothing leaves the site by default**. Only the **coordinates rounded to 3 decimals** (about 100 m; a setting `places_precision` gives **4 or 5 decimals to a service of one's own only**, the public one always gets 3) and the language are sent, with a User-Agent `WP-Media-Helper/<version> (<site address>[; <contact>])` and the site as referer. **The public Nominatim is held to its policy** (read on `operations.osmfoundation.org/policies/nominatim/` on 21 November 2026 by that session): **4 requests a minute at most, one at a time, https only**, whatever the settings (a job that runs at intervals counts as bulk use); a 429, a 5xx or no answer stops every request with a back-off of 2, 4, 8 minutes… up to 6 hours, **never shorter than `Retry-After`**. **Cache by cell** (3 decimals and the language) in the table `media_helper_places`, kept when the index is reset, holding **the whole answer** (the JSON as it came, in the format `geocodejson`, `osm_type`, `osm_id`, `place_id`, `display_name`); failed cells are retried after 1 hour, 6 hours, 1 day, 3 days, and given up after 5. **A backfill of about 18,000 cells at 4 a minute is about 3 days of cron: for it, use a Nominatim of one's own** (the setting "requests a minute", 60 by default). Commands `wp media-helper geo places status|run|reset`; the filter `wp_media_helper_geocoding` (true by default) is a kill switch.
- **The contract** (phase C; internal, **coordinates are never printed publicly**): `apply_filters( 'wp_media_helper_file_geo', null, $address )`, or `wp_media_helper_get_file_geo( $address )`. The address is an absolute path, a relative path, the URL of the original **or the URL of a thumbnail of the cache** (`uploads/thumbnails/<dir>/<name>-WxH.ext`, mapped back to the original); a file outside the uploads is keyed by its absolute path, and a `wp-content/gallery` URL is mapped through `content_url()`. It answers **`null` when the file is not in the index**; otherwise:
  - **a photograph**: `type` `file`, `state` (`unread`, `none`, `positioned`), `lat` and `lon` (**only when `positioned`**), `taken_at` (`Y-m-d H:i:s`, the site's time zone), `taken_source` (`name`, `embedded`, `mtime`), `taken_precision` (a date with a day only is placed at **noon**), and `place`;
  - **a GPX track**: `type` `track`, `state`, `start` and `end`, each with `lat`, `lon`, `at` (**UTC**) and `place`;
  - **`place`** (**changed on 2026-11-22, after a quality check of 1,255 places of Eric's library: the address keys of `jsonv2` do not mean the same in every country, `municipality` being the arrondissement in France, the commune in Italy, the gmina in Poland, a district in Hungary; the plugin now asks for `format=geocodejson` and gives the levels under stable names; do not read `address` or `extratags` any more**) is `null` until the cell was looked for (**the cache only, never a request**), then `state` (`found` or `nothing`), `cell`, `language`, `osm_type`, `osm_id`, `display_name`, **`levels`**, `answer` (the whole decoded answer, which holds the extra tags such as `wikidata` under `features[0].properties.geocoding.extra`), `service`, `fetched_at` and `attribution` (`© OpenStreetMap contributors`). **`levels`** has, with **empty strings and never null for a missing level**:
    - `name` (what was found: a pass, a hamlet, a farm, a peak) and `kind` (the OpenStreetMap key and value: `place hamlet`, `natural peak`);
    - `site`: **the place name below the commune**, for a grouping finer than `settlement`: the name of what was found when it is a place (`place locality`, `hamlet`, `isolated_dwelling`…), **not** a land use, a boundary or a building (a housing estate or a marsh is not a place name); else `locality`, else `district`; **never equal to `settlement`**; empty when none;
    - `locality` (hamlet level: a hamlet, an isolated dwelling) and `district` (a quarter of a town): **not a reliable hamlet-versus-quarter distinction** (*Le Foirail* and *Masdamont* come out as `district`, *Tasdon* as `locality`), which is why `site` exists;
    - `settlement`: **the commune in most countries** (a village, a town or a city; the numbered level 8 when the flat field is missing); `county` (département, province, powiat, megye), `state` (région, voivodeship, land), `country`;
    - `admin`: the numbered administrative levels **as OpenStreetMap numbers them** (`level2` the country, `level4`, `level6`, `level7`, `level8`…, only the ones present). **They are numbered per country** (Poland: the gmina is level 7; France: the commune is level 8 and the arrondissement level 7; Italy: level 7 is the Comunità), so **`admin` is extra information, never the way to find the commune**.
  - the place of a bare position: `apply_filters( 'wp_media_helper_place_of', null, $lat, $lon )`.
- **What Index must take from it**:
  - **`taken_at` has a second only when the name has a time or the EXIF `DateTimeOriginal` was read**: a photograph whose name carries only the date is not opened for its date (only for its GPS), so its time is **noon**. The grouping by time and the duration of a stay must **tell the precision** (`taken_precision`, `taken_source`) and not trust a noon.
  - **A place has no entry until the cell was looked for**: Index asks again later, and **a photograph with no place yet is not an error**.
  - **The place may carry its Wikidata item** (in the whole `answer`, under `features[0].properties.geocoding.extra`, **no longer in `extratags`**): when it does, the entry has its identifier at once and the enrichment of slice 604 has less to do; **when it does not (a hamlet often has none), the entry has only names** (checked on four objects on 2026-11-21: see "Places from coordinates").
  - **Which level is the entry** (Eric's rule, **now expressible with the names of the contract**): the entry of a photograph or of an end of a track is **`levels.site`**, the place name below the commune, **with `levels.settlement` (the commune) above it**, then `county`, `state`, `country` as the upper entries of the hierarchy. **About 10 % of the places have no commune** (a point on the coast or in a marsh, outside the boundaries of any commune in the data): `settlement` and `county` are then empty, and Index **falls back to `site`, then `locality`** and attaches the entry to the highest level that exists, instead of dropping the place. **An administrative boundary found as the object** (*Mauguio*, *Quartier du Port*, seen with 4 decimals) is handled by the plugin: the service leaves the object out of the chain of its address, so the plugin takes its name as `settlement` when it is level 8 and as `site` when it is level 9 or below.
  - **Checked by the Media Helper session**: on Eric's library (285, then 49 places, France, Italy and Poland) **`settlement` is the commune everywhere**; **Poland, Hungary, Finland and Lithuania are not checked yet**: `wp media-helper geo places quality [--count=N]` prints the real distribution (levels, numbered levels, what was found, a random sample), and the `settlement` of those countries is to be looked at with `--count=30`. **The cache of the first period (`jsonv2`) is still read** (the same `levels`, derived from the old keys), **but Eric resets it** (`wp media-helper geo places reset --all`) **so that every cell is asked again in the new format**: only then do the numbered `admin` levels exist.
  - **Index does not depend on Media Helper** (a site without it simply has no photographs and no GPX places): it uses the filter, and **`null` means "no data"**.
- **NextGEN Gallery** (`wp-content/gallery`, outside the uploads) **can be a source** if the allowed base is moved to the content directory: `define( 'WP_MEDIA_HELPER_ALLOWED_BASE', __DIR__ . '/wp-content' )` in `wp-config.php` (**not `WP_CONTENT_DIR`**, which is not defined at that point: Eric got an HTTP 500), or the filter `wp_media_helper_allowed_base` in a must-use plugin. Their GPS is read like the others; they **cannot be registered as attachments**. **Eric chose to open it.**
- **For the existing files**, run `wp media-helper geo run` once (about one `exif_read_data` per photograph); the reading of the positions then starts after the pass over the sources and in the hourly maintenance.
- **Useful beyond the index** (a caption, a map, the media panel): the place is a **property of the file**, not of a post.
- **Still open**: how the photographs of a page are **matched** to rows of the index in practice (the filter takes the address as the page gives it: to check on the blog); the volume of the backfill with a service of one's own; HEIC photographs are not read.

## Identifiers: Wikidata first (Decided), places to discuss

**Proposed rule.** An entry holds **several identifiers** (`wikidata`, `osm`, `geonames`, and `wikipedia` as a *provenance*) and **one canonical**: the Wikidata item if there is one, else the OpenStreetMap object, else GeoNames, else none (the entry is then identified by its label and its aliases).

- **Why Wikidata**: stable, multilingual labels, a language-independent identifier, "instance of" to **infer the kind**, cross-references to the others (coordinates, GeoNames, OpenStreetMap, taxon names, eBird). It also is what WhoBIRD already uses for the birds.
- **Why not the title of a Wikipedia page**: it depends on the language and can be renamed; it is kept as the **provenance** of a candidate and gives the Wikidata item through the API (`pageprops`, `wikibase_item`; **from memory, not checked**), cached.
- **Places**: **Wikidata first, OpenStreetMap as a fallback**. Wikidata has an item for every commune and every well-known place, **less often for a hamlet, a pass, a hut, a campsite** that OpenStreetMap does have; an OpenStreetMap identifier (type and number) **is not as stable** as an item (objects are split, merged, re-created; **from memory, not checked**), and an OpenStreetMap object often carries a `wikidata` tag, which reconciles the two when it appears. GeoNames is a third possibility (stable numbers, an offline file). **To be confirmed by Eric.**
- **Redirects**: Wikidata merges items and leaves a redirect; the resolver must follow it (the old identifier stays an alias).
- **Network**: a call to Wikidata or Wikipedia needs **a polite client** (a User-Agent that names the site, a throttle, a cache with no expiry and a periodic refresh), as WhoBIRD does; **the module has its own client** and does not borrow that of WhoBIRD.

## The kind of an entry (Decided by Eric, 2026-11-21; the details are Proposed)

The **kind** of an entry says **what it is**: a place, a species, a route, a person, a work, a product, an event or incident, **other**. It serves **the typography of the printed index** (the first book told places, routes and incidents apart by typography), **several indexes** (places, birds), **leaving out** what must not be indexed (a tag with no kind), and **telling homonyms apart** (*Beaufort*, the village, and *Beaufort*, the cheese).

- **Where it comes from**: the geolocation gives **place**; WhoBIRD gives **species**; a **tag** gets its kind from Eric, in bulk; a **link to Wikipedia or Wikidata** does not say what it is.
- **Decided (Eric)**: for those, **everything is automatic, and corrected afterwards** (not "nothing is decided without a validation"): the module **deduces the kind from Wikidata** ("instance of" and its subclasses, translated to the short list of kinds), the default is **other** when nothing matches, and Eric **corrects in bulk** in the queue ("all these links are plants").
- **Proposed details**: the translation is a **small table that Eric can edit** (a class of Wikidata, a kind), looked up **along the chain of subclasses**; the class chains are **cached** (a few dozen classes serve thousands of entries) so that the service is asked little; **a kind set by hand is never overwritten** by the automatic one; the list of kinds is **short and editable**. **Not verified**: the quality of the deduction on the real links of the blog (three on the post of the col de Barrancq: a path, a plant, a genus).

## The resolver and the manual linking (Decided: it must exist; the form is Proposed)

A candidate carries **keys**: its identifiers, the key of its label (accents and case folded), and **keys local to its source** (`tag:123`, `wikipedia:fr:Orange_(Vaucluse)`). The resolver looks for an entry that has one of them **among its aliases**:

- one entry: the mention is made;
- none: the entry is **created**, approved at once or **a candidate to validate**, by the policy of the source;
- **several**, or a label whose entry has **another identifier**, or a **kind conflict**, or a **low confidence** (a geocoding that hesitates): **ambiguous**, put in a queue.

**The queue ("To resolve", a screen of the menu Otherguise).** For each case: its **evidence** (the post, the link or the word, the coordinates, the source) and **candidate matches**: the existing entries with a similar label and the results of a **search in Wikidata** (with their descriptions: "Orange, commune of Vaucluse"). The actions:

1. **link to an existing entry**;
2. **create a new entry**, from a Wikidata item (a search field, or a pasted Wikidata, Wikipedia or OpenStreetMap address) or with no identifier;
3. **set the kind**;
4. **ignore** (this key, for this source, from now on).

**Linking is adding a key to the aliases of the entry.** The decision is therefore **permanent** and the source does not ask again, whatever the post; no table of its own. The same mechanism serves the **merge of two entries** (their aliases and mentions are united, the old one becomes an alias) and the **correction** of a wrong link. A **per-post exception** ("this link in that post does not mean that entry") is a statement `index/excludes` on the post.

In the editor, a **panel "Index entries of this post"** lists the mentions with their source and their status, with the same actions for this post.

## Harvesters and enrichers: what each needs (Proposed)

| What is read | In | Candidates | Identifiers | Difficulty |
|---|---|---|---|---|
| `data-index` (WhoBIRD, marks, adapters) | the page | the entry named | in the attribute, or by the resolver | the convention to write in each plugin |
| Links to Wikipedia / Wikidata | the contents of the post | the title in the address | the Wikidata item (API, cached) | **the kind is unknown**: inferred from "instance of", or set in the queue |
| `.gpx` files named in the page (all of `<main>`) | the page | one place per end of the track, **by time** | the municipality of the address chain, or the names of the title; OSM or Wikidata | coordinates to a name; the file must be read (public address or disk) |
| Images of the post (`<img>` and links to image files, NextGEN included) | `<main>` | places, aggregated (a minimum of photos, or the first and the last) | idem | **new EXIF code** (the thumbnails keep the GPS), tens of photos over tens of kilometres: noise |
| Tags and categories (with a kind) | the database | one per term that has a kind (the others stay out); **not systematic on the blog** (Eric), so a secondary source of places | by the queue: linked once to the entry of the geolocation | none |
| Full-text search | the contents of the post | the entry and its variants | by the resolver | **precision**: homonyms, plurals; a screen to validate; it may **write the marks** |

The geocoding (coordinates to a place) is the one real decision of the list: an **online service** with a cache and the courtesy it asks for, or an **offline file** (GeoNames, communes); the names vary with the language and need aliases in both cases. **Open (Eric).**

## Consumers

- **The API of the module**: the entries of a scope (the whole site, a date range, **the units of a section of Books**), sorted according to the language (`Collator` if the extension `intl` is there, a fallback otherwise: **to check**), with their mentions (post, strength, anchor) and **no page number**.
- **Books** (**coupled to Index by filters, in both directions, with no class of one named in the other**: a declared dependency would have switched Index on wherever Books is on; slice 611): the locators become **pages** by the pagination map (slice 227: the page of a unit is the page of its first heading); the **ranges** (12-14), the main references in bold and the layout are Books' business (the role `index` of slice 228). **Where a passage is printed** and has a marker, the page is the one of the passage; **otherwise the page of the post**, and the report lists the mentions that fell back to the post.
- **The web**: a block of **alphabetical index** (letters, the kinds as filters), a **page per entry** that lists its posts (a public archive of the taxonomy, an option), a block **"Index entries of this post"**.

## Attribution (Decided by Eric, 2026-11-21)

**One notice for each index, not one per entry**, crediting **OpenStreetMap and the Wikimedia Foundation**. What the notice is for:

- **OpenStreetMap** (places read from coordinates by Media Helper, whose answers carry the attribution): the data are under the **ODbL**, which asks to credit "© OpenStreetMap contributors" with a link to `openstreetmap.org/copyright`. **A single visible line at the end of the index is a reasonable reading of "wherever the data are used"; it is Eric's decision, and the wording is the minimum, not a legal opinion.**
- **Wikidata and Wikipedia** (identifiers, labels, kinds: slice 604): the structured data of Wikidata are **CC0**, which does **not** require a credit, and Wikimedia **greatly appreciates** one; **crediting the Wikimedia Foundation is a choice, not an obligation**. No text of Wikipedia is kept.
- **Where**: the HTML output of the index (a book or the web) ends with **one line** such as *Places: © OpenStreetMap contributors. Identifiers and names: Wikidata, Wikimedia Foundation.*, **with links**, **only when the index shows something that comes from those services** (an index of categories and tags alone gets none); the text is **a setting with a default**, so that a book can word it in its own colophon; **the CSV and the JSON** carry it as a **field of the data**, not as a line. **Not done yet**: the renderer of slice 610 prints no notice; the block of a book (611) and the web block will.

## Vocabulary and the dimensions of an index (Decided by Eric, 2026-11-22)

**Words.** A **topic** is a concept that is indexed (what the code of slices 600 to 610 still calls an *entry*: the term of the taxonomy `index_entry`). An **entry** is a **line of a printed or displayed index**, the rendering of a topic with its locators. A **mention** is the statement that a post speaks of a topic. **The code was renamed on 2026-11-22, right after slice 611** (Eric): `Entries` → `Topics`, `TermEntries` → `TermTopics`, the taxonomy `index_entry` → `index_topic` (the terms are moved once, on `init`, by `TopicsMigration`; their meta and the statements of the mentions do not change), `Module::entries()` → `topics()`, `wp otherguise index entries` → `topics` (the old name stays as a deprecated alias), the mark signal `entry` → `topic` (the signals already stored are read as such), the counts of the kinds `entries` → `topics`. **What keeps the name *entry* is the printed line of an index** (`index-entry`, `books-index__entry`, the key `entries` of a group in the data). **The documents of slices 600 to 610 and the earlier sections of this note, written before, say *entry* where the code now says *topic*.**

**Three dimensions describe a topic or a mention**, and an index chooses what it lists on each of them:

| Dimension | Of what | Hierarchy | Where it comes from |
|---|---|---|---|
| **Kind** | the **topic** | **yes**, editable data (slice 603): a waterway is a place | the sources, the table of Wikidata classes, a person |
| **Source** | the **mention** | **yes**, a path, **fixed by the code** (slice 605): `post/category`, `post/tag`, `post/link`, `post/mark`, `image/address`, `image/title`, `image/category`, `image/tag`, `trace/start`, `trace/end`, `whobird`, `hand`… | each harvester declares its own |
| **Visibility** | the **mention** (the topic *in a post*) | the **modes** of the module Modes | **every mode by default** (Eric: simpler); narrowed by a mark in the text (`data-index-modes`) or by a rule of the source |

- **A source is hierarchical** because the same mechanism has several subjects: a **post**, an **image** (shown in a post) or a **trace** (a GPX file named in a post). **The subject of a mention stays the post** (an image or a file of Media Helper is not an entity of Triples, and the locator of a topic is the post), and a qualifier says **which image or which trace** produced it (`index/via`, slice 605). **`image` and `trace` are the sources of the photographs and tracks; the names `post/…` replace today's flat `categories`, `tags` and `page`.**
- **Visibility is of the mention, not of the topic**: a topic can be seen in one post and be only metadata in another. **By default a mention is visible in every mode**, a tag and a category included (their topics are indexed even if the theme does not print them, which is what the first book did). **It is narrowed only when something says so**: a mark `data-index-modes="web"` on the element, or a rule declared by a source. **Measuring it by rendering the page in each mode was proposed and not retained for now**: the print pass of slice 612 gives the most useful part of it for free (a mention has a marker in the PDF **if and only if its element is printed**).
- **An index chooses its topics with `include` and `exclude`**, lists of tokens `dimension:value` (`kind:place`, `source:image`, `visibility:print`); **a value stands for its descendants** (the sub-kinds, the sub-sources); **inside one dimension the tokens are alternatives, across dimensions they are all required, and `exclude` removes whatever matches**. **The filter applies to the mentions, not to the topics**: an index keeps the mentions that pass, and **a topic is listed only if one remains**. The default is **everything**.
- **Several indexes for one book** follow: **the topics visible in the book itself** (`include="visibility:print"`, locators are pages of what is printed); **a general index** (no filter, or `exclude="kind:other"`); **an index of the photographs** (`include="source:image"`, the page of each photograph).
- **Pages**: **the PDF markers are generalised from the headings to every mention that is visible in the text** (Eric: a link, a mark, a photograph), so the page of a topic is **the page of the element that shows it**, not the page of the post (slice 612). A mention with no printed element (a tag, a category, a mention of a mode other than print) **falls back to the page of the post**, **which carries the QR code of the article**: **no new QR code is made** (Eric): **the reader who wants more goes to the article on the web with the QR code that is already printed**. The fallback locators get their own CSS class so that a book can mark them (an italic page number, a note in the colophon).

## Topics, motifs and observations (the principle Decided by Eric, 2026-11-24; the details are Proposed)

> **2026-11-25: a *designation* is now called a *motif*** (the vocabulary of the module is in [`vocabulary.md`](vocabulary.md)); **the screens and the commands say *motif* since slice 634**; the text below keeps the old word, which means *motif*.

A **topic** is an **abstraction** (the river Allier, the département Allier: each its own topic with its own place in the tree). A **designation** is **the way a source designates something**, wherever it is found (the link to one Wikipedia page, the commune given by the photographs, a tag): it is **the class**, and it is **what is assigned to a topic**. An **observation** is **one occurrence of a designation in a post**: it is **the instance**. **There is no merge and no split, only assignments**, automatic or manual: moving what was seen from one topic to another is assigning the designation again, nothing is deleted, a wrong guess of the system is put right on **one screen that manages the topics and their designations** (never the observations, which would be an endless list). The enrichment happens **before** a topic is created, once per designation. The details, the storage (Triples for now), the migration and the slices that follow are in [slice 625](../slices/625-index-topics-and-observations.md); the code of slices 600 to 624 still has *mention*s attached to topics directly.

## Marks in the text (Proposed)

> **2026-11-25 (slice [636](../slices/636-index-marks-in-json.md)): a mark has two forms**, the text form below and **a JSON form** (`data-index` whose value starts with `{` or `[`: `{"topic":"wikidata:Q25422","kind":"species","label":"Pinson des arbres","source":"whobird"}`; the keys win over the companion attributes), and **a mark may say its source** (`data-index-source`, or `source`): the source is then `post/mark/<source>` when it is registered below the marks. The generic reader is `Harvest/PageReader`, the source `post/mark`.

`<span data-index="Santiago de Compostela">Compostelle</span>`: **the attribute of the convention above** written by hand; it names the **entry** (a label, or an identifier), the optional `data-index-sort` and `data-index-main`. A mark **also counts as a tag** (a mention of the post) even if its passage is not printed. In the editor: a **button** that opens a field with **auto-completion on the entries that exist**. **To check**: WordPress filters some attributes on saving for the roles that have no `unfiltered_html`. A search source may **write the marks** after the validation (an assistant), which changes the contents of the posts: a command with `--dry-run` and a report, tried on a copy first.

## What is proposed first (Eric's order, 2026-11-21)

**Start with what the blog already holds; the photographs, more complex, come last.**

1. **[Slice 600](../slices/600-index-core-and-categories.md)**: the **core** (entries, mentions, resolver and aliases) and the **categories**, then the **tags** that have a kind.
2. **[Slice 601](../slices/601-index-reading-a-page.md)**: **reading a page** (a pure function over a string of HTML: the `data-index` attributes, the **links to Wikipedia**, the GPX files, the images) and a command to try it on the real pages.
3. The **loopback job** that fetches the pages (602), and **WhoBIRD**: the convention in its renderer, so that **the names of the birds** come with their Wikidata identifiers.
4. The **Wikidata client** (the item of a Wikipedia link, the automatic **kind**) and the **queue screen**.
5. The **web blocks**, then the **integration into Books** (the role `index`, ranges).
6. The **marks** `data-index` and their button; the assistant of the search.
7. **The photographs and the GPX files**, with Media Helper, which reads the coordinates and geocodes as the files arrive (the brief was sent to its session).

## Open

- The **geocoding**: **decided and built by Media Helper** (a service of one's own for the backfill, the public Nominatim at 4 requests a minute otherwise); what is left is the **matching of the photographs of a page to its index**, to check on the blog.
- The **tags** (Eric: not systematic): the place entries come from the geolocation, and a tag is **linked once** to an entry in the queue; whether a tag **with a kind** also gives an entry of its own when no geolocation has made it: **Open**.
- The name `index` for the module and the dependency Books → Index (checked by `ArchitectureTest`); the numbering of the slices.
- Several **sources on one mention** in Triples: **works** (slice 600); the availability of `Collator`: not tested with a real locale.
- **What WhoBIRD prints**: a page with observations is needed. (The link to the GPX file, the wrapper of the contents of the post and the images were looked at: see above.)
- **Which level of the address** is the entry of a place: **answered** by the contract of Media Helper (`settlement` is the commune, `site` the entry below it; see "Who geolocates"); **to check on Poland, Hungary, Finland and Lithuania**.
- The **time** of rendering every post, the way to **detect a page that changed** without the post changing, and the behaviour of the loopback behind the firewall and the cache of the blog (the internal address of Books may be enough).
- **Never tried** on the real blog: the code of slices 600 to 610 ran under automated tests and on a scratch WordPress; the Wikidata enrichment **never ran against the real services**; the work of Media Helper was **read, not run**, here and by its own session.
