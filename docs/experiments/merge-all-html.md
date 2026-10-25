<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Test branch `merge-all-html`: the print page of a section is the concatenation of its units

**A throw-away test, not for `main`.** Option 4 of [`../design/books.md`](../design/books.md) ("merge all the HTML before the pagination"), to be tried on the real pages of the blog before deciding.

## What it does

On the print mode (`?mode=print`, or the first mode of `books_preview_frame_modes`), the page of a **section** shows its own content and then, **after the whole page of the section** (in `wp_footer`, outside its template, so that a template that puts the content in a column does not squeeze the units), in the order of the panel, the page of each **published** unit **as WordPress would render it in the print mode**: a query for that unit, the template that WordPress finds for it (the variants of the Modes module are in the hierarchy), the blocks of that template. Each unit is in a `div.books-merge-unit` that starts a new page (`break-before: page`); a unit that is itself a section is followed by its own units (6 levels at most, cycles are stopped). Lazy loading of the images is switched off for these pages (the print engine does not scroll).

- `?books_merge=0`: the page of the section without its units.
- `?books_merge_limit=N` **on the front page of the section** (`https://site/?page_id=<id>&mode=print&books_merge_limit=N`, or its permalink with `?mode=print&books_merge_limit=N`): only the first N units (to try 20, then 100, then all). **Not on the address of the editor**: the PDF is fetched by the print server, which does not see what is in the address bar of your browser. For the PDF, put the number in a **custom field `books_merge_limit` of the section** (the panel "Champs personnalisés"), or in the option of the same name (`wp option update books_merge_limit 10`). The argument wins over the field, the field over the option; 0 or nothing: no limit. The cache of the PDF does not know the limit: use **Refresh** after changing it.
- The PDF preview of the panel works on the section as on any post. **The cache key of the PDF follows the section only**: after a change in a unit, use **Refresh** in the panel.
- Only published units (the PDF job is anonymous). Nothing for drafts.

## The log

`wp-content/uploads/books-merge/merge.log`, one JSON line per event:

- `merge_start` / `merge_done`: the section, the number of units, the seconds, the number of SQL queries, the size of the HTML (KB);
- `unit`: for each unit, its position, the milliseconds, the queries, the size of its HTML;
- `pdf_convert`: the seconds the PDF server took, whether it worked, the error, the size of the PDF (KB);
- on every line: `php_mb` and `php_peak_mb` (PHP, allocated), `rss_mb` and `rss_peak_mb` (resident memory of the process, from `/proc/self/status`).

The same summary is in an HTML comment at the end of the section's content (`<!-- books-merge {...} -->`). The memory of **Gotenberg/Chromium** is not visible from WordPress: watch the container (`docker stats`) during the conversion.

## Seen on a scratch WordPress (Twenty Twenty-Five, a print variant of `single` and `page`)

20 units of text: 0.13 s; 200 units of one paragraph and five images: 1.4 s, about 5 SQL queries per unit, 10 MB of PHP, 430 KB of HTML. Chromium (Playwright `page.pdf`, A5) on the 200 units: 3.1 s, 480 MB, 798 pages. **Plain blocks only**: no shortcode, map, chart or QR code, so this says nothing yet about the pages of the blog.

## To try on testing

1. Build the plugin from this branch (the workflow `cs-grafting-plugin-zip` only runs for the branches of the GitHub variable `WORKFLOWS_ZIP_BRANCHES`: add `merge-all-html` to it, or zip `plugin/` by hand) and install it on testing by hand.
2. Make a section with the pages of last year's book (the panel "Book section" or `wp otherguise books section add`).
3. Open `<section>?mode=print&books_merge_limit=20`, then 100, then without a limit; read the log.
4. Ask for the PDF in the panel; read `pdf_convert` and the container.

**Known limits of the hack**: each unit is wrapped by the `div.wp-site-blocks` of its template (nested), the headers and footers of a template are repeated in each unit, duplicated element ids and scripts that assume one map per page are not handled, nothing is cached.
