<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 221 — Books: the sizes of the images for the print

Status: **coded (2026-10-21)**; see "Verified" for what ran. Dependencies: slices 219 (the page format), 220 (the PDF), the analysis in [`../design/books.md`](../design/books.md) ("Resolution and weight of the images in the PDF"), and Media Helper (`Thumbnails`, commits of 2026-10-20: scheduling by default, stale sizes, `wp media-helper thumbnails generate`). Module: `Books`.

## Goal

The printer wants 300 ppi at the final size. A browser picks the variant of an image by the width of its slot at a pixel ratio of 1, so the PDF of Gotenberg had 133 to 152 ppi (sizes `gallery` 300 px and `large` 1024 px). Decided with Eric (2026-10-21): the theme uses **names** of image sizes, **Books owns the dimensions** (so a change of the page format needs no change of the theme), the sizes are made for every photo (the disk is not a problem), and the generation is left to Media Helper.

## What it does

1. **The sizes are declared by the theme or the site** through the filter `books_print_sizes`, as **fractions of the usable width of the page** (the page minus its margins) and an optional ratio: no pixel in the theme, and Books ships **no size of its own** (a size depends on the layout, which the theme knows):

   ```php
   add_filter( 'books_print_sizes', function ( $sizes ) {
       $sizes['books-print-full']  = array( 'fraction' => 0.9 );                     // proportional, 90 % of the usable width
       $sizes['books-print-thumb'] = array( 'fraction' => 0.3, 'ratio' => '16:9' ); // cropped 16:9, 30 %
       return $sizes;
   } );
   ```

   A name must be `books-print-` followed by lower case letters, digits and hyphens; a fraction is a number from 0.05 to 1; a ratio is `W:H` or a number (cropped) or absent (proportional). Anything else is ignored with no error.
2. **Books computes and registers them** (`add_image_size`, on `init` at priority 20, **always**, so that Media Helper makes them for every photo): the width is `ceil( usable width in mm x fraction / 25.4 x ppi )` with a target of **300 ppi** (the filter `books_print_ppi` can change it); the height is `round( width / ratio )` with a crop, or open (proportional) without a ratio. An A5 page of 148 mm with margins of 10 mm (usable width 128 mm) gives, for the fractions above, `books-print-full` 1361 px and `books-print-thumb` 454 x 255 (the file of the printer had a main photo of 1024 px at 216 ppi and thumbnails of 477 px).
3. **In the print mode the `srcset` is removed** (`wp_calculate_image_srcset` answers `false`): WordPress puts in it every size with the same ratio and Chromium, at a pixel ratio of 1, would take the smallest candidate that covers the slot, defeating the size asked for. The print modes are the modes of the filter `books_preview_frame_modes` (default `print`, the same as the frame of the preview panel); the active mode comes from the Modes module (`modes_active_mode`). Outside the print mode nothing changes.
4. **A command that lists the sizes** to feed `wp media-helper thumbnails generate`: `wp otherguise books sizes [--format=<table|json|names>]` (`names` prints the names separated by commas, for `--size=`). Read-only.

## The use in the templates (the theme)

The theme uses the names in its print templates: `size` in the block `wp-media-helper/gallery`, `sizeSlug` in the image blocks (and in the featured image block). Asked of the session of the theme (see the message of 2026-10-21).

## What it relies on (Media Helper) and what to do on a site

- Media Helper schedules the generation after an import whatever `DISABLE_WP_CRON` says, makes the missing sizes on demand, detects a size whose definition changed (fingerprint of the registered sizes, catch-up in batches) and offers `wp media-helper thumbnails generate [--size=<names>] [--stale] [--post-ids=<ids>] …`.
- **The first time Media Helper sees the fingerprint it only records it**: when the sizes are first declared, run once for the existing photos `wp media-helper thumbnails generate --size="$(wp otherguise books sizes --format=names)"` (with `--dry-run` first, then perhaps `--post-ids=<the posts of the book>`).
- Changing the page format (**Settings → Otherguise books**) changes the dimensions: Media Helper sees it at the next request and catches up in the background; the PDF cache of slice 220 is keyed on the page format, so the PDF files are made again.

## Verified

- **PHPUnit** (474 tests in all, all pass): the declarations (valid and ignored), the pixels for the A5 and A4 formats and for another resolution, the registration (open height for a proportional size), the ratios, the resolution bounds, the removal of the `srcset` in a print mode and not elsewhere, the command (table, JSON, names, refusal of a format, no size), the hooks of the module.
- **Real WordPress 7.1.3, with the real Media Helper and WP-CLI (2026-10-21)**, with sizes declared by a file of `mu-plugins` (full 0.9, thumbnail 0.3 at 16:9) and the default A5 page with margins of 10 mm: `wp otherguise books sizes` prints `books-print-full 1361 x open` and `books-print-thumb 454 x 255 crop`; `wp_get_registered_image_subsizes()` lists them; the **WordPress core made them at the import of a photo of 4000 x 2250** (1361 x 766 and 454 x 255 in the metadata); in the web mode the `img` of `books-print-thumb` has a `srcset` with **all** the sizes of the same ratio (300, 454, 768, 1024, 1361, 1536, 2048), as expected, and **in the print mode the `srcset` is gone** and the `src` is the named size; `wp media-helper thumbnails generate --size=<the names> --post-ids=4 --dry-run` accepts the names (an unknown name is refused with the list, which includes ours).
- **Never tried**: with a theme that uses the names in a print template and a real PDF of Gotenberg (resolution of the images in the PDF, to check with `pdfimages -list`); the catch-up of Media Helper after a change of the page format; `wp media-helper thumbnails generate` on the photos registered by Media Helper (the scratch site has none).

## Not in this slice

The sizes for the web mode, the `sizes` attribute, the altitude profile (pixel ratio of the chart), RGB/CMYK, and any change of Media Helper.
