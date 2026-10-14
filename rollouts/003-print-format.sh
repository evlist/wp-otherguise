#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Rollout 003: the print format comes from the modes. ?mode=print gives the page that ?print gave: the galleries come from the block of Media
# Helper (and from the links that the migration makes), the QR code encodes the address of the web mode, the sizes of the maps are
# attributes of the GPX block; the plugins that are no longer needed are deactivated, wp-pdf-helper last. The story, the risks and what must
# exist before are in docs/rollouts/003-print-format.md.
#
# Usage: rollouts/003-print-format.sh --site=staging [--no-validate] [--dry-run]
#        rollouts/003-print-format.sh --site=prod --rollout=N [--no-validate] [--yes] [--force]
#   The script does not run git: put the clones of the site on the state to try first (a tag, a branch, `rollout.sh update`). The plugins
#   that are copies from Gitea (Attached GPX, the gallery plugins...) are not in the tag: they are updated by hand BEFORE the script, which
#   checks them by what they do. On production the validations are not asked, they are listed at the end.
#
# To go back: `wp plugin activate <plugin>` for each plugin deactivated, and `wp option update wp_media_helper_attachment_method native`;
# the migration changed neither post_parent nor the flag wpdfh.print.
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=../tools/staging/rollout-lib.sh
. "$HERE/../tools/staging/rollout-lib.sh"

THEME=wp-twenty-twenty-five-printable
METHOD_OPTION=wp_media_helper_attachment_method
# One page of each template, and the post of 83 images (the same paths exist on production, staging and testing).
SAMPLES="/2025/06/18/repos-a-santinalla-1-3/ /2025/10/03/repos-a-madrid/ /2025/10/04/madrid-montpellier-en-train/ /2025/10/04/sixieme-mois-sur-les-chemins-de-compostelle-et-dailleurs/"
MADRID=/2025/10/03/repos-a-madrid/
RETIRED_FIRST="wp-printable-gallery wp-printable-qrcode"
NOT_NEEDED="burst-statistics upgrade-for-unattach-re-attach-media-attachments upload-media-exif-date url-shortify wp-extra-file-types"
RETIRED_LAST="wp-pdf-helper"

rollout_init "003-print-format" "$@"
if [ "$SITE" = prod ]; then
  [[ "$ROLLOUT_NUMBER" =~ ^[0-9]+$ ]] || die "On production, say which rollout: --rollout=N (the tag made on staging)."
fi

# The public addresses are in the environment (PROD_URL, STAGING_URL, which select_target sets from TESTING_URL for the testing site).
if [ "$SITE" = prod ]; then SITE_URL=${PROD_URL:-https://e.vli.st}; OTHER_URL=${STAGING_URL}; OTHER=staging; else SITE_URL=${STAGING_URL}; OTHER_URL=${PROD_URL:-https://e.vli.st}; OTHER=production; fi

plugin_active() { wp plugin is-active "$1" >/dev/null 2>&1; }
plugin_state() { if plugin_active "$1"; then echo active; else echo inactive; fi; }
# Deactivates the plugins that are active; the others are left alone, so that the script can be run twice. Never deletes.
deactivate_if_active() {
  local p
  for p in "$@"; do
    if plugin_active "$p"; then step "Deactivate the plugin $p" wp plugin deactivate "$p"; else say "The plugin $p is not active (or not installed): left alone"; fi
  done
}
# page_has <path> <query> <text>: yes when the page, asked at the public address as a visitor would, contains the text.
page_has() {
  local body
  body=$(curl -fsSL -m 90 -A rollout-script "$SITE_URL$1$2") || { echo "(no answer from $SITE_URL$1$2)"; return 0; }
  case "$body" in *"$3"*) echo yes ;; *) echo no ;; esac
}

# For each example post: the web gallery lists every image attached to the post, and the print gallery those that the flag wpdfh.print marked.
EQUIVALENCE_PHP=$(cat <<'PHP'
$bad = array();
foreach ( explode( ' ', '__PATHS__' ) as $path ) {
	$id = url_to_postid( home_url( $path ) );
	if ( ! $id ) {
		$bad[] = $path . ' (no post)';
		continue;
	}
	$native  = array_keys( get_attached_media( 'image', $id ) );
	$flagged = array();
	foreach ( $native as $a ) {
		if ( '' !== (string) get_post_meta( $a, 'wpdfh.print', true ) ) {
			$flagged[] = $a;
		}
	}
	$web   = wp_media_helper_get_attached_media( $id, array( 'mime_type' => 'image', 'context' => 'web' ) );
	$print = wp_media_helper_get_attached_media( $id, array( 'mime_type' => 'image', 'context' => 'print' ) );
	sort( $native );
	sort( $flagged );
	sort( $web );
	sort( $print );
	if ( $native !== $web ) {
		$bad[] = $path . ' web ' . count( $web ) . '/' . count( $native );
	}
	if ( $flagged !== $print ) {
		$bad[] = $path . ' print ' . count( $print ) . '/' . count( $flagged );
	}
}
echo $bad ? implode( '; ', $bad ) : 'ok';
PHP
)

