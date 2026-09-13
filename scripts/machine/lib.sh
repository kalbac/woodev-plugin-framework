#!/usr/bin/env bash
# Shared helpers for the two-machine scripts (Windows Git Bash and macOS).
# Sourced, never run. See docs-internal/wiki/two-machine-setup.md.

# Git Bash rewrites /var/... arguments into Windows paths before docker sees them.
# Harmless on macOS, required on Windows.
export MSYS_NO_PATHCONV=1

ROOT="$( git rev-parse --show-toplevel )"
# Gitignored. It holds secrets (rig DB, wp-config constants) and must never be committed:
# the repository is public.
TRANSFER="${WOODEV_TRANSFER_DIR:-$ROOT/.machine-transfer}"

die() {
	printf '✗ %s\n' "$*" >&2
	exit 1
}

say() {
	printf '• %s\n' "$*"
}

# Print the name of one of THIS project's wp-env containers: cli | tests-cli | wordpress |
# tests-wordpress | mysql | tests-mysql.
#
# wp-env derives the container prefix from the project's ABSOLUTE path, so it differs between
# machines. It also CHANGED SHAPE between wp-env versions — a bare 32-char hash on the Windows
# desktop (de59f74e…), `wp-env-<project-dir>-<8 hex>` on the laptop's 11.15.0 — so matching the old
# shape found nothing and every rig script died with "wp-env is not running" (measured s136).
# Never hardcode either: take any `*-cli-1` container, keep the one that MOUNTS this framework, and
# build the other names from its prefix. Another wp-env project on the same machine (the licensing
# issuer) does not mount woodev-framework, so it is skipped. `-tests-cli-1` is excluded by name —
# it is a different role, not a different project.
rig_container() {
	local role="$1" c prefix=''

	for c in $( docker ps --format '{{.Names}}' | grep -E -- '-cli-1$' | grep -v -E -- '-tests-cli-1$' ); do
		if docker exec "$c" test -f /var/www/html/woodev-framework/woodev/class-plugin.php 2> /dev/null; then
			prefix="${c%-cli-1}"
			break
		fi
	done

	[ -n "$prefix" ] || return 1
	printf '%s-%s-1\n' "$prefix" "$role"
}
