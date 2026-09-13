# Gotcha index — [shipping/location] Location provider layer

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [shipping/location] **A fixture docblock asserted an API parameter that does not exist (`/location/regions?region_code=`) and a capability was declared on it — every region key resolved to the same wrong row.** → [the-fixture-docblock-asserted-an-api-parameter-that-does-not-exist](../gotchas/the-fixture-docblock-asserted-an-api-parameter-that-does-not-exist.md) (s96)
- [shipping/location] **To sample a region's settlements, scope the LIST — `suggest()` by name returns homonyms from other regions.** → [list-by-region-scope-not-suggest-by-name](../gotchas/list-by-region-scope-not-suggest-by-name.md) (s92)
- [shipping/location] **One `/select` response narrows EVERY level — including a still-queued pick it could not have named — wiping its optimistic record.** → [a-shared-select-queue-narrows-a-level-its-response-never-named](../gotchas/a-shared-select-queue-narrows-a-level-its-response-never-named.md) (s89)
- [shipping/location] **An empty `owners[country][level]` DISARMS the cross-provider guard that reads it — falsy is an open gate, not a closed one.** → [an-empty-level-owner-silently-disarms-the-cross-provider-guard](../gotchas/an-empty-level-owner-silently-disarms-the-cross-provider-guard.md) (s88)
- [shipping/location] **«Список с поиском» is `ajax-select2`, NOT `related-list` — and DaData can never offer «Предустановленный список» at all.** → [the-three-location-field-modes-and-their-russian-labels](../gotchas/the-three-location-field-modes-and-their-russian-labels.md) (s87)
- [shipping/location] **`within_applied` reports what the scope BUILDER decided, not what the provider honoured — fixed s106 (#358) via the independent `scope_narrowing` field.** → [within-applied-reports-the-scope-builder-not-the-provider](../gotchas/within-applied-reports-the-scope-builder-not-the-provider.md) (s78)
- [shipping/location] **A served level can come from the FALLBACK provider, not the active one — "the active provider lacks X" and "X is unserved" are different statements.** → [a-level-served-can-come-from-the-fallback-not-the-active-provider](../gotchas/a-level-served-can-come-from-the-fallback-not-the-active-provider.md) (s76)
- [shipping/location] **One identity, two roles: one must refuse, the other must fall back.** → [one-identity-two-roles-one-must-refuse-the-other-must-fall-back](../gotchas/one-identity-two-roles-one-must-refuse-the-other-must-fall-back.md) (s74)
- [shipping/location] **A derived ancestor is not the one the customer picked.** → [a-derived-ancestor-is-not-the-one-the-customer-picked](../gotchas/a-derived-ancestor-is-not-the-one-the-customer-picked.md) (s74)
- [shipping/location] **DaData gives a city of federal significance ONE key on both levels and `ancestors: []` — measured for Moscow/SPb/Sevastopol/Baikonur plus most big BY/KZ/UZ cities. Never test `ancestors()` raw; ask `is_within()`.** → [dadata-collapses-region-and-settlement-into-one-key](../gotchas/dadata-collapses-region-and-settlement-into-one-key.md) (s111)
- [shipping/location] **A DOM attribute is the wrong seam on a WooCommerce checkout — the node is not yours.** → [a-dom-attribute-is-the-wrong-seam-on-a-woocommerce-checkout](../gotchas/a-dom-attribute-is-the-wrong-seam-on-a-woocommerce-checkout.md) (s72)
- [shipping/location] **A locality's display NAME is not an identifier — the same settlement answers «Москва» or «Moscow» depending on the account's locale.** → [a-locality-display-name-is-not-an-identifier](../gotchas/a-locality-display-name-is-not-an-identifier.md) (s71)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
