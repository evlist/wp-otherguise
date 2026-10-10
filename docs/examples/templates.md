<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# The single-post templates of the site: web and print

The two templates of a blog post as they are used today on Eric's site (Twenty Twenty-Five theme), copied unchanged:

- [`single-post-template.html`](single-post-template.html): the **web** version, the custom template **`publication-randonnee`** ("publication de randonnée");
- [`single-post-template-print.html`](single-post-template-print.html): the **print** version, the custom template **`publication-randonnee-print`**, selected today by the `?print` hack.

Both are templates of the database (created in the site editor, theme `twentytwentyfive`). Both started as a copy of the standard template `single` of the theme, which Eric left untouched for fear that a theme update would overwrite a modification (see [the question of updates](#modifying-single-or-using-custom-templates) below). The files keep the names `single-post-template*` for what they are: the template of a single post. The slugs of the site are the ones above.

This page describes what they contain and where they differ, because those differences are exactly what the plugin has to express: **the same post, presented by two templates**. How the plugin ties them together is at the end.

What is read from the files is stated as such; what is inferred (the purpose of a class, of a block) is marked *(inferred)*.

## The same post, two pages

| | Web (`publication-randonnee`) | Print (`publication-randonnee-print`) |
|---|---|---|
| Header | part `header-large-title` | part `header` (the theme's standard one) |
| Title | `post-title`, alone, above the image | `post-title` and the date on **one line**: *Title (12 mars 2026)*, in a small grey flex group (`title-date-line`) |
| Featured image | `post-featured-image` | same, forced to **16/9** |
| Author line | print icon, "Écrit par" + author (link) + "dans" + categories + "le" + date + "." | **absent** |
| Excerpt | `post-excerpt`, large, above the content | `post-excerpt`, medium, **inside the second column**, above the content |
| Videos | block `wp-attached-videos` | **absent** |
| GPX track | block `wp-attached-gpx`, in the grid | block `wp-attached-gpx`, in the **first column** |
| QR code to the web page | **absent** | block `wppqr/wp-printable-qrcode`, first column, under the GPX |
| Post content | `post-content`, full width | `post-content`, full width, second column |
| Bird identification | block `wpwbd/wp-whobird` | **absent** |
| Photo gallery | block `wpprg/wp-printable-gallery` | same |
| Tags | `post-terms` (post_tag) | **absent** |
| Previous / next post | navigation | **absent** |
| Comments | list, pagination, form | **absent** |
| More posts | "Plus de publications", a query loop of 4 posts | **absent** |
| Footer | part `footer` | part `footer` |

In short: the print version keeps **title, image, GPX, text and gallery**, drops everything that only makes sense on a screen (interaction, navigation, videos, comments, other posts, the audio-based bird block), and adds the one thing a reader of paper needs, **a way back to the web version** (the QR code).

## Structure of the web template

```
template-part  header-large-title
main
└─ group #post-content
   ├─ post-title
   ├─ post-featured-image
   ├─ group .author-line          (flex, wrap, small, accent colour)
   │  ├─ html: print icon (javascript: link that sets ?print)   ← to be replaced, slice 205
   │  ├─ "Écrit par"   .no-print .print-link
   │  ├─ post-author-name (link)   .no-print
   │  ├─ "dans"                    .no-print
   │  ├─ post-terms (category)     .no-print
   │  ├─ "le"                      .date-prefix
   │  ├─ post-date  "j F Y"
   │  └─ "."
   ├─ post-excerpt                (large)
   ├─ wp-attached-videos
   ├─ group #post-grid
   │  ├─ wp-attached-gpx
   │  └─ group #post-grid-column2
   │     ├─ post-content
   │     ├─ wp-whobird
   │     └─ wp-printable-gallery
   ├─ post-terms (post_tag)
   ├─ group: post-navigation-link (previous, next)
   ├─ comments (title, list, pagination, form)
   └─ group .other-posts: heading + query (4 posts: title, date)
template-part  footer
```

## Structure of the print template

```
template-part  header
main
└─ group #post-content
   ├─ group .title-date-line      (flex, wrap, small, accent colour)
   │  ├─ post-title (h1)  .title-part
   │  ├─ " (" .date-part
   │  ├─ post-date "j F Y"  .date-part
   │  └─ ")"  .date-part
   ├─ post-featured-image  16/9
   └─ group #post-grid
      ├─ group #post-grid-column-1   .post-grid-column
      │  ├─ wp-attached-gpx
      │  └─ wppqr/wp-printable-qrcode        ← link back to the web page
      └─ group #post-grid-column-2   .post-grid-column
         ├─ post-excerpt      (medium)
         ├─ post-content
         └─ wp-printable-gallery
template-part  footer
```

## What the comparison shows

1. **Two different trees, not one tree with parts hidden.** The layout changes (title and date on one line, two columns, excerpt moved), blocks are removed, one is added. A stylesheet alone cannot do that, which is why a second template exists.
2. **The web template already contains traces of the print mode**: the classes `no-print` and `print-link` on the author line, and the icon whose link sets `?print`. *(inferred)* They belong to the hack: the author line is hidden by CSS when printing the web page, and the same line does not exist at all in the `-print` template.
3. **Shared pieces are duplicated**: `post-featured-image`, `wp-attached-gpx`, `post-excerpt`, `post-content`, `wp-printable-gallery` and the footer are in both. A change to one of them (an extra attribute on the gallery, say) has to be made twice.
4. **Two template parts also differ**: `header-large-title` for the web, `header` for print. The plugin's variants cover template parts as well as templates (slice 202).
5. **The two links between the modes are different blocks today**: a `javascript:` link in `wp:html` (web to print) and a QR code (print to web). Slice 205 replaces the first with the block `modes/link`; the QR code keeps its own block for now.

## What the plugin does with it

The plugin makes explicit what the hack does by naming convention and a query-string test.

| Today (the hack) | With the plugin |
|---|---|
| `?print` in the query string selects the `-print` template by name. | `?mode=print` (and the alias `?print`, so existing links keep working) selects the **mode**; the **relation** "`publication-randonnee` has the variant `publication-randonnee-print` in the mode `print`" is stored as a statement (Triples), declared on the screen **Tools → Modes**. |
| The header of the print version is chosen by the template itself. | The same screen declares the **part variant**: `header-large-title` has the variant `header` in the mode `print`. |
| A link in `wp:html`, with JavaScript, to reach the print version. | The block `modes/link` (target mode as a parameter), rendered by the server, hidden when the target is the current mode. |
| One more page type (a page, an archive, a category) needs a new hack. | One more relation. Other modes (a book, a video) are other values of the same parameter. |
| Nothing says which template belongs to which. | The relations are queryable and shown (`modes/has-variant`, `modes/has-part-variant`), and a template deleted behind the screen's back is flagged as missing. |

## Modifying `single` or using custom templates

A template of a theme comes from a file (`templates/single.html`). When it is edited in the site editor, WordPress does not change the file: it saves the modification as a `wp_template` post in the database, tied to the theme, and uses it instead of the file. Two consequences, from how WordPress works (not tried on Eric's site):

- A **theme update does not overwrite** a modified `single`: it replaces the files, and the database copy keeps winning. "Reset" in the editor deletes the copy and goes back to the file. A custom template such as `publication-randonnee` is stored the same way, so it has no more protection than a modified `single`.
- The price of modifying `single` is the reverse: later improvements of the theme's `single.html` are no longer seen, as for any copy. Templates are tied to the theme that is active (its stylesheet): after a change of theme, or with a child theme, the copies have to be made again. Back up the database before big changes.

Neither choice matters to the plugin: a variant is any template of the active theme, so it can be `single`, a custom template or a template of a custom post type.

- With a **modified `single`**, every post uses it, no per-post choice is needed and there is only one relation to declare (`single` has the variant `single-print`), but all posts get the same layout.
- With **custom templates** chosen in the "Template" setting of a post (as today), only the posts that choose it get the hike layout, and the relation is declared on those two templates.

The case of the custom template chosen per post has been **tried on a real WordPress 7.1.3** with scratch templates: a post whose template is a custom one shows it, shows the variant under `?print` and `?mode=print`, and nothing changes without them.

## Open ideas, not decided

- **A default template for new posts.** Choosing `publication-randonnee` in the "Template" setting is a manual step on every post, with a risk of forgetting it. Making it the default for new posts would remove it. Whether WordPress can do this natively was not checked; if it cannot, the plugin could do it (listed in the [candidate slices](../slices/README.md)).

These come from reading the two files; nothing is planned.

- **The shared blocks are written twice.** Whether the plugin should help (a block that shows its content only in some modes, or a shared pattern) is an open question; the variant model of slice 202 deliberately keeps two full templates.
- **Blocks present in one mode only** (videos, bird identification, comments, more posts) could be declared "web only" instead of being absent from the other template. It would shorten the print template to the three or four blocks that differ, but it is a different mechanism from the variants and is not designed.
- **The print template has no link back as text**, only the QR code: a `modes/link` to `web` could sit next to it (slice 205, point 7).
- The same post in a **book** mode (the Books module) will be a third tree; this page is the first reference of what a mode changes.
