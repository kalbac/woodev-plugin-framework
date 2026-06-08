# eCheck/ACH Audit — Removal Plan

> Audit date: 2026-05-10 (s3). 125+ references in 14 files across payment-gateway/.

## Scope: 14 files, 6 categories

| File | Category | Reference count |
|------|----------|----------------|
| `class-payment-gateway.php` | Core gateway | ~50 |
| `class-payment-gateway-direct.php` | Direct (form-entry) gateway | ~35 |
| `class-payment-gateway-payment-form.php` | Payment form rendering | ~30 |
| `class-payment-gateway-hosted.php` | Hosted (redirect) gateway | ~8 |
| `payment-tokens/class-payment-gateway-payment-token.php` | Token model | ~12 |
| `payment-tokens/class-payment-gateway-payment-tokens-handler.php` | Token handler | ~12 |
| `class-payment-gateway-my-payment-methods.php` | My Payment Methods | ~8 |
| `class-payment-gateway-helper.php` | Static helpers | ~3 |
| `class-payment-gateway-plugin.php` | Plugin bootstrap | ~5 |
| `api/interface-payment-gateway-api-payment-notification-echeck-response.php` | API interface | full file |
| `api/interface-payment-gateway-api-response.php` | API response | ~1 |
| `handlers/abstract-payment-handler.php` | Payment handler | ~2 |
| `handlers/abstract-hosted-payment-handler.php` | Hosted handler | ~4 |
| `admin/class-payment-gateway-admin-payment-token-editor.php` | Admin editor | ~3 |
| **Assets** | JS + CSS + Images | ~22 |
| **Total** | **15 locations** | **~160** |

---

## Architecture overview

### How eCheck works in the framework

The framework uses a **gateway type** pattern (`$payment_type` property):
- `'credit-card'` (default) → credit card gateways
- `'echeck'` → checking/savings account gateways

The type is set in the gateway constructor via `$args['payment_type']` — the framework itself never creates eCheck gateways; **dependent plugins do**.

Two gate-checking methods dispatch behavior:
- `is_credit_card_gateway()` → `return self::PAYMENT_TYPE_CREDIT_CARD == $this->get_payment_type()`
- `is_echeck_gateway()` → `return self::PAYMENT_TYPE_ECHECK == $this->get_payment_type()`

These are called **14 times** across 4 files in `if/elseif` chains that branch between credit card and eCheck processing.

### Dependency chain

```
PAYMENT_TYPE_ECHECK constant
  → $payment_type property
    → is_echeck_gateway() gate method
      → [14 if/elseif eCheck branches across gateway classes]
      → [eCheck-specific token methods: get_account_type(), set_account_type()]
      → [eCheck payment form fields + sample check renderer]
      → [Payment_Notification_eCheck_Response interface]
```

---

## Removal strategy: 3-phase approach

### Phase 1: Trait extraction (non-breaking, can ship in minor version)

Extract **all eCheck-specific code** into a single trait: `Woodev\Framework\Payment\eCheck_Gateway_Trait`.

**What goes into the trait (25+ methods/blocks):**

1. **`class-payment-gateway.php` → trait:**
   - `get_default_title()` eCheck branch (line ~830)
   - `get_default_description()` eCheck branch (line ~848)
   - `get_icon()` eCheck image block (lines 1310-1316)
   - `get_echeck_transaction_approved_message()` — full method (line 2251)
   - `add_transaction_data()` eCheck meta save block (lines 2105-2117)
   - `complete_payment()` eCheck note block (line 1439-1440)
   - 8 JS error messages for eCheck validation (lines 461-471)
   - `supports_check_field()` — full method
   - `is_echeck_gateway()` — trait can override to return `true`

2. **`class-payment-gateway-direct.php` → trait:**
   - `validate_check_fields()` — full method (~80 lines, line 225)
   - `get_order()` eCheck attribute blocks (lines 567-575, 596-599)
   - `do_transaction()` eCheck dispatch block (line 771-773)
   - `add_payment_method()` eCheck note (line 907-915)

3. **`class-payment-gateway-payment-form.php` → trait:**
   - `get_echeck_fields()` — full method (line 365)
   - `get_sample_check_html()` — full method (line 471)
   - `render_sample_check()` — full method (line 827)

4. **`class-payment-gateway-hosted.php` → trait:**
   - eCheck `instanceof` check in `get_order_for_ipn()` (lines 447-449 → via trait hook)

5. **Handlers → trait (via hooks):**
   - `abstract-payment-handler.php` eCheck note (line 200-201)
   - `abstract-hosted-payment-handler.php` eCheck `instanceof` (lines 292-295)

**Injection mechanism:** Replace nested `if/elseif ($this->is_echeck_gateway())` blocks with **filter hooks** or `method_exists($this, '…')` guard:
```php
// Before (in get_default_title):
} elseif ( $this->is_echeck_gateway() ) {
    return esc_html__( 'eCheck', 'woodev-plugin-framework' );
}

// After (trait-extracted):
$title = apply_filters( 'wc_' . $this->get_id() . '_payment_gateway_default_title', $title, $this );
// Trait hooks in and overrides for eCheck
```

**Key challenge:** Many eCheck branches are embedded **inside** shared methods (not separate method calls). Trait extraction requires adding extension points (hooks) to the base class.

**Phase 1 outcome:** Base class simplified (~250 lines removed from shared methods), eCheck logic consolidated in one trait file. Plugin devs add `use eCheck_Gateway_Trait;` to retain existing behavior.

---

### Phase 2: API interface deprecation (breaking, v2.0.0)

Remove the dedicated eCheck response interface:

1. **`api/interface-payment-gateway-api-payment-notification-echeck-response.php`** — entire file (32 lines)
   - `get_account_type()` — eCheck-specific
   - `get_check_number()` — eCheck-specific

