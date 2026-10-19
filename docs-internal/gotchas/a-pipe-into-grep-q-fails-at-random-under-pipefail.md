# Gotcha: [build/ci] — a pipe into `grep -q` fails at random under `pipefail`
> Tags: bash, release, zip | Session: s188

`grep -q` exits on the first match without reading the rest of its input. If the writer (`printf "$entries"`,
`unzip -l`, `sed`) is still writing, it gets SIGPIPE (exit 141), and under `set -o pipefail` the WHOLE pipeline fails —
so a check that FOUND what it looked for reports failure. The CDEK release script's ZIP validation failed in 45 of 100
Linux runs this way ("the ZIP has no woocommerce-edostavka.php" while `unzip` proved it was there); on macOS it passed,
because the small listing fit the pipe buffer before grep exited. `… | head -n 1` has the same shape, but only bites when
the writer produces more than a pipe buffer (~64 KB) after the first line.

❌ **Wrong:**

```bash
set -euo pipefail
printf '%s\n' "$entries" | grep -q "^${slug}/${slug}.php$"
```

✅ **Correct:** let grep read a file (or drop `-q` so it consumes all input).

```bash
printf '%s\n' "$entries" > "$tmp/entries"
grep -qx "${slug}/${slug}.php" "$tmp/entries"
```

## Related

- [a-release-trigger-on-version-change-never-fires-for-a-version-already-on-the-branch](a-release-trigger-on-version-change-never-fires-for-a-version-already-on-the-branch.md)
- [../gotcha-index/build.md](../gotcha-index/build.md)
