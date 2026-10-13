# gotcha: a wp-cli `memory_get_peak_usage()` is not the storefront request's peak

**Namespace:** `[perf/memory]`
**Discovered:** s176 (2026-10-13), MEM1262 profiling of #1262

## What happens

Y15 measured «both carriers 262 MB, each alone ~140 MB» with `memory_get_peak_usage(true)` in wp-cli and concluded a 128 MB shop
fatals. The real cold web request (`/?add-to-cart=12`, same rig, caches cleared) peaked at **130 MB**: the CLI process starts at a
~128–140 MB baseline the web request does not have. The difference was real anyway (130 > 128 still fatals), but the attribution
from CLI numbers pointed at the framework's log redaction (#1265), which saved only 1–2 MB.

The real consumer was the Yandex plugin's Moscow point list (4.5 MB body): +48 MB framework stdClass decode, +45 MB a second
assoc decode in the plugin, +22 MB slim rows. Fixed in yandex#56 (decode once): 130 → 85 MB.

## ✅ Correct

Measure the web request stage by stage (a temporary mu-plugin logging `memory_get_usage()` at hooks), or subtract the CLI
baseline taken right after bootstrap. Attribute Δ per stage before choosing which repo to fix.

## Related

- [the-skipped-count-is-dominated-by-whether-sodium-is-enabled](the-skipped-count-is-dominated-by-whether-sodium-is-enabled.md) — another environment-shaped number
- Session: [../sessions/s176.md](../sessions/s176.md)
