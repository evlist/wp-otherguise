#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Rollouts: the same versions of the plugins on staging and on production. A rollout is a git tag `rollout-N` put, in every plugin
# repository that a site mounts, on the commit that runs on staging, and pushed to GitHub. Production then checks out that tag, so what
# runs there is exactly what was rehearsed on staging. Meant to run on the Docker host, where the clones are.
#
# The repositories are not configured: they are the bind mounts of the site's container under wp-content/plugins (and wp-content/themes)
# whose source is a git clone or a directory of one (`<clone>/plugin`). Anything else (the plugins copied by the sync, mounts that are not git) is left alone.
#
# Usage: rollout.sh <command> --site=<prod|staging|testing> [options]
#   status                  for each repository: branch or tag, commit, rollout tags on it, local changes, distance from origin.
#   update                  (staging, testing) bring each repository to the latest commit of its default branch (fast-forward only).
#   create                  (staging only) tag the commit that runs on staging `rollout-N`, N being the next number, in every repository,
#                           and push the tags. Refuses local changes and commits that were not pushed (the tag would not be reproducible).
#   create --baseline       (production only) the same, to record what runs on production now: the starting point of the rollouts, when
#                           production was set up before them. It only tags, it never changes the code.
#   apply <N>               check out `rollout-N` in every repository of the site, remembering where each was, so that `rollback` can go
#                           back. Refuses if one repository lacks the tag or has local changes, before touching any of them.
#   rollback                put every repository back where it was before the last `apply`.
#   --dry-run               (update, create, apply, rollback) say what would be done, change nothing.
#   --yes                   do not ask for confirmation (apply and rollback ask you to type the name of the container).
#
# Settings (environment variables, as in sync-site.sh and target.sh): PROD_CONTAINER, STAGING_CONTAINER, STAGING_URL, TESTING_CONTAINER...
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=target.sh
. "$HERE/target.sh"
PROD_CONTAINER=${PROD_CONTAINER:-docker-e-vli-st_wordpress_1}
STAGING_CONTAINER=${STAGING_CONTAINER:-docker-sb-vli-st_wordpress-sb_1}
STAGING_URL=${STAGING_URL:-https://sb.vli.st}
PROD_URL=${PROD_URL:-https://e.vli.st}

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
say() { printf '\n== %s\n' "$*"; }

COMMAND=${1:-}
[ -z "$COMMAND" ] || shift
SITE="" DRY_RUN=0 YES=0 NUMBER="" BASELINE=0
for arg in "$@"; do
  case "$arg" in
    --site=prod | --site=staging | --site=testing) SITE=${arg#--site=} ;;
    --site=*) die "Unknown site: ${arg#--site=} (prod, staging or testing)." ;;
    --dry-run) DRY_RUN=1 ;;
    --yes) YES=1 ;;
    --baseline) BASELINE=1 ;;
    -h | --help) COMMAND=help ;;
    -*) die "Unknown option: $arg" ;;
    *) [ -z "$NUMBER" ] || die "Too many arguments."; NUMBER=$arg ;;
  esac
done
case "$COMMAND" in
  help | -h | --help) sed -n '2,/^set -euo/{/^set -euo/!p}' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
  status | update | create | apply | rollback) ;;
  *) die "Say what to do: status, update, create, apply <N> or rollback (see --help)." ;;
esac
[ -n "$SITE" ] || die "Say which site: --site=prod, --site=staging or --site=testing."
[ "$COMMAND" = apply ] || [ -z "$NUMBER" ] || die "Only apply takes a number."
if [ "$COMMAND" = apply ]; then
  [[ "$NUMBER" =~ ^[0-9]+$ ]] || die "apply takes the number of a rollout, for example: apply 3 --site=prod."
fi
[ "$BASELINE" -eq 0 ] || [ "$COMMAND/$SITE" = create/prod ] || die "--baseline only goes with: create --site=prod."
case "$COMMAND/$SITE" in
  update/prod) die "Production is never updated to the latest commit: apply a rollout." ;;
  create/prod) [ "$BASELINE" -eq 1 ] || die "Rollouts are created on staging, where they were rehearsed. To record what runs on production now: create --baseline --site=prod." ;;
  create/testing) die "Rollouts are created on staging, where they were rehearsed." ;;
esac

if [ "$SITE" = prod ]; then
  CONTAINER=$PROD_CONTAINER
else
  select_target "$SITE" || exit 2
  CONTAINER=$STAGING_CONTAINER
