#!/usr/bin/env bash
# Print the name of this project's wp-env container for a role, on whichever machine you are on.
#
#   scripts/machine/rig-container.sh cli          # dev rig (:8973) — carries the options
#   scripts/machine/rig-container.sh tests-cli    # test stack (:8974) — option-free, runs integration
#
# The container prefix is a hash of the project's absolute path, so it is different on the Windows
# desktop and the macOS laptop. Use this instead of pasting a prefix into a command.
set -euo pipefail
. "$( dirname "$0" )/lib.sh"
[ $# -eq 1 ] || die 'usage: rig-container.sh <cli|tests-cli|wordpress|tests-wordpress|mysql|tests-mysql>'
rig_container "$1" || die 'this project'"'"'s wp-env is not running — npx wp-env start'
