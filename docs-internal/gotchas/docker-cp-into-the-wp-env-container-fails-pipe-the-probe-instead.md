# Gotcha: [rig/probes] — `docker cp` into the wp-env container fails; pipe the probe in instead
> Tags: rig, docker, wp-env, probes | Session: s106

## What happens

The documented way to run a rig probe is "`docker cp` it into the container's `/tmp`, then
`wp eval-file`". The copy fails:

```
$ docker cp probe.php de59f74e...-cli-1:/tmp/probe.php
Error response from daemon: mkdir /var/lib/docker/rootfs/overlayfs/cd5ba85f…/var/www/html/
wp-content/plugins/woodev-test-shipping-method/woodev: file exists

$ docker exec de59f74e...-cli-1 wp eval-file /tmp/probe.php
Error: '/tmp/probe.php' does not exist.
```

The error names a path deep inside the *mounted plugin tree* and has nothing to do with `/tmp` or
with the file being copied — `docker cp` walks the whole container filesystem to build its
destination, and trips over the bind-mounted `woodev` directory that the wp-env stack maps into
`wp-content/plugins/`. Nothing is wrong with the probe, the container, or the path.

The second line is the trap: `wp eval-file` reports a perfectly ordinary "does not exist", so a
session that did not read the first line diagnoses a wrong path and spends the next few minutes
fixing something that was never broken.

## Root cause

`docker cp` resolves the container's filesystem through the overlay driver and fails on a bind
mount whose target directory already exists in the lower layer. The wp-env stack always has at
least one such mount (the framework checkout under `wp-content/plugins/`), so on this rig
`docker cp` INTO the container is unreliable by construction, not intermittently.

`docker exec -i` does not touch the overlay at all — it writes through a process already running
inside the container.

## Fix

❌ Wrong — the documented-but-broken route:

```bash
docker cp "$SCRATCH/probe.php" "$C:/tmp/probe.php"
docker exec "$C" wp eval-file /tmp/probe.php
```

✅ Correct — pipe it through a shell already inside the container, and verify it landed:

```bash
MSYS_NO_PATHCONV=1 docker exec -i "$C" sh -c 'cat > /tmp/probe.php' < "$SCRATCH/probe.php"
MSYS_NO_PATHCONV=1 docker exec "$C" sh -c 'wc -c /tmp/probe.php'      # proof, not assumption
MSYS_NO_PATHCONV=1 docker exec "$C" wp eval-file /tmp/probe.php --user=1
MSYS_NO_PATHCONV=1 docker exec "$C" rm -f /tmp/probe.php              # probes never outlive the pass
```

`--user=N` matters whenever the probe touches anything user-scoped: without it wp-cli runs as
nobody, `is_user_logged_in()` is false, and a store that keeps its authoritative copy in user meta
answers as if it were empty.

## Related
- [wp-safe-remote-request-local-rig](wp-safe-remote-request-local-rig.md) — the other rig traps that
  make a working probe read as a broken feature
- [the-cdek-fixture-credentials-are-not-the-option-they-look-like](the-cdek-fixture-credentials-are-not-the-option-they-look-like.md)
  — same shape of failure from the other end: the probe runs, and measures the wrong thing
