<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

Moved here from `evlist/shared-room` on 2026-10-09. Points are marked **Decided** (Eric accepted it), **Proposed** (suggested by Claude, not confirmed) or **Open**. The delivered state is in [`../IA.md`](../IA.md) and [`../slices/`](../slices/README.md); this document keeps the reasoning.

# 2. Multiple templates

**Decided**: replace the naming-convention hack with explicit relations between templates, and generalize "print" into a **mode**, with several modes definable.

- A **mode** is a declared entity: slug (`print`, `book`, `cover`...), label, query-string trigger. Delivered in slice 200 (proposed choices to confirm): declared in code, `web` (default) and `print` built in, alias `?print`.
- A **relation** *(source template, mode) → target template* (delivered in slice 202: proposed choices to confirm there) says "template `bar` is the `print` version of template `foo`". One target can serve several sources. A default target per mode is possible: *(\*, print) → print-default*.
- **Decided**: a mode is *not* a predicate. The mode of a relation is given by a further statement about the relation (see [triples.md](triples.md#reification-statements-about-statements)): "`bar` is the `print` version of `foo`" is `(template:foo, has-variant, template:bar)` plus `(that statement, mode, mode:print)`.
- **Resolution** (delivered in slice 203): WordPress picks the template as usual (full hierarchy); if a mode is active, the plugin looks for a relation for that template, then a default target for the mode, then applies the fallback.

Decided details:

- **Fallback** when no relation applies: the normal template. Mode inheritance (for example `book` inherits from `print`) is optional.
- Relations also apply to **template parts** (for example `header` and `header-print`), not only to whole templates.
- **Trigger**: `?mode=print`, with an optional `?print` alias so existing links keep working.
- **Storage and editing**: an admin screen, with JSON export and import so the relations can be versioned.

Proposed details:

- Optional side effects per mode: `noindex`, `rel=canonical` to the normal version, a CSS class on `<body>`, mode-specific styles and scripts.
- Internal links and pagination keep the active mode (not done yet, to be off by default). The link to another mode is the block `modes/link` (**decided**, slice 205): one container block with the target mode as a parameter; the QR code of the print version stays the existing `wppqr/wp-printable-qrcode` block for now.
- **Proposed**: a *default template per post type* for the default mode (for example posts: `publication-randonnee`), set in the modes screen. It is the template given to a new post of that type, and the mode of the other modes follows from the relations. Open: how to preselect the template (a native WordPress mechanism, if any, or a filter on the data of new posts, opt-in); what to do with a post whose template has no variant in the requested mode (today: the template is shown as is); whether the screen should warn when the default template has no variant in a mode.
- No chaining of relations by default; detect cycles when saving.
- Security: allow-list of modes, the query-string value never designates a file path, only administrators edit relations.
- Page caches must vary on the query string.
- Set the filter priority explicitly and document coexistence with themes and plugins hooking the same filter (see the Thumbnails Folder lesson in `CLAUDE.md`).

**Verified in slice 201** (WordPress source 6.6, 6.9 and 7.1, and a run on 7.1.3, see [`../slices/201-modes-template-hooks.md`](../slices/201-modes-template-hooks.md)): the point is the filter `{$type}_template_hierarchy` of `get_query_template()`, which serves block themes (database and files) and classic themes alike; template parts are handled apart, with `render_block_data` on the block `core/template-part`. The hook of the current hack, `get_block_templates`, is rejected. **Proposed**, to be confirmed with the slice.


# The current hack: `wp-pdf-helper`

Found on 2026-10-09: the hack is a small WordPress plugin, "WP PDF Helper", in the public repository <https://gitea.dyomedea.com/vdv/wp-pdf-helper> (one commit, May 2025, titled "Regression"; empty README; one PHP file of 124 lines and a few assets). The Gitea host was reachable from the session without any network change because the repository is public; private repositories there would still need a read-only token.

What it does:

- **Mode trigger.** At load time, `if ( ! array_key_exists( 'print', $_GET ) ) return;`. Everything below runs only when the query string has a `print` key (any value, even empty). The mode is decided once, at plugin load, and is not propagated to links.
- **Template swap, block themes only.** A `get_block_templates` filter takes the id of the first `wp_template` in the result (`theme//single`), appends `-print`, loads it with `get_block_template()` and, if it exists, replaces the first element. Classic PHP themes are not supported. No check that the result is non-empty, and the filter applies to every call of `get_block_templates`, not only the front-end template resolution.
- **Print stylesheet.** Enqueues `wp-pdf-helper-print.css`: hides header, navigation, footer, videos, comments, query loops and map controls with `display: none !important`, adjusts margins and font sizes, adds `page-break-*` rules. It depends on the theme's class names and on other plugins' blocks (`wp-block-wpprg-wp-printable-gallery`, Leaflet, WP GPX Maps). It has no `@page` rule, so page size and margins are not defined here.
- **A second query parameter.** `?gpxmap-size=small|large` sets the map and chart heights through the `wpagpx_shortcode_parameters` filter of WP GPX Maps, and turns off attachments and downloads in print mode.
- **A "Print" checkbox in the media library.** A column added to the media list saves the post meta `wpdfh.print = 'always'` through AJAX. Nothing in this plugin reads that meta; whatever uses it (a theme template or the printable gallery block) lives elsewhere. It is a per-attachment flag, global to all posts, which is the primitive version of a statement qualified by mode.
- **No PDF production.** Nothing here creates or assembles PDFs; the description says "helps to print". PDFs presumably come from the browser's print function or an external tool. The `.print-link` style exists but no code in the repository generates the link, so it is probably in the theme.

Problems visible in the code (useful as a checklist for the replacement):

- The AJAX handler `wpdfh_set_print_metadata` has **no nonce and no capability check** and does not validate `post_id`: any logged-in user, whatever the role, can set or delete the meta on any post.
- Debug `error_log` calls remain active, including a `print_r` of the template object on every filtered call.
- Global functions with generic names (`add_media_column`, `manage_custom_columns`, `ww_load_styles`): collision risk. Hard-coded English labels, no text domain, no `uninstall`, no version on enqueued assets, empty README, an editor configuration file committed.
- **Naming collisions in the template hierarchy.** Appending `-print` to a template slug produces names WordPress also uses itself: `page-print` is the template of a page whose slug is `print`, `category-print` and `tag-print` those of a term with that slug, `single-print` the template of a post type named `print`. Explicit relations between templates avoid this.

Implications for the design:

- Block themes (and block templates stored in the database or in theme files) must be supported; the hook of the hack (`get_block_templates`) is not the right one, see slice 201.
- A mode may need **parameters or options** (here the map size) and **hooks for third-party plugins** (WP GPX Maps) that adapt their output per mode. To design: a way for integrations to ask "which mode is active, with which options?".
- The per-attachment "Print" flag becomes a mode-qualified attachment per post (see the Media Helper section).
- Per-mode assets (stylesheets) are needed, as already listed; some of what the CSS hides could be removed from the print templates instead.
- The `-print` templates are `wp_template` posts created in the site editor (database), not theme files. Where the print links come from is still to find out.

Answers from Eric (2026-10-09):

- **The `-print` templates are created in the site editor**, so they are `wp_template` posts stored in the database, not theme files. Their ids still have the form `theme//slug`. They are tied to the active theme and are not versioned in git (export from the site editor is the only copy outside the database). The admin screen for relations should list templates from both sources (files and database).
- **The "Regression" commit** concerns the print CSS the plugin injects: a rule was removed. Which rule is not known, and the repository has a single commit, so there is no history to compare. It shows how fragile a global stylesheet full of `!important` overrides is. Eric recalled the rule as `.skip-link.screen-reader-text { display: none !important; }`; it is present in the committed file (last selector of the first rule), so the repository copy already has it and the deployed copy may differ. It is very specific to the current theme.
- **The print links** are included discreetly in Eric's view templates so that a visitor can reach the print version.
- **PDFs are produced and assembled by hand today:** each page is printed to PDF with the Samsung Internet browser on Android (the only browser Eric found that does not add a header and footer), then the PDFs are arranged and merged with PDF Arranger on Ubuntu. Automation would save a lot of time.
