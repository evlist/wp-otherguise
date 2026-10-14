<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Rollout 003: the print format comes from the modes

Status: **proposed, not written** (the prerequisites below do not exist yet; no script). Tag: `rollout-N` (filled in by `rollout.sh create`). Depends on [002](002-child-theme.md) being applied: the child theme is active and the variants are declared for it.

## Goal and visible effect

`?mode=print` gives the same page as `?print`, and the plugin `wp-pdf-helper` is switched off. What changes for the administrator: the "Print" column of the media library and the flag `wpdfh.print` are gone as an interface. What a visitor sees: the print icon of the web templates now leads to `?mode=print` (the links are blocks `modes/link`), and the QR code of the print templates, a link itself, leads back to the web version. The pages `?print` stop working (no link leads to them any more; the PDF are made by opening the page in the print mode in Samsung Internet).

## Prerequisites: what must exist before the rollout

Decided by Eric (2026-10-13). Each line is to be implemented and tried on **testing** before staging. Nothing is ticked: none of it is known to be done.

| Done | Feature | Repository and commit | Tried on testing |
|---|---|---|---|
| [x] | The gallery block in Media Helper: **`wp-media-helper/gallery`**, wrapper class `wp-block-wp-media-helper-gallery`, the same output as `wp-printable-gallery` (`columns=2`, `size=gallery`, `link=file`), the order of today (`menu_order`, then ID), the images from the links of the active mode (method `otherguise`) or from `get_attached_media()` (`native`); with no image it prints nothing (the old print mode then showed every attached image). The order is an attribute `orderby` of the block (`title` by default, as the old block that was edited on production; `menu_order ID`, `date`), and the six templates ask for `title` explicitly; the order by date taken (`"orderby":"date"`) is a later, separate choice | `evlist/wp-media-helper`, `940977d` (after `b497b67`, `ae9ec4a`, `7d52b46`), `docs/slices/043-gallery-block.md` | yes, Eric, testing, 2026-10-14 (the order of the images is the one of production; the editor script and the method `otherguise` are not tried) |
| [x] | Media Helper lets the methods `native` and `otherguise` live together, so that the migration can run at the end of the rollout (and, with the setting on, returns the images attached the WordPress way when no context is asked, or when the filter `wp_media_helper_native_links_in_context` says so) | `evlist/wp-media-helper` (slice 042 step 7, tag `rollout-2` at `8ab96c5`, then `b497b67`) | the method `native` only (the block works before the migration); the migration and the method `otherguise`: not yet, they are tried during the rehearsal |
| [x] | Otherguise: the filter `modes_active_mode` (the slug of the active mode, which the gallery block of Media Helper uses as its context) and the answer to `wp_media_helper_native_links_in_context` (before the migration the native links count in the default mode, not in print) | `evlist/wp-otherguise`, slice 214 | `modes_active_mode` used by the block on testing, 2026-10-14; the native-links filter matters only with the method `otherguise`: not yet |
| [x] | Attached GPX: the attributes `mapHeight`, `graphHeight`, `showAttachments`, `showDownload` | `vdv/wp-attached-gpx`, commit `e6d0bbe` | yes, Eric, testing, 2026-10-14 (the map is 200px) |
| [x] | Theme: in the three web templates (`journee`, `publication-randonnee`, résumé mensuel) the JavaScript link of the hack is replaced by a block `modes/link` to the print mode (icon print, accessible label "Print"); in the three print templates the QR code is the shortcode `[kaya_qrcode_dynamic content_url="1" …][modes_url mode="web"][/kaya_qrcode_dynamic]` (Kaya QR Code Generator, active) in a block Shortcode: it encodes the address of the web version and is itself the link back, so `wp-printable-qrcode` is no longer used | `evlist/wp-twenty-twenty-five-printable` | yes, Eric, testing, 2026-10-14 |
| [x] | Otherguise: the shortcode `[modes_url mode="web"]` (slice 216) | `evlist/wp-otherguise` | yes, Eric, testing, 2026-10-14 (the QR code leads to the web version) |
| [x] | Theme: the GPX block of the print templates carries the sizes that `wp-pdf-helper` imposed: `publication-randonnee-print` `mapHeight` 200px, `graphHeight` 150px; résumé mensuel print 600px and 300px; both `showAttachments` and `showDownload` false | theme | yes, Eric, testing, 2026-10-14 |
| [x] | Theme: the six templates that use the gallery (`journee`, `publication-randonnee`, `wp-custom-template-r-sum-mensuel-randonn-e`, each with its `-print`) call `wp-media-helper/gallery` and the rule of `modes/print.css` follows its class (`wp-block-wp-media-helper-gallery`) | theme (`953d784`) | yes, Eric, testing, 2026-10-14 |
| [x] | Theme: the rules `.print-link` and the font Dashicons (nothing uses them once the links are blocks), and the class wrongly put on the paragraph "Écrit par", are removed | theme (`1c0ce35`) | yes, Eric, testing, 2026-10-14 |
| [x] | Otherguise: the stylesheet `modes/print.css` of the theme (slice 213), `wp otherguise media migrate` (slice 211), the block `modes/link` (slice 205) | `evlist/wp-otherguise` | `modes/print.css` loads in the print mode: yes, Eric, testing, 2026-10-14; `media migrate`: not yet, during the rehearsal |

