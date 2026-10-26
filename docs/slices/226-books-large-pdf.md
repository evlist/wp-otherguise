<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 226 — Books: large PDF files (written to disk, sent as a file)

Status: **coded (2026-10-26)**, see "Verified". Dependencies: slices 220 (the PDF job and its routes), 225 (the address of the site for the server, which made long pages possible).

## Goal

A PDF of a whole section is **hundreds of megabytes** (the first tests on testing: 35 MB for 20 units, 86 MB for 50, 167 MB for 100). Slice 220 held the PDF in memory several times (the answer of the server, the file, the page count that sent it back to the server) and gave it to the editor as **base64 in JSON**: 496 MB of PHP for a 167 MB PDF, a JSON of 222 MB to download and decode in the browser, and a panel that seemed to hang after "Generating the PDF… 25 s" while the file travelled.

## What it does

- **The server's answer goes straight to a file.** `Renderer::convert_url_to_file()` (Gotenberg: `wp_remote_request()` with `stream` and `filename`) writes the body as it comes, in `books-pdf/<key>.part`; `PdfStore::commit()` keeps it as `<key>.pdf` **only if it starts with `%PDF-`** (else it is deleted) and moves it with the WordPress file system API. An error status gives the first 300 characters of the answer as the message, and the file is deleted. A file that was never finished is cleaned up by the daily cleanup like the others.
- **The pages are counted in the file**, in pieces of 4 MB, by the page objects (`/Type /Page`, not `/Type /Pages`): `PdfPages::count()`. It is exact on the PDFs of Chromium (checked against `pdfinfo`: 798 pages, and the two PDFs that Gotenberg made for the blog). A file with no readable page object is sent back to the server to be counted when it is **under 20 MB**, as before; a larger one has no page count.
- **The PDF is sent as a file**, not as base64: `GET books/v1/pdf/<id>/file` now gives `{ filename, pages, preview, size, url }` and `GET books/v1/pdf/<id>/download` sends the bytes: `Content-Type: application/pdf`, `Accept-Ranges: bytes`, a single byte range (`206`, `416` when it cannot be satisfied), in pieces of 1 MB, no copy in memory. The address carries a REST nonce (`_wpnonce`), which is what an `iframe` or a link can carry; the capability is still `edit_post` on the post.
- **The panel** shows the PDF with that address (the viewer of the browser asks for the pieces it needs, a link downloads it) and no longer decodes anything. `PdfJobs::file()` and the base64 are gone.

## Not done

- The page counted by the server for a PDF over 20 MB with no readable page object.
- A real PDF viewer test in the panel (see "Verified").
- The cleanup by size of the cache (a book of 350 MB, kept 7 days).

## Tests

`BooksPdfTest` (the partial files and the commit, the pages in pieces and across the end of a piece, the byte ranges, a job that leaves no file when the server fails or does not send a PDF, the routes, the download), `BooksRenderTest` (the conversion to a file, its errors), `books-preview-pdf.test.js` (the panel uses the address; an answer without one is reported).

## Verified

On a scratch WordPress 7.1.3, with a stand-in for Gotenberg that sends a 212 MB file (a real Chromium PDF of 798 pages followed by padding): the job took **3.6 s and 65 MB of PHP** (the same file was 3 times its size in memory before), the pages were counted (798) and the file kept; through the real REST routes in a logged-in editor (Chromium): the answer of `file` with a nonce in the address; a range `bytes=0-99` gave `206` and `%PDF-`, the last 50 bytes `206` with the right `Content-Range`, a range past the end `416`, the whole file `200` with its `Content-Length` (212 MB in 4.7 s), and a wrong nonce `403`.

**Never tried**: a real Gotenberg (the streaming of a long answer from a real server), the PDF viewer of a browser in the panel (the iframe asks for the pieces), the blog's sites, a server that does not give `Content-Length`.
