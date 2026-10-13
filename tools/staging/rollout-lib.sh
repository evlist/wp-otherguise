# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# The library of the rollout scripts (rollouts/NNN-name.sh). A rollout script is the procedure of one iteration, written as commands, checks
# and questions; it runs on staging as often as needed (staging is synchronised again when something goes wrong) and on production when it is
# right. This file gives it:
#
#   rollout_init <name> "$@"       reads --site=<prod|staging|testing>, --rollout=<N> (production), --no-validate, --dry-run, --yes, --force.
#   rollout_begin                  logs, asks for the confirmation on production, refuses production for a script that was not rehearsed
#                                  on staging, backs the database up before the first change on production.
#   step "title" cmd...            runs a command, stops if it fails.
#   note "title" cmd...            runs a command to show its output, never stops (to record a state).
#   check "description" cmd...     runs a command quietly; stops if it fails. For what a command can decide.
#   check_output "description" "expected" cmd...    the same, comparing what the command prints.
#   validate "text"                asks Eric to look at something; skipped with --no-validate. "n" stops the script. On production it never
#                                  asks (the script was rehearsed on staging): the text is shown and listed again at the end, to look at afterwards.
#   manual "what to do" "what to check"    a gesture without a command: always asked (never skipped), stops without a terminal.
#   wp ARGS...                     WP-CLI in the container of the site.
#   rollout_versions               the versions of the repositories. Git is managed outside the scripts (the clones are put on the state to
#                                  rehearse: a tag, a branch...): on staging and testing this notes the state and remembers it; on production
#                                  it checks that the clones are on rollout-N (put there with `rollout.sh apply N`) and that this is the state
#                                  that was rehearsed.
#   rollout_end                    on staging, after a complete run with the validations, records that this script was rehearsed, with the
#                                  state of the repositories.
#
# Each action is numbered and announced by a banner (colours when the output is a terminal and NO_COLOR is not set; the log has no colours):
# CHANGE (step), STATE (note), LOOK (validate), MANUAL (manual); a check is one numbered line.
#
# Every step should be safe to run twice. There is no rollback to write: production is backed up and staging can be synchronised again.
#
# Environment: as in sync-site.sh and target.sh (PROD_CONTAINER, STAGING_CONTAINER, TESTING_CONTAINER, ...). ROLLOUT_LOG_DIR (default
# ~/rollout-logs), ROLLOUT_BACKUP_DIR (~/rollout-backups), ROLLOUT_STATE_DIR (~/.local/state/vli-rollouts), ROLLOUT_TTY (the terminal to ask,
# /dev/tty by default; the tests use a file).

