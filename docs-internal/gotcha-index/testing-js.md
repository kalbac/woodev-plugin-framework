# Gotcha index — [testing/js] JavaScript testing pitfalls

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [testing/js] **`npm run test:js` is ONE of the FIVE commands CI's `JS Tests` job runs — the other four gate generated artefacts and the TypeScript-by-default rule, so a brand-new `.js` page is green locally and red in CI. `ts-baseline.txt` is for migrations, not an escape hatch.** → [npm-run-test-js-is-not-the-whole-js-gate](../gotchas/npm-run-test-js-is-not-the-whole-js-gate.md) (s125)
- [testing/js] **A CLOSED custom select holds none of its options — a `queryByText(...).toBeNull()` against it passes whatever the option set is.** → [a-closed-custom-select-renders-no-options](../gotchas/a-closed-custom-select-renders-no-options.md) (s88)
- [testing/js] **PowerShell drops `--roots` from the documented jest command.** → [powershell-drops-the-roots-flag-from-the-jest-command](../gotchas/powershell-drops-the-roots-flag-from-the-jest-command.md) (s73)
- [testing/js] **`npx jest` is not how this project runs JS tests — it silently loses jsdom.** → [npx-jest-bypasses-wp-scripts-jsdom](../gotchas/npx-jest-bypasses-wp-scripts-jsdom.md)
- [testing/js] **A local jest run used to count every agent worktree nested inside the repo — fixed by `jest-unit.config.js` scoping `roots`.** → [jest-scans-agent-worktrees-inside-the-repo](../gotchas/jest-scans-agent-worktrees-inside-the-repo.md) (s55, fixed s107/#188)
- [testing/js] **`jest.resetModules()` gives a fresh module, not a fresh `document.body` — zombie listeners keep answering.** → [jest-resetmodules-leaves-listeners-on-the-surviving-body](../gotchas/jest-resetmodules-leaves-listeners-on-the-surviving-body.md) (s70)
- [testing/js] **A test that advances the WHOLE interval does not pin the delay — it passes for 0 too.** → [advancing-the-whole-interval-does-not-pin-a-delay](../gotchas/advancing-the-whole-interval-does-not-pin-a-delay.md) (s64)
- [testing/js] **`toEqual( [] )` against a "was not called" recorder can pass while the call happened.** → [jest-toequal-empty-array-ignores-undefined](../gotchas/jest-toequal-empty-array-ignores-undefined.md) (s52)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
