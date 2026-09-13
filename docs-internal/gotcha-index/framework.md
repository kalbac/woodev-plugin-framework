# Gotcha index — [framework/*] Framework internals

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [framework/includes-wiring] **New framework class files must be wired into `includes()`, not just the Composer classmap.** → [dispatcher-files-unwired-in-includes](../gotchas/dispatcher-files-unwired-in-includes.md)
- [framework/includes-wiring] **Vendored framework has no runtime autoloader — every `includes()` require must be complete.** → [box-packer-interface-unwired-in-includes](../gotchas/box-packer-interface-unwired-in-includes.md) (s11)
- [framework/autoload] **Framework classes must be in the generated classmap, or they WSOD on a real vendored boot.** → [framework-classmap-autoload-vendored-boot](../gotchas/framework-classmap-autoload-vendored-boot.md) (s27)
- [framework/autoload] **class_exists() "init-once" guards break under the runtime class-map autoloader.** → [classmap-autoload-breaks-class-exists-once-guard](../gotchas/classmap-autoload-breaks-class-exists-once-guard.md) (s35)
- [framework/autoload] **Deleting a framework file has a tail: `includes()`, the class map, and fixture bootstraps.** → [file-deletion-tail-includes-classmap-fixtures](../gotchas/file-deletion-tail-includes-classmap-fixtures.md) (s45)
- [framework/handler-extraction] **A handler extraction that self-registers its hook can silently disable a subclass override.** → [handler-extraction-must-preserve-override-chain](../gotchas/handler-extraction-must-preserve-override-chain.md)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
