#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Tests of rollouts/003-print-format.sh with stand-ins for docker, curl, rollout.sh and templates.sh: the preflight (read only, before the
# backup), the order of the steps, the checks that stop the script, the plugins deactivated, the second run, production without questions.
# Nothing here touches a real container or site. Run: tests/staging/rollout-003-test.sh
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT="$HERE/../.."
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
PASS=0 FAIL=0
check() { if [ "$2" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAIL=$((FAIL + 1)); echo "FAIL  $1"; fi; }

mkdir -p "$T/repo/tools" "$T/bin" "$T/plugins"
cp -r "$ROOT/tools/staging" "$T/repo/tools/staging"
cp -r "$ROOT/rollouts" "$T/repo/rollouts"
printf '#!/usr/bin/env bash\necho "templates.sh $*" >>"$LOG"\nexit "${STUB_EXIT_TEMPLATES:-0}"\n' >"$T/repo/tools/staging/templates.sh"
cat >"$T/repo/tools/staging/rollout.sh" <<'S'
#!/usr/bin/env bash
echo "rollout.sh $*" >>"$LOG"
case "$1" in
  state) cat "$T/state_out" ;;
  verify) exit "${STUB_EXIT_VERIFY:-0}" ;;
  *) exit 0 ;;
esac
S
chmod +x "$T/repo/tools/staging/templates.sh" "$T/repo/tools/staging/rollout.sh"
printf 'wp-otherguise\taaa\nwp-theme\tbbb\n' >"$T/state_out"
SCRIPT="$T/repo/rollouts/003-print-format.sh"
export LOG="$T/calls.log" T
export PROD_CONTAINER=prod STAGING_CONTAINER=stg STAGING_URL=https://sb.test PROD_URL=https://prod.test
export ROLLOUT_LOG_DIR="$T/logs" ROLLOUT_BACKUP_DIR="$T/backups" ROLLOUT_STATE_DIR="$T/state"

cat >"$T/bin/docker" <<'STUB'
#!/usr/bin/env bash
case "$1" in
  inspect) echo true; exit 0 ;;
  cp) echo "docker $*" >>"$LOG"; : >"${3}"; exit 0 ;;
esac
shift
while [[ "${1:-}" == -* ]]; do case "$1" in -u | -e) shift 2 ;; *) shift ;; esac; done
container=$1; shift
[ "$1" = wp ] && shift
args="$*"
echo "[$container] $args" >>"$LOG"
case "$args" in
  "cli version"*) exit "${STUB_NO_WPCLI:-0}" ;;
  "cli has-command"*) exit "${STUB_NO_MIGRATE_CMD:-0}" ;;
  "config get DISABLE_WP_CRON") echo "${STUB_CRON_CONST-1}" ;;
  "theme list --status=active --field=name") echo "${STUB_THEME:-wp-twenty-twenty-five-printable}" ;;
  "plugin is-active "*) [ -e "$T/plugins/${args#plugin is-active }" ]; exit $? ;;
  "plugin deactivate "*) rm -f "$T/plugins/${args#plugin deactivate }"; echo "Success: deactivated" ;;
  "plugin list"*) ls "$T/plugins" ;;
  "option get wp_media_helper_attachment_method") [ -e "$T/method" ] && cat "$T/method" || exit 1 ;;
  "option update wp_media_helper_attachment_method "*) echo "${args##* }" >"$T/method"; echo Success ;;
  "otherguise media migrate"*) echo "Done: 3 link(s) created, 0 already linked, 0 skipped." ;;
  "eval "*"url_to_postid"*) echo "${STUB_EQUIV:-ok}" ;;
  "eval "*"is_registered"*) echo "${STUB_GALLERY_BLOCK:-yes}" ;;
  "eval "*"mapHeight"*) echo "${STUB_GPX_ATTRS:-mapHeight,graphHeight,showAttachments,showDownload}" ;;
  "eval "*"shortcode_exists"*) echo "${STUB_SHORTCODES:-yes}" ;;
  "eval "*"gpx+xml"*) echo "${STUB_MIME:-yes}" ;;
  "eval "*"get_stylesheet_directory"*) echo "${STUB_TEMPLATES:-yes}" ;;
  "sh -c command -v mysqldump"*) exit "${STUB_NO_MYSQL:-0}" ;;
  "sh -c grep -l"*) exit "${STUB_NO_CRON:-0}" ;;
  "test -f /etc/cron.d/wp-cron") exit "${STUB_NO_CRONFILE:-0}" ;;
  "db export"*) : ;;
