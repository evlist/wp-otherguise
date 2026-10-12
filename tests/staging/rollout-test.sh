#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Tests of tools/staging/rollout.sh with real git repositories (a bare "GitHub" per plugin and one clone per site) and a stand-in for
# docker that only answers `inspect`. Nothing here touches a real container nor GitHub. Run: tests/staging/rollout-test.sh
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../../tools/staging/rollout.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
PASS=0 FAIL=0
check() { if [ "$2" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAIL=$((FAIL + 1)); echo "FAIL  $1"; fi; }
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null
export T

# Two plugins on "GitHub", and a clone of each for staging and for production, plus a mount that is not a git clone.
for p in plug-a plug-b; do
  git init -q --bare -b main "$T/github/$p.git"
  git clone -q "$T/github/$p.git" "$T/seed/$p" 2>/dev/null
  (cd "$T/seed/$p" && git checkout -q -b main && echo 1 >f && git add f && git commit -q -m "first" && git push -q origin main)
  for site in stg prod; do git clone -q "$T/github/$p.git" "$T/$site/$p"; done
done
mkdir -p "$T/stg/plain" "$T/prod/plain"

mkdir -p "$T/bin"
cat >"$T/bin/docker" <<'STUB'
#!/usr/bin/env bash
case "$1" in
  inspect)
    case "$*" in
      *Running*) echo true ;;
      *) case "$*" in *prod*) site=prod ;; *) site=stg ;; esac
         for p in plug-a plug-b; do echo "$T/$site/$p /var/www/html/wp-content/plugins/$p"; done
         echo "$T/$site/plain /var/www/html/wp-content/plugins/plain" ;;
    esac ;;
esac
STUB
chmod +x "$T/bin/docker"
export PATH="$T/bin:$PATH"
export PROD_CONTAINER=prod STAGING_CONTAINER=stg STAGING_URL=https://sb.test

ro() { bash "$SCRIPT" "$@"; }
tags() { git -C "$T/github/$1.git" tag --list 'rollout-*' | paste -sd, -; }
head_of() { git -C "$T/$1/$2" rev-parse --short HEAD; }
push_commit() { # plugin: a new commit on GitHub
  (cd "$T/seed/$1" && echo "$RANDOM" >f && git add f && git commit -q -m "more" && git push -q origin main)
}

# Guards.
ro >/dev/null 2>&1; check "needs a command" $((! $?))
ro status >/dev/null 2>&1; check "needs a site" $((! $?))
ro status --site=nowhere >/dev/null 2>&1; check "refuses an unknown site" $((! $?))
ro update --site=prod >/dev/null 2>&1; check "never updates production" $((! $?))
ro create --site=prod >/dev/null 2>&1; check "never creates a rollout on production" $((! $?))
ro create --site=testing >/dev/null 2>&1; check "never creates a rollout on testing" $((! $?))
ro apply --site=prod >/dev/null 2>&1; check "apply needs a number" $((! $?))
ro apply x --site=prod >/dev/null 2>&1; check "apply refuses a number that is not a number" $((! $?))

# Status.
ro status --site=staging >"$T/out" 2>&1; check "status runs" $?
grep -q "plug-a *main" "$T/out" && grep -q "plug-b" "$T/out"; check "status lists the clones" $?
grep -q "Not a git clone, left alone" "$T/out"; check "a mount that is not a git clone is left alone" $?

# Update.
push_commit plug-a
ro update --site=staging --dry-run >"$T/out" 2>&1; grep -q "plug-a: main -> main, 1 new commit" "$T/out"; check "update --dry-run shows the new commits" $?
[ "$(head_of stg plug-a)" != "$(git -C "$T/seed/plug-a" rev-parse --short HEAD)" ]; check "...and changes nothing" $?
ro update --site=staging >/dev/null 2>&1; [ "$(head_of stg plug-a)" = "$(git -C "$T/seed/plug-a" rev-parse --short HEAD)" ]; check "update brings staging to the latest commit" $?

