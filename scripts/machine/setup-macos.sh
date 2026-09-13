#!/usr/bin/env bash
# First-time (and repeatable) setup of this repository on the macOS laptop.
# Safe to re-run: every step checks before it acts, and the only destructive steps ask first.
#
#   scripts/machine/setup-macos.sh            # check, fix what is safe, ask before the rest
#   scripts/machine/setup-macos.sh --install  # also `brew install` missing tools
#   scripts/machine/setup-macos.sh --yes      # answer yes to every prompt
#
# Works whether the repo was COPIED from the Windows desktop or freshly CLONED; copy
# .machine-transfer/ (written by rig-state-export.sh on the other machine) into the repo root either
# way. Walkthrough and the why of every step: docs-internal/wiki/two-machine-setup.md.
set -uo pipefail
cd "$( dirname "$0" )/../.." || exit 1
. scripts/machine/lib.sh

INSTALL=0
YES=0
for a in "$@"; do
	case "$a" in
		--install) INSTALL=1 ;;
		--yes) YES=1 ;;
		*) die "unknown argument: $a" ;;
	esac
done

RESULTS=''
record() { RESULTS="${RESULTS}$1|$2\n"; }
ask() {
	[ "$YES" -eq 1 ] && return 0
	printf '%s [y/N] ' "$1"
	read -r reply
	[ "$reply" = 'y' ] || [ "$reply" = 'Y' ]
}

section() { printf '\n== %s\n' "$*"; }

# ---------------------------------------------------------------------------
section 'Platform'
[ "$( uname -s )" = 'Darwin' ] || say "not macOS ($( uname -s )) — this script is written for the laptop; continuing anyway"
say "$( uname -s ) $( uname -m )"

# ---------------------------------------------------------------------------
section 'Toolchain'
missing=''
command -v brew > /dev/null || die 'Homebrew is required: https://brew.sh'
command -v git > /dev/null || missing="$missing git"
command -v php > /dev/null || missing="$missing php"
command -v composer > /dev/null || missing="$missing composer"
command -v node > /dev/null || missing="$missing node@22"
command -v gh > /dev/null || missing="$missing gh"

if [ -n "$missing" ]; then
	if [ "$INSTALL" -eq 1 ]; then
		# shellcheck disable=SC2086
		brew install $missing || die "brew install failed:$missing"
		case "$missing" in *node@22*) say 'node@22 is keg-only: add "$(brew --prefix node@22)/bin" to PATH (e.g. in ~/.zprofile)' ;; esac
	else
		die "missing:$missing — re-run with --install, or: brew install$missing"
	fi
fi

php -r 'exit( version_compare( PHP_VERSION, "8.1", "<" ) ? 1 : 0 );' || die "PHP $( php -r 'echo PHP_VERSION;' ) is below 8.1"
say "PHP $( php -r 'echo PHP_VERSION;' )"
# The skipped-test baseline depends on sodium (gotcha the-skipped-count-is-dominated-by-whether-sodium-is-enabled).
# Ask PHP directly — `php -m | grep -q` is FLAKY under `set -o pipefail`: grep closes the pipe on the
# match, php dies with 255, pipefail promotes it, and a present extension is reported MISSING. Measured
# s136: six identical runs answered `255 255 0 0 0 0`, and two runs of this script disagreed about the
# same machine (gotcha grep-q-under-pipefail-turns-a-successful-match-into-a-failed-pipeline).
if php -r 'exit( extension_loaded( "sodium" ) ? 0 : 1 );'; then
	say 'ext-sodium: on'
	record sodium ok
else
	say '⚠ ext-sodium is OFF — the licensing tests will skip'
	record sodium MISSING
fi

node -e 'process.exit( Number( process.versions.node.split( "." )[ 0 ] ) < 22 ? 1 : 0 )' || die "node $( node -v ) — the repo needs >= 22 (.nvmrc)"
say "node $( node -v )"