**Plugins that are not git clones** (copied from Gitea into the containers, so not covered by the tag and put back to the state of production by every synchronisation): Attached GPX, the gallery plugin, and any other block plugin. They are updated by hand on staging, then on production, before the script. The script checks them by what they do, not by a version: the block `create-wp-attached-gpx/wp-attached-gpx` has the four attributes, and `WP_Block_Type_Registry::get_instance()->is_registered( 'wp-media-helper/gallery' )` is true. After a new synchronisation of staging they must be updated again.

### The image of the containers: a real cron (to copy to staging and production by hand)

The background tasks (the thumbnails of Media Helper, and later the long jobs of the plugins) need a WP-Cron that runs on its own: `DISABLE_WP_CRON` is on, so visits no longer trigger it. The two files below are **not in any git clone**: Eric copies them into the directory of the Docker stack of **staging and production** (they were made and tried on **testing** on 2026-10-14: `cron` runs, and `wp cron event run --due-now` executed 23 events, among them a test event), rebuilds the image and recreates the container (`up -d --build`, not a plain restart: the entrypoint writes its files at start).

| Done | What | Where | Tried |
|---|---|---|---|
| [x] | `Dockerfile` and `wp-cron-entrypoint.sh` below | testing | yes, 2026-10-14 |
| [ ] | the same two files | staging | no |
| [ ] | the same two files | production | no |
| [ ] | **WP-Cron triggered by the visits is disabled** (`DISABLE_WP_CRON` is true) on **production as in the other environments**, so that the real cron is the only trigger and the tasks do not run twice | production (staging and testing: set by `sync-site.sh`, not to be undone by `--keep-cron`) | no |

`Dockerfile`:

```dockerfile
FROM wordpress
COPY custom.ini $PHP_INI_DIR/conf.d/
COPY --from=wordpress:cli /usr/local/bin/wp /usr/local/bin/wp
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-client cron && rm -rf /var/lib/apt/lists/*
COPY wp-cron-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/wp-cron-entrypoint.sh
ENTRYPOINT ["wp-cron-entrypoint.sh"]
CMD ["apache2-foreground"]
```

`wp-cron-entrypoint.sh`:

```sh
#!/bin/sh
printenv | grep '^WORDPRESS_' | sed "s/'/'\\\\''/g; s/^\([^=]*\)=\(.*\)\$/export \1='\2'/" > /etc/wp-cron.env
printf 'PATH=/usr/local/bin:/usr/bin:/bin\n* * * * * www-data . /etc/wp-cron.env; cd /var/www/html && wp cron event run --due-now >/dev/null 2>&1\n' > /etc/cron.d/wp-cron
chmod 0644 /etc/cron.d/wp-cron
cron
exec docker-entrypoint.sh "$@"
```

What each line is for (each was a failure on testing before it was right):

- the values of `/etc/wp-cron.env` are **quoted**: cron does not pass the environment of the container, so the `WORDPRESS_*` variables that `wp-config.php` reads are written to a file; unquoted, a value with a parenthesis (`WORDPRESS_CONFIG_EXTRA`, a password) is a syntax error and the job dies silently;
- `PATH=` at the top of the cron file: the cron of Debian uses `/usr/bin:/bin`, where there is neither `wp` nor `php` (they are in `/usr/local/bin`): the job failed with `wp: not found`, its output sent to `/dev/null`;
- `wp cron event run --due-now` ignores `DISABLE_WP_CRON`, which stays true so that visits do not run the tasks a second time;
- the file `/etc/cron.d/wp-cron` has no dot in its name (cron ignores such names) and is `0644`.

**`DISABLE_WP_CRON` on production** (the copies get it from `sync-site.sh`): in the `docker-compose.yml` of the stack, `WORDPRESS_CONFIG_EXTRA: "define('DISABLE_WP_CRON', true);"` (then recreate the container), or once in the site: `docker exec -u www-data $PROD_CONTAINER wp config set DISABLE_WP_CRON true --raw`. To check it: `docker exec -u www-data $PROD_CONTAINER wp config get DISABLE_WP_CRON` prints `1`. Without it, a visit and the cron can run the same task together.

To check on each site after the rebuild (the variables are those of the environment, `$STAGING_CONTAINER`, `$PROD_CONTAINER`, `$TESTING_CONTAINER`):

```sh
docker exec -u www-data $TESTING_CONTAINER sh -c '. /etc/wp-cron.env && echo "env OK" && cd /var/www/html && wp db check'
docker exec -u www-data $TESTING_CONTAINER wp cron event schedule otherguise_cron_check now 2>/dev/null
sleep 70
docker exec -u www-data $TESTING_CONTAINER wp cron event list --fields=hook 2>/dev/null | grep otherguise_cron_check || echo "executed"
```

