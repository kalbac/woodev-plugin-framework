# Gotcha: [rig/wp-env] — after a host directory under a nested mount is recreated, the wp-env containers see an EMPTY path until restarted
> Tags: wp-env, docker, virtiofs, orbstack, integration | Session: s222

The rig mounts the framework checkout and, nested inside it, `tests/_fixtures/woodev-test-plugin/woodev` (and the plugins' `woodev/`).
When the host recreates such a directory (a sync script, a branch switch), the running container keeps the stale nested mount and the path
reads as missing. Symptoms: every rig page fatals (s222, a worker fixed it with `docker restart` of the wordpress + cli containers), and the
integration suite dies in bootstrap with `Failed opening required '…/tests/_fixtures/woodev-test-plugin/woodev/class-plugin.php'`, which reads
like a broken fixture and is not one.

❌ **Wrong:** debugging the fixture, re-running composer, or rebuilding wp-env.

✅ **Correct:**

```sh
C="$(scripts/machine/rig-container.sh tests-cli)"
docker exec "$C" ls /var/www/html/woodev-framework/tests/_fixtures/woodev-test-plugin/woodev   # "No such file" = stale mount
docker restart "$C"    # same for the wordpress / cli containers when rig pages fatal
```

## Related

- [wpenv-windows-gitbash-path-mangling.md](wpenv-windows-gitbash-path-mangling.md) — the integration command itself
- [../gotcha-index/rig.md](../gotcha-index/rig.md) — rig index
