# An unbumped plugin on the rig pins an older framework for every plugin

**Namespace:** `[rig/bootstrap]`
**Discovered:** 2026-11-16 (s233, framework 2.1.9 rig regression)

## The trap

The loader picks the copy with the highest declared `framework_version` among ALL active plugins. The rig mounts the
framework primary into the carrier plugins, but Woodev plugins that are not part of the wave stay active too.

On 16.11.2026 Ozon Seller declared `2.1.8` while CDEK / Yandex / Russian Post declared `2.0.28`. After framework 2.1.9 was
merged, the rig still ran **2.1.8** from `woocommerce-ozon-seller/woodev`: `Woodev_Plugin::VERSION` was 2.1.8, the new
`Guest_Access_Check` class was missing, and the connection check still called `/test`. The first full e2e run tested the
old framework and looked like a mostly-green regression.

## Wrong

Run the regression right after the release merge and trust the result because the primary checkout is on the new `main`.

## Correct

Read the loaded version first, and make the plugins under test win:

```bash
wp eval 'echo Woodev_Plugin::VERSION, " ", ( new ReflectionClass( "Woodev_Plugin" ) )->getFileName(), "\n";'
```

If another plugin pins a higher version, either bump that plugin's `framework_version` (its own PR) or deactivate it for
the run (`wp plugin deactivate woocommerce-ozon-seller`) and re-activate it afterwards. A plugin left behind after a
framework release is also a release task: bump it, or the next rig run is wrong again.

## How to see it

The coexistence e2e test 04 («winner is the newer declared copy») fails with the old version in its message.

## Related

- [the-rig-loads-the-first-registered-framework-copy-when-versions-tie](the-rig-loads-the-first-registered-framework-copy-when-versions-tie.md)
- [sync-framework-while-the-primary-is-on-the-release-branch-bumps-the-plugin-pin](sync-framework-while-the-primary-is-on-the-release-branch-bumps-the-plugin-pin.md)