# Create.
echo dirty >>"$T/stg/plug-b/f"
ro create --site=staging >/dev/null 2>&1; check "create refuses local changes" $((! $?)); [ -z "$(tags plug-a)" ]; check "...and tags nothing" $?
git -C "$T/stg/plug-b" checkout -q f
(cd "$T/stg/plug-b" && echo x >g && git add g && git commit -q -m "not pushed")
ro create --site=staging >/dev/null 2>&1; check "create refuses a commit that is not on GitHub" $((! $?)); [ -z "$(tags plug-a)" ]; check "...and tags nothing" $?
(cd "$T/stg/plug-b" && git reset -q --hard origin/main)
ro create --site=staging --dry-run >"$T/out" 2>&1; grep -q "rollout-1" "$T/out" && [ -z "$(tags plug-a)" ]; check "create --dry-run names the rollout and tags nothing" $?
ro create --site=staging >"$T/out" 2>&1; rc=$?; check "create succeeds" $rc
[ "$rc" -eq 0 ] || cat "$T/out"
[ "$(tags plug-a)" = "rollout-1" ] && [ "$(tags plug-b)" = "rollout-1" ]; check "create pushed rollout-1 to every repository" $?
[ "$(git -C "$T/github/plug-a.git" rev-parse 'rollout-1^{commit}' | cut -c1-7)" = "$(head_of stg plug-a)" ]; check "the tag is on the commit that runs on staging" $?
git -C "$T/stg/plug-a" tag -n1 rollout-1 | grep -q "rehearsed on https://sb.test"; check "the tag says where it was rehearsed" $?
push_commit plug-b; ro update --site=staging >/dev/null 2>&1; ro create --site=staging >/dev/null 2>&1
[ "$(tags plug-a)" = "rollout-1,rollout-2" ]; check "the next rollout is numbered 2 in every repository" $?

# Apply on production.
ro apply 1 --site=prod --dry-run >"$T/out" 2>&1; rc=$?; check "apply --dry-run succeeds" $rc
git -C "$T/prod/plug-a" symbolic-ref -q HEAD >/dev/null; check "...and changes nothing" $?
ro apply 9 --site=prod --yes >/dev/null 2>&1; check "apply refuses a rollout that does not exist" $((! $?))
git -C "$T/prod/plug-a" symbolic-ref -q HEAD >/dev/null; check "...and changes nothing" $?
before=$(head_of prod plug-b)
echo dirty >>"$T/prod/plug-b/f"
ro apply 2 --site=prod --yes >/dev/null 2>&1; check "apply refuses local changes" $((! $?)); git -C "$T/prod/plug-a" symbolic-ref -q HEAD >/dev/null; check "...in no repository, the clean ones included" $?
git -C "$T/prod/plug-b" checkout -q f
echo wrong | ro apply 2 --site=prod >/dev/null 2>&1; check "apply asks for the name of the container" $((! $?))
ro apply 2 --site=prod --yes >"$T/out" 2>&1; rc=$?; check "apply succeeds" $rc
[ "$(head_of prod plug-a)" = "$(git -C "$T/github/plug-a.git" rev-parse --short 'rollout-2^{commit}')" ] && [ "$(head_of prod plug-b)" = "$(git -C "$T/github/plug-b.git" rev-parse --short 'rollout-2^{commit}')" ]; check "production is on the commits of rollout-2" $?
! git -C "$T/prod/plug-a" symbolic-ref -q HEAD >/dev/null; check "...as a detached head" $?
# New commits on GitHub after the rollout do not reach production.
push_commit plug-a; ro status --site=prod >"$T/out" 2>&1
grep -q "plug-a .*rollout-2" "$T/out"; check "status shows the rollout tag on production" $?

# Rollback.
ro rollback --site=prod --dry-run >"$T/out" 2>&1; grep -q "plug-a" "$T/out"; check "rollback --dry-run lists the repositories" $?
ro rollback --site=prod --yes >/dev/null 2>&1; rc=$?; check "rollback succeeds" $rc
git -C "$T/prod/plug-a" symbolic-ref -q --short HEAD | grep -q main; check "production is back on its branch" $?
[ "$(head_of prod plug-b)" = "$before" ]; check "...at the commit it was at" $?
ro rollback --site=prod --yes >"$T/out" 2>&1; grep -q "nothing to go back to" "$T/out"; check "a second rollback has nothing to go back to" $?

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
