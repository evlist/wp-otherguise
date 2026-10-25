<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 220 — Books: a PDF preview through a Gotenberg server

Status: **coded (2026-10-20), tried against a stand-in of Gotenberg, never against the real server**; Eric's go for the background job was given on 2026-10-20 (asked on 2026-10-20, after a first trial of Gotenberg that worked: see [`../design/books.md`](../design/books.md), "First trial"). Points marked **Decided** come from Eric; the rest is **Proposed** or **Open** until he confirms. Dependencies: slice 219 (the preview panel, its page format and its `books_preview` data). Module: `Books`.

## Goal

Eric converts one post to PDF with a Gotenberg container (Chromium behind an HTTP API) and gets a correct file (962 KB, about 21 s, map and photos). Two things are wanted in the plugin:

1. **Decided.** In the settings screen **Settings → Otherguise books**, a section to enter **the address of the Gotenberg server**.
2. **Decided.** In the preview panel, a **second mode, available only when the server is configured**, that shows the **PDF page** instead of the HTML rendering. The switch is made with **"Web" / "PDF" buttons in the bar of the panel**, and the PDF has a **download** button and/or an **open in a new tab** button.

## Why the plugin has to be in the middle (Proposed)

The browser of the editor cannot call Gotenberg directly: in Eric's setup it sits on an internal Docker network (`global`) and has no public address, and a Gotenberg open to the Internet would let anyone convert any page. So **WordPress calls Gotenberg** (server to server) and hands the PDF to the panel, which asks for it with the credentials of the logged-in user. This adds a REST route (an entry point: it needs a capability check, a nonce and a test, as the project rules say).

## What was learnt from the trial that shapes the design

- **It takes about 21 seconds** for a post with a map and photos. That is too long for a request that the browser simply waits for (proxy and PHP time limits, nothing to show), so the conversion is a **background job** with a status that the panel polls, as the project rules ask for long work (WP-Cron with progress and a log). A cache makes the second view instant.
- **The PDF had 2 pages while the HTML panel said "fits".** Gotenberg prints with the *print* media by default and the panel uses the *screen* media; the pagination of Chromium is not a height. Showing the real PDF settles which one to believe, and the number of pages should be shown (open question 4). Which media the conversion emulates is decided by trial (open question 5).
- **Network**: the container must reach the site (on the same Docker host, through the network where the proxy is: see `books.md`); the address of the server in the settings is the one WordPress uses, for example `http://<container name or address>:3000` on the Docker network.

## Proposal

### 1. The setting

- Option `books_renderer` (an array) with `gotenberg_url`, empty by default: **no server, no PDF mode**, and nothing changes for the other users of the plugin (Eric: extra software must stay optional).
- Validation (`Render\ServerAddress`, pure PHP): an `http` or `https` URL with a host, no user or password, no query or fragment; a trailing slash is removed; anything else is refused with a message and the old value is kept. The address is chosen by an administrator (`manage_options`, the capability of the screen) and is **internal on purpose** (a private address such as `10.0.0.159`, or a Docker container name): so the client uses `wp_remote_request()`, **not** `wp_safe_remote_request()` (which refuses private addresses).
- On the screen, below the page format: the field, a line that says what it is, and, when an address is set, the result of a **check of the server** (`GET <address>/health`, the route that Gotenberg offers for that, **to verify**) shown as "reachable" or the error. The check never blocks saving.

### 2. The client (`Render\GotenbergClient`, behind an interface `Render\Renderer`)

- One method: convert a URL to PDF with the paper size and margins of the page format of slice 219 (millimetres to inches: width, height, four margins), `printBackground`, a wait, and the media (open question 5). A small `multipart/form-data` body built by hand (boundary, fields), sent with `wp_remote_request()`, as decided in `books.md` (no library, no dependency). Returns the bytes of the PDF or a `WP_Error` that carries the HTTP status and the first part of Gotenberg's message (the error body of Gotenberg is JSON or text and short: the 92 bytes of the failed trial).
- The URL sent is **only** the permalink of the post, given by WordPress (`get_permalink()` of a published post) with the print mode: **never an address typed by a user** (the plugin only sends addresses of its own site). The mode is the slug of the panel (`print` by default) and the page carries the `books_preview` argument so that the admin bar is hidden (slice 219).
- Timeouts: the HTTP timeout is longer than the conversion (the job runs in the cron, not in a page request), and a delay setting for the wait (`waitDelay`) is a field of the settings with a default of 5 seconds, because the maps need time.