To debug, replace `>/dev/null 2>&1` by `>/tmp/wp-cron.log 2>&1` in the entrypoint for a while. Two improvements not made in these files: `/etc/wp-cron.env` holds the database password and is readable by everyone in the container (`chown www-data:www-data` and `chmod 600` would fix that); and nothing logs the failures of the job. On staging and testing the copies run the scheduled tasks of production plugins; the must-use plugin `staging-safety.php` (written by `sync-site.sh`) sends no mail, but a task that calls an external service is not stopped.

### Pages to try, one per template

The three custom templates each have a print variant. The same paths exist on testing, staging and production (testing is a copy of production):

| Template | Example (testing; the same path on `e.vli.st`) | What it has |
|---|---|---|
| `journee` | <https://testing.vli.st/2025/06/18/repos-a-santinalla-1-3/> | a gallery, no GPX track |
| `publication-randonnee` | <https://testing.vli.st/2025/10/04/madrid-montpellier-en-train/> | a GPX track and a gallery (and `https://testing.vli.st/2025/10/03/repos-a-madrid/`, with 83 images, to compare the order of the photos) |
| `wp-custom-template-r-sum-mensuel-randonn-e` (monthly summary) | <https://testing.vli.st/2025/10/04/sixieme-mois-sur-les-chemins-de-compostelle-et-dailleurs/> | several GPX tracks (8 maps), no gallery |

Each is opened as it is (web) and with `?mode=print`; on production the old print is `?print`. The template of each was checked on the served pages on 2026-10-14 (`post-template-journee`, `post-template-publication-randonnee`, `post-template-wp-custom-template-r-sum-mensuel-randonn-e`). The list of the pages that use a template: `wp post list --post_type=any --post_status=any --meta_key=_wp_page_template --meta_value=journee --fields=ID,post_title,post_status` (few pages use `journee`).

## The script (to write once the prerequisites are met)

Order proposed, to be confirmed when it is written. **On production nothing is asked** (the validations are listed at the end), so the checks are the safety net, and what must be true of the container and the plugins is checked **first**, before the backup is made:

1. the versions, and the state before (`wp plugin list` with versions, since the plugins copied from Gitea are not tagged);
2. a manual step: update the plugins copied from Gitea; then the functional checks above (the script stops if they fail);
3. deactivate `wp-printable-gallery` and `wp-printable-qrcode` (Eric: at the start of the rollout; they stay installed; the QR code now comes from Kaya and the shortcode of slice 216);
4. compare `?print` (production) with `?mode=print` (the site being rolled out), page by page, while `wp-pdf-helper` still works;
5. `wp otherguise media migrate --dry-run`, read the report, then run it; the migration comes last because Media Helper lets both methods coexist;
6. choose the method `otherguise` in Media Helper (to be done in the script once the name of the option is checked);
7. compare again, and the list of photos of a sample of posts before and after (the same list, in the same order);
8. deactivate `wp-pdf-helper` (last), and compare the pages in the print mode one more time.

## What to watch

- **Until the migration and the choice of the method, `?print` shows every attached image.** Media Helper does not read the print flag `wpdfh.print` of the native method (it was a hack), so with the method `native` the gallery block lists all the images attached to the post. The migration (step 5) and then the choice of the method `otherguise` (step 6) are what restrict print to the images that were flagged; only after them do the print galleries show the flagged images alone (and an image that is not linked to the mode print does not show). The web keeps showing every attached image the whole time (the filter of slice 214 for the default mode, once the method is `otherguise`). The migration command takes a criterion on any meta (`--meta-key`, `--meta-value`, `--restrict-mode`, `--mime`, slice 215); the defaults are the flag of wp-pdf-helper.

- **The same photos in print.** The old gallery showed, with `?print`, the images whose `wpdfh.print` was not empty; the migration gives them every mode and gives the others every mode except print. Compare the lists on a sample of posts, flagged or not.
- **The sizes of the maps in print** (200/150 or 600/300 by template), with no attachments and no download link.
- **The QR code** now comes from Kaya (`[kaya_qrcode_dynamic]`) around `[modes_url mode="web"]`: the address of the web version (the permalink), which must be the same as before; the code is made by JavaScript in the browser, as it was. Its link, on the screen, leads to the web version.
- **The icon** of the print link is now an SVG of the block `modes/link`; the theme no longer loads the font Dashicons (wp-pdf-helper did, for every visitor).
- **`?print` addresses** written by hand in posts or menus, if any, will no longer do anything.
- **Going back:** reactivate `wp-pdf-helper` and `wp-printable-gallery`; the migration did not change `post_parent` or the flag.

## Not in this rollout

The order of the photos by date taken (a visible change, made later in Media Helper); the classes `author-line` and `title-part` (Eric: not a concern); `noindex` and `canonical` of the print mode (later slices of the Modes module).

## Versions

(Pasted from `rollout.sh create --site=staging` when the rollout is created.)

## Log

(Date, site, who ran it, what differed from the text and the correction made to it.)
