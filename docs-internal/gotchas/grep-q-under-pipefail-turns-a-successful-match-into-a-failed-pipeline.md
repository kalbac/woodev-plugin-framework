# Gotcha: [tooling/shell] — `grep -q` under `set -o pipefail` turns a SUCCESSFUL match into a failed pipeline
> Tags: tooling, shell, bash, pipefail | Session: s136
> **Measured on:** macOS laptop, first run of `scripts/machine/setup-macos.sh` (13.09.2026). The
> mechanism is POSIX, not platform-specific — it needs only a producer that reacts to a closed pipe.

## What happens

`scripts/machine/setup-macos.sh` checks that the `wp` on PATH is the pinned wp-cli 2.12.0:

```sh
set -uo pipefail
if command -v wp > /dev/null && ! wp --version 2> /dev/null | grep -q '2.12.0'; then
	say "⚠ wp-cli on PATH is $( wp --version ) — …uninstall it…"
	record wp-cli MISMATCH
fi
```

wp-cli on the laptop **is** 2.12.0, and the script still printed the warning and recorded
`wp-cli MISMATCH` in its summary — advising the operator to uninstall the exact version the gate
wants. The message text was mangled too, because `$( wp --version )` re-runs the command without
redirecting stderr.

## Root cause

Two facts that are individually harmless:

1. `grep -q` exits **as soon as it matches** and closes its end of the pipe.
2. `set -o pipefail` makes a pipeline's status the **rightmost non-zero** status in it, not the last
   command's.

So the match — the success case — kills the producer. wp-cli notices the closed stdout and exits
**255**; `pipefail` promotes that to the pipeline's status; `!` negates it, and the branch fires.
Measured, in that order:

```text
set -o pipefail; wp --version 2>/dev/null | grep -q '2.12.0'  → 255   (matched!)
set +o pipefail; wp --version 2>/dev/null | grep -q '2.12.0'  → 0
set -o pipefail; wp --version 2>/dev/null | grep -c '2.12.0'  → 0     (grep -c reads to EOF)
set -o pipefail; wp --version 2>/dev/null | cat > /dev/null   → 0
```

⚠ **It is a RACE, so it is flaky, not merely wrong.** The failure needs the producer to still be
writing when grep quits. The same script's ext-sodium check — `php -m | grep -qi '^sodium$'` —
answered `ok` on the first run and `MISSING` on the second, on one machine minutes apart; six
identical runs of it gave **`255 255 0 0 0 0`**. A check that reports a present extension as absent
half the time is worse than one that is simply broken: it invites you to chase the wrong baseline.

That is also why such a line can pass for months and then "start failing" on a new machine — here
PHP 8.5 adds deprecation notices to wp-cli's stdout, which widens the window.

## Fix

Capture the output, then match the string — no pipe to break:

```sh
# ❌ wrong — a successful match can be reported as a failed pipeline
if ! wp --version 2> /dev/null | grep -q '2.12.0'; then …

# ✅ correct — nothing closes a pipe early, and the captured text is reused in the message
wp_version="$( wp --version 2> /dev/null | tail -1 )"
case "$wp_version" in
	*2.12.0*) say "wp-cli $wp_version" ;;
	*)        say "⚠ wp-cli on PATH is '$wp_version' — …"; record wp-cli MISMATCH ;;
esac
```

`grep -c … > /dev/null` also works (it reads to EOF), but the captured variable is better: it makes
the value available to the message instead of re-running the command.

Where the question has a direct answer, ask it without a pipe at all:

```sh
# ❌ wrong — flaky, and "MISSING" is the wrong answer half the time
if php -m | grep -qi '^sodium$'; then …

# ✅ correct — no pipe, and it asks PHP the actual question
if php -r 'exit( extension_loaded( "sodium" ) ? 0 : 1 );'; then …
```

⚠ **Do not "fix" this by dropping `pipefail`** — the rest of the script relies on it. The bug is the
early-closing consumer, not the option.

## Related

- [a-git-hook-committed-non-executable-is-silently-ignored-on-posix](a-git-hook-committed-non-executable-is-silently-ignored-on-posix.md) — the other shell trap where a green-looking run hides an inert check
- [npx-wp-env-installs-a-stub-package-not-wordpress-env](npx-wp-env-installs-a-stub-package-not-wordpress-env.md) — the second defect the same first run of `setup-macos.sh` surfaced
- [../wiki/two-machine-setup.md](../wiki/two-machine-setup.md) — the script this was measured in