### 3. The job and the storage

- Starting a conversion schedules a single WP-Cron event `books_render_pdf` for (post id, the key below). The state is kept in a transient: `queued`, `running`, `done` (with the page count when known), `failed` (with the message), and the start time.
- **Key of the cache** = hash of: the id of the post, its `post_modified_gmt`, the page format, the mode, the media setting and the plugin's version of the conversion options. A published post that has not changed, with the same format, is served from the cache; changing the format or editing the post makes a new conversion.
- The PDF is written in a directory under the uploads, `books-pdf/`, with a **name made from the hash** (not guessable) and a protective `index.php` and `.htaccess`; it is **served only through the REST route** (never by its address), so the capability is checked each time. Old files are removed after a while by a daily cleanup (the number of days is a setting, default 7), and by `uninstall`.
- One conversion at a time per post; a second request while one is `running` returns its state. A failed job is not retried by itself: the panel shows the message and the **Refresh** button starts it again.

### 4. The REST route (namespace `books/v1`)

| Route | Method | What | Capability |
|---|---|---|---|
| `/pdf/<post_id>` | `POST` | starts the conversion (or returns the cached `done` state); body: the mode | `edit_post` on that post |
| `/pdf/<post_id>` | `GET` | the state (`queued`, `running`, `done`, `failed`, message, pages, seconds elapsed) | `edit_post` |
| `/pdf/<post_id>/file` | `GET` | the PDF (`application/pdf`, `Content-Disposition: inline; filename="<slug>.pdf"`), or `404` | `edit_post` |

- Only **published** posts (decision 1 of slice 219); a draft answers `400` with a message. Nothing happens when no server is configured (`404`/`501`).
- The REST cookie authentication and its nonce (`X-WP-Nonce`, the one the editor already has through `wp.apiFetch`) protect the routes; the panel uses `wp.apiFetch` with `parse: false` for the file.

### 5. The panel (changes to slice 219)

- `window.booksPreview` gets `pdf: { enabled: true, restUrl, nonce... }` **only when the server is configured**; without it the panel is exactly the one of slice 219.
- **"Web | PDF" buttons** in the bar of the panel (the mode selector stays: the PDF is of the selected mode, `print` by default). **PDF** starts or reads the conversion and polls the state every 2 seconds with a message ("Generating the PDF… 12 s"); on `done` it fetches the file, makes a blob address (`URL.createObjectURL`) and shows it in the `iframe` (the PDF viewer of the browser, at the proportion of the page); on `failed` it shows the message. **Web** shows the HTML rendering of slice 219 again.
- **"Download"** (a link with the blob address and `download="<slug>.pdf"`) and **"Open in a new tab"** (the blob address in a new window) are shown when the PDF is ready. They matter on **phones**: the browsers of phones often do not display a PDF inside an iframe, and then the two buttons are the way to see it (Eric uses the editor on a phone).
- **Refresh** in PDF mode starts a new conversion (bypasses the cache); the "out of date" mark of slice 219 applies too (the PDF is that of the saved post, so a dirty post gets the mark).
- The verdict line of the web mode is replaced, in PDF mode, by "PDF: N pages" when the page count is known (open question 4), next to "HTML: fits / overflows by N mm", so that the two measures can be compared.
- The blob address is revoked when the panel is closed, on a new conversion and on a change of mode.

## When the PDF kept is out of date (Eric, 2026-10-20)

The key of the cache (post, its last change, mode, format, settings) **does not see everything that changes the page**: the photos attached or detached with Media Helper (they change the links, not the post), the featured image, a change of template or of the theme, a stylesheet, a plugin. A PDF kept for the same key is then shown although the page changed. Eric first took the PDF view for stale because he had gone back to the **Web** view, pressed **Refresh**, and found the PDF unchanged (the **Refresh** of the PDF view itself makes a new conversion).

