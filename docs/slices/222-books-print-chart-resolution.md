<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 222 — Books: the resolution of the altitude profile in the print mode

Status: **coded (2026-10-21), not yet tried on a real site**; see "Verified". Dependencies: slices 220 (the PDF), 221 (how the print modes are recognised: `PrintSrcset`). Module: `Books`. Analysis and trial: [`../design/books.md`](../design/books.md) ("Resolution of the map and of the profile").

## Goal

In the PDF the altitude profile of `wp-gpx-maps` is a bitmap: a Chart.js `<canvas class="plot">` of 194 x 150 px at a pixel ratio of 1, that is **96 ppi** at A4 and about 136 ppi once the page is reduced to A5, against 300 ppi for the photos. Chromium puts a canvas in the PDF as an image of its pixels, so the only way to improve it is to draw it with more pixels.

## What it does

In a print mode (the modes of the filter `books_preview_frame_modes`, `print` by default, the same rule as slice 221), Books puts in the `<head>`, **before the scripts of the maps**, a small inline script that makes `window.devicePixelRatio` answer a larger number (**3** by default):

```js
Object.defineProperty( window, 'devicePixelRatio', { configurable: true, get: () => 3 } );
```

Chart.js reads this value when it builds the chart and sizes the canvas: 194 x 150 CSS px become **582 x 450** pixels, about 290 ppi at A4 and 410 ppi at A5, and the size on the page does not change.

- The ratio is a number from 1 to 4 (the filter `books_print_pixel_ratio`; 1 turns the script off, which is also what happens outside a print mode and on a site where the filter answers anything else). Not a setting screen option: a filter is enough until a second value is needed.
- The active mode comes from the filter `modes_active_mode`, compared with the print modes as `PrintSrcset` does.
- The script goes through `wp_add_inline_script` or `wp_head` at priority 0 (before any script that reads the ratio); the choice is made when coding, and the **priority and the coexistence** are documented (rule of the repository for a shared hook).
- The text is a constant of the module, with the number inserted after validation (never raw input in the script).

## What it does not do

- **The tiles of the map.** `L.TileLayer` `detectRetina` was tried and **rejected** (books.md): with the standard tiles of OpenStreetMap the zoom+1 tiles show the texts at half size and add small details (peak names, road numbers, trees, contour lines), unreadable after the reduction. The tiles stay at 96 ppi; the track and the marker are vectors and stay sharp. True @2x tiles from another provider would be a setting of `wp-gpx-maps`, not of this slice.
- **The QR code** (`img.wpkqcg_qrcode`, filled by JavaScript): separate, if needed.
- The photos (slice 221).

## Side effects to check

- `window.devicePixelRatio` is global: every script that reads it in the print mode sees 3. Checked on 2026-10-21 on the public page (Chromium of the sandbox, not Gotenberg): the canvas became 582 x 450, the tiles stayed at zoom 12 (Leaflet's `L.Browser.retina` becomes true, but the layers do not use it without `detectRetina`), nothing else visible changed. To check again after each update of `wp-gpx-maps` or of another plugin that draws a canvas.
- The photos are not affected: the print mode has no `srcset` (slice 221), so the browser has no candidate to choose by the ratio.
- A canvas of 582 x 450 is a lossless PNG-like image in the PDF: a few tens of KB, to measure.

## Tests

- PHPUnit: the script is printed in a print mode with the default ratio, with the ratio of the filter, not printed outside a print mode, not printed for a ratio of 1 or an invalid value (text, 0, 5, negative), the number is the only variable part of the text; an entry for the hook (priority) in `BooksModuleTest`.
- No jsdom test for the script itself (a one-line property definition): the printed text was evaluated once in Node on a bare `window` and `devicePixelRatio` answered 3.

## Verified

- **Automated (PHPUnit, 480 tests)**: `Images\PrintPixelRatio` (`script()`, `print_script()`), the cases above, the hook `wp_head` priority 0 in `BooksModuleTest`. `phpcs` (WordPress): no error on the new class and on the module.
- **By hand**: the text of the script evaluated in Node on a bare `window` gives a ratio of 3; the same override added before the page scripts in the Chromium of the sandbox on the public page gave the canvas 582 x 450 (before this slice existed, 2026-10-21).
- **Never tried**: the plugin on a real WordPress site, a real Gotenberg. To do on testing after deployment: `pdfimages -list` of the PDF of `/2026/05/16/sant-llorenc-de-morunys/` must show the profile at about 290 ppi (A4) instead of 96, and the page must still fit on one page; and check that `wp_head` prints the script before the scripts of `wp-gpx-maps` (view source).
