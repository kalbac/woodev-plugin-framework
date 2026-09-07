# Gotcha: [api/error-boundary] — A vendor's REFUSAL is not an API exception, and every plugin draws that line itself

> Tags: api, shipping, integration, measurement | Session: s124

## What happens

`Woodev_API_Base::handle_response()` throws `Woodev_API_Exception` on exactly one condition:

```php
if ( is_wp_error( $response ) ) {
    throw new Woodev_API_Exception( … );
}
```

`is_wp_error()` is true for **transport** failures — DNS, timeout, cURL. It is NOT true for an
HTTP 400/422 carrying `{"errors":[{"code":"…","message":"…"}]}`. WordPress считает такой ответ
успешным: `wp_remote_post()` returns an array, not a `WP_Error`.

So the sentence "the API returned an error" splits in two, and only one half throws. A refusal —
«габариты превышены», «ПВЗ закрыт», «город получателя не распознан» — arrives as an ordinary
response and flows straight past every `catch`.

**Identical in v1 and v2** (measured s124 against `plugins-reference/`), so this is not something
the v2 rewrite introduced or fixed.

## Root cause

The framework deliberately leaves the semantic boundary to the caller, through a hook:

```php
// woodev/api/class-api-base.php:1182
protected function do_post_parse_response_validation() {}
```

An empty stub. It is called after parsing, and a plugin overrides it to decide what counts as a
failure. **All six reference plugins override it** — edostavka (×3 clients), yandex-delivery,
russian-post, vkredit — each with its own rule. That is the whole reason the operator's report was
«и те, и те, но по-разному у разных перевозчиков»: whether a business refusal ever becomes an
exception depends on which plugin you are looking at.

edostavka's version, and the two defects visible in it:

```php
protected function do_post_parse_response_validation() {
    $errors = $this->get_response()->get_errors();

    if ( $this->get_response_code() > 400 ) {              // ← STRICTLY greater: HTTP 400 itself passes
        throw new Woodev_API_Exception( $this->get_response_message(), $this->get_response_code() );
    } elseif ( $errors ) {
        throw new Woodev_API_Exception( implode( ".\n", $errors ) );   // ← structure flattened to a string
    }
}
```

`> 400` lets the single most common validation status through, and `implode()` destroys the
`code`/`message` pairs the carrier sent — after which no consumer can branch on a code, only
substring-match a sentence.

## Why it bites downstream

`Abstract_Shipment_Handler::export()` catches `Woodev_API_Exception` and treats everything else as
success. A refusal that never became an exception therefore:

- writes an **empty** carrier order id into order meta;
- fires `shipment_exported` — a hook whose name asserts the opposite of what happened;
- does NOT fire `shipment_export_failed` and schedules no retry.

The docblock there acknowledges this as "existing, unrelated behaviour". It is only unrelated as
long as nobody reads the hook name as a fact.

## How to apply

- **Never assume a non-throwing response means the vendor agreed.** Check the payload for the
  vendor's own error shape before treating an id/result as real.
- When writing an API client on this base, `do_post_parse_response_validation()` is where the
  semantic boundary goes — and it is a **contract you are writing from scratch**, not one the
  framework gave you. Say in the client what counts as a refusal.
- Comparing a status: `>= 400`, not `> 400`. The off-by-one silently exempts the commonest
  validation code.
- Preserve `code` and `message` separately as far as the consumer. A joined string is unbranchable,
  and a merchant-facing note built from it inherits whatever punctuation and language the join used.

## Related

- [a-cast-is-not-a-degradation](a-cast-is-not-a-degradation.md) — same family: a value that satisfies the type and loses the meaning
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — why a green unit suite says nothing here: the stub never returns the vendor's refusal shape
- [a-registered-setting-without-a-control-never-renders](a-registered-setting-without-a-control-never-renders.md) — declaring a thing is not the same fact as it reaching a screen
