#!/usr/bin/env bash
# Restore the dev rig from .machine-transfer/ (written by rig-state-export.sh on the other machine).
# Run on the machine you are ARRIVING at, from the repo root.
#
#   scripts/machine/rig-state-import.sh          # asks before replacing the database
#   scripts/machine/rig-state-import.sh --yes    # no prompt
#
# ⚠ It REPLACES this machine's dev rig database. Export this machine's state first if it holds
# anything the other one does not.
set -euo pipefail
. "$( dirname "$0" )/lib.sh"

ASSUME_YES=0
[ "${1:-}" = '--yes' ] && ASSUME_YES=1

[ -f "$TRANSFER/rig/dev-db.sql" ] || die "no export found at $TRANSFER/rig — run rig-state-export.sh on the other machine and copy the folder"
cat "$TRANSFER/rig/manifest.txt"

# The gitignored files go back first: .wp-env.override.json decides the ports and the WP core pin.
if [ -f "$TRANSFER/wp-env.override.json" ]; then
	if [ ! -f "$ROOT/.wp-env.override.json" ]; then
		cp "$TRANSFER/wp-env.override.json" "$ROOT/.wp-env.override.json"
		say 'restored .wp-env.override.json'
	elif ! cmp -s "$TRANSFER/wp-env.override.json" "$ROOT/.wp-env.override.json"; then
		say '.wp-env.override.json differs from the export — kept THIS machine'"'"'s copy; diff them if the rig misbehaves'
	fi
fi
if [ -f "$TRANSFER/composer.lock" ] && [ ! -f "$ROOT/composer.lock" ]; then
	cp "$TRANSFER/composer.lock" "$ROOT/composer.lock"
	say 'restored composer.lock'
fi
if [ -f "$TRANSFER/plugins-reference.tar" ] && [ ! -d "$ROOT/plugins-reference" ]; then
	( cd "$ROOT" && tar -xf - ) < "$TRANSFER/plugins-reference.tar"
	say 'restored plugins-reference/'
fi

CLI="$( rig_container cli )" || {
	say 'dev rig not running — starting it (first start downloads images; minutes)'
	( cd "$ROOT" && npx @wordpress/env start )
	CLI="$( rig_container cli )" || die 'the rig did not come up — check docker and npx @wordpress/env start output'
}
say "dev rig container: $CLI"

if [ "$ASSUME_YES" -ne 1 ]; then
	printf 'Replace the dev rig database on THIS machine with the export? [y/N] '
	read -r answer
	[ "$answer" = 'y' ] || [ "$answer" = 'Y' ] || die 'aborted, nothing changed'
fi

say 'importing the database'
docker exec -i "$CLI" wp db import - < "$TRANSFER/rig/dev-db.sql"

say 'restoring mu-plugins'
docker exec -i -u root "$CLI" tar -C /var/www/html/wp-content -xf - < "$TRANSFER/rig/mu-plugins.tar"

say 'restoring WOODEV_* constants'
node -e '
	const rows = JSON.parse( require( "fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	for ( const r of rows ) {
		const raw = typeof r.value !== "string";
		// NUL-separated so a value can never be split or re-quoted by the shell.
		process.stdout.write( [ r.name, raw ? JSON.stringify( r.value ) : r.value, raw ? "raw" : "str" ].join( "\t" ) + "\0" );
	}
' "$TRANSFER/rig/wp-config-constants.json" |
	while IFS=$'\t' read -r -d '' name value kind; do
		if [ "$kind" = 'raw' ]; then
			docker exec "$CLI" wp config set "$name" "$value" --type=constant --raw --quiet
		else
			docker exec "$CLI" wp config set "$name" "$value" --type=constant --quiet
		fi
	done

docker exec "$CLI" wp cache flush --quiet || true
docker exec "$CLI" wp rewrite flush --quiet || true

say 'verifying against the manifest'
printf '  orders:  export=%s  now=%s\n' \
	"$( grep '^orders=' "$TRANSFER/rig/manifest.txt" | cut -d= -f2 )" \
	"$( docker exec "$CLI" wp eval 'echo count( wc_get_orders( [ "limit" => -1, "return" => "ids" ] ) );' )"
printf '  wp:      export=%s  now=%s\n' \
	"$( grep '^wp=' "$TRANSFER/rig/manifest.txt" | cut -d= -f2 )" \
	"$( docker exec "$CLI" wp core version )"
say 'done. Open http://localhost:8973/wp-admin (admin / password) and look before trusting it.'
