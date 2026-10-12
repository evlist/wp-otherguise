#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Test of tools/staging/templates.sh and templates.php on a scratch WordPress with WP-CLI (not the CI): a stand-in for docker hands the
# `wp eval` of the script to the real WP-CLI of the scratch site. The active theme there must be a child theme with at least the file
# template `publication-randonnee` (evlist/wp-twenty-twenty-five-printable). It creates templates in the database and removes them.
# Run: WPSH=/path/to/wp.sh tests/staging/templates-test.sh. Never use a site that matters.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
WPSH=${WPSH:?WPSH must run WP-CLI on a scratch site}
SCRIPT="$HERE/../../tools/staging/templates.sh"
T=$(mktemp -d)
PASS=0 FAIL=0
check() { if [ "$2" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAIL=$((FAIL + 1)); echo "FAIL  $1"; fi; }

mkdir -p "$T/bin"
cat >"$T/bin/docker" <<STUB
#!/usr/bin/env bash
case "\$1" in
  inspect) echo true; exit 0 ;;
esac
shift
while [[ "\${1:-}" == -* ]]; do case "\$1" in -u | -e) shift 2 ;; *) shift ;; esac; done
shift   # the container
[ "\$1" = wp ] && shift
exec $WPSH "\$@"
STUB
chmod +x "$T/bin/docker"
export PATH="$T/bin:$PATH"
export PROD_CONTAINER=prod STAGING_CONTAINER=stg STAGING_URL=https://sb.test

fixtures() { # create or remove ($1 = create|remove)
  $WPSH eval '
    foreach ( get_posts( array( "post_type" => array( "wp_template", "wp_template_part" ), "post_status" => "any", "numberposts" => -1 ) ) as $p ) {
      if ( 0 === strpos( $p->post_title, "ogtpl" ) ) { wp_delete_post( $p->ID, true ); }
    }
    if ( "'"$1"'" === "remove" ) { return; }
    $active = get_stylesheet();
    $make = function ( $type, $slug, $theme, $content ) {
      $id = wp_insert_post( array( "post_type" => $type, "post_name" => $slug, "post_title" => "ogtpl " . $slug . " " . $theme, "post_content" => $content, "post_status" => "publish" ) );
      wp_set_object_terms( $id, $theme, "wp_theme" );
    };
    $part = "<!-- wp:template-part {\"slug\":\"header\",\"theme\":\"twentytwentyfive\"} /-->";
    $make( "wp_template", "publication-randonnee", $active, $part );
    $make( "wp_template", "og-db-only", $active, "<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->" );
    $make( "wp_template", "og-parent-only", "twentytwentyfive", "<!-- wp:paragraph --><p>parent</p><!-- /wp:paragraph -->" );
    $make( "wp_template_part", "footer", $active, "<!-- wp:paragraph --><p>f</p><!-- /wp:paragraph -->" );
  ' >/dev/null 2>&1
}
trap 'fixtures remove; rm -rf "$T"' EXIT
fixtures create

ro() { bash "$SCRIPT" "$@"; }

ro >/dev/null 2>&1; check "needs a command" $((! $?))
ro check >/dev/null 2>&1; check "needs a site" $((! $?))
ro export --site=prod >/dev/null 2>&1; check "export needs a directory" $((! $?))
ro delete --site=prod >/dev/null 2>&1; check "delete needs a backup directory" $((! $?))

out=$(ro check --site=staging 2>&1); rc=$?
[ "$rc" -eq 1 ]; check "check exits 1 when a copy shadows a file" $?
grep -qP "^SHADOWS\twp_template\tpublication-randonnee\t" <<<"$out"; check "a database copy of a template with a file SHADOWS" $?
grep -qP "^SHADOWS\twp_template_part\tfooter\t" <<<"$out"; check "so does a part that only the parent theme has as a file" $?
grep -qP "^DATABASE-ONLY\twp_template\tog-db-only\t" <<<"$out"; check "a template with no file is DATABASE-ONLY" $?
grep -qP "^UNUSED\twp_template\tog-parent-only\t.*twentytwentyfive\t" <<<"$out"; check "a copy attached to the parent theme is UNUSED by the child" $?
grep -q "^ACTIVE" <<<"$out"; check "check names the active theme" $?

ro export "$T/export" --site=staging >"$T/export.out" 2>&1; rc=$?; check "export succeeds" $rc
[ -f "$T/export/templates/publication-randonnee.html" ] && [ -f "$T/export/templates/og-db-only.html" ] && [ -f "$T/export/parts/footer.html" ]; check "export writes templates/ and parts/" $?
grep -q 'wp:template-part {"slug":"header"}' "$T/export/templates/publication-randonnee.html" && ! grep -q '"theme"' "$T/export/templates/publication-randonnee.html"; check "export removes the theme attribute of the template parts" $?
php -r 'exit( is_array( json_decode( file_get_contents( $argv[1] ), true ) ) ? 0 : 1 );' "$T/export/customTemplates.json"; check "customTemplates.json is valid JSON" $?
grep -q '"name": "og-db-only"' "$T/export/customTemplates.json" && ! grep -q '"name": "footer"' "$T/export/customTemplates.json"; check "customTemplates.json lists the custom templates, not the parts" $?

ro delete --site=staging --backup-dir "$T/backup" --dry-run >"$T/dry.out" 2>&1; rc=$?; check "delete --dry-run succeeds" $rc
ro check --site=staging >/dev/null 2>&1; [ "$?" -eq 1 ]; check "...and deletes nothing" $?
[ ! -d "$T/backup" ]; check "...and writes no backup" $?
echo wrong | ro delete --site=staging --backup-dir "$T/backup" >/dev/null 2>&1; check "delete asks for the name of the container" $((! $?))
ro check --site=staging >/dev/null 2>&1; [ "$?" -eq 1 ]; check "...and deletes nothing without it" $?
ro delete --site=staging --backup-dir "$T/backup" --yes >"$T/del.out" 2>&1; rc=$?; check "delete --yes succeeds" $rc
[ -f "$T/backup/templates/publication-randonnee.html" ] && [ -f "$T/backup/parts/footer.html" ] && [ ! -f "$T/backup/templates/og-db-only.html" ]; check "the backup holds the copies that were deleted, only those" $?
grep -q "^DELETED" "$T/del.out"; check "delete reports what it deleted" $?
out=$(ro check --site=staging 2>&1); rc=$?
[ "$rc" -eq 0 ]; check "afterwards check exits 0" $?
grep -q "og-db-only" <<<"$out" && grep -q "^UNUSED" <<<"$out"; check "the database-only and the unused templates are left alone" $?
ro delete --site=staging --backup-dir "$T/backup2" --yes 2>&1 | grep -q "nothing to delete"; check "a second delete has nothing to do" $?

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
