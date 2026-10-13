#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Tests of tools/staging/rollout-lib.sh and of rollouts/002-child-theme.sh with stand-ins for docker, rollout.sh and templates.sh: the order
# of the steps, the checks, the questions, the guard of production, the backup. Nothing here touches a real container. Run:
# tests/staging/rollout-script-test.sh
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT="$HERE/../.."
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
PASS=0 FAIL=0
check() { if [ "$2" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAIL=$((FAIL + 1)); echo "FAIL  $1"; fi; }

# A copy of the tools and of the scripts, with the other tools replaced by stand-ins that only log.
mkdir -p "$T/repo/tools" "$T/bin"
cp -r "$ROOT/tools/staging" "$T/repo/tools/staging"
cp -r "$ROOT/rollouts" "$T/repo/rollouts"
printf '#!/usr/bin/env bash\necho "templates.sh $*" >>"$LOG"\nexit "${STUB_EXIT_TEMPLATES:-0}"\n' >"$T/repo/tools/staging/templates.sh"
cat >"$T/repo/tools/staging/rollout.sh" <<'S'
#!/usr/bin/env bash
echo "rollout.sh $*" >>"$LOG"
case "$1" in
  state) cat "$T/state_out" ;;
  verify) exit "${STUB_EXIT_VERIFY:-0}" ;;
  *) exit "${STUB_EXIT_ROLLOUT:-0}" ;;
esac
S
chmod +x "$T/repo/tools/staging/templates.sh" "$T/repo/tools/staging/rollout.sh"
printf 'wp-otherguise\taaa\nwp-theme\tbbb\n' >"$T/state_out"
SCRIPT="$T/repo/rollouts/002-child-theme.sh"
export LOG="$T/calls.log" T
export PROD_CONTAINER=prod STAGING_CONTAINER=stg STAGING_URL=https://sb.test
export ROLLOUT_LOG_DIR="$T/logs" ROLLOUT_BACKUP_DIR="$T/backups" ROLLOUT_STATE_DIR="$T/state"
echo wp_theme_parent >"$T/active"
echo "template twentytwentyfive//a twentytwentyfive//b print" >"$T/variants"

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
echo "wp[$container] $args" >>"$LOG"
case "$args" in
  "theme is-installed"*) exit "${STUB_NOT_INSTALLED:-0}" ;;
  "cli has-command"*) exit "${STUB_NO_RETHEME:-0}" ;;
  "plugin get wp-pdf-helper"*) echo "wp-pdf-helper active" ;;
  "eval "*"modes/print.css"*) echo "${STUB_PRINT_CSS_IN_THEME:-yes}" ;;
  "eval "*"dashicons"*) echo "${STUB_DASHICONS:-yes}" ;;
  "eval "*"fira-sans"*) echo "${STUB_FONTS_IN_THEME:-yes}" ;;
  "eval "*"lettrine"*) echo "${STUB_CSS_IN_THEME:-yes}" ;;
  "eval "*"wp_style_is"*) echo "${STUB_STYLE_ENQUEUED:-yes}" ;;
  "eval "*) echo "${STUB_THEME_EVAL:-ok}" ;;
  "plugin is-active custom-css-js") exit "${STUB_PLUGIN_INACTIVE:-0}" ;;
  "post list --post_type=custom-css-js"*) echo "${STUB_SNIPPETS:-33286}" ;;
  "post get 32919 --field=post_type") echo "${STUB_BLOCK:-wp_block}" ;;
  "option get home") echo https://sb.test ;;
  "theme list --status=active --field=name") cat "$T/active" ;;
  "theme activate "*) echo "${args#theme activate }" >"$T/active" ;;
  "otherguise modes variant retheme "*) set -- $args; echo "template $6//a $6//b print" >"$T/variants"; echo "template ${5}//a" >/dev/null ;;
  "otherguise modes variant list --theme="*) theme=${args#*--theme=}; grep "//" "$T/variants" | grep " $theme//" | sed "s/^template //" | tr ' ' '\t' ;;
  "otherguise modes variant list"*) cat "$T/variants" ;;
  "db export"*) : ;;
