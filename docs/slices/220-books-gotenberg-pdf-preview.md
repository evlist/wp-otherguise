<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 220 — Books: a PDF preview through a Gotenberg server

Status: **planned in detail, no code yet** (Eric asked for it on 2026-10-20, after a first trial of Gotenberg that worked: see [`../design/books.md`](../design/books.md), "First trial"). Points marked **Decided** come from Eric; the rest is **Proposed** or **Open** until he confirms. Dependencies: slice 219 (the preview panel, its page format and its `books_preview` data). Module: `Books`.

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

## Order of work (test first)

1. `ServerAddress` and its tests; the option `books_renderer`, the settings fields (nonce and capability as in slice 219), `uninstall`.
2. The multipart body builder and `GotenbergClient`, tested against the filter `pre_http_request` (no network): the fields, the inches, the URL sent, the errors (HTTP status, timeout, empty body, a non-PDF body).
3. The job (cron event, state, cache key, storage, cleanup) with injected clock and file system.
4. The REST routes: capability, post status, no server, states, the PDF response.
5. The panel: the buttons, the polling, the blob address, the download and open buttons, the messages (jsdom, with a fake `wp.apiFetch` and a fake `URL.createObjectURL`).
6. A trial on the **real** Gotenberg, with Eric's container; then the notes of the results in this slice and in `books.md`.

## Entry points and checks (conventions of the project)

An admin screen section (capability, nonce, Settings API), three REST routes (capability, cookie nonce), a WP-Cron event, a daily cleanup; no WP-CLI command in this slice (a `wp books pdf <post>` would be natural for the later manifest: not planned here). Strings are translatable (text domain `otherguise`), SPDX headers, `uninstall` removes the options, the transients and the directory. No `shell_exec`. Honest verification: say what ran only under PHPUnit and jsdom and what was only tried on Eric's Gotenberg.

## Open questions (do not assume)

1. **Is the background job what Eric wants for one post?** The alternative, simpler, is a synchronous REST call that waits for the 21 s, with a spinner: less code, but it breaks the project rule for long work and risks the time limits of the proxy and PHP. Proposed: the job.
2. The **address of the server for staging and production**: a setting per site (as proposed), set by hand; nothing in the rollout scripts yet.
3. **The check of the server**: the route `/health` of Gotenberg is remembered, not verified; the check may use another route.
4. **The page count of the PDF**: how to get it. Counting the page objects of the file in PHP is fragile; a PDF-engine route of Gotenberg may give it (to examine with the "PDF engines" of `books.md`); or `pdf.js` reading it in the browser. Until known, the panel shows the count only when it is obtained.
5. **Print or screen media** for the conversion: `print` is Gotenberg's default, `screen` is what the panel measures. To decide by trial, comparing with the PDF of last year (the print CSS of the theme is not under `@media print`).
6. **Footers and page numbers** are not in this slice (see `books.md`: the numbers of a single conversion restart at 1 for each post).
7. **Lifetime of the files** and who may read them: proposed above (hash names, served through REST, removed after 7 days).
8. **Gotenberg's version** and its options (`waitDelay` versus a wait on an expression) are those of the trial; to re-read in its documentation before coding.

## Not in this slice

The merge of several posts, the numbering, the table of contents, the book manifest, another rendering engine, the preview of drafts, and any change to the rollout scripts.
