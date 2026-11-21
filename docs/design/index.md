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
- **The change in WhoBIRD** (Eric's plugin; **not made**: the repository was cloned read-only): in `BirdListItemRenderer::render()`, add to the `<li>` `data-index="wikidata:Q…"`, `data-index-kind="species"` and `data-index-label` (the common name from Wikidata, which is already in `$birdData`), the identifier coming from `getMappingByBirdnetId( $this->birdnetId )['wikidata_qid']`, when there is one. A few lines; the names of the birds then come out **with the case of Wikidata**, not of the app.
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
- **A named place to stay is better still**: the first point is **on a camp site that has a name** (*Camping Tunnel International*), which is exactly where one slept. **Proposed**: a named object of the kind *lodging* (camp site, caravan site, hotel, hostel, guest house, mountain hut, chalet) at the point or within a few tens of metres is a **candidate of its own, with the kind *lodging***, as a sub-entry of the place where it is.
- **The names of the levels cannot be trusted**: the key `municipality` is **not** the municipality in France (*Albertville* and *Bonneville* are arrondissements or groups of municipalities, and *Beaufort* is a `village`); *Étroubles* is both `village` and `city`; *Chamonix-Mont-Blanc* is a `town` and *Argentière* a `village` inside it. **The municipality must be recognised by its administrative level and by Wikidata ("instance of" municipality), not by the name of the key.** Only two countries were looked at.
- **A hamlet has no identifier in the answer**: none of the four objects carried a `wikidata` tag, and the chain gives **names without OpenStreetMap numbers**. The identifier of the municipality (and of the hamlet, if there is one) needs **another call** (a search by name and area, or a reverse at the right zoom); two zooms of the same point gave **different** nearest objects (*Combe* at zoom 14, *Le Créton* at zoom 18). Until there is an identifier, the entry is identified by **its label, its municipality and its country** (a key among its aliases: the manual linking of the section above covers the mistakes).

**Proposed model** (to confirm):

1. **A cache of the whole answer** (derived data, not in Triples, rebuildable): the chain with its names, the object found, the date, the service, keyed by the coordinates rounded to about 100 m, the level asked and the language. It lets the rule change **without calling the service again** (a rate limit), keeps the index **reproducible** when OpenStreetMap changes, and **explains** an entry.
2. **Entries in a hierarchy**: *municipality > hamlet (> lodging)*. **The mention is attached to the most specific entry**; **the municipality lists the mentions of its descendants** (computed when the index is read, not stored twice), so that **one finds the place under the hamlet and under the municipality**.
3. **The levels above the municipality** (county, state, country) are kept as **qualifiers** of the entry (to tell homonyms apart: *Beaufort (Savoie)*), **not as entries**: the tags *france*, *espagne*, *galice* already cover them, and the first printed book had a flat index.
4. **The mention keeps only its provenance**: the source, the coordinates rounded, the level, the key of the cache; and a **role** (`start`, `end`, `photo`): **the end of a day is where one slept**, so an index of "nights" is the entries with the role `end` (and the start of the next day).

**Open**: the list of the kinds of objects that count as *lodging*; the radius; the same rules for **the photographs** (many points, roads: probably the **place-like level only and a minimum number of photos**); the rules by country (France, Italy, Switzerland and Spain are the ones of the blog); whether the hamlet is **always** created or only when the point has no municipality-level name that is enough.

**What the geocoding gave** (two calls to Nominatim, zoom 14, on the first and the last point; one call to the search of Wikidata; the courtesy asked by the services was respected):

- the **nearest named object**, not the municipality: *Combe* (an isolated dwelling, in Cerisey, Étroubles) and *La Glirettaz* (a hamlet of Beaufort), with **the chain of the address** (hamlet, municipality, department, region, country) in the answer. The track starts and ends where a van is parked, so **a hamlet is what is found**; **the municipality is a level of the chain to be chosen by a rule**, the hamlet being a **sub-entry** if wanted (`Beaufort > La Glirettaz`).
- **neither object had a `wikidata` tag**: for the small places OpenStreetMap is indeed **the only identifier** there is, as the rule "Wikidata first, OpenStreetMap as a fallback" supposed.
- the search of Wikidata for "Étroubles" gives **Q35036, "Italian municipality in the Aosta Valley"**, with a description: **what the queue needs to show to choose**.

## Identifiers: Wikidata first (Decided), places to discuss

**Proposed rule.** An entry holds **several identifiers** (`wikidata`, `osm`, `geonames`, and `wikipedia` as a *provenance*) and **one canonical**: the Wikidata item if there is one, else the OpenStreetMap object, else GeoNames, else none (the entry is then identified by its label and its aliases).

- **Why Wikidata**: stable, multilingual labels, a language-independent identifier, "instance of" to **infer the kind**, cross-references to the others (coordinates, GeoNames, OpenStreetMap, taxon names, eBird). It also is what WhoBIRD already uses for the birds.
- **Why not the title of a Wikipedia page**: it depends on the language and can be renamed; it is kept as the **provenance** of a candidate and gives the Wikidata item through the API (`pageprops`, `wikibase_item`; **from memory, not checked**), cached.
- **Places**: **Wikidata first, OpenStreetMap as a fallback**. Wikidata has an item for every commune and every well-known place, **less often for a hamlet, a pass, a hut, a campsite** that OpenStreetMap does have; an OpenStreetMap identifier (type and number) **is not as stable** as an item (objects are split, merged, re-created; **from memory, not checked**), and an OpenStreetMap object often carries a `wikidata` tag, which reconciles the two when it appears. GeoNames is a third possibility (stable numbers, an offline file). **To be confirmed by Eric.**
- **Redirects**: Wikidata merges items and leaves a redirect; the resolver must follow it (the old identifier stays an alias).
- **Network**: a call to Wikidata or Wikipedia needs **a polite client** (a User-Agent that names the site, a throttle, a cache with no expiry and a periodic refresh), as WhoBIRD does; **the module has its own client** and does not borrow that of WhoBIRD.

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
| Tags and categories (with a kind) | the database | one per term that has a kind (the others stay out) | by the queue | none |
| Full-text search | the contents of the post | the entry and its variants | by the resolver | **precision**: homonyms, plurals; a screen to validate; it may **write the marks** |

The geocoding (coordinates to a place) is the one real decision of the list: an **online service** with a cache and the courtesy it asks for, or an **offline file** (GeoNames, communes); the names vary with the language and need aliases in both cases. **Open (Eric).**

## Consumers

- **The API of the module**: the entries of a scope (the whole site, a date range, **the units of a section of Books**), sorted according to the language (`Collator` if the extension `intl` is there, a fallback otherwise: **to check**), with their mentions (post, strength, anchor) and **no page number**.
- **Books** (the module depends on Index, not the reverse): the locators become **pages** by the pagination map (slice 227: the page of a unit is the page of its first heading); the **ranges** (12-14), the main references in bold and the layout are Books' business (the role `index` of slice 228). **Where a passage is printed** and has a marker, the page is the one of the passage; **otherwise the page of the post**, and the report lists the mentions that fell back to the post.
- **The web**: a block of **alphabetical index** (letters, the kinds as filters), a **page per entry** that lists its posts (a public archive of the taxonomy, an option), a block **"Index entries of this post"**.

## Marks in the text (Proposed)

`<span data-index="Santiago de Compostela">Compostelle</span>`: **the attribute of the convention above** written by hand; it names the **entry** (a label, or an identifier), the optional `data-index-sort` and `data-index-main`. A mark **also counts as a tag** (a mention of the post) even if its passage is not printed. In the editor: a **button** that opens a field with **auto-completion on the entries that exist**. **To check**: WordPress filters some attributes on saving for the roles that have no `unfiltered_html`. A search source may **write the marks** after the validation (an assistant), which changes the contents of the posts: a command with `--dry-run` and a report, tried on a copy first.

## What is proposed first

1. The **core**: the taxonomy, the statements, the resolver, `rebuild`, a minimal screen; the harvester of the **tags**.
2. **The reading of a page**: the pure function over a string of HTML (the `data-index` attributes, the links), **then** the loopback job. Tried first on **one real page of the blog**.
3. The **web blocks**.
4. The **integration into Books** (the role `index`, ranges).
5. The **Wikidata client** and the **queue** (the links to Wikipedia, the manual linking).
6. The convention **in WhoBIRD**; the **marks** and their button.
7. **GPX files** and **photos** (the geocoding).
8. The **assistant** of the search.

## Open

- The **geocoding**: online or offline; the language of the names.
- How the **kind** of an entry that comes from Wikipedia is set: inferred ("instance of" through Wikidata, network), set in the queue, or a default kind.
- Whether the entries **derive from the existing tags** (a tag with a kind gives a linked entry, no double entry) or the tags are **migrated** to the taxonomy.
- The name `index` for the module and the dependency Books → Index (checked by `ArchitectureTest`); the numbering of the slices.
- Several **sources on one mention** in Triples; the availability of `Collator`.
- **What WhoBIRD prints**: a page with observations is needed. (The link to the GPX file, the wrapper of the contents of the post and the images were looked at: see above.)
- **Which level of the address** is the entry of a place (the municipality?), and which are the sub-entries. **A saved copy of the HTML of one post is needed**; nothing of this was looked at.
- The **time** of rendering every post, the way to **detect a page that changed** without the post changing, and the behaviour of the loopback behind the firewall and the cache of the blog (the internal address of Books may be enough).
- **Never tried**: everything. No line of this module is written; the three plugins were read, not run.
