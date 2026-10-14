<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 216 — Modes: the shortcode `[modes_url]`

Status: **done** (Eric, 2026-10-14). Dependencies: slice 205 (the block `modes/link` and the address of a page in a mode).

## Why

The QR code of the print pages must lead to the web version. The plugin `wp-printable-qrcode` encoded `get_permalink()`, which is right only because the target mode is the default one; the address of a page in a mode belongs to Otherguise. A block cannot hand its output to another block, but a shortcode can be the content of the *dynamic* shortcode of Kaya QR Code Generator (`[kaya_qrcode_dynamic]…[/kaya_qrcode_dynamic]` runs `do_shortcode()` on its content before the code is made). Otherguise needs to know nothing of Kaya, nor Kaya of Otherguise.

## What it does

```
[modes_url mode="web"]            the address of the current post in the mode web
[modes_url mode="print" post="12"] the address of post 12 in the mode print
```

- Only the address, escaped with `esc_url_raw`. The default mode has no argument in its address; another mode gets `?mode=<slug>`; an alias of the address (`?print`) is replaced (`ModeUrl::build`).
- `post`: an id; by default the current post (`get_the_ID()`, so it works in a loop); without a post, the address of the request (as the block `modes/link` does).
- Empty when the modes are disabled, when `mode` is missing or does not exist.

In a template, with the block Shortcode: `[kaya_qrcode_dynamic content_url="1" size="100" …][modes_url mode="web"][/kaya_qrcode_dynamic]`: the QR code encodes the address and is itself the link to it (`content_url`), with no second link and no link inside a link.

## Verified

PHPUnit (413 tests): the address in the default mode and in another one, `post`, no post, the unknown or missing mode, odd attributes, disabled modes, the escaping; the hook `init` of the module. **Never tried on a real WordPress site**, nor inside the block Shortcode of a template, nor with the real Kaya plugin (the code of Kaya 1.7.0 was read: its dynamic shortcode runs `do_shortcode` on its content, and adds a link around the image only when `content_url` or `url` is given).

## Not in this slice

A block that would do the same (a block cannot feed another block); a context key for the blocks inside `modes/link` (a possible later slice, if a block must sit inside a link to a mode).
