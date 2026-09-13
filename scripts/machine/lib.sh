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
# wp-env derives the container prefix from a hash of the project's ABSOLUTE path, so it differs
# between machines (on the Windows desktop it is de59f74e…). Never hardcode it: find the cli
# container that mounts this framework, and build the other names from its prefix. Another wp-env
# project on the same machine (the licensing issuer) does not mount woodev-framework, so it is skipped.
rig_container() {
	local role="$1" c prefix=''

	for c in $( docker ps --format '{{.Names}}' | grep -E '^[0-9a-f]{32}-cli-1$' ); do
		if docker exec "$c" test -f /var/www/html/woodev-framework/woodev/class-plugin.php 2> /dev/null; then
			prefix="${c%-cli-1}"
			break
		fi
	done

	[ -n "$prefix" ] || return 1
	printf '%s-%s-1\n' "$prefix" "$role"
}
