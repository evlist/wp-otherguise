#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Rollout NNN: title. One sentence on what changes and what a visitor sees (often: nothing). The story, the risks and what was observed are
# in docs/rollouts/NNN-name.md. Copy this file to rollouts/NNN-name.sh and write the steps.
#
# Usage: rollouts/NNN-name.sh --site=staging [--no-validate] [--dry-run]
#        rollouts/NNN-name.sh --site=prod --rollout=N [--no-validate] [--yes] [--force]
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=../tools/staging/rollout-lib.sh
. "$HERE/../tools/staging/rollout-lib.sh"

rollout_init "NNN-name" "$@"
if [ "$SITE" = prod ]; then
  [[ "$ROLLOUT_NUMBER" =~ ^[0-9]+$ ]] || die "On production, say which rollout: --rollout=N (the tag made on staging)."
fi
rollout_begin

# The versions: the tag on production, the latest commits elsewhere.
if [ "$SITE" = prod ]; then
  step "Put the repositories on rollout-$ROLLOUT_NUMBER" rollout_sh apply "$ROLLOUT_NUMBER" --site=prod --yes
else
  step "Bring the repositories to the latest commits" rollout_sh update --site="$SITE"
fi

# What must be true before.
# check "description" command...

# Where we start from, to compare afterwards.
# note "title" command...

# The steps. Each must be safe to run twice.
# step "title" command...
# manual "what to do" "what to check"

# What must be true after.
# check "description" command...
# check_output "description" "expected" command...
# validate "what Eric has to look at"

rollout_end
