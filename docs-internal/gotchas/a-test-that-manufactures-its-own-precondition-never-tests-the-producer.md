# A test that manufactures its own precondition never tests whoever is supposed to produce it

**Namespace:** `[testing/*]`
**Found:** s98 (27.08.2026), by a critic pass, in two unrelated suites written the same night.

## The trap

A guard reads something; a test sets that something by hand and asserts the guard behaves. Every
such test stays green when **the producer of that something disappears** — and the producer is
usually the half that ships broken.

Two instances, same shape:

| Guard | What the test set by hand | What nothing tested |
|---|---|---|
| `Payment_Token_Editor::save()` returns unless `$_POST[…_rendered]` is present | the marker, directly in `$_POST` | that the **view** still prints it. Delete the hidden input and all four tests stay green — while every ordinary admin token edit silently does nothing, because `save()` returns on the absent marker. |
| `sanitize_log_field()` strips control characters | called the helper directly | that `ajax_log_event()` still **calls** it. Regress the endpoint to `trim()` and all 21 tests stay green. |

Both are worse than an untested producer, because the suite reports coverage of the very
behaviour that is broken.

## How to notice

Ask of each test: **which line of production code could I delete and leave this green?** If the
answer includes the line that creates the input, the test covers a consumer and nothing else.

## ✅ Correct

Assert the contract at BOTH ends, and compare them rather than restating the literal:

```php
// the producer emits the name the consumer looks for
$this->assertStringContainsString( 'name="<?php echo esc_attr( $rendered_marker_name ); ?>"', $view );
$this->assertStringContainsString( '$rendered_marker_name = $this->get_rendered_marker_name();', $editor );

// and drive the endpoint, not only its helper
$handler->ajax_log_event();
$this->assertStringNotContainsString( "\n", $handler->logged[0] );
```

## Related

- [measure-a-gate-where-the-gate-can-actually-fire](measure-a-gate-where-the-gate-can-actually-fire.md) — the mirror image: a gate that cannot fire in the test
- [parse-str-loses-information-so-never-compare-queries-with-it](parse-str-loses-information-so-never-compare-queries-with-it.md) — the other finding from the same pass