esac
exit 0
STUB
cat >"$T/bin/curl" <<'STUB'
#!/usr/bin/env bash
for a in "$@"; do url=$a; done
echo "curl $url" >>"$LOG"
base=${url%%/20*}
[ "${STUB_NO_ANSWER:-0}" = 1 ] && exit 22
echo "<link id='modes-theme-print-css'> aria-label=\"Print\" wp-block-wp-media-helper-gallery <a href=\"$base/2025/10/03/repos-a-madrid/\" style=\"x\">"
[ "${STUB_NO_MAP200:-0}" = 1 ] || echo "height:200px;position:relative"
[ "${STUB_OLD_GALLERY:-no}" = yes ] && echo "wp-block-wpprg-wp-printable-gallery"
[ -e "$T/plugins/wp-pdf-helper" ] && echo "wp-pdf-helper-print.css"
exit 0
STUB
chmod +x "$T/bin/docker" "$T/bin/curl"
export PATH="$T/bin:$PATH"

ALL="wp-otherguise wp-media-helper wp-attached-gpx kaya-qr-code-generator wp-printable-gallery wp-printable-qrcode wp-pdf-helper burst-statistics upgrade-for-unattach-re-attach-media-attachments upload-media-exif-date url-shortify wp-extra-file-types"
reset() {
  : >"$LOG"; rm -rf "$T/plugins" "$T/method"; mkdir -p "$T/plugins"
  for p in $ALL; do : >"$T/plugins/$p"; done
  unset STUB_NO_WPCLI STUB_NO_MIGRATE_CMD STUB_CRON_CONST STUB_THEME STUB_EQUIV STUB_GALLERY_BLOCK STUB_GPX_ATTRS STUB_SHORTCODES STUB_MIME STUB_TEMPLATES STUB_NO_MYSQL STUB_NO_CRON STUB_NO_CRONFILE STUB_NO_ANSWER STUB_NO_MAP200 STUB_OLD_GALLERY STUB_EXIT_VERIFY
  printf 'wp-otherguise\taaa\nwp-theme\tbbb\n' >"$T/state_out"
}
answers() { yes "" | head -20 >"$T/answers"; export ROLLOUT_TTY="$T/answers"; }
run() { bash "$SCRIPT" "$@"; }
line() { grep -n "$1" "$LOG" | head -1 | cut -d: -f1; }
changed() { grep -q "plugin deactivate\|option update\|^\[[a-z]*\] otherguise media migrate$\|db export" "$LOG"; }

# Arguments and dry run.
reset; run >/dev/null 2>&1; check "needs a site" $((! $?))
reset; answers; run --site=prod --yes >/dev/null 2>&1; check "production needs --rollout=N" $((! $?))
reset; answers; run --site=staging --dry-run >"$T/out" 2>&1; rc=$?; check "a dry run succeeds" $rc
! changed; check "...and changes nothing" $?
grep -q "Dry run" "$T/out"; check "...and says so" $?

# A full run on staging with the validations.
reset; answers; run --site=staging >"$T/out" 2>&1; rc=$?; check "a full run on staging succeeds" $rc
[ "$rc" -eq 0 ] || tail -25 "$T/out"
for p in wp-printable-gallery wp-printable-qrcode burst-statistics upgrade-for-unattach-re-attach-media-attachments upload-media-exif-date url-shortify wp-extra-file-types wp-pdf-helper; do
  [ ! -e "$T/plugins/$p" ]; check "the plugin $p is deactivated" $?
done
for p in wp-otherguise wp-media-helper wp-attached-gpx kaya-qr-code-generator; do [ -e "$T/plugins/$p" ]; check "the plugin $p is not touched" $?; done
[ "$(cat "$T/method")" = otherguise ]; check "the method of Media Helper is otherguise" $?
! grep -q "^rollout.sh update\|^rollout.sh apply" "$LOG"; check "the script runs no git" $?
a=$(line "plugin deactivate wp-printable-gallery"); b=$(line "media migrate --dry-run"); c=$(grep -n "^\[[a-z]*\] otherguise media migrate$" "$LOG" | head -1 | cut -d: -f1); d=$(line "option update"); e=$(line "plugin deactivate burst-statistics"); f=$(line "plugin deactivate wp-pdf-helper")
[ "$a" -lt "$b" ]; check "the old gallery plugin is deactivated before the migration" $?
[ "$b" -lt "$c" ]; check "the dry run comes before the migration" $?
[ "$c" -lt "$d" ]; check "the migration comes before the choice of the method" $?
[ "$d" -lt "$e" ]; check "the method is chosen before the plugins that are no longer needed are deactivated" $?
[ "$e" -lt "$f" ]; check "wp-pdf-helper is deactivated last" $?
[ "$(line 'plugin deactivate wp-printable-qrcode')" -lt "$b" ]; check "wp-printable-qrcode too is deactivated before the migration" $?
grep -q "^curl https://sb.test/2025/10/03/repos-a-madrid/?mode=print" "$LOG"; check "the pages are asked at the public address of the site" $?
grep -q "^templates.sh check --site=staging" "$LOG"; check "the templates of the database are checked" $?
grep -q "STEP .* CHANGE  Deactivate the plugin wp-pdf-helper" "$T/out"; check "the steps are announced and numbered" $?