LIB_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=target.sh
. "$LIB_DIR/target.sh"
PROD_CONTAINER=${PROD_CONTAINER:-docker-e-vli-st_wordpress_1}
STAGING_CONTAINER=${STAGING_CONTAINER:-docker-sb-vli-st_wordpress-sb_1}
STAGING_URL=${STAGING_URL:-https://sb.vli.st}

SITE="" ROLLOUT_NUMBER="" VALIDATE=1 DRY_RUN=0 YES=0 FORCE=0 ROLLOUT_NAME="" CONTAINER="" CHANGED=0 VALIDATED=1 AFTERWARDS=()

# Colours only on a terminal (decided before the output goes through tee into the log) and unless NO_COLOR is set.
STEP_N=0
WIDTH=${COLUMNS:-$(tput cols 2>/dev/null || echo 100)}
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
  C_RST=$'\033[0m' C_BOLD=$'\033[1m' C_DIM=$'\033[2m' C_RED=$'\033[1;31m' C_GRN=$'\033[32m' C_YEL=$'\033[1;33m'
  B_CHANGE=$'\033[1;97;44m' B_STATE=$'\033[1;30;46m' B_LOOK=$'\033[1;30;43m' B_MANUAL=$'\033[1;97;45m' B_DONE=$'\033[1;30;42m' B_HEAD=$'\033[1;97;100m'
else
  C_RST="" C_BOLD="" C_DIM="" C_RED="" C_GRN="" C_YEL="" B_CHANGE="" B_STATE="" B_LOOK="" B_MANUAL="" B_DONE="" B_HEAD=""
fi

# banner <colour> <number or ""> <kind> <title>: a full-width bar that is easy to find when the output scrolls by.
banner() {
  local colour=$1 number=$2 kind=$3 title=$4 text pad
  text=" ${number:+STEP $number  }${kind}  ${title}"
  pad=$((WIDTH - ${#text}))
  [ "$pad" -gt 0 ] || pad=0
  printf '\n%s%s%*s%s\n' "$colour" "$text" "$pad" "" "$C_RST"
}
next_step() { STEP_N=$((STEP_N + 1)); }

die() { printf '\n%sERROR: %s%s\n' "$C_RED" "$*" "$C_RST" >&2; exit 1; }
say() { banner "$B_HEAD" "" "" "$*"; }
quiet_warnings() { grep -v -e 'Constant WP_DEBUG already defined' -e 'Function _load_textdomain_just_in_time was called' || true; }

# WP-CLI in the container of the site, as the web server's user.
wp() { docker exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache "$CONTAINER" wp "$@" 2> >(quiet_warnings >&2); }
# The other tools of the directory.
rollout_sh() { "$LIB_DIR/rollout.sh" "$@"; }
templates_sh() { "$LIB_DIR/templates.sh" "$@"; }

rollout_init() {
  ROLLOUT_NAME=$1
  shift
  for arg in "$@"; do
    case "$arg" in
      --site=prod | --site=staging | --site=testing) SITE=${arg#--site=} ;;
      --site=*) die "Unknown site: ${arg#--site=} (prod, staging or testing)." ;;
      --rollout=*) ROLLOUT_NUMBER=${arg#--rollout=} ;;
      --no-validate) VALIDATE=0 ;;
      --dry-run) DRY_RUN=1 ;;
      --yes) YES=1 ;;
      --force) FORCE=1 ;;
      -h | --help)
        echo "Usage: $(basename "$0") --site=<prod|staging|testing> [--rollout=<N> (production)] [--no-validate] [--dry-run] [--yes] [--force]"
        echo "  --no-validate  do not ask the optional validations (the manual gestures are still asked)"
        echo "  --dry-run      print what would be done, run nothing that changes anything"
        echo "  --yes          do not ask for the confirmation on production"
        echo "  --force        run on production although this script was not rehearsed on staging"
        exit 0 ;;
      *) die "Unknown option: $arg" ;;
    esac
  done
  [ -n "$SITE" ] || die "Say which site: --site=prod, --site=staging or --site=testing."
  if [ "$SITE" = prod ]; then
    CONTAINER=$PROD_CONTAINER
  else
    select_target "$SITE" || exit 2
    CONTAINER=$STAGING_CONTAINER
  fi
  [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" = "true" ] || die "Container $CONTAINER is not running."
  VALIDATED=$VALIDATE
}

# The fingerprint of the script and of this library: a rehearsal counts only for the text that was rehearsed.
rollout_hash() { cat "$0" "${BASH_SOURCE[0]}" | sha256sum | cut -c1-16; }

STATE_DIR_DEFAULT="${XDG_STATE_HOME:-$HOME/.local/state}/vli-rollouts"
rollout_state_file() { printf '%s/%s-%s' "${ROLLOUT_STATE_DIR:-$STATE_DIR_DEFAULT}" "$ROLLOUT_NAME" "$(rollout_hash)"; }

