# Gotcha index — [framework/*] Framework internals

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [framework/includes-wiring] **New framework class files must be wired into `includes()`, not just the Composer classmap.** → [dispatcher-files-unwired-in-includes](../gotchas/dispatcher-files-unwired-in-includes.md)
- [framework/includes-wiring] **Vendored framework has no runtime autoloader — every `includes()` require must be complete.** → [box-packer-interface-unwired-in-includes](../gotchas/box-packer-interface-unwired-in-includes.md) (s11)
- [framework/autoload] **Framework classes must be in the generated classmap, or they WSOD on a real vendored boot.** → [framework-classmap-autoload-vendored-boot](../gotchas/framework-classmap-autoload-vendored-boot.md) (s27)
- [framework/autoload] **class_exists() "init-once" guards break under the runtime class-map autoloader.** → [classmap-autoload-breaks-class-exists-once-guard](../gotchas/classmap-autoload-breaks-class-exists-once-guard.md) (s35)
- [framework/autoload] **Deleting a framework file has a tail: `includes()`, the class map, and fixture bootstraps.** → [file-deletion-tail-includes-classmap-fixtures](../gotchas/file-deletion-tail-includes-classmap-fixtures.md) (s45)
- [framework/handler-extraction] **A handler extraction that self-registers its hook can silently disable a subclass override.** → [handler-extraction-must-preserve-override-chain](../gotchas/handler-extraction-must-preserve-override-chain.md)
- [framework/payment-gateway] **A bank's documented notification IP range is not the range it calls from: T-Bank sends from `212.233.80.0/22`, not only `91.194.226.0/23` — an IP-only trust list answers 403.** → [a-bank-s-documented-notification-ip-range-is-not-the-range-it-calls-from](../gotchas/a-bank-s-documented-notification-ip-range-is-not-the-range-it-calls-from.md) (s202)
- [framework/payment-gateway] **A bank binds its test keys to the cabinet's site domain: `create` returns 400 for return URLs on another domain, and a URL-less probe passes without proving the domain.** → [a-bank-binds-its-test-keys-to-the-cabinet-domain-so-a-url-less-probe-proves-nothing](../gotchas/a-bank-binds-its-test-keys-to-the-cabinet-domain-so-a-url-less-probe-proves-nothing.md) (s202)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
- [framework/plugin] **`Woodev_Plugin::is_plugin_active()` compares the bare file name — a `folder/file.php` basename never matches; check `active_plugins` (+ sitewide keys) directly.** → [woodev-plugin-is-plugin-active-matches-the-bare-filename-only](../gotchas/woodev-plugin-is-plugin-active-matches-the-bare-filename-only.md) (s170)
- [framework/payment-gateway] **`Woodev_Payment_Gateway_Hosted`: opt into pending responses; verification preserves status; `get_order()` does not start attempts (#1480).** → [a-hosted-gateway-s-default-response-flow-fails-orders-it-does-not-understand](../gotchas/a-hosted-gateway-s-default-response-flow-fails-orders-it-does-not-understand.md) (s199, s201)