fi
[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" = "true" ] || die "Container $CONTAINER is not running."

# The clones the site mounts: the root of the repository on the host and the name in the site. What is mounted is often a directory of
# the clone (`<clone>/plugin` for a plugin whose repository holds more than the plugin), so the repository is the root of its work tree.
DIRS=() NAMES=()
while read -r source destination; do
  case "$destination" in
    /var/www/html/wp-content/plugins/* | /var/www/html/wp-content/themes/*) ;;
    *) continue ;;
  esac
  if top=$(git -C "$source" rev-parse --show-toplevel 2>/dev/null); then
    already=0
    for known in "${DIRS[@]+"${DIRS[@]}"}"; do [ "$known" != "$top" ] || already=1; done
    [ "$already" -eq 1 ] || { DIRS+=("$top"); NAMES+=("$(basename "$destination")"); }
  else
    echo "Not a git clone, left alone: $destination ($source)"
  fi
done < <(docker inspect -f '{{range .Mounts}}{{if eq .Type "bind"}}{{println .Source .Destination}}{{end}}{{end}}' "$CONTAINER")
[ "${#DIRS[@]}" -gt 0 ] || die "$CONTAINER mounts no git clone under wp-content/plugins or wp-content/themes."

g() { local i=$1; shift; git -C "${DIRS[$i]}" "$@"; }
default_branch() { # index -> the default branch of origin
  local ref
  ref=$(g "$1" symbolic-ref -q --short refs/remotes/origin/HEAD 2>/dev/null) && echo "${ref#origin/}" || echo main
}
current() { g "$1" symbolic-ref -q --short HEAD 2>/dev/null || echo "($(g "$1" describe --tags --always))"; }
dirty() { [ -n "$(g "$1" status --porcelain)" ]; }

need_clean() {
  local bad=() i
  for i in "${!DIRS[@]}"; do if dirty "$i"; then bad+=("${NAMES[$i]}"); fi; done
  [ "${#bad[@]}" -eq 0 ] || die "Local changes in: ${bad[*]}. Commit or discard them first; nothing was changed."
}
# Fetches the branches and the rollout tags of every repository; git's own message is shown when it fails. With `soft`, a failure is only a
# warning (status still shows the local state).
fetch_all() {
  local i out
  for i in "${!DIRS[@]}"; do
    # The branches, then only the rollout tags: other tags (a `nightly` tag that a workflow moves at every push, for example) are none of
    # our business and git refuses to update a tag that moved. A rollout tag that moved is an anomaly: git's refusal is shown.
    if ! out=$(g "$i" fetch --prune origin 2>&1 && g "$i" fetch origin 'refs/tags/rollout-*:refs/tags/rollout-*' 2>&1); then
      if [ "${1:-}" = soft ]; then echo "WARNING: git fetch failed in ${NAMES[$i]} (${DIRS[$i]}), showing the local state: $out" >&2
      else die "git fetch failed in ${NAMES[$i]} (${DIRS[$i]}): $out"; fi
    fi
  done
}
confirm() { # a message; asks for the name of the container
  [ "$YES" -eq 1 ] || [ "$DRY_RUN" -eq 1 ] || {
    echo
    echo "$1"
    read -r -p "Type the name of the container to go on [$CONTAINER]: " answer
    [ "$answer" = "$CONTAINER" ] || die "Not confirmed."
  }
}

case "$COMMAND" in
status)
  say "Rollout status of the $SITE site ($CONTAINER)"
  fetch_all soft
  width=10
  for name in "${NAMES[@]}"; do [ "${#name}" -le "$width" ] || width=${#name}; done
  for i in "${!DIRS[@]}"; do
    tags=$(g "$i" tag --points-at HEAD --list 'rollout-*' | paste -sd, -)
    upstream=$(g "$i" rev-list --left-right --count "HEAD...origin/$(default_branch "$i")" 2>/dev/null | awk '{print "ahead " $1 ", behind " $2 " of origin"}' || true)
    printf '%-*s  %-12s %s  tags: %s  %s%s\n' "$width" "${NAMES[$i]}" "$(current "$i")" "$(g "$i" rev-parse --short HEAD)" "${tags:-none}" "${upstream:-no origin branch}" \
      "$(dirty "$i" && echo '  LOCAL CHANGES' || true)"
  done
  ;;

update)
  need_clean
  fetch_all
  for i in "${!DIRS[@]}"; do
    branch=$(default_branch "$i")
    incoming=$(g "$i" rev-list --count "HEAD..origin/$branch")
    echo "${NAMES[$i]}: $(current "$i") -> $branch, $incoming new commit(s)"
    if [ "$DRY_RUN" -eq 1 ]; then g "$i" log --oneline "HEAD..origin/$branch" | sed 's/^/    /'; continue; fi
    g "$i" -c advice.detachedHead=false checkout -q "$branch"
    g "$i" pull -q --ff-only origin "$branch" || die "${NAMES[$i]}: cannot fast-forward $branch."
  done
  [ "$DRY_RUN" -eq 0 ] || echo "Dry run: nothing changed."
  ;;

create)
  need_clean
  fetch_all
  for i in "${!DIRS[@]}"; do
    [ -n "$(g "$i" branch -r --contains HEAD)" ] || die "${NAMES[$i]}: the commit $(g "$i" rev-parse --short HEAD) is not on GitHub. Push it first: a rollout must be reproducible."
  done
  last=0
  for i in "${!DIRS[@]}"; do
    while read -r tag; do
      n=${tag#rollout-}
      [[ "$n" =~ ^[0-9]+$ ]] && [ "$n" -gt "$last" ] && last=$n
    done < <(g "$i" tag --list 'rollout-*')
  done
  tag="rollout-$((last + 1))"
  where=$STAGING_URL
  [ "$BASELINE" -eq 0 ] || where="production ($CONTAINER), as it is"
  say "Rollout $tag on $where"
  for i in "${!DIRS[@]}"; do
    printf '%-24s %s  %s\n' "${NAMES[$i]}" "$(g "$i" rev-parse --short HEAD)" "$(g "$i" log -1 --format=%s)"
  done
  if [ "$DRY_RUN" -eq 1 ]; then echo "Dry run: nothing tagged."; exit 0; fi
  if [ "$BASELINE" -eq 1 ]; then message="Rollout ${tag#rollout-}, baseline: what runs on production, $(date -u +%Y-%m-%d)"
  else message="Rollout ${tag#rollout-}, rehearsed on $STAGING_URL, $(date -u +%Y-%m-%d)"; fi
  done_indexes=() failed=0
  for i in "${!DIRS[@]}"; do
    g "$i" tag -a "$tag" -m "$message"
    if g "$i" push -q origin "refs/tags/$tag"; then done_indexes+=("$i"); else
      echo "ERROR: cannot push $tag from ${NAMES[$i]}." >&2
      failed=1
      break
    fi
  done
  if [ "$failed" -eq 1 ]; then
    # Nothing is left half done: the tags that were made and pushed are taken back.
    for i in "${!DIRS[@]}"; do
      g "$i" tag -d "$tag" >/dev/null 2>&1 || true
    done
    for i in "${done_indexes[@]}"; do g "$i" push -q origin ":refs/tags/$tag" || echo "WARNING: $tag is still on GitHub for ${NAMES[$i]}: delete it by hand." >&2; done
    die "The rollout was not created."
  fi
  echo "Created and pushed $tag in ${#DIRS[@]} repositories. On production: rollout.sh apply ${tag#rollout-} --site=prod"
  ;;

apply)
  tag="rollout-$NUMBER"
  fetch_all
  missing=()
  for i in "${!DIRS[@]}"; do g "$i" rev-parse -q --verify "refs/tags/$tag^{commit}" >/dev/null || missing+=("${NAMES[$i]}"); done
  [ "${#missing[@]}" -eq 0 ] || die "$tag does not exist in: ${missing[*]}. Nothing was changed."
  need_clean
  say "$tag on the $SITE site ($CONTAINER)"
  for i in "${!DIRS[@]}"; do
    from=$(g "$i" rev-parse HEAD) to=$(g "$i" rev-parse "refs/tags/$tag^{commit}")
    if [ "$from" = "$to" ]; then printf '%-24s already at %s\n' "${NAMES[$i]}" "$tag"; else
      printf '%-24s %s -> %s (%s)\n' "${NAMES[$i]}" "$(g "$i" rev-parse --short "$from")" "$(g "$i" rev-parse --short "$to")" "$(current "$i")"
    fi
  done
  if [ "$DRY_RUN" -eq 1 ]; then echo "Dry run: nothing changed."; exit 0; fi
  confirm "This puts the plugins of $SITE ($CONTAINER) on $tag."
  for i in "${!DIRS[@]}"; do
    [ "$(g "$i" rev-parse HEAD)" != "$(g "$i" rev-parse "refs/tags/$tag^{commit}")" ] || continue
    g "$i" config --local rollout.previous "$(g "$i" symbolic-ref -q --short HEAD || g "$i" rev-parse HEAD)"
    g "$i" -c advice.detachedHead=false checkout -q "refs/tags/$tag"
  done
  echo "Done. Check the site and the plugin versions; go back with: rollout.sh rollback --site=$SITE"
  ;;

rollback)
  need_clean
  say "Rollback on the $SITE site ($CONTAINER)"
  for i in "${!DIRS[@]}"; do
    previous=$(g "$i" config --local --get rollout.previous 2>/dev/null || true)
    if [ -z "$previous" ]; then printf '%-24s nothing to go back to\n' "${NAMES[$i]}"; continue; fi
    printf '%-24s %s -> %s\n' "${NAMES[$i]}" "$(current "$i")" "$previous"
  done
  if [ "$DRY_RUN" -eq 1 ]; then echo "Dry run: nothing changed."; exit 0; fi
  confirm "This puts the plugins of $SITE ($CONTAINER) back where they were before the last apply."
  for i in "${!DIRS[@]}"; do
    previous=$(g "$i" config --local --get rollout.previous 2>/dev/null || true)
    [ -n "$previous" ] || continue
    g "$i" -c advice.detachedHead=false checkout -q "$previous"
    g "$i" config --local --unset rollout.previous
  done
  echo "Done."
  ;;
esac
