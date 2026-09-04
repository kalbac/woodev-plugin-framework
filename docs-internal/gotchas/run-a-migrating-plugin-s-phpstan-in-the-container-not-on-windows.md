# Gotcha: [tooling/phpstan] — A migrating plugin's PHPStan takes 35 minutes on Windows and 84 seconds in the container
> Tags: tooling, phpstan, windows, docker, migration | Session: s116

## What happens

`vendor/bin/phpstan analyse` in a plugin that vendors the framework appears to hang. It produces no
progress output worth watching, occupies a PHP process for over half an hour, and — if two runs are
started because the first "looks stuck" — they contend and neither finishes. Killing them yields
`completed, exit 0` from a background runner, which reads like a passing gate and is not one.

Measured in s116 on `woocommerce-edostavka`, both runs from a cleared result cache, identical
config, identical verdict (`[OK] No errors`):

| | wall clock |
|---|---|
| Windows filesystem, `--memory-limit=4G` | **35 m 37 s** |
| Inside the wp-env container, `--memory-limit=2G` | **1 m 24 s** |

## Root cause

Not isolated, and this gotcha does not pretend otherwise. Two candidates were live at once and were
not separated:

1. **The analysed surface.** A migrating plugin's `phpstan.neon` carries `scanDirectories: woodev`
   so framework symbols resolve — that is ~99k lines walked for symbol discovery on top of the
   plugin's own. The framework's own PHPStan run, over 221 files with no such scan, finishes on
   Windows in about a minute, so the Windows filesystem is not slow at PHPStan per se.
2. **Host memory pressure.** Free RAM was **2.9 GB of 15.3** with two wp-env stacks running, while
   the Windows run was allowed 4G. Swapping would produce exactly this shape.

What IS ruled out: process contention (the 35-minute figure is a single process, the earlier
two-process attempt is separate) and a wrong verdict (both runs agree).

The remedy is the same whichever candidate dominates, which is why it is worth recording before the
cause is pinned.

## Fix

```bash
# ❌ Looks stuck, and a second attempt makes it worse
vendor/bin/phpstan analyse --memory-limit=4G

# ✅ Same config, same verdict, 25× faster — the container already mounts the plugin
C=<wp-env cli container>
MSYS_NO_PATHCONV=1 docker exec -w /var/www/html/wp-content/plugins/<plugin> "$C" \
  sh -c 'vendor/bin/phpstan analyse --memory-limit=2G --no-progress'
```

`MSYS_NO_PATHCONV=1` is required or Git Bash rewrites the `-w` path into a Windows one.

And the second half of the lesson: **a background task killed with `Stop-Process` reports
`completed` with exit code 0.** That is the runner reporting the kill, not the tool reporting a
pass. Never count it as a green gate — re-run and read the tool's own verdict.

## Related

- [phpstan-windows-parallel-worker-segfault](phpstan-windows-parallel-worker-segfault.md) — the other Windows PHPStan trap: at 2G the parallel worker dies and prints `Found 1 error`, which reads as a real failure
- [three-agents-is-the-concurrency-cap-on-this-machine](three-agents-is-the-concurrency-cap-on-this-machine.md) — the same 15.3 GB ceiling, and why tools fail here in ways that read as code defects
- [docker-cp-into-the-wp-env-container-fails-pipe-the-probe-instead](docker-cp-into-the-wp-env-container-fails-pipe-the-probe-instead.md) — the other half of driving work through this container