esac
exit 0
STUB
chmod +x "$T/bin/docker"
cat >"$T/bin/curl" <<'STUB'
#!/usr/bin/env bash
for a in "$@"; do url=$a; done
echo "curl $url" >>"$LOG"
case "$url" in
  *"?mode=print") [ "${STUB_PRINT_MODE:-yes}" = yes ] && echo "<link id='modes-theme-print-css'>" || echo "<html>" ;;
  *) [ "${STUB_PRINT_ON_WEB:-no}" = yes ] && echo "<link id='modes-theme-print-css'>" || echo "<html>" ;;
esac
STUB
chmod +x "$T/bin/curl"
export PATH="$T/bin:$PATH"

run() { bash "$SCRIPT" "$@"; }
answers() { printf '%s\n' "$@" >"$T/answers"; export ROLLOUT_TTY="$T/answers"; }
reset() { : >"$LOG"; echo twentytwentyfive >"$T/active"; echo "template twentytwentyfive//a twentytwentyfive//b print" >"$T/variants"; unset STUB_BLOCK STUB_NOT_INSTALLED STUB_EXIT_ROLLOUT STUB_EXIT_TEMPLATES STUB_EXIT_VERIFY STUB_THEME_EVAL STUB_CSS_IN_THEME STUB_STYLE_ENQUEUED STUB_PLUGIN_INACTIVE STUB_SNIPPETS STUB_FONTS_IN_THEME STUB_NO_RETHEME STUB_PRINT_CSS_IN_THEME STUB_PRINT_MODE STUB_PRINT_ON_WEB STUB_DASHICONS; printf 'wp-otherguise\taaa\nwp-theme\tbbb\n' >"$T/state_out"; }

# Guards of the arguments.
reset; run >/dev/null 2>&1; check "needs a site" $((! $?))
run --site=nowhere >/dev/null 2>&1; check "refuses an unknown site" $((! $?))
run --site=staging --bogus >/dev/null 2>&1; check "refuses an unknown option" $((! $?))
reset; answers "" "" "" "" "" "" ""; run --site=prod --yes >/dev/null 2>&1; check "production needs --rollout=N" $((! $?))
! grep -q "activate" "$LOG"; check "...and changes nothing" $?
run --site=staging --help >/dev/null 2>&1; check "--help works" $?

# Dry run.
reset; run --site=staging --dry-run >"$T/out" 2>&1; rc=$?; check "a dry run succeeds" $rc
! grep -q "theme activate\|variant retheme" "$LOG"; check "...and runs nothing that changes anything" $?
grep -q "Dry run" "$T/out"; check "...and says so" $?
[ ! -d "$T/state" ] || [ -z "$(ls "$T/state" 2>/dev/null)" ]; check "...and records no rehearsal" $?

