<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Three PDF files of the same page: "Le cap de Peñas"

One page of Eric's travel blog (post of 3 July 2025), in three PDF files, kept to compare the geometry, the resolution and the weight of the images. The analysis is in [`../../books.md`](../../books.md) ("Resolution and weight of the images in the PDF"). The files are **samples**, not licensed for any other use (see `LICENSES/LicenseRef-All-Rights-Reserved.txt`).

| File | Made by | When | Page | Weight | Main photo | Gallery photos (8) | Altitude profile | Map tiles |
|---|---|---|---|---|---|---|---|---|
| `2025-07-03-phone-chromium-A4.pdf` | Chromium 130 (Skia), printed on Eric's phone | 2025-07-03 | A4, 595 x 841 pt | 3.88 MB | 1024 x 577 JPEG, 152 ppi | 1024 x 573 or 577, 455 ppi; two of them **lossless** (1.36 and 1.17 MB) | 679 x 525, 336 ppi | 256 x 256, 96 ppi |
| `2026-10-20-gotenberg-A4.pdf` | Gotenberg 8 (Chromium 155) | 2026-10-20 | A4, 596 x 842 pt | 0.79 MB | 1024 x 577 JPEG, 152 ppi | 300 x 169 JPEG, 133 ppi | 194 x 150, 96 ppi | 256 x 256, 96 ppi |
| `2025-11-03-printer-final-A5.pdf` | the page of the book sent to the printer (made with PDF Arranger and pdfcpu: the page scaled to A5, compressed, the page number added) | 2025-11-03 | **A5, 420 x 595 pt** | 0.57 MB | 1024 x 577 JPEG, **216 ppi** | 477 x 267 or 269 JPEG, **300 ppi** | 427 x 330, 299 ppi | 256 x 256 JPEG, 136 ppi |

The figures come from `pdfimages -list` (poppler); the ppi of an image is its pixels divided by the size at which the file places it, so it depends on the page of that file. The printer's file is the reference for the result that was judged very good.

What the third file shows (see `books.md`): the layout is the one of the A4 files **scaled by 0.707** (the margins and the proportions are the same), the images of the gallery were reduced to 300 ppi **at the final size** (477 px for about 40 mm), the main photo stays at its 1024 px (216 ppi at 120 mm) and was accepted, the map tiles are recompressed as JPEG at 136 ppi, the fonts are embedded except `Helvetica` (the page number, added afterwards) and the PDF version is 1.3.
