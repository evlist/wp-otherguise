<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 231 — Books: the print page of a section with all its units

Status: **coded (2026-10-26)** from the test branch `merge-all-html`, **hardened for `main`** (see "What changed from the test"). Dependencies: slices 223 (sections), 220 and 226 (the PDF job), 225 (the address of the site for the server). Context: [`../design/books.md`](../design/books.md), "Query Loop experiment" and "Series of 2026-10-26" (option 4: merge the HTML of the units before the pagination; **a possible alternative provided that the weight of every page is optimised early**, Eric 2026-10-26).

## What it does

In a print mode (the first of `books_preview_frame_modes`, `print` by default), the page of a **section** shows its own content and then, **after the whole page and before the scripts of the footer**, the page of each of its units **as WordPress renders it in that mode**: the main query is made to ask for the unit, the template that WordPress finds for it (the variants of the Modes module are in the hierarchy) is rendered, the query is put back. Every unit is in a `div.books-merge-unit` (`data-post`, `data-depth`) that starts a new page (`break-before: page`); a unit that is a section is followed by its own units (six levels at most). Lazy loading of images is off for these pages. The current post is the unit while its template renders, so the shortcodes of the templates (`[modes_url]`, hence the QR codes) give **the address of the unit**.

Only **published units without a password** are merged; the others are logged as skipped. The page of the section ends with `<!-- books-merge {"section":…,"units":…,"seconds":…,"queries":…,"html_kb":…} -->`. A PDF of the section made through the panel (slice 220) is then the PDF of the whole section in **one** document: the pagination is Chromium's, the physical page of each unit is readable in it (slice 227).

## Who may ask for it

A merge costs the rendering of every unit, so **a visitor cannot ask for it** (`Merge\\MergeAccess`):
- a user who **can edit the section** (`edit_post`) gets it on the print page of the section;
- the **PDF job**, which has no user, adds `books_merge_token=<expiry>.<HMAC-SHA256>` to the address it asks the print server for: the token is signed with `wp_salt( 'auth' )` for **that section** and lasts **an hour**;
- **the preview of the editor** (`books_preview` in the address) **does not merge**: the HTML view of a section shows the section only (merging it took longer than the PDF); the PDF view has the units.
- anybody else gets the page of the section as it was.

## Limits

At most **1000 units** (`MAX_UNITS`) and, by default, **ten minutes of PHP** (the filter `books_merge_time_limit`, thirty seconds at least; the limits of the web server and of the print server remain: Gotenberg's `--api-timeout` and the proxy). The number of units can be lowered by the address (`books_merge_limit=N`, only for who may merge), by a **custom field `books_merge_limit` of the section** (the print server adds no argument, so this is the way for a PDF), or by the option of the same name (`wp option update books_merge_limit 10`); the argument wins over the field, the field over the option. The cache of the PDF does not know that number: use **Refresh** after changing it.

## The log

Off by default: `wp option update books_merge_log 1` turns it on (and a site in debug mode has it on). One JSON line per event in a file of the **protected directory of the PDF files**, under a name made with a key of the site (`merge-<16 hex digits>.log`: a server that ignores `.htaccess` gives a file that has a known name, this one has not), emptied past a megabyte, removed by the clean-up and by the uninstall. `wp otherguise books merge-log [--lines=<n>] [--show-path] [--clear]` prints it. Events: `merge_start` and `merge_done` (units, seconds, SQL queries, KB of HTML), `unit_start` and `unit` (milliseconds, queries, KB), `merge_aborted` (written at shutdown when PHP stopped in the middle: the units done, the unit in progress, the last PHP error, the connection), `pdf_convert` (seconds, whether it worked, the size of the PDF), `unit_skipped`. Every line has the PHP memory and, on Linux, the resident memory of the process (`/proc/self/status`). Nothing in it comes from the visitor.

## What changed from the test

- **No `shell_exec`** was ever in the code (it had been allowed for the test; only `/proc/self/status` is read).
- A visitor could ask for the merge of any section by its address, with `set_time_limit( 0 )`: now an editor or the job's **token**, ten minutes at most, 1000 units.
- `?books_merge=0` (switch off) is gone; the preview of the editor does not merge instead.
- The log was `uploads/books-merge/merge.log` (readable by address, unlimited): now off by default, protected, limited.
- The units were echoed in `wp_footer`: nothing is echoed any more, an output buffer puts them in front of the footer (`wp_kses_post` would have stripped the scripts and the SVG of the maps and the charts).
- The global query was replaced (`$GLOBALS['wp_query'] = …`): the main query is now asked again and **restored** (`WP_Query::query()` on the main object, then its properties put back and `wp_reset_postdata()`), so no global is assigned and `phpcs` has no exclusion for this code.
- Not kept: the experiment document; the measures stay in `docs/design/books.md`.

## Not done

- **Unpublished units** (a draft section previewed with its drafts): the units are asked as a visitor would; a later slice can use the private links of the Access module for them.
- A cache per unit, a failure of one unit that does not stop the whole (all or nothing, as measured), the pages that are not daily pages (maps, charts, templates never tried in the print mode).
- A **menu to ask for the merge in the panel**: any section is merged by the PDF view; the **HTML view never is**.

## Tests

`BooksMergeTest` (the token: valid, expired, another section, another key, malformed; who is allowed; the log off, on, past a megabyte, without a path; the command; the name of the file and the protection of the directory), `BooksMergedPrintDbTest` (with the real statements: the order, the depth, a draft and a password left out, no merge for a visitor, for a page that is not a print page, for the preview, for an empty section, the three ways to limit the number, a post in two sections, the units put in front once), `BooksPdfTest` (the token and the project in the address that the print server asks for; the conversion in the log). 

## Verified

Chromium and a scratch WordPress 7.1.3 (the child theme of the blog, 20 units of text): a visitor with no token, a bad token or the token of another section gets **no unit**; the right token gets them (4 units with a limit of 4, each with its own title); an editor logged in gets them, and **not** with `books_preview=1`; the units are in front of the footer scripts and the page ends once; the log is off, then on in a file with an unguessable name, read, emptied and refused by name when it is not of that form; a PDF of the page of the section in Chromium (A5 of the book project) has **22 pages for 20 units**. Before that, on the real pages of the blog on testing, with the test branch: 1 to 184 units (see `docs/design/books.md`).

**Never tried**: the token with a real Gotenberg (the address with its token goes through the internal address of slice 225), the hardened version on testing, a section of several hundred units after the change, an nginx that ignores `.htaccess`.