- **Done:** a **Refresh of the Web view marks the PDF as out of date**: the next time the PDF view is opened it asks for a **new conversion** (forced), once; the following switches use the cache again. A refresh made by the program after a save is not forced (the key changes with the post) and does not do that. This is in the panel only (no server change); another browser keeps seeing the file until it is regenerated or removed after the days of the settings.
- **Known limit, noted (Eric, 2026-10-20), not fixed:** a case the Refresh of the Web view does not cover. (1) PDF view; (2) a change that the key of the cache does not see (a photo added with Media Helper); (3) back to the **Web** view, whose loading is in fact a refresh of the HTML but **not a forced one** (it also happens on each switch); (4) the PDF view shows the **old** PDF again from the cache. Deleting the PDF at each display of the Web view would fix it but is **radical**: each Web → PDF switch would cost a conversion of 20 seconds or more even when nothing changed, and the cache would serve almost nothing. Decision for now: **keep this behaviour and use the Refresh button of the PDF view** (or of the Web view, then the PDF view) when the page was changed. The ways to see more were considered and **not built**:
  - **A revision counter per post in the key of the cache**, raised by `wp_media_helper_attached` and `wp_media_helper_detached` (fired in PHP by Media Helper whatever the method, with the post id: verified in `EditorMediaController` of Media Helper), by the metas such as the featured image, and, for the modes and the rank of a link, by the Triples events; plus a counter of the whole site raised by a change of theme, template, plugin or setting. It fixes this case for every browser and keeps the cache useful; it sees only what is known. **Listening to Media Helper alone would not be robust** (Eric, 2026-10-20): depending on its configuration (the method `native`), a photo can be attached or detached by the **standard mechanisms of the WordPress media library** (the modal of the editor, "Attach" in the list of the library, `wp_update_post`, an import, WP-CLI), which fire none of its events. The counter would have to listen to **three families**: the actions of Media Helper; the actions of WordPress for an attachment (`add_attachment`, `attachment_updated` with a comparison of `post_parent` before and after, `delete_attachment`); and the Triples events for the links of Otherguise. Even then, a change that WordPress does not know about (a SQL update, a file replaced on the disk or a source such as Nextcloud that changes without WordPress being told) stays invisible: **no mechanism of observation is robust at 100 %, and the Refresh button remains the only guarantee.**
  - **The key made from the HTML as generated** (rather than from the post as stored): **not enough either** (Eric agrees): the HTML does not change when a file is replaced under the same name, the stylesheets and the images it refers to must be fingerprinted too, what the page loads with JavaScript (nonces, maps) differs from one loading to the next, and the plugin would have to fetch its own page (which may fail from a container).
  - **A screenshot** instead of the PDF as a quick test (Gotenberg can make one): probably **almost as long to make as a PDF**, and it would only work for a document of **one page**. Not retained.
  - **Watching the writes of the editor** (a `wp.apiFetch` middleware) to mark the PDF out of date: **Media Helper does not use `apiFetch`**, it posts with `window.fetch` to `admin-ajax`, so it would need a wrap of `fetch`, fragile.
- **Open, not done:** the PDF view shows no age. Showing "made N minutes ago" next to "PDF: N pages" would help to judge it.
- **Open, not done, a mechanism that sees more:** (a) a counter per post, raised when something that changes its page is written (the statements of Media Helper links, the metas such as the featured image, the attachments) and a counter for the whole site raised by a change of theme, template, plugin or setting, both part of the key; it catches what is known and nothing else; (b) a **fingerprint of the page**: hash of the HTML as rendered **and** of what it refers to (the stylesheets, the images, the GPX files by their dates or sizes), because the HTML alone does not change when a file is replaced under the same name; it needs the plugin to fetch its own page (which may fail from a container, see `books.md`) and is heavy. For now the button is the answer.

## Built, and what differs from the plan