# A full run on staging, with the validations: five answers (three validations, one manual gesture).
reset; answers "" "" "" "" "" "" ""; run --site=staging >"$T/out" 2>&1; rc=$?; check "a full run on staging succeeds" $rc
[ "$rc" -eq 0 ] || tail -20 "$T/out"
! grep -q "^rollout.sh update\|^rollout.sh apply" "$LOG"; check "the script runs no git: it does not update the clones" $?
grep -q "^rollout.sh state --site=staging" "$LOG"; check "it remembers the state of the clones" $?
grep -q "wp\[stg\] theme activate wp-twenty-twenty-five-printable" "$LOG"; check "the theme is activated" $?
grep -q "variant retheme twentytwentyfive wp-twenty-twenty-five-printable" "$LOG"; check "the variants are moved" $?
a=$(grep -n "theme activate" "$LOG" | head -1 | cut -d: -f1); b=$(grep -n "otherguise modes variant retheme [a-z]" "$LOG" | head -1 | cut -d: -f1)
[ "$a" -lt "$b" ]; check "the theme is activated before the variants are moved" $?
grep -q "^templates.sh check --site=staging" "$LOG"; check "the templates are checked" $?
ls "$T/state"/*.validated >/dev/null 2>&1; check "a rehearsal is recorded" $?
grep -q "wp-otherguise	aaa" "$T/state"/*.validated; check "...with the state of the clones" $?
ls "$T/logs"/002-child-theme-staging-*.log >/dev/null 2>&1; check "a log is kept" $?

# Running it again changes nothing that is already done.
: >"$LOG"; answers "" "" "" "" "" "" ""; run --site=staging >/dev/null 2>&1; rc=$?; check "a second run succeeds" $rc
! grep -q "theme activate" "$LOG"; check "an active theme is not activated again" $?

# A comparison with the other site after each change, so as to know which one made a difference.
reset; answers "" "" "" "" "" "" ""; run --site=staging >"$T/out" 2>&1
n() { grep -n "$1" "$T/out" | head -1 | cut -d: -f1; }
[ "$(n 'BEFORE any change')" -lt "$(n 'CHANGE  Activate the theme')" ]; check "the sites are compared before any change" $?
[ "$(n 'CHANGE  Activate the theme')" -lt "$(n 'AFTER THE THEME SWITCH')" ] && [ "$(n 'AFTER THE THEME SWITCH')" -lt "$(n 'CHANGE  Move the variants')" ]; check "they are compared right after the theme switch, before the variants move" $?
[ "$(n 'CHANGE  Move the variants')" -lt "$(n 'AFTER THE VARIANTS MOVED')" ] && [ "$(n 'AFTER THE VARIANTS MOVED')" -lt "$(n 'CHANGE  Deactivate the plugin')" ]; check "...right after the variants moved" $?
[ "$(n 'CHANGE  Deactivate the plugin')" -lt "$(n 'AFTER THE PLUGIN CUSTOM CSS')" ]; check "...right after the plugin is deactivated" $?
grep -q "AT THE END" "$T/out"; check "...and at the end" $?
grep -q "The fonts (Fira Sans, Literata)" "$T/out"; check "the comparison after the switch says that the fonts must be those of the other site" $?
reset; unset ROLLOUT_TTY; run --site=staging --no-validate </dev/null >"$T/out" 2>&1
[ "$(grep -c 'Validation skipped' "$T/out")" -eq 6 ]; check "--no-validate skips the six comparisons and questions" $?
rm -rf "$T/state"
reset; answers "" "" "" "" "" "" ""; run --site=staging >/dev/null 2>&1
reset; answers "" "" "" "" "" "" ""; run --site=prod --rollout=3 --yes >"$T/out" 2>&1
! grep -q "BEFORE any change" "$T/out"; check "on production there is no comparison before the change (the other site is already changed)" $?
grep -q "AFTER THE THEME SWITCH" "$T/out"; check "...but there is after each step" $?

# The fonts and the sizes of the blog come from the theme.json of the theme.
reset; answers "" "" "" "" "" "" ""; STUB_FONTS_IN_THEME=no run --site=staging >"$T/out" 2>&1; check "the script stops when the fonts are not in the theme.json of the theme" $((! $?))
grep -q "fonts and the sizes of the blog are in the theme.json" "$T/out"; check "...and says which check failed" $?
! grep -q "otherguise modes variant retheme [a-z]" "$LOG"; check "...before the variants are moved" $?

# The CSS of the blog and the plugin Custom CSS & JS.
reset; answers "" "" "" "" "" "" ""; run --site=staging >/dev/null 2>&1
grep -q "wp\[stg\] plugin deactivate custom-css-js" "$LOG"; check "the plugin Custom CSS & JS is deactivated" $?
a=$(grep -n "theme activate" "$LOG" | head -1 | cut -d: -f1); b=$(grep -n "plugin deactivate custom-css-js" "$LOG" | head -1 | cut -d: -f1)
[ "$a" -lt "$b" ]; check "...after the theme is active" $?
reset; answers "" "" "" "" "" "" ""; STUB_PLUGIN_INACTIVE=1 run --site=staging >/dev/null 2>&1; rc=$?; check "an inactive plugin is left alone" $rc
! grep -q "plugin deactivate" "$LOG"; check "...it is not deactivated again" $?
reset; answers "" "" "" "" "" "" ""; STUB_SNIPPETS="33286
40001" run --site=staging >"$T/out" 2>&1; check "another active snippet stops the script" $((! $?))
grep -q "the only active snippet" "$T/out"; check "...with that reason" $?
! grep -q "plugin deactivate" "$LOG"; check "...and the plugin is not deactivated" $?
reset; answers "" "" "" "" "" "" ""; STUB_CSS_IN_THEME=no run --site=staging >"$T/out" 2>&1; check "the plugin is not deactivated when the CSS is not in the theme" $((! $?))
! grep -q "plugin deactivate" "$LOG"; check "...it stops before" $?
reset; answers "" "" "" "" "" "" ""; STUB_STYLE_ENQUEUED=no run --site=staging >/dev/null 2>&1; check "the plugin is not deactivated when the theme does not load its stylesheet" $((! $?))
reset; answers "" "" "" "" "" "" ""; STUB_NO_RETHEME=1 run --site=staging >"$T/out" 2>&1; check "a plugin without the command retheme stops the script" $((! $?))
! grep -q "theme activate" "$LOG"; check "...before the theme is activated" $?
reset; answers "" "" "" "" "" "" ""; STUB_PRINT_CSS_IN_THEME=no run --site=staging >/dev/null 2>&1; check "a theme without modes/print.css stops the script" $((! $?))
! grep -q "theme activate" "$LOG"; check "...before the theme is activated" $?
reset; answers "" "" "" "" "" "" ""; STUB_PRINT_MODE=no run --site=staging >"$T/out" 2>&1; check "the script stops when the mode print does not load modes/print.css" $((! $?))
grep -q "handle modes-theme-print" "$T/out"; check "...with that reason" $?
grep -q "^curl https://sb.test/?mode=print" "$LOG"; check "...asked at the public address of the site (STAGING_URL)" $?
reset; answers "" "" "" "" "" "" ""; STUB_PRINT_ON_WEB=yes run --site=staging >/dev/null 2>&1; check "the script stops when the web mode loads modes/print.css too" $((! $?))
reset; answers "" "" "" "" "" "" ""; STUB_DASHICONS=no run --site=staging >/dev/null 2>&1; check "the script stops when Dashicons is not loaded for visitors" $((! $?))
reset; answers "" "" "" "" "" "" ""; run --site=staging >"$T/out" 2>&1; grep -q "^wp\[stg\] plugin get wp-pdf-helper" "$LOG"; check "the state of wp-pdf-helper is noted" $?
! grep -q "plugin deactivate wp-pdf-helper" "$LOG"; check "...and the plugin is not touched" $?

# Questions.
reset; answers "" n; run --site=staging >/dev/null 2>&1; rc=$?; check "a refused validation stops the script" $((! rc))
rm -rf "$T/state"
reset; answers "" "" "" "" "" "" ""; STUB_BLOCK=page run --site=staging >"$T/out" 2>&1; check "a check that fails stops the script" $((! $?))
! grep -q "theme activate" "$LOG"; check "...before anything is changed" $?
grep -q "reusable block 32919" "$T/out"; check "...and says which one" $?
reset; answers ""; STUB_NOT_INSTALLED=1 run --site=staging >/dev/null 2>&1; check "a theme that is not installed stops the script" $((! $?))
reset; answers "" "" "" "" "" "" ""; STUB_THEME_EVAL="broken: Stylesheet is missing." run --site=staging >"$T/out" 2>&1; check "a theme that WordPress cannot read stops the script" $((! $?))
grep -q "Stylesheet is missing" "$T/out"; check "...and says why" $?
! grep -q "theme activate" "$LOG"; check "...before it is activated" $?
reset; answers "" "" "" "" "" "" ""; STUB_EXIT_TEMPLATES=1 run --site=staging >"$T/out" 2>&1; check "a database copy that hides a file after the switch stops the script" $((! $?))
grep -q "no database copy hides a file" "$T/out"; check "...and says so" $?
rm -rf "$T/state"

reset; answers "" ""; run --site=staging --no-validate >"$T/out" 2>&1; rc=$?; check "--no-validate asks nothing: it runs without a terminal" $rc
grep -q "Validation skipped" "$T/out"; check "...and says what it skipped" $?
[ -z "$(ls "$T/state"/*.validated 2>/dev/null)" ]; check "a run without the validations is not recorded as a rehearsal" $?
unset ROLLOUT_TTY
reset; run --site=staging </dev/null >"$T/out" 2>&1; check "without a terminal the validations are refused" $((! $?))

# Production.
reset; answers "" "" "" "" "" "" ""; run --site=prod --rollout=3 --yes >"$T/out" 2>&1; check "production is refused when the script was not rehearsed" $((! $?))
grep -q "not rehearsed" "$T/out"; check "...with that reason" $?
! grep -q "wp\[prod\]" "$LOG"; check "...and nothing was touched" $?

reset; answers "" "" "" "" "" "" ""; run --site=staging >/dev/null 2>&1
reset; answers "" "" "" "" "" "" ""; run --site=prod --rollout=3 --yes >"$T/out" 2>&1; rc=$?; check "after the rehearsal production runs" $rc
[ "$rc" -eq 0 ] || tail -20 "$T/out"
grep -q "^rollout.sh verify 3 --site=prod" "$LOG"; check "production is checked to be on the tag" $?
! grep -q "^rollout.sh update\|^rollout.sh apply" "$LOG"; check "...the script moves nothing" $?
a=$(grep -n "db export" "$LOG" | head -1 | cut -d: -f1); b=$(grep -n "theme activate" "$LOG" | head -1 | cut -d: -f1)
[ -n "$a" ] && [ "$a" -lt "$b" ]; check "the database is backed up before the first change" $?
ls "$T/backups"/before-002-child-theme-*.sql >/dev/null 2>&1; check "the backup is copied out of the container" $?

reset; answers "" "" "" "" "" "" ""; STUB_EXIT_VERIFY=1 run --site=prod --rollout=3 --yes >"$T/out" 2>&1; check "production is refused when it is not on the tag" $((! $?))
grep -q "rollout.sh apply 3" "$T/out"; check "...and the message says how to put it there" $?
! grep -q "theme activate" "$LOG"; check "...before anything is changed" $?
reset; printf 'wp-otherguise\tzzz\nwp-theme\tbbb\n' >"$T/state_out"; answers "" "" "" "" "" "" ""; run --site=prod --rollout=3 --yes >"$T/out" 2>&1; check "production is refused when the tag is not the rehearsed state" $((! $?))
grep -q "not what was rehearsed on staging for: wp-otherguise" "$T/out"; check "...and says for which repository" $?
reset; printf 'wp-otherguise\tzzz\nwp-theme\tbbb\n' >"$T/state_out"; answers "" "" "" "" "" "" ""; run --site=prod --rollout=3 --yes --force >/dev/null 2>&1; check "--force goes on with a state that was not rehearsed" $?
reset; printf 'wp-otherguise\taaa\nwp-theme\tbbb\nwp-extra\tccc\n' >"$T/state_out"; answers "" "" "" "" "" "" ""; run --site=prod --rollout=3 --yes >/dev/null 2>&1; check "a repository that staging does not have is not compared" $?

reset; answers "wrong" "" "" "" "" "" "" ""; run --site=prod --rollout=3 >/dev/null 2>&1; check "production asks for the name of the container" $((! $?))
! grep -q "theme activate" "$LOG"; check "...and changes nothing without it" $?
reset; answers "prod" "" "" "" "" "" "" ""; run --site=prod --rollout=3 >/dev/null 2>&1; check "the right name goes on" $?

# Changing the script invalidates the rehearsal.
echo "# changed" >>"$SCRIPT"
reset; answers "" "" "" "" "" "" ""; run --site=prod --rollout=3 --yes >"$T/out" 2>&1; check "a script that changed since its rehearsal is refused on production" $((! $?))
reset; answers "" "" "" "" "" "" ""; run --site=prod --rollout=3 --yes --force >/dev/null 2>&1; check "--force goes on anyway" $?
reset; answers "" "" "" "" "" "" ""; run --site=prod --rollout=3 --yes --no-validate --force >/dev/null 2>&1; check "--no-validate works on production" $?

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