# What must be true of the container, the plugins and the theme, checked before anything is changed (the backup of production included):
# read only. A plugin copied from Gitea that is not up to date stops the script here, with what to update.
rollout_preflight() {
  check "WP-CLI runs in the container" wp cli version
  check "the container has a MySQL client (the backup of production needs it)" docker exec "$CONTAINER" sh -c 'command -v mysqldump || command -v mariadb-dump'
  check "cron runs in the container" docker exec "$CONTAINER" sh -c 'grep -l "^cron$" /proc/[0-9]*/comm'
  check "the file of cron exists (/etc/cron.d/wp-cron)" docker exec "$CONTAINER" test -f /etc/cron.d/wp-cron
  check_output "WP-Cron triggered by the visits is disabled (DISABLE_WP_CRON is true)" "1" wp config get DISABLE_WP_CRON
  check_output "the active theme is $THEME (rollout 002)" "$THEME" wp theme list --status=active --field=name
  local p
  for p in wp-otherguise wp-media-helper wp-attached-gpx kaya-qr-code-generator; do
    check "the plugin $p is active" wp plugin is-active "$p"
  done
  check "the command wp otherguise media migrate exists (Otherguise is up to date)" wp cli has-command "otherguise media migrate"
  check_output "the gallery block wp-media-helper/gallery is registered (update Media Helper)" "yes" wp eval 'echo WP_Block_Type_Registry::get_instance()->is_registered( "wp-media-helper/gallery" ) ? "yes" : "no";'
  check_output "the block of Attached GPX has its attributes mapHeight, graphHeight, showAttachments, showDownload (update the plugin from Gitea, commit e6d0bbe or later)" "mapHeight,graphHeight,showAttachments,showDownload" wp eval '$b = WP_Block_Type_Registry::get_instance()->get_registered( "create-wp-attached-gpx/wp-attached-gpx" ); echo $b ? implode( ",", array_intersect( array( "mapHeight", "graphHeight", "showAttachments", "showDownload" ), array_keys( (array) $b->attributes ) ) ) : "none";'
  check_output "the shortcodes modes_url (Otherguise) and kaya_qrcode_dynamic (Kaya) exist" "yes" wp eval 'echo shortcode_exists( "modes_url" ) && shortcode_exists( "kaya_qrcode_dynamic" ) ? "yes" : "no";'
  check_output "Media Helper declares the types gpx and vtt (wp-extra-file-types is no longer needed)" "yes" wp eval '$m = get_allowed_mime_types(); echo in_array( "application/gpx+xml", $m, true ) && in_array( "text/vtt", $m, true ) ? "yes" : "no";'
  check_output "the six templates of the theme use the block wp-media-helper/gallery and none of the old blocks" "yes" wp eval '$d = wp_get_theme( "wp-twenty-twenty-five-printable" )->get_stylesheet_directory() . "/templates/"; $ok = true; foreach ( array( "journee", "publication-randonnee", "wp-custom-template-r-sum-mensuel-randonn-e" ) as $t ) { foreach ( array( "", "-print" ) as $x ) { $c = (string) file_get_contents( $d . $t . $x . ".html" ); if ( false === strpos( $c, "wp-media-helper/gallery" ) || false !== strpos( $c, "wpprg/" ) || false !== strpos( $c, "wppqr/" ) ) { $ok = false; } } } echo $ok ? "yes" : "no";'
}
rollout_begin

# 1. The versions. Git is managed outside this script: put the clones on the state to rehearse (a tag, a branch) first.
rollout_versions

# 2. Where we start from. The plugins that are copies from Gitea are not in the tag: their versions are noted here.
note "The plugins, before (the ones copied from Gitea are not in the tag)" wp plugin list --fields=name,status,version
note "The method of attachment of Media Helper, before (nothing means the native one)" wp option get "$METHOD_OPTION"
COMPARE="Compare $SITE_URL with $OTHER_URL ($OTHER), page by page, on the four example pages ($SAMPLES): each as it is, with ?mode=print, and with ?print while wp-pdf-helper is active. The order of the photos, the number of images, the maps, the QR code, the link to the print mode. (tools/staging/compare-sites.sh --path=... [--print|--mode=print] lists what differs.)"
if [ "$SITE" != prod ]; then
  validate "BEFORE any change. $COMPARE Staging is a copy of production: note every difference that is already there, so as not to blame the next steps for it."