# lint:i18n-sources prefers a `wp` on PATH and pins wp-cli 2.12.0; a newer brew wp-cli would change what
# the gate measures. Without one on PATH the gate copies the pinned phar out of the rig — which is right.
#
# Capture the version instead of piping it into `grep -q`: grep closes the pipe on the first match,
# wp-cli exits 255 on the broken pipe, and `set -o pipefail` then promotes that to the pipeline's
# status — so a SUCCESSFUL match read as a failure and this warned about the very version it wanted
# (measured on the laptop, s136). PHP 8.5 also prints deprecation notices on wp-cli's stdout, hence
# the last line rather than the whole output.
if command -v wp > /dev/null; then
	wp_version="$( wp --version 2> /dev/null | tail -1 )"
	case "$wp_version" in
		*2.12.0*) say "wp-cli $wp_version" ;;
		*)
			say "⚠ wp-cli on PATH is '$wp_version' — lint:i18n-sources pins 2.12.0; uninstall it (brew uninstall wp-cli) and let the gate use the rig's copy"
			record wp-cli MISMATCH
			;;
	esac
fi

docker info > /dev/null 2>&1 || die 'docker is not running — start Docker Desktop / OrbStack'
say "docker $( docker version --format '{{.Server.Version}} {{.Server.Arch}}' )"

