#!/usr/bin/env sh
#
# Regenerates Composer's classmap when a git operation changed framework PHP files.
# Shared by the `post-merge` and `post-checkout` hooks; not a hook itself.
#
# WHY THIS EXISTS. `vendor/` is gitignored, so `vendor/composer/autoload_classmap.php`
# is a purely LOCAL build artifact: git will never update it, because for git it is not
# a build step at all. Pull a branch that ADDED a framework class and the snapshot on
# disk still predates it, so `ClassMapCompletenessTest` fails in a tree where nothing is
# actually broken. CI never sees this — it installs dependencies from scratch every run.
#
# It cost s121 twice (a critic in its worktree, the coordinator in the main checkout) and
# then became the FIRST line of the next session's handoff, which is the most expensive
# place a two-second command can live. Issue #802.
#
# Usage: refresh-composer-autoload.sh <old-rev> <new-rev>
set -e

old="$1"
new="$2"

# No composer, no work to do — never fail the git operation over it.
command -v composer >/dev/null 2>&1 || exit 0

[ -n "$old" ] && [ -n "$new" ] || exit 0
[ "$old" = "$new" ] && exit 0

# Only framework sources can move a class. A docs- or test-only change cannot, and
# regenerating for those would put two seconds on every branch switch for nothing.
changed=$(git diff --name-only "$old" "$new" -- 'woodev/**/*.php' 'woodev/*.php' 'composer.json' 2>/dev/null || true)

[ -n "$changed" ] || exit 0

composer dump-autoload -q >/dev/null 2>&1 || true

exit 0