# The preflight stops the script before any change.
pre() { # name, variable, value
  reset; answers; export "$2"="$3"; run --site=staging >"$T/out" 2>&1; rc=$?
  [ "$rc" -ne 0 ]; check "preflight: $1 stops the script" $?
  ! changed; check "...before any change" $?
}
pre "no WP-CLI" STUB_NO_WPCLI 1
pre "no MySQL client" STUB_NO_MYSQL 1
pre "cron not running" STUB_NO_CRON 1
pre "no cron file" STUB_NO_CRONFILE 1
pre "DISABLE_WP_CRON not true" STUB_CRON_CONST ""
pre "another theme" STUB_THEME twentytwentyfive
pre "no migrate command" STUB_NO_MIGRATE_CMD 1
pre "no gallery block" STUB_GALLERY_BLOCK no
pre "GPX block without its attributes" STUB_GPX_ATTRS none
pre "shortcodes missing" STUB_SHORTCODES no
pre "types gpx and vtt not declared" STUB_MIME no
pre "templates that still use the old blocks" STUB_TEMPLATES no
grep -q "update the plugin from Gitea" "$T/out" || { reset; answers; STUB_GPX_ATTRS=none run --site=staging >"$T/out" 2>&1; }
grep -q "update the plugin from Gitea" "$T/out"; check "...and the message says what to update" $?
reset; answers; rm -f "$T/plugins/wp-media-helper"; run --site=staging >/dev/null 2>&1; check "preflight: Media Helper inactive stops the script" $((! $?))
! changed; check "...before any change" $?

# Checks after a step.
reset; answers; STUB_OLD_GALLERY=yes run --site=staging >"$T/out" 2>&1; check "the old gallery block still on the page stops the script" $((! $?))
reset; answers; STUB_EQUIV="/2025/10/03/repos-a-madrid/ web 80/83" run --site=staging >"$T/out" 2>&1; check "a gallery that differs stops the script" $((! $?))
! grep -q "plugin deactivate burst-statistics" "$LOG"; check "...before the other plugins are deactivated" $?
grep -q "repos-a-madrid/ web 80/83" "$T/out"; check "...and says where" $?
reset; answers; STUB_NO_MAP200=1 run --site=staging >/dev/null 2>&1; check "a map that is not 200px stops the script" $((! $?))
! grep -q "plugin deactivate wp-pdf-helper" "$LOG"; check "...and wp-pdf-helper is still active" $?
reset; answers; STUB_NO_ANSWER=1 run --site=staging >/dev/null 2>&1; check "a site that does not answer stops the script" $((! $?))

# The second run changes nothing that was done.
reset; answers; run --site=staging >/dev/null 2>&1; : >"$LOG"; answers; run --site=staging >"$T/out" 2>&1; rc=$?; check "a second run succeeds" $rc
! grep -q "plugin deactivate" "$LOG"; check "...a plugin that is inactive is left alone" $?
grep -q "left alone" "$T/out"; check "...and it says so" $?

# --no-validate is not recorded as a rehearsal; the validations answered "n" stop the script.
reset; printf 'n\n' >"$T/answers"; export ROLLOUT_TTY="$T/answers"; run --site=staging >/dev/null 2>&1; check "a validation refused stops the script" $((! $?))

# Production: the rehearsal is required; no question; the preflight comes before the backup.
reset; answers; run --site=staging >/dev/null 2>&1
reset; : >"$T/answers"; run --site=prod --rollout=3 --yes >"$T/out" 2>&1; rc=$?; check "production runs after the rehearsal with no answer at all" $rc
[ "$rc" -eq 0 ] || tail -20 "$T/out"
grep -q "LATER" "$T/out"; check "...the validations are listed afterwards, not asked" $?
[ "$(line 'cli version')" -lt "$(line 'db export')" ]; check "...the preflight comes before the backup" $?
[ -n "$(ls "$T/backups" 2>/dev/null)" ]; check "...and the database is backed up" $?
reset; : >"$T/answers"; STUB_NO_MYSQL=1 run --site=prod --rollout=3 --yes >"$T/out" 2>&1; check "production without MySQL client stops" $((! $?))
! grep -q "db export" "$LOG"; check "...before the backup is tried" $?
! grep -q "plugin deactivate" "$LOG"; check "...and before any plugin is touched" $?
reset; : >"$T/answers"; STUB_EXIT_VERIFY=1 run --site=prod --rollout=3 --yes >/dev/null 2>&1; check "production not on the tag stops" $((! $?))
reset; : >"$T/answers"; run --site=prod --rollout=3 --yes --force >/dev/null 2>&1; check "--force runs a script that was rehearsed" $?

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