# ---------------------------------------------------------------------------
section 'Git'
git config core.hooksPath .githooks
# A copy from Windows carries core.fileMode=false, which hides a lost exec bit on a hook
# (gotcha a-git-hook-committed-non-executable-is-silently-ignored-on-posix). macOS can see modes.
git config core.fileMode true
# lib.sh is SOURCED, never run — it is committed 100644, so chmod-ing it here dirties the tree the
# moment fileMode=true starts reporting the bit (measured on the laptop, s136).
chmod +x .githooks/* scripts/machine/rig-*.sh scripts/machine/setup-macos.sh 2> /dev/null
git worktree prune
say 'hooksPath=.githooks, fileMode=true, worktrees pruned'

if gh auth status > /dev/null 2>&1; then
	gh auth setup-git
	say 'gh authenticated; git credential helper set'
else
	say '⚠ gh is not logged in — run `gh auth login`, then `gh auth setup-git` (push hangs silently without it)'
	record gh-auth MISSING
fi

# A repo COPIED from Windows keeps CRLF in text files git normalises (text=auto). Bash scripts with
# CRLF die with $'\r': command not found. Re-checkout exactly those files, and only when they carry
# no real change.
crlf_files="$( git ls-files --eol | awk '$2 == "w/crlf" && $1 != "i/crlf" { sub( /^[^\t]*\t/, "" ); print }' )"
if [ -n "$crlf_files" ]; then
	n="$( printf '%s\n' "$crlf_files" | wc -l | tr -d ' ' )"
	dirty="$( git status --porcelain --untracked-files=no | wc -l | tr -d ' ' )"
	if [ "$dirty" != '0' ]; then
		say "⚠ $n files have CRLF from Windows, but the tree has uncommitted changes — commit or stash, then re-run"
		record line-endings SKIPPED
	elif ask "$n tracked files have Windows CRLF line endings. Re-checkout them with LF?"; then
		printf '%s\n' "$crlf_files" | while IFS= read -r f; do rm -f -- "$f"; done
		git checkout -- .
		say "re-checked out $n files"
		record line-endings fixed
	fi
else
	say 'line endings: clean'
fi

# ---------------------------------------------------------------------------
section 'Transfer bundle (.machine-transfer/)'
if [ -d "$TRANSFER" ]; then
	[ -f .wp-env.override.json ] || { [ -f "$TRANSFER/wp-env.override.json" ] && cp "$TRANSFER/wp-env.override.json" .wp-env.override.json && say 'restored .wp-env.override.json'; }
	[ -f composer.lock ] || { [ -f "$TRANSFER/composer.lock" ] && cp "$TRANSFER/composer.lock" composer.lock && say 'restored composer.lock'; }
	[ -d plugins-reference ] || { [ -f "$TRANSFER/plugins-reference.tar" ] && tar -xf - < "$TRANSFER/plugins-reference.tar" && say 'restored plugins-reference/'; }
else
	say '⚠ no .machine-transfer/ — the rig will start EMPTY and plugins-reference/ is missing (five contract tests skip)'
	record transfer-bundle MISSING
fi
[ -f .wp-env.override.json ] || say '⚠ no .wp-env.override.json — wp-env will use its default ports (8888/8889), not 8973/8974'

# ---------------------------------------------------------------------------
section 'Dependencies'
platform="$( uname -s )-$( uname -m )"
# node_modules copied from Windows holds win32 native binaries; a marker says which machine built it.
if [ "$( cat node_modules/.machine-platform 2> /dev/null )" != "$platform" ]; then
	say "node_modules was not built on $platform — reinstalling (npm ci)"
	rm -rf node_modules
	npm ci && printf '%s' "$platform" > node_modules/.machine-platform && record npm-ci ok || record npm-ci FAILED
else
	say 'node_modules: built on this platform'
fi
composer install --no-interaction && record composer ok || record composer FAILED
npx playwright install chromium > /dev/null && say 'playwright chromium ready' || record playwright FAILED

# ---------------------------------------------------------------------------
section 'Rig'
if [ -f "$TRANSFER/rig/dev-db.sql" ]; then
	if ask 'Start the rig and import the transferred state (replaces this machine'"'"'s dev DB)?'; then
		if [ "$YES" -eq 1 ]; then bash scripts/machine/rig-state-import.sh --yes; else bash scripts/machine/rig-state-import.sh; fi
		record rig-import "$?"
	fi
else
	npx @wordpress/env start && record wp-env ok || record wp-env FAILED
fi

# ---------------------------------------------------------------------------
section 'Gates (the CURRENT-STATE baselines are Windows measurements — compare SKIPPED, not only counts)'
run_gate() {
	local name="$1"
	shift
	say "$name: $*"
	if "$@" > "/tmp/woodev-gate-$name.log" 2>&1; then record "$name" ok; else record "$name" "FAILED (see /tmp/woodev-gate-$name.log)"; fi
}
rm -f .phpunit.result.cache
run_gate phpcs composer phpcs
run_gate phpstan vendor/bin/phpstan analyse --memory-limit=4G --no-progress
run_gate unit vendor/bin/phpunit --testsuite=Unit
run_gate jest npm run test:js
run_gate typecheck npm run typecheck
run_gate lint-docs npm run lint:docs
run_gate build npm run build
if [ -n "$( git status --porcelain -- woodev/assets/build )" ]; then record build-parity 'DIFF — the build on this machine does not match the committed bundles'; else record build-parity ok; fi
if TC="$( rig_container tests-cli )"; then
	run_gate integration docker exec -w /var/www/html/woodev-framework -e TEST_SUITE=integration "$TC" \
		sh -c 'rm -f .phpunit.result.cache; vendor/bin/phpunit --testsuite=Integration'
fi
tail -3 /tmp/woodev-gate-unit.log 2> /dev/null

# ---------------------------------------------------------------------------
section 'Summary'
printf "$RESULTS" | awk -F'|' '{ printf "  %-16s %s\n", $1, $2 }'
cat << 'EOF'

Not scriptable — do these by hand once (details: docs-internal/wiki/two-machine-setup.md):
  • Orca: add this folder as a repo; worktree base path is relative (.orca/worktrees) and carries over
  • Orca skills: orca skills install --skill orchestration --agent claude-code (and orca-cli, computer-use)
  • Claude Code: check /mcp shows serena, context7 and supermemory. Serena is the official plugin at
    USER scope and needs `uv`; MCP binds at SESSION START, so a server added now surfaces NEXT session
  • Codex: ~/.codex/config.toml model = "gpt-5.6-terra"
  • If the bundle carried ~/.claude rules (.machine-transfer/claude-global-instructions.md), MERGE
    them — never overwrite — then delete the bundle: it holds secrets and this repo is public
EOF