rollout_begin() {
  local log_dir=${ROLLOUT_LOG_DIR:-$HOME/rollout-logs}
  if [ "$DRY_RUN" -eq 0 ]; then
    mkdir -p "$log_dir"
    local log="$log_dir/$ROLLOUT_NAME-$SITE-$(date +%Y%m%d-%H%M%S).log"
    exec > >(tee >(sed -u 's/\x1b\[[0-9;]*m//g' >>"$log")) 2>&1
    echo "Log: $log"
  fi
  say "Rollout $ROLLOUT_NAME on the $SITE site ($CONTAINER)$([ "$DRY_RUN" -eq 0 ] || echo ', dry run')"

  if [ "$SITE" = prod ]; then
    if [ "$FORCE" -eq 0 ] && [ ! -e "$(rollout_state_file).validated" ]; then
      die "This text of $ROLLOUT_NAME was not rehearsed on staging, with the validations, to the end. Run it on staging first (or --force)."
    fi
    [ "$FORCE" -eq 0 ] || echo "WARNING: --force, the rehearsal on staging is not required."
    if [ "$YES" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
      ask "This changes PRODUCTION ($CONTAINER). Type the name of the container to go on: " answer
      [ "$answer" = "$CONTAINER" ] || die "Not confirmed."
    fi
    rollout_backup
  fi
}

# Asks a question on the terminal and reads the answer into the variable named by $2. With ROLLOUT_TTY (the tests) the answers are the lines
# of that file; when they run out the answer is "n".
ask() {
  local reply
  if [ -n "${ROLLOUT_TTY:-}" ]; then
    [ -r "$ROLLOUT_TTY" ] || die "No terminal to ask: $1"
    if ! { true >&9; } 2>/dev/null; then exec 9<"$ROLLOUT_TTY"; fi
    read -r -u 9 reply || reply=n
  else
    { : </dev/tty; } 2>/dev/null || die "No terminal to ask: $1 (the validations can be skipped with --no-validate, not the manual gestures)."
    read -r -p "$1" reply </dev/tty || reply=n
  fi
  printf -v "$2" '%s' "$reply"
}

# The database of production, before the first change.
rollout_backup() {
  [ "$DRY_RUN" -eq 0 ] || { echo "Dry run: the database would be backed up."; return 0; }
  local dir=${ROLLOUT_BACKUP_DIR:-$HOME/rollout-backups} file
  file="before-$ROLLOUT_NAME-$(date +%Y%m%d-%H%M%S).sql"
  mkdir -p "$dir"
  say "Backup of the database of production: $dir/$file"
  docker exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache "$CONTAINER" wp db export "/tmp/$file" >/dev/null || die "The backup failed: nothing was changed."
  docker cp "$CONTAINER:/tmp/$file" "$dir/$file" || die "Cannot copy the backup out of the container: nothing was changed."
  docker exec -u root "$CONTAINER" rm -f "/tmp/$file" || true
  echo "Backed up: $(wc -c <"$dir/$file") bytes."
}

step() {
  local title=$1
  shift
  next_step
  banner "$B_CHANGE" "$STEP_N" "CHANGE" "$title"
  if [ "$DRY_RUN" -eq 1 ]; then echo "Dry run: $*"; return 0; fi
  CHANGED=1
  "$@" || die "Step failed: $title"
}

note() {
  local title=$1
  shift
  next_step
  banner "$B_STATE" "$STEP_N" "STATE " "$title"
  if [ "$DRY_RUN" -eq 1 ]; then echo "Dry run: $*"; return 0; fi
  "$@" || true
}

check() {
  local description=$1
  shift
  next_step
  if [ "$DRY_RUN" -eq 1 ]; then echo "Dry run, check: $description"; return 0; fi
  if "$@" >/dev/null 2>&1; then printf '%sSTEP %s  OK  %s%s\n' "$C_GRN" "$STEP_N" "$description" "$C_RST"; else die "Check failed: $description"; fi
}

check_output() {
  local description=$1 expected=$2 actual
  shift 2
  next_step
  if [ "$DRY_RUN" -eq 1 ]; then echo "Dry run, check: $description"; return 0; fi
  actual=$("$@" 2>/dev/null) || actual="(the command failed)"
  if [ "$actual" = "$expected" ]; then printf '%sSTEP %s  OK  %s%s\n' "$C_GRN" "$STEP_N" "$description" "$C_RST"; else die "Check failed: $description. Expected \"$expected\", got \"$actual\"."; fi
}

validate() {
  local answer
  next_step
  if [ "$VALIDATE" -eq 0 ]; then echo "Validation skipped (--no-validate): $1"; return 0; fi
  if [ "$DRY_RUN" -eq 1 ]; then echo "Dry run, validation: $1"; return 0; fi
  if [ "$SITE" = prod ]; then
    banner "$B_LOOK" "$STEP_N" "LATER " "${1%%.*} (to look at afterwards; nothing is asked on production)"
    printf '%s\n' "$1"
    AFTERWARDS+=("STEP $STEP_N  $1")
    return 0
  fi
  banner "$B_LOOK" "$STEP_N" "LOOK  " "${1%%.*}"
  printf '%s\n' "$1"
  ask "${C_YEL}   Enter when it is right, n if it is not: ${C_RST}" answer
  [ "$answer" != n ] || die "Validation refused: $1"
}

manual() {
  local answer
  next_step
  banner "$B_MANUAL" "$STEP_N" "MANUAL" "${1%%.*}"
  printf '%s\n   Then check: %s\n' "$1" "$2"
  if [ "$DRY_RUN" -eq 1 ]; then echo "Dry run: asked here."; return 0; fi
  ask "${C_YEL}   Enter when it is done and checked, n if it is not: ${C_RST}" answer
  [ "$answer" != n ] || die "Manual step refused: $1"
}

rollout_versions() {
  local rehearsed now
  if [ "$SITE" != prod ]; then
    note "The repositories (the state that is being tried)" rollout_sh status --site="$SITE"
    [ "$DRY_RUN" -eq 1 ] || STATE_NOW=$(rollout_sh state --site="$SITE")
    return 0
  fi
  [[ "$ROLLOUT_NUMBER" =~ ^[0-9]+$ ]] || die "On production, say which rollout: --rollout=N (the tag made on staging), after: rollout.sh apply N --site=prod."
  say "The repositories of production must be on rollout-$ROLLOUT_NUMBER"
  [ "$DRY_RUN" -eq 0 ] || { echo "Dry run: checked here."; return 0; }
  rollout_sh verify "$ROLLOUT_NUMBER" --site=prod || die "Put production on the tag first: rollout.sh apply $ROLLOUT_NUMBER --site=prod"
  rehearsed=$(sed '1d' "$(rollout_state_file).validated" 2>/dev/null || true)
  now=$(rollout_sh state --site=prod)
  if [ -n "$rehearsed" ]; then
    # Only the repositories that staging and production both have are compared.
    local mismatch=""
    while IFS=$'\t' read -r name commit; do
      [ -n "$name" ] || continue
      local there
      there=$(printf '%s\n' "$now" | awk -F'\t' -v n="$name" '$1 == n { print $2 }')
      if [ -n "$there" ] && [ "$there" != "$commit" ]; then mismatch="$mismatch $name"; fi
    done <<<"$rehearsed"
    if [ -n "$mismatch" ]; then
      if [ "$FORCE" -eq 1 ]; then echo "WARNING: --force, not the rehearsed commits for:$mismatch"
      else die "rollout-$ROLLOUT_NUMBER is not what was rehearsed on staging for:$mismatch. Rehearse the commits that the tag has, or tag what was rehearsed."; fi
    else
      echo "OK  the commits of rollout-$ROLLOUT_NUMBER are those that were rehearsed on staging"
    fi
  fi
}

rollout_end() {
  banner "$B_DONE" "" "DONE" "$ROLLOUT_NAME on the $SITE site ($STEP_N steps)"
  if [ "${#AFTERWARDS[@]}" -gt 0 ]; then
    printf '\n%sTo look at now that it is done (nothing was asked on production):%s\n' "$C_YEL" "$C_RST"
    printf ' - %s\n' "${AFTERWARDS[@]}"
  fi
  [ "$DRY_RUN" -eq 0 ] || { echo "Dry run: nothing was recorded."; return 0; }
  if [ "$SITE" = staging ] && [ "$VALIDATED" -eq 1 ]; then
    mkdir -p "${ROLLOUT_STATE_DIR:-$STATE_DIR_DEFAULT}"
    { date -u +%Y-%m-%dT%H:%M:%SZ; printf '%s\n' "${STATE_NOW:-}"; } >"$(rollout_state_file).validated"
    echo "Recorded: this text of $ROLLOUT_NAME was rehearsed on staging with the validations. It can run on production."
  elif [ "$SITE" = staging ]; then
    echo "Not recorded as a rehearsal: it ran without the validations. Run it once on staging with them before production."
  fi
}