fi

# 3. The plugins replaced by the block of Media Helper and by Kaya with [modes_url]: deactivated first, they stay installed.
deactivate_if_active $RETIRED_FIRST
check_output "the old gallery block is gone from the print page of $MADRID" "no" page_has "$MADRID" "?mode=print" "wp-block-wpprg-wp-printable-gallery"
validate "AFTER wp-printable-gallery AND wp-printable-qrcode WERE DEACTIVATED. $COMPARE The galleries and the QR codes come from the new blocks now. Until the migration, ?mode=print lists every attached image (the method is still the native one)."

# 4. The migration of the attachments into links (the module media), then the method of Media Helper.
note "The migration, read first (dry run)" wp otherguise media migrate --dry-run
step "Migrate the attachments into links (wp otherguise media migrate)" wp otherguise media migrate
step "Choose the method otherguise in Media Helper" wp option update "$METHOD_OPTION" otherguise
check_output "the method of Media Helper is otherguise" "otherguise" wp option get "$METHOD_OPTION"
check_output "on the example pages the web gallery lists every attached image and the print gallery those that were flagged (wpdfh.print)" "ok" wp eval "${EQUIVALENCE_PHP/__PATHS__/$SAMPLES}"
validate "AFTER THE MIGRATION AND THE CHOICE OF THE METHOD. $COMPARE The photos of ?mode=print must be those of ?print on production, in the same order (the order is by title)."

# 5. What a visitor of Repos à Madrid must see, asked over HTTP as a visitor would.
check_output "$MADRID in print: the map is 200px high (attributes of the GPX block)" "yes" page_has "$MADRID" "?mode=print" "height:200px"
check_output "$MADRID in print: the gallery is the block of Media Helper" "yes" page_has "$MADRID" "?mode=print" "wp-block-wp-media-helper-gallery"
check_output "$MADRID in print: the QR code is a link to the web version" "yes" page_has "$MADRID" "?mode=print" "<a href=\"$SITE_URL$MADRID\" style="
check_output "$MADRID as it is: the link to the print mode is there" "yes" page_has "$MADRID" "" "aria-label=\"Print\""
check_output "$MADRID with ?print: the mode print and its stylesheet are there" "yes" page_has "$MADRID" "?print=print" "modes-theme-print-css"

# 6. The plugins that are no longer needed.
deactivate_if_active $NOT_NEEDED
check_output "Media Helper still declares the types gpx and vtt" "yes" wp eval '$m = get_allowed_mime_types(); echo in_array( "application/gpx+xml", $m, true ) && in_array( "text/vtt", $m, true ) ? "yes" : "no";'
validate "AFTER THE FIVE PLUGINS THAT WERE NO LONGER NEEDED WERE DEACTIVATED (burst-statistics, upgrade-for-unattach-re-attach-media-attachments, upload-media-exif-date, url-shortify, wp-extra-file-types). Look at one page of each template as it is, and in the print mode. Upload a .gpx file and a .vtt file in the media library once, to be sure they are accepted."

# 7. wp-pdf-helper last: ?print is served by the mode print from now on.
validate "BEFORE wp-pdf-helper IS DEACTIVATED (the last step). $COMPARE ?print still works through wp-pdf-helper: this is the last time to compare it with ?mode=print."
deactivate_if_active $RETIRED_LAST
check_output "wp-pdf-helper is not active" "inactive" plugin_state wp-pdf-helper
check_output "$MADRID with ?print: the mode print and its stylesheet are still there" "yes" page_has "$MADRID" "?print=print" "modes-theme-print-css"
check_output "$MADRID with ?print: the stylesheet of wp-pdf-helper is gone" "no" page_has "$MADRID" "?print=print" "wp-pdf-helper-print.css"

# 8. What must be true afterwards.
for p in $RETIRED_FIRST $NOT_NEEDED $RETIRED_LAST; do
  check_output "the plugin $p is not active" "inactive" plugin_state "$p"
done
check "no database copy hides a file of the theme" templates_sh check --site="$SITE"
note "The plugins, after" wp plugin list --fields=name,status,version

validate "AT THE END. $COMPARE Whatever you noted after a step above and that is still different is what the rollout has changed. The galleries of the monthly summaries are empty, as before (their photos belong to the daily posts)."
validate "The PHP error log has nothing new (wp-content/debug.log)."
rollout_end