2. **`api/interface-payment-gateway-api-response.php`** — remove `echeck` from `get_payment_type()` docblock

3. **`class-payment-gateway-plugin.php`** — remove `require_once` for the interface (line 218)

4. **`abstract-hosted-payment-handler.php`** — remove `instanceof Woodev_Payment_Gateway_API_Payment_Notification_eCheck_Response` check (lines 292-295)

5. **`class-payment-gateway-hosted.php`** — remove `case self::PAYMENT_TYPE_ECHECK` in `get_order_for_ipn()` (lines 447-449)

**Phase 2 outcome:** eCheck API type gone. Plugins that use eCheck API responses will get PHP fatal errors — **breaking change**, requires v2.0.0.

---

### Phase 3: Token model cleanup (breaking, v2.0.0)

1. **`class-payment-gateway-payment-token.php`:**
   - Remove `get_account_type()` — eCheck-only method
   - Remove `set_account_type()` — eCheck-only method
   - Remove `is_echeck()` — falls back to `!is_credit_card()`, becomes always false
   - Simplify `get_type_full()` eCheck branch
   - Keep `type_from_account_number()` deprecated (not eCheck-specific, `account_number` naming is generic)

2. **`class-payment-gateway-payment-tokens-handler.php`:**
   - Remove `account_type` from `get_merge_attributes()` (line 670)
   - Remove eCheck branch in `create_token()` (line 122-123)
   - Remove eCheck branch in `get_order_note()` (line 798-807)

3. **`class-payment-gateway-my-payment-methods.php`:**
   - Remove `$echeck_tokens` property
   - Remove eCheck token separation in `load_tokens()`
   - Simplify to single `$tokens` array

4. **`admin/class-payment-gateway-admin-payment-token-editor.php`:**
   - Remove `case 'echeck'` in `get_fields()` (line 531-556)

**Phase 3 outcome:** Token model simplified. Breaking change — plugins that add eCheck tokens will get errors.

---

### Phase 4: Asset cleanup (non-breaking)

Remove eCheck-specific assets:
- `assets/js/frontend/woodev-payment-gateway-frontend.js` — eCheck validation logic
- `assets/css/frontend/woodev-payment-gateway-frontend.css` — eCheck form styling
- `assets/css/frontend/woodev-payment-gateway-payment-form.css` — eCheck form styling
- `assets/images/card-echeck.svg` / `card-echeck.png` / `sample-check.png`

---

### Phase 5: Core cleanup (breaking, v2.0.0)

After trait extraction (Phase 1) deprecation cycle (1 major version):

1. **Remove from `class-payment-gateway.php`:**
   - `PAYMENT_TYPE_ECHECK` constant
   - `$supported_check_fields` property
   - All remaining eCheck references in docblocks

2. **Remove from `class-payment-gateway-plugin.php`:**
   - eCheck references in docblocks (lines 73, 793, 829, 861)

3. **Remove from `class-payment-gateway-helper.php`:**
   - `'checking'` / `'savings'` entries in `payment_type_to_name()` (lines 174-175)

---

## What CANNOT be removed (shared infrastructure)

These stay because they're used by credit_card gateways too:

| Item | File | Why it stays |
|------|------|-------------|
| `PAYMENT_TYPE_CREDIT_CARD` constant | `class-payment-gateway.php` | Core: all non-eCheck gateways use it |
| `$payment_type` property | `class-payment-gateway.php` | Core: credit_card and future types |
| `get_payment_type()` | `class-payment-gateway.php` | Public API — called by plugins |
| `is_credit_card_gateway()` | `class-payment-gateway.php` | Used 14+ times in branch logic |
| `$shared_settings` property | `class-payment-gateway.php` | Used for multi-gateway config sharing (not eCheck-specific) |
| `token->is_credit_card()` | `payment-token.php` | Core: used by token formatters |
| `token->get_type()` returning `'credit_card'` | `payment-token.php` | Core: main branch |
| `get_payment_type()` in API response | `api/…response.php` | Core: credit_card branch stays |
| `luhn_check()` | `helper.php` | Used by credit_card too (param named `account_number` is generic) |
| `card_type_from_account_number()` | `helper.php` | Kept — used by credit_card token inference |

---

## Risk assessment

| Risk | Severity | Mitigation |
|------|----------|-----------|
| 10+ dependent plugins may use eCheck | **High** | Audit ALL dependents BEFORE removal. Trait extraction Phase 1 is safe — opt-in |
| `is_echeck_gateway()` removed → plugins get fatal | **Critical** | Deprecation cycle: keep gate method as `return false;` for 1 version |
| API interface removed → plugins implementing it fail | **Critical** | Keep empty interface for 1 version, mark `@deprecated` |
| Mixed gateway plugins (credit_card + echeck) break | **High** | Audit — contact plugin authors. Trait approach supports them in transition |

---

## Recommended execution order

```
1. Audit ALL dependent plugins — does ANY plugin set payment_type='echeck'?     [discovery]
2. Phase 1: Trait extraction → ship in v1.x (non-breaking)                       [~4h coding]
3. Deprecation cycle: 1 minor version                                             [passive]
4. Phase 2+3+5: Remove trait, interfaces, token model → ship in v2.0.0           [~2h coding]
5. Phase 4: Asset cleanup → ship in v2.0.0                                       [~30min coding]
```

**Total estimated effort:** 6.5 hours + dependent plugin audit.
**Must be preceded by:** WP/WC version bump (#1 in backlog) to clear minimum dependency checks first.

---

## Related
- [[FUTURE-BACKLOG]] — task #2 "Remove Unused US-Specific Payment Types"
- [[GOTCHAS]] → [compat/hpos-order-meta-safety]
