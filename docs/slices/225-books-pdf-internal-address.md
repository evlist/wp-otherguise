<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 225 — Books: the address by which the PDF server reaches the site

Status: **coded (2026-10-26)**, see "Verified". Dependencies: slice 220 (the PDF job and the settings of the server).

## Goal

The PDF server asks for the page of a post by its **public address**, which goes through the proxy of the site (TLS, and its time limits: 60 seconds is common). A long page, such as the merged page of a section, is cut by the proxy (`504`, seen on testing on 2026-10-26 at 60 s) while PHP is still working. When the server and the site are on the same network (Docker), the server can ask the site directly. Asked by Eric: rewrite `https://testing.vli.st` into `http://wordpress-testing-vli-st`, the name of the service in `docker-compose`.

## What it does

- A setting **"Address of this site for the server"** (`books_renderer[site_address]`, Settings, Otherguise, Books, and `wp otherguise books renderer set --site-address=<address>`), **empty by default** (nothing changes). An http or https address of a **host only**: no path, user, password, query or fragment (`http://wordpress`, `http://wordpress:8080`).
- When it is set, the **beginning** of the address sent to the server (scheme, host and port of the home page of the site) is replaced by it: `https://testing.vli.st/livre/?mode=print` becomes `http://wordpress-testing-vli-st/livre/?mode=print`. An address of another site, or with another scheme or port, is left alone. Only the **page asked for** is concerned: the images, styles and scripts it holds keep the addresses that WordPress gives them (the public ones).
- `redirect_canonical` is cancelled (priority 5) for a request whose host is the one of this address, so that WordPress does not send the server back to the public address and the proxy. In the scratch test WordPress did not redirect a page asked by another host name anyway, so this is a **precaution** that was not shown to be needed; it can be removed if it never matters.
- The cache key of the PDF does not include this setting (the PDF is the same).

## Limits

- **JavaScript of the page that calls the site back** (the REST API, `admin-ajax.php`) calls it by its public address, from a page whose origin is the internal one: that is a cross-origin request. The REST API answers it (it sends the CORS headers), `admin-ajax.php` does not. Not tried on a real page of the blog.
- The page is served over **http**: a plugin that forces https (a redirect) would send the server back out. The header `X-Forwarded-Proto` is not sent.
- The server must be able to resolve the name (same Docker network).

## Tests

`BooksSiteAddressTest` (the address checks, the rewrite with its refusals, the host, the setting with its notices and its command, the canonical redirect) and `BooksPdfTest` (the renderer receives the rewritten address); the existing tests of the settings, the command and the hooks are updated for the new key.

## Verified

On a scratch WordPress 7.1.3: the setting saved by the command; the job gives the renderer `http://localhost:8090/?page_id=336&mode=print` for the public `http://127.0.0.1:8090/…`; the page asked by that other host name answers 200 with its content. **Not tried**: with a real Gotenberg and Docker network (the point of the slice), HTTPS to HTTP on the blog, the cross-origin calls.
