# s219 overnight plan — «Доставка в городе» + release-ready «Стоимость доставки за расстояние» 2.0.0

> Written at the end of s218 (05.11.2026). Operator-facing words in Russian are kept as he said them.

## Mode

Autonomous overnight (`orca-team` §9): no questions to the operator; a fork → Инбокс card + reversible choice. Goal: plugin
2.0.0 RELEASE-READY with «Доставка в городе». **Boundary:** no tag / `gh release`, no EDD, no posts, no mailing; framework
2.0.26 release PR #1606 stays a draft (VERSION merge after his morning look). Morning: his rig look → release → schedule the
mailing and the TG post.

## Binding decisions (do not reopen)

- Spec `docs-internal/specs/2026-11-05-range-shipping-city-mode.md` — exists only on fw PR #1611 branch `kalbac/spec-city-mode` (r4 48c9cb11; merge the PR with the spec once r5 passes): K6–K10, K11′. The city list is NOT a delivery
  boundary (the range method's grid is); it sets the checkout locality choice and the provider's suggestion scope; what a listed
  place covers is the PROVIDER's decision (measured: DaData «Москва» filter → Коммунарка yes, Зеленоград no). One
  provider-agnostic rule. Sub-tab «Доставка в городе»; only the delivery address; no licence → off + admin notice; DaData
  down → the saved list keeps working.
- r5 inputs (operator): the CDEK location provider drops the dictionary row's `fias_guid` (`woocommerce-edostavka`
  `includes/location/class-cdek-location-provider.php:22`; the adapter already uses it, `class-cdek-location-adapter.php:206`) —
  keep `fias_guid` + coordinates in the CDEK record and scope DaData suggestions by it (city OR settlement fias id); **FIAS is
  Russia-only** — elsewhere DaData has OSM ids / CDEK rows carry no fias_guid → country + name scoping.
- Framework 2.0.26 also carries #1591 (manual status refresh TypeError; closes yandex#159 on rebuild) and #1592 (zones
  «Undefined array key RU» after CDEK→DaData) — operator 05.11.2026.
- Road coefficient default 1,3 (no bands); probe window 1–15 km; Google = Routes API computeRouteMatrix; Mapbox needs no card,
  Google needs a non-RU card (texts already say so).

## Steps

1. Spec r5 (Sonnet or Opus): apply the r5 inputs; Codex gpt-6.1-sol critic; ≤ 2 rounds, then implement what is settled.
2. Cards from the spec: framework F2 (PHP) + F3 (checkout clients, classic + block), plugin C14 (settings «Доставка в городе»,
   publication, key-removed fallback) + C15 (fixtures, rig A1–A12, docs), plus an edostavka card for the CDEK `fias_guid`
   record field (CDEK plugin release is NOT tonight — only the PR, parked). Issues in Russian on the boards with «Приоритет».
3. Framework #1591 and #1592 (Sonnet each; Codex luna critic; public CI).
4. Waves: F2 ∥ #1591 ∥ #1592 → F3 → C14 → C15 (rig). Every UI screen: coordinator Playwright sweep at 1440 and 1280 BEFORE
   calling it done (memory `i-look-at-every-admin-screen-before-saying-ready`); critic of another lineage on every PR.
5. Release prep: framework release PR #1606 refreshed (2.0.26 = everything merged); plugin: `framework_version` 2.0.26 +
   `scripts/sync-framework.sh` in one commit against the merged framework main (not merged into plugin main before the
   framework VERSION merge — park it as a PR), changelog «Requires Woodev Framework 2.0.26+» + city mode; gallery re-shot with
   the new tab; texts updated (card, docs, changelog, blog, TG) — placeholder for his 2 referral links stays.
6. Session save (full protocol) with the morning list for him.

## Traps (s218)

- Workers wrote stray copies into the framework PRIMARY checkout twice — every brief: «Orca worktree only; NEVER write into
  /Users/maksimmartirosov/Projects/woodev-plugin-framework itself». The rig resolves the framework from
  `wp-content/plugins/woocommerce-order-tracking-number/woodev` — mirror there under the rig lock with `COPYFILE_DISABLE=1 tar`.
- Keys in `.env` may be quoted: strip quotes; put keys on the rig only via the admin form (Playwright), never echo.
- Yandex trial: 100 req/day until 12.11.2026; 2GIS demo key: pairs > 50 km → 403 (handled as no_route).
- A watchdog never with `&`; one process for all handles; API «Connection lost» → nudge the worker, restart the watchdog after
  ~90 s (the old error text stays in the buffer).

## Related

- [v2 spec](../specs/2026-11-05-range-shipping-v2-design.md)
- [session s218](../sessions/s218.md)
