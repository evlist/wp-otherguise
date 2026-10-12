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
for tool in rollout.sh templates.sh; do
  printf '#!/usr/bin/env bash\necho "%s $*" >>"$LOG"\nexit "${STUB_EXIT_%s:-0}"\n' "$tool" "$(echo "${tool%.sh}" | tr a-z A-Z)" >"$T/repo/tools/staging/$tool"
  chmod +x "$T/repo/tools/staging/$tool"
done
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
export PATH="$T/bin:$PATH"

run() { bash "$SCRIPT" "$@"; }
answers() { printf '%s\n' "$@" >"$T/answers"; export ROLLOUT_TTY="$T/answers"; }
reset() { : >"$LOG"; echo twentytwentyfive >"$T/active"; echo "template twentytwentyfive//a twentytwentyfive//b print" >"$T/variants"; unset STUB_BLOCK STUB_NOT_INSTALLED STUB_EXIT_ROLLOUT STUB_EXIT_TEMPLATES; }

# Guards of the arguments.
reset; run >/dev/null 2>&1; check "needs a site" $((! $?))
run --site=nowhere >/dev/null 2>&1; check "refuses an unknown site" $((! $?))
run --site=staging --bogus >/dev/null 2>&1; check "refuses an unknown option" $((! $?))
reset; answers "" "" "" ""; run --site=prod --yes >/dev/null 2>&1; check "production needs --rollout=N" $((! $?))
! grep -q "activate" "$LOG"; check "...and changes nothing" $?
run --site=staging --help >/dev/null 2>&1; check "--help works" $?

# Dry run.
reset; run --site=staging --dry-run >"$T/out" 2>&1; rc=$?; check "a dry run succeeds" $rc
! grep -q "theme activate\|variant retheme\|rollout.sh update" "$LOG"; check "...and runs nothing that changes anything" $?
grep -q "Dry run" "$T/out"; check "...and says so" $?
[ ! -d "$T/state" ] || [ -z "$(ls "$T/state" 2>/dev/null)" ]; check "...and records no rehearsal" $?

# A full run on staging, with the validations: five answers (three validations, one manual gesture).
reset; answers "" "" "" ""; run --site=staging >"$T/out" 2>&1; rc=$?; check "a full run on staging succeeds" $rc
[ "$rc" -eq 0 ] || tail -20 "$T/out"
grep -q "^rollout.sh update --site=staging" "$LOG"; check "staging is brought to the latest commits" $?
grep -q "wp\[stg\] theme activate wp-twenty-twenty-five-printable" "$LOG"; check "the theme is activated" $?
grep -q "variant retheme twentytwentyfive wp-twenty-twenty-five-printable" "$LOG"; check "the variants are moved" $?
a=$(grep -n "theme activate" "$LOG" | head -1 | cut -d: -f1); b=$(grep -n "variant retheme" "$LOG" | head -1 | cut -d: -f1)
[ "$a" -lt "$b" ]; check "the theme is activated before the variants are moved" $?
grep -q "^templates.sh check --site=staging" "$LOG"; check "the templates are checked" $?
ls "$T/state"/*.validated >/dev/null 2>&1; check "a rehearsal is recorded" $?
ls "$T/logs"/002-child-theme-staging-*.log >/dev/null 2>&1; check "a log is kept" $?

# Running it again changes nothing that is already done.
: >"$LOG"; answers "" "" "" ""; run --site=staging >/dev/null 2>&1; rc=$?; check "a second run succeeds" $rc
! grep -q "theme activate" "$LOG"; check "an active theme is not activated again" $?

# Questions.
reset; answers "" n; run --site=staging >/dev/null 2>&1; rc=$?; check "a refused validation stops the script" $((! rc))
rm -rf "$T/state"
reset; answers "" "" "" ""; STUB_BLOCK=page run --site=staging >"$T/out" 2>&1; check "a check that fails stops the script" $((! $?))
! grep -q "theme activate" "$LOG"; check "...before anything is changed" $?
grep -q "reusable block 32919" "$T/out"; check "...and says which one" $?
reset; answers ""; STUB_NOT_INSTALLED=1 run --site=staging >/dev/null 2>&1; check "a theme that is not installed stops the script" $((! $?))
reset; answers "" "" "" ""; STUB_EXIT_TEMPLATES=1 run --site=staging >"$T/out" 2>&1; check "a database copy that hides a file after the switch stops the script" $((! $?))
grep -q "no database copy hides a file" "$T/out"; check "...and says so" $?
rm -rf "$T/state"

reset; answers "" ""; run --site=staging --no-validate >"$T/out" 2>&1; rc=$?; check "--no-validate still asks for the manual gesture" $rc
grep -q "Validation skipped" "$T/out"; check "...and says what it skipped" $?
[ -z "$(ls "$T/state"/*.validated 2>/dev/null)" ]; check "a run without the validations is not recorded as a rehearsal" $?
unset ROLLOUT_TTY
reset; run --site=staging </dev/null >"$T/out" 2>&1; check "without a terminal the validations are refused" $((! $?))

# Production.
reset; answers "" "" "" ""; run --site=prod --rollout=3 --yes >"$T/out" 2>&1; check "production is refused when the script was not rehearsed" $((! $?))
grep -q "not rehearsed" "$T/out"; check "...with that reason" $?
! grep -q "wp\[prod\]" "$LOG"; check "...and nothing was touched" $?

reset; answers "" "" "" ""; run --site=staging >/dev/null 2>&1
reset; answers "" "" "" ""; run --site=prod --rollout=3 --yes >"$T/out" 2>&1; rc=$?; check "after the rehearsal production runs" $rc
[ "$rc" -eq 0 ] || tail -20 "$T/out"
grep -q "^rollout.sh apply 3 --site=prod --yes" "$LOG"; check "production gets the tag, not the latest commits" $?
! grep -q "^rollout.sh update" "$LOG"; check "...and is never updated" $?
a=$(grep -n "db export" "$LOG" | head -1 | cut -d: -f1); b=$(grep -n "theme activate" "$LOG" | head -1 | cut -d: -f1)
[ -n "$a" ] && [ "$a" -lt "$b" ]; check "the database is backed up before the first change" $?
ls "$T/backups"/before-002-child-theme-*.sql >/dev/null 2>&1; check "the backup is copied out of the container" $?

reset; answers "wrong" "" "" "" ""; run --site=prod --rollout=3 >/dev/null 2>&1; check "production asks for the name of the container" $((! $?))
! grep -q "theme activate" "$LOG"; check "...and changes nothing without it" $?
reset; answers "prod" "" "" "" ""; run --site=prod --rollout=3 >/dev/null 2>&1; check "the right name goes on" $?

# Changing the script invalidates the rehearsal.
echo "# changed" >>"$SCRIPT"
reset; answers "" "" "" ""; run --site=prod --rollout=3 --yes >"$T/out" 2>&1; check "a script that changed since its rehearsal is refused on production" $((! $?))
reset; answers "" "" "" ""; run --site=prod --rollout=3 --yes --force >/dev/null 2>&1; check "--force goes on anyway" $?
reset; answers "" "" "" ""; run --site=prod --rollout=3 --yes --no-validate --force >/dev/null 2>&1; check "--no-validate works on production" $?

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
