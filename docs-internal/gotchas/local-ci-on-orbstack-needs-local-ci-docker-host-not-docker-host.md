# gotcha: local-ci on OrbStack needs `LOCAL_CI_DOCKER_HOST`, and a set `DOCKER_HOST` is a hard error

**Namespace:** `[tooling/local-ci]` · **Discovered:** s203 (30.10.2026, Mac, OrbStack, run-local-ci via `npx -y`)

## What happens

1. With OrbStack the Docker socket is `~/.orbstack/run/docker.sock`; `/var/run/docker.sock` may be missing (the
   `docker` CLI still works through its `orbstack` context). local-ci looks at `/var/run/docker.sock` and dies before any
   job: «local-ci couldn't use a Docker socket at /var/run/docker.sock … missing or a dangling symlink».
2. Setting `DOCKER_HOST` to fix it is rejected: «DOCKER_HOST is no longer supported. Rename it to LOCAL_CI_DOCKER_HOST».

## Correct

```bash
env -u DOCKER_HOST LOCAL_CI_DOCKER_HOST=unix://$HOME/.orbstack/run/docker.sock \
  npx -y run-local-ci run -w .github/workflows/ci.yml --commit-status
```

Both s203 merge-evidence runs (edostavka#157, #158) were green 2/2 this way.

> **Token (s205):** `--commit-status` needs a GitHub token; export `LOCAL_CI_GITHUB_TOKEN` in the shell and never pass `--github-token` — the flag puts the token on argv: [local-ci-github-token-flag-puts-the-token-on-argv](local-ci-github-token-flag-puts-the-token-on-argv.md).

## Related

- [run-local-ci-requires-run-subcommand](run-local-ci-requires-run-subcommand.md)
- [npx-run-local-ci-in-a-fresh-terminal-waits-for-an-install-prompt](npx-run-local-ci-in-a-fresh-terminal-waits-for-an-install-prompt.md)
