# Legacy v1 plugin excerpts

Minimal excerpts of a few files from the v1 Woodev carrier plugins, kept as **read-only evidence**.
They pin data contracts that live on installed merchant sites: settings option keys, order-meta
prefixes and shipping-method ids. The v2 plugins must keep reading and migrating that data, so the
tests compare against the real v1 literals instead of a value remembered by hand.

The v1 plugins are paid commercial products and this repository is public, so these files are
**not the plugin source**. Each one keeps only what its test reads and carries a header saying so.
Relative paths match the original plugin, so a path in a test reads the same as the path in the
plugin.

| Folder | Content | Used by | What it pins |
|---|---|---|---|
| `woocommerce-yandex-delivery/` | Literal excerpts of 4 files (Yandex Delivery 1.3.7): the declarations the regexes match, no logic | `tests/unit/Contract/Yandex*ContractTest.php` and `recipes/yandex-*.recipe.json` | settings option key, order-meta prefix, method ids `yandex_delivery_other_day` / `yandex_delivery_express`, the `tariff` values |
| `woocommerce-edostavka/` | Signature-only skeletons of 3 files (CDEK): class headers and method signatures, every body empty, no strings | `tests/js/signature-probe.test.js` (card #767) and the default pairs of `scripts/signature-probe.mjs` | the class and method signatures a v2 base class must stay compatible with |

## Rules

- **Never executed.** The files are only read as text (regex, the signature probe). They are not
  runnable PHP: nothing here is loaded, autoloaded or analysed. phpcs and phpstan only scan
  `woodev/`, phpunit only discovers `tests/unit` and `tests/integration`, and composer's
  `autoload-dev` maps `tests/unit` and `tests/integration` only.
- **Never edited casually.** A change here would move the contract the test pins. If an excerpt has
  to change, a real v1 release changed the contract, and the matching test changes with it.
- **Never grown back into source.** Do not paste plugin logic, comments, strings or keys in here.
  If a new test needs another literal, add that literal, not the file around it.
