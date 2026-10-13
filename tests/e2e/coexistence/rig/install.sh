#!/usr/bin/env bash
# Installs (default) or removes (`remove`) the rig-only helper mu-plugin in the wp-env dev container.
# The wordpress container is written as root; wp-content/mu-plugins is not bind-mounted, so this survives `wp-env stop/start`
# but not a volume wipe — install again after one. `docker cp` into the container fails on a bind mount, hence the pipe.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
wordpress="${WOODEV_E2E_WP_CONTAINER:-$(docker ps --format '{{.Names}}' | grep -E '^wp-env-.*-wordpress-1$' | grep -v tests | head -n1)}"
target="/var/www/html/wp-content/mu-plugins/zz-y15-coexistence-helper.php"

if [ -z "$wordpress" ]; then
	echo "No wp-env dev wordpress container is running." >&2
	exit 1
fi

if [ "${1:-install}" = "remove" ]; then
	docker exec -u root "$wordpress" rm -f "$target"
	echo "removed $target"
else
	docker exec -u root -i "$wordpress" sh -c "cat > $target" < "$here/zz-y15-coexistence-helper.php"
	docker exec -u root "$wordpress" chmod 644 "$target"
	echo "installed $target in $wordpress"
fi
