#!/usr/bin/env bash
# Export the dev rig state that git does not carry into .machine-transfer/ (gitignored), so the
# other machine can restore the same rig. Run on the machine you are LEAVING.
#
#   scripts/machine/rig-state-export.sh
#
# What it takes: the dev database (orders, options, zones, popular settlements), the container-only
# mu-plugins, the WOODEV_* constants set inside the container's wp-config.php, the gitignored
# .wp-env.override.json and composer.lock, and plugins-reference/.
# The tests environment is NOT exported: it is option-free by design and rebuilt by the suite.
set -euo pipefail
. "$( dirname "$0" )/lib.sh"

CLI="$( rig_container cli )" || die 'the dev rig is not running — start it with: npx wp-env start'
say "dev rig container: $CLI"

mkdir -p "$TRANSFER/rig"

say 'exporting the dev database'
docker exec "$CLI" wp db export - --add-drop-table > "$TRANSFER/rig/dev-db.sql"

say 'exporting wp-content/mu-plugins'
docker exec "$CLI" tar -C /var/www/html/wp-content -cf - mu-plugins > "$TRANSFER/rig/mu-plugins.tar"

say 'exporting WOODEV_* constants from wp-config.php'
docker exec "$CLI" wp config list --format=json |
	node -e '
		let d = "";
		process.stdin.on( "data", ( c ) => ( d += c ) ).on( "end", () => {
			const rows = JSON.parse( d ).filter( ( r ) => /^WOODEV_/.test( r.name ) );
			process.stdout.write( JSON.stringify( rows.map( ( r ) => ( { name: r.name, value: r.value } ) ), null, 2 ) );
		} );
	' > "$TRANSFER/rig/wp-config-constants.json"

for f in .wp-env.override.json composer.lock; do
	if [ -f "$ROOT/$f" ]; then
		cp "$ROOT/$f" "$TRANSFER/${f#.}"
		say "copied $f"
	fi
done

if [ -d "$ROOT/plugins-reference" ]; then
	say 'packing plugins-reference/'
	# Never `tar -C D:/…` or `-f D:/…`: GNU tar in Git Bash reads "D:" as a remote host. cd plus a
	# shell redirect keeps every path out of tar's argv, and works the same with BSD tar on macOS.
	( cd "$ROOT" && tar -cf - plugins-reference ) > "$TRANSFER/plugins-reference.tar"
fi

say 'writing the manifest'
{
	printf 'exported_at=%s\n' "$( date -u +%Y-%m-%dT%H:%M:%SZ )"
	printf 'git_head=%s\n' "$( git -C "$ROOT" rev-parse HEAD )"
	printf 'git_branch=%s\n' "$( git -C "$ROOT" branch --show-current )"
	printf 'wp=%s\n' "$( docker exec "$CLI" wp core version )"
	printf 'wc=%s\n' "$( docker exec "$CLI" wp eval 'echo defined( "WC_VERSION" ) ? WC_VERSION : "?";' )"
	printf 'orders=%s\n' "$( docker exec "$CLI" wp eval 'echo count( wc_get_orders( [ "limit" => -1, "return" => "ids" ] ) );' )"
	printf 'active_plugins=%s\n' "$( docker exec "$CLI" wp plugin list --status=active --field=name | tr '\n' ' ' )"
} > "$TRANSFER/rig/manifest.txt"

cat "$TRANSFER/rig/manifest.txt"
say "done → $TRANSFER (gitignored; it contains secrets — never commit or share it)"
