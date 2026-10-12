#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Test of tools/staging/site-environment.php on a scratch WordPress with WP-CLI (not the CI). The plugin is loaded as a must-use plugin for
# the duration of the test and removed afterwards. Run: WPSH=/path/to/wp.sh tests/staging/site-environment-test.sh
# WPSH is a command that runs WP-CLI on the scratch site; the environment is forced with --exec, since wp-config.php may define it. Never use a site that matters.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
WPSH=${WPSH:?WPSH must run WP-CLI on a scratch site}
SRC="$HERE/../../tools/staging/site-environment.php"
PASS=0 FAIL=0
check() { if [ "$2" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAIL=$((FAIL + 1)); echo "FAIL  $1"; fi; }

MU=$($WPSH eval 'echo WPMU_PLUGIN_DIR;')
mkdir -p "$MU"
cp "$SRC" "$MU/site-environment.php"
trap 'rm -f "$MU/site-environment.php"; $WPSH option delete otherguise_site_synced_at >/dev/null 2>&1' EXIT

# Prints what a page prints in its footer: $1 environment ("" for none defined), $2 "front" or "admin", $3 action.
footer() {
  $WPSH --exec="define( 'WP_ENVIRONMENT_TYPE', '$1' );" eval "if ( '$2' === 'admin' ) { set_current_screen( 'dashboard' ); } ob_start(); do_action( '$3' ); echo ob_get_clean();" 2>/dev/null
}

$WPSH option delete otherguise_site_synced_at >/dev/null 2>&1

out=$(footer production front wp_footer); [ -z "$out" ]; check "production: nothing on the public pages" $?
out=$(footer production admin admin_footer); grep -q 'otherguise-site-environment' <<<"$out" && grep -q '#c62828' <<<"$out" && grep -q '>production<' <<<"$out"; check "production: red frame and label in the administration" $?
out=$(footer staging front wp_footer); grep -q '#e65100' <<<"$out" && grep -q '>staging<' <<<"$out"; check "staging: orange frame and label on the public pages" $?
out=$(footer staging admin admin_footer); grep -q '#e65100' <<<"$out"; check "staging: and in the administration" $?
out=$(footer staging front login_footer); grep -q '#e65100' <<<"$out"; check "staging: and on the login page" $?
out=$(footer development front wp_footer); grep -q '#2e7d32' <<<"$out" && grep -q '>testing<' <<<"$out"; check "development: green frame, labelled testing" $?
out=$(footer local front wp_footer); grep -q '>testing<' <<<"$out"; check "local: labelled testing" $?
out=$(footer staging front wp_footer); grep -q 'pointer-events:none' <<<"$out"; check "the frame lets the clicks through" $?
grep -q '@media print{#otherguise-site-environment{display:none}}' <<<"$out"; check "the frame is not printed" $?

$WPSH option update otherguise_site_synced_at 2026-10-12T10:03:00Z >/dev/null
out=$(footer staging front wp_footer); grep -q 'copy of 2026-10-12' <<<"$out"; check "staging: the label shows the date of the copy" $?
out=$(footer production admin admin_footer); ! grep -q 'copy of' <<<"$out"; check "production: no date of copy" $?

bar=$($WPSH --exec="define( 'WP_ENVIRONMENT_TYPE', 'production' );" eval 'require_once ABSPATH . WPINC . "/class-wp-admin-bar.php"; $b = new WP_Admin_Bar(); do_action_ref_array( "admin_bar_menu", array( &$b ) ); $n = $b->get_node( "otherguise-site-environment" ); echo $n ? $n->title : "none";' 2>/dev/null)
grep -q 'production' <<<"$bar" && grep -q '#c62828' <<<"$bar"; check "the admin bar names the environment, on production too" $?

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
