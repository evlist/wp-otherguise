<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 222 — Books: the resolution of the altitude profile in the print mode

Status: **coded and tried on the testing site (2026-10-23)**; see "Verified". Dependencies: slices 220 (the PDF), 221 (how the print modes are recognised: `PrintSrcset`). Module: `Books`. Analysis and trial: [`../design/books.md`](../design/books.md) ("Resolution of the map and of the profile").

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
- **The QR code** (`img.wpkqcg_qrcode`, 70 x 70 px at 96 ppi, about 136 ppi at A5). **Asked of the theme (Eric, 2026-10-23), not done yet.** The plugin (Kaya QR Code Generator) cannot change the resolution but takes an image size, which its JavaScript reads from the hidden input `…_size` and `data-size` (100 today). Measured in Chromium on the testing page, `?mode=print`, by rewriting that value: size 100 gives an image of 70 x 70 px, 200 gives 175, 300 gives 245, 400 gives 350, 600 gives 525 (the code has 35 modules, so the size is a whole number of pixels per module: 2, 5, 7, 10, 15). The image is shown at its natural size, limited by the inline style `width: auto; height: auto; max-width: 100%` (no `!important`). So **two changes that must go together**, both in the theme (message sent to the session `wp-twenty-twenty-five-printable`): ask for size **300** in the block or shortcode of the print template (245 px: about 336 ppi at the A4 layout, over 300 ppi after the reduction to A5; 200 is the minimum), and show it at today's size by a rule of the print stylesheet, `.wpkqcg_qrcode { width: 70px !important; height: auto !important; max-width: none !important; }`, otherwise the page grows and can overflow (and if the template is shared with the web mode, the rule must apply there too). Eric's wait expression for Gotenberg is not affected (`naturalWidth > 0`, a non-empty `src`). **To check once done**: `pdfimages -list` of a regenerated PDF must show the QR code at 245 x 245 px instead of 70 x 70, and the page must still fit. Not tried in Gotenberg; how the printed code scans was not tried either.
- **The overlap of the labels of the horizontal axis** of the profile (for example `10km` and `12km`, or `50km` and `100km`, on top of each other at the right end): it comes from `wp-gpx-maps` (seen with and without this slice), not from the pixel ratio; to look into an option of Chart.js (maximum number of labels) or a rule of the print CSS.
