# A stale plugin checkout on the rig shows a defect that `main` already fixed — pull every plugin checkout before a rig pass

**Namespace:** `[rig/checkouts]` · **Discovered:** s240 (21.11.2026)

## What happens

On the s240 rig pass the Ozon Seller orders page carried an empty «Ozon Доставка» box. The Ozon Доставка checkout sat at `05db9df`, before ozd PR #102 (its box only on its own orders), so a fixed bug looked live. Branches left from earlier checks (`s238-check-114`) do the same.

## Root cause

A rig checkout is a working tree, not a mirror of `main`; nothing updates it. `sync-framework.sh` only refreshes `woodev/` inside a plugin, not the plugin itself.

## Fix

- ❌ Look at the rig and file or fix what it shows.
- ✅ Before a rig pass: `git -C <plugin> fetch && git status -sb` for every active plugin, pull `main`, delete spent check branches, print `Woodev_Plugin::VERSION`; then look.

## Related

- [an-unbumped-plugin-on-the-rig-pins-an-older-framework-for-every-plugin](an-unbumped-plugin-on-the-rig-pins-an-older-framework-for-every-plugin.md) — the same family, the framework version
- [s240 session](../sessions/s240.md)
