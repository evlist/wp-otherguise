<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 205 — Modes: the link to another mode

Status: **done**; the choices below are **to confirm**. Dependencies: slices 200 (modes), 203 (variants applied). Module: `Modes`.

## Goal

Let a template, a page or a post offer a link to the same content in another mode: the discreet printer icon next to "Écrit par…" on the web version, and (later) the link back to the web version on the printable one. Today this is a `wp:html` block holding a `javascript:` link that rewrites `window.location.search` (see [`docs/examples/single-post-template.html`](../examples/single-post-template.html), the single-post template of the site, copied unchanged; its print counterpart is next to it, and [`templates.md`](../examples/templates.md) compares the two).

## Decision (Eric)

**One block that takes the target mode as a parameter and makes a link around its content.** The same block serves the printer icon, a text link, and any other presentation. The QR code is a second step: the existing block `wppqr/wp-printable-qrcode` ([wp-printable-qrcode](https://gitea.dyomedea.com/vdv/wp-printable-qrcode), it prints `get_permalink()` through the Kaya QR Code Generator plugin) keeps working unchanged, so nothing is needed for it here.

## The block `modes/link`

A dynamic block with inner blocks (the content of the link is whatever the author puts inside: an icon, a paragraph, an image).

| Attribute | Meaning |
|---|---|
| `mode` | Slug of the target mode. |
| `label` | Accessible name of the link (`aria-label`, and `title`), for a link whose content is only an icon. |

Server-side rendering:

- **Address**: the permalink of the current post (block context `postId`, so it also works in a query loop), otherwise the address of the current request; any `mode` or alias (`?print`) is removed from it and `mode=slug` is added, unless the target is the default mode (then the address has no mode at all). Other query arguments and the fragment are kept. The pure function is `ModeUrl::build()`; `modes_url( $slug, $url = null )` is the public function for themes and plugins. The address uses `?mode=print`, not the alias `?print`, which stays only for links that already exist.
- **Nothing is rendered** when the modes are disabled, when the target mode does not exist (a mode declared by a plugin that was deactivated), or when the target **is the mode of the request**: a link to the page one is on is useless, and it lets one template carry the link in every mode.
- **`rel="nofollow"`**, so that crawlers do not follow every post to its print version.
- If the block has no content, the link shows the label of the target mode.
- A link inside the content would give nested anchors, which HTML forbids: the block does not check this; the editor says so in the description of the block.

In the editor: a plain-JavaScript script (no build step; the module has no JavaScript tooling), a select for the mode in the inspector, a text control for the label, inner blocks. The modes are handed to the script with an inline script. A **block variation** "Link to the print version" is a ready-made printer icon (inline SVG with `currentColor`, so that nothing depends on the Dashicons font, which visitors do not load).

## Not in this slice

QR code (later); propagating the mode in internal links and pagination (off by default, later); per-mode assets and options (206); side effects such as `noindex` and `canonical` on the print version (207).

## Tests

PHPUnit: `ModeUrl` (default mode, removal of `mode` and aliases, kept arguments and fragment, a URL with no query), the block (the rules above, escaping, context), registration. jsdom (`tests/js/`): the editor script registers the block and its edit function. On a real WordPress: front end of a post whose single template uses the block in place of the `javascript:` link, and insertion in the editor, in Chromium ([`tests/real-wordpress/link-block/`](../../tests/real-wordpress/link-block/)).

## Verified

- PHPUnit: `ModesLinkTest` (10 address cases, rendering, escaping, the refusals, the link back); the boot hooks. 354 tests / 1394 assertions with a database.
- jsdom (`cd tests/js && npm install && npm test`, 5 tests): registration, variation, select options, attributes, save.
- **Real WordPress 7.1.3** ([`tests/real-wordpress/link-block/`](../../tests/real-wordpress/link-block/flow.js), 21 checks): a `single` template of the database using the block in place of the `javascript:` link; in web mode one link to `?p=ID&mode=print` with `rel`, `aria-label`, icon inside; in print mode (`?print`, `?print=print`, `?mode=print`) the link to print is gone and the link to the web version has no mode; an unknown mode behaves as web; disabled modes render nothing; `modes_url()`; in the block editor (Chromium) the block and its variation, the list of modes, the inspector select, the serialized markup, no page error. [Screenshot](../screenshots/205-link-block-editor.png).

## Not verified

The real site's own templates (the block is tried in a scratch `single` template, not in Eric's template with the Kaya QR block); a post in a query loop (the `postId` context is used but was not tried); other browsers; translations; the modes' pages in another language plugin (the address comes from `get_permalink()`).

## To confirm

1. One container block with inner blocks, a `mode` and a `label`.
2. The link disappears when its target is the current mode.
3. `?mode=print` in generated links, not `?print`.
4. `rel="nofollow"`.
5. Plain JavaScript for the editor, no `@wordpress/scripts` build.
6. The variation's icon is an inline SVG printer.
7. The print template ([`single-post-template-print.html`](../examples/single-post-template-print.html), copied unchanged) has no link back to the web version: only the QR code block in the first column. A `modes/link` to `web` could go there too; where is for Eric to say.
