# `is_need_license()` (presentation) vs `is_license_required()` (enforcement)

> [licensing/two-layer] Two near-identical method names live on different classes and mean
> opposite-trust things. Confusing them reintroduces the exact piracy hole the design closes.

## The trap

Since S3.1 (2026-06-10) licensing has a deliberate **two-layer** model with two confusingly-similar predicates:

| Method | Class | Layer | Trust | Governs |
|--------|-------|-------|-------|---------|
| `is_need_license(): bool` | `Woodev_Plugin` (base) | L1 — local intent | **UNTRUSTED** (a plugin/fork sets it freely) | **presentation only**: license-page block, admin nags, the WC settings form-wrap, the license action-link, the activate/deactivate form-submit handlers |
| `is_license_required(): bool` | `Woodev_Plugins_License` | L2 — server authority | **TRUSTED** (only a verified server-signed claim can flip it; default `true`) | **enforcement**: the outcome of `is_license_valid()` / `is_active()` for a license-free product |

## ❌ Wrong — gates a feature/enforcement on the local flag

```php
// In a plugin, or a future framework feature gate:
if ( $this->is_need_license() ) {
    $this->run_premium_feature(); // ❌ a pirate sets is_need_license()=false → unlocks for free
}

// Or, worse, leaking the local flag into the enforcement helpers:
public function is_license_valid() {
    if ( ! $this->plugin->is_need_license() ) { // ❌ NEVER — local flag must not validate
        return true;
    }
    ...
}
```

## ✅ Correct

- **Unlock features / treat as licensed** → consult **enforcement** only:
  ```php
  if ( $this->get_license_instance()->is_license_valid() ) { // routes through is_license_required() (server)
      $this->run_premium_feature();
  }
  ```
- **Render the license UI / suppress nags** → consult the **local** flag:
  ```php
  if ( ! $this->is_need_license() ) {
      echo 'Лицензия не требуется';
      return;
  }
  ```

## Why it matters

`is_license_required()` is the anti-pirate chokepoint: it reads no local option and is never influenced by `is_need_license()`. If signing (S3.1 spec §4) later makes `is_license_required()` read a **verified** server claim, the trap gets sharper — a developer "simplifying" by gating enforcement on the local `is_need_license()` would hand pirates free feature unlock. The unit test `LicenseNeedLicenseFlagTest::test_anti_pirate_flag_does_not_validate_license` locks this invariant; do not weaken it.

## Related
- Spec: `docs-internal/platform-v2-s3-licensing-need-license-spec.md` (§2 two-layer model, §3.3, §6 anti-pirate)
- [[contract-string-not-derivable]] — another "looks derivable but isn't" licensing/shipping trap
- woodev-core server half: `D:\Projects\woodev_theme\docs\superpowers\specs\2026-06-10-woodev-core-license-authority-signing-spec.md`