What exists: `Render\ServerAddress`, `Render\Multipart`, `Render\Renderer` and `Render\GotenbergClient`; `Settings\RendererSettings` (option `books_renderer`); `Pdf\PdfStore`, `Pdf\JobState`, `Pdf\PdfJobs`, `Pdf\WordPressPosts`; `Rest\PdfRoutes` (`books/v1`); the section **PDF server** of **Settings → Otherguise books**; the "Web / PDF" views, download and open buttons in `panel.js`; the cron events `books_render_pdf` and the daily `books_pdf_cleanup`. Differences from the proposal above:

- **The file route answers JSON, not `application/pdf`**: `{ filename, base64, pages }`. The editor decodes it into a `Blob`. Simpler than streaming through the REST server, and fine for a PDF of a post (a few MB); to revisit for large books.
- **The waits are settings, with no wait by default.** Gotenberg's documentation (read on 2026-10-20) says it waits for nothing by default: `waitDelay` (a duration), `waitForExpression` (a JavaScript expression, awaited), `waitForSelector` (an element that appears), and `skipNetworkIdleEvent` which is **already `true` by default** (so the advice given in the chat to set it was a no-op). The screen has "Wait (seconds)" (default 0), "Wait until (JavaScript)" and "Wait for (CSS selector)", all optional; Eric thought the fixed delay might be superfluous in the 21 s of the trial.
- **`/health` is verified in the documentation**: `GET /health` answers 200 and `{"status":"up","details":{…}}`, or 503 when it is down. The screen shows "The server answers" or the error.
- **The page count** comes from `POST /forms/pdfengines/metadata/read` (a file `files`; the JSON is keyed by file name and each value has ExifTool tags, among them `PageCount`: documented). Counting page objects in PHP is not reliable: a PDF made by Firefox (cairo) has its objects in compressed streams. The count is shown when the server gives it.
- **Sizes in inches** (the documentation says units `in`, `pt` or `cm`; no unit means inches): the millimetres of the page format are converted with four decimals.
- **Gotenberg's error body is plain text** (400, 409, 503) and is shown, cut at 300 characters.
- **No per-request timeout exists in Gotenberg** (the documentation names none): the limit is the server's own `--api-timeout` (30 s by default; Eric's conversion took about 21 s). A longer conversion needs `--api-timeout` raised on the server.
- **Latency of the job**: the event is scheduled at once and `spawn_cron()` is called, but with `DISABLE_WP_CRON` set (as on Eric's sites) the event waits for the real cron: up to the interval of the system cron (a minute?) before the conversion starts. The panel shows the elapsed time and gives up after 10 minutes with "is WP-Cron running?".

## Order of work (test first)

1. `ServerAddress` and its tests; the option `books_renderer`, the settings fields (nonce and capability as in slice 219), `uninstall`.
2. The multipart body builder and `GotenbergClient`, tested against the filter `pre_http_request` (no network): the fields, the inches, the URL sent, the errors (HTTP status, timeout, empty body, a non-PDF body).
3. The job (cron event, state, cache key, storage, cleanup) with injected clock and file system.
4. The REST routes: capability, post status, no server, states, the PDF response.
5. The panel: the buttons, the polling, the blob address, the download and open buttons, the messages (jsdom, with a fake `wp.apiFetch` and a fake `URL.createObjectURL`).
6. A trial on the **real** Gotenberg, with Eric's container; then the notes of the results in this slice and in `books.md`.

## Verification

- **PHPUnit** (456 tests, all pass): the address (valid and refused), the settings (defaults, bounds, a refused address keeps the old one, an empty one turns the server off), the multipart body, the client (fields, inches, waits, media, errors, page count, health) against a double of the HTTP function, the store (keys, protection files, cleanup, removal), the state, the jobs (queue once, run, cache key, force, failures, lost jobs, files without state), the routes (registration, capability, answers), the screen, the editor data, the wiring of the module.
- **jsdom** (69 tests, all pass): the PDF view (buttons, polling, blob address, download, open, failures, give up, refresh forced or not, mode change, close, stale mark) and its connection to the editor with a fake `wp.apiFetch`.
- **Real WordPress 7.1.3 + real editor in Chromium (Playwright), with a stand-in of Gotenberg** (a small Node server that answers the three routes of its documentation and prints the page with a real Chromium): the screen shows "The server answers"; a bad address is refused with a notice and the old one is kept; an empty address turns the server off; the PDF view of a published post starts the conversion, the cron event (run by WP-CLI with `DISABLE_WP_CRON`) converts it, the panel shows "PDF: 3 pages." (a post of the default theme: the count is meaningless) and the PDF in the viewer of the browser; **Download** gives `hello-print.pdf` (149 KB); **Open in a new tab** opens the blob address; **Web** brings the HTML page back.
- **Tried by Eric on testing, with the real Gotenberg 8 (2026-10-20 and 21):** the PDF view of a real post of the blog with a wait of 0 s ("it works perfectly": the map, the photos, the QR code; "PDF: 1 page."), the address set on the screen, the **page count from the metadata route** (`PageCount`), including a post that overflows onto **2 pages** (the count shows 2), the **Refresh** of the PDF view that makes a new conversion, and the downloads and the new tab buttons were available. The first trial had 2 pages for a post that the HTML panel said fitted: with the print media (decision 5) the HTML view can differ from the PDF, see above.
- **Never tried**: the exact interval of the cron of Eric's sites (and so the wait before a conversion starts), a phone, the WP-CLI commands against the real Gotenberg, a long book, the cleanup after the days of the settings.

## Entry points and checks (conventions of the project)

An admin screen section (capability, nonce, Settings API), three REST routes (capability, cookie nonce), a WP-Cron event, a daily cleanup; no WP-CLI command in this slice (a `wp books pdf <post>` would be natural for the later manifest: not planned here). Strings are translatable (text domain `otherguise`), SPDX headers, `uninstall` removes the options, the transients and the directory. No `shell_exec`. Honest verification: say what ran only under PHPUnit and jsdom and what was only tried on Eric's Gotenberg.

## Open questions (do not assume)

1. **Is the background job what Eric wants for one post?** The alternative, simpler, is a synchronous REST call that waits for the 21 s, with a spinner: less code, but it breaks the project rule for long work and risks the time limits of the proxy and PHP. Proposed: the job.
2. ~~The address of the server for staging and production.~~ **Decided** (Eric, 2026-10-20): **one server per site**, and a **WP-CLI command** to set it, to make the synchronisation scripts easy (see "The WP-CLI command" below). Nothing in the scripts of `shared-room` yet.
3. **The check of the server**: the route `/health` of Gotenberg is remembered, not verified; the check may use another route.
4. **The page count of the PDF**: how to get it. Counting the page objects of the file in PHP is fragile; a PDF-engine route of Gotenberg may give it (to examine with the "PDF engines" of `books.md`); or `pdf.js` reading it in the browser. Until known, the panel shows the count only when it is obtained.
5. ~~Print or screen media.~~ **Decided** (Eric, 2026-10-20): the conversion uses the **`print` media** (the default of the setting and of Gotenberg), because `@media print` rules allow, when needed, to refine the printing by removing or changing elements that were left on purpose for the HTML preview. Consequence: **the HTML view of the panel (screen media) shows what `@media print` hides or changes in the PDF, so its "fits / overflows" can differ from the PDF**; the number of pages of the PDF view is the one to believe, and the HTML view is the quick approximation. The setting `screen` stays available for comparisons.
6. **Footers and page numbers** are not in this slice (see `books.md`: the numbers of a single conversion restart at 1 for each post).
7. **Lifetime of the files** and who may read them: proposed above (hash names, served through REST, removed after 7 days).
8. **Gotenberg's version** and its options (`waitDelay` versus a wait on an expression) are those of the trial; to re-read in its documentation before coding.

## The WP-CLI command (coded, 2026-10-20; documented in [`../wp-cli.md`](../wp-cli.md))

Eric's decision 2: **one server per site**, so a copy of production made for staging or testing (`sync-site.sh` of `shared-room`) carries the address of the **production** server in its database and must be pointed at its own. A command that can be run twice (the rule of the project for a step of a migration), with a report and a way to check:

```
wp otherguise books renderer get   [--format=<table|json>]
wp otherguise books renderer set   [--address=<address>] [--wait-delay=<seconds>] [--wait-expression=<js>]
                                   [--wait-selector=<css>] [--media=<print|screen>] [--keep-days=<n>] [--dry-run]
wp otherguise books renderer unset [--dry-run]
wp otherguise books renderer check
```

- **`get`** prints the settings (the same validated values as the screen).
- **`set`** changes **only the options given** (the option of the address is **`--address`**: WP-CLI keeps `--url` for the site and does not pass it to the command, found in the first real run), with the same validation as the screen (an invalid address, a delay out of bounds: an error message, exit status 1, nothing changed); it reports what changed ("address: A -> B" or "nothing to change"), so a second run changes nothing; `--dry-run` reports without writing.
- **`unset`** turns the PDF mode off (empty address), keeping the other settings.
- **`check`** asks the server (`GET /health`) and exits with 0 when it is up, 1 with the message otherwise: usable in a rollout script to verify the step.
- Registered on `cli_init` by the module `Books`, with a test per sub-command (as the project requires for every entry point). Tried with the real WP-CLI on the scratch site against the stand-in of Gotenberg; never against a real one. The address is taken from the command line: **no secret** is involved (an address on the Docker network).

**For the synchronisation scripts** (the repository `shared-room`, `tools/staging/`; the session that takes care of it decides): after the copy of the database, `wp otherguise books renderer set --address="$GOTENBERG_URL"` with a variable per site declared with the others by `set-environment.sh` (for example `STAGING_GOTENBERG_URL`, `TESTING_GOTENBERG_URL`), then `renderer check`. The files `uploads/books-pdf/` copied from production are harmless (names made of a hash, removed after 7 days) and could be excluded from the copy; the states in the transients of the copied database refer to keys that the new site will recompute.

## The wait for the QR code and the map tiles (Eric, production, 2026-10-21)

On production the PDF was made before the QR code and the tiles of the map had loaded when the images came too fast. Facts read in the HTML of a print page: the QR code of Kaya is `<img class="wpkqcg_qrcode" src="">` whose `src` is **empty** until its JavaScript fills it; the map of WP GPX Maps is a `div.wpgpxmaps` with a Leaflet map whose tiles are `img.leaflet-tile`, added after the map is created; the altitude profile is a `canvas.plot` (Chart.js animates it for about a second by default). **The condition that Eric uses, in the field "Wait until (JavaScript)" of the settings, and which works for him:**

```js
(() => { const m = [...document.querySelectorAll('.wpgpxmaps')], t = [...document.querySelectorAll('.leaflet-tile')], q = [...document.querySelectorAll('img.wpkqcg_qrcode')]; return m.every(x => x.querySelector('.leaflet-tile')) && t.every(x => x.complete) && q.every(x => x.getAttribute('src') && x.complete && x.naturalWidth > 0); })()
```

It is true at once on a page with no map or QR code, counts a tile in error as finished (`complete` is true for a broken image) and does not wait for tiles that a late zoom of Leaflet would add (a `waitDelay` of about a second covers that).

**Decided by Eric: nothing is coded for it for now.** It is tied to the plugins of this blog (Kaya, WP GPX Maps), so it stays a value typed in the setting, not a built-in option of Books. Considered and **not built**: a built-in option "wait for the QR code and the map tiles", and restricting the free JavaScript field to the users who have `unfiltered_html` (the code runs in the Chromium of Gotenberg, on the pages of the site, without cookies, but from the network position of Gotenberg, which on a multisite could be reached by a site administrator who has no `unfiltered_html`). The field is saved by users with `manage_options`; `waitForSelector` (a CSS selector, no code) stays free.

## Not in this slice

The merge of several posts, the numbering, the table of contents, the book manifest, another rendering engine, the preview of drafts, and any change to the rollout scripts.

**Changed by slice 403 (2026-10-25):** a post that is not published is no longer refused when it can have a private link (module Access): it is converted through the link, as a preview that says so.
