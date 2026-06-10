# [i18n/russian-source-plural-n] `_n()` with Russian source strings renders wrong plural forms without a translation catalog

**Discovered:** 2026-06-11 (s7, GPT-5.5 critic BLOCK on the B-1 mixed-fleet notice renderer)

## Root cause

This codebase uses **Russian as the source language** for user-facing strings (text
domain `woodev-plugin-framework`). WordPress's `_n( $single, $plural, $number )` without
a loaded translation catalog falls back to **English 2-form logic** (`$number === 1 ?
$single : $plural`). Russian has **3 plural forms** (1 плагин / 2 плагина / 21 плагин →
"one" form again), so counts like 21, 31, 101 render the wrong form — and there is no
ru_RU catalog to fix it, because Russian IS the source.

## ❌ Wrong

```php
$message = sprintf(
	_n( 'Плагин %s отключён.', 'Плагины %s отключены.', $count, 'woodev-plugin-framework' ),
	$names
); // 21 plugins -> "Плагины ... отключены" is wanted, but 21 needs the singular-shaped form; 2-form _n() can never express Russian correctly
```

## ✅ Correct

Use ONE count-neutral phrasing that reads correctly for any N:

```php
$message = sprintf(
	__( 'Следующие плагины собраны для устаревшей версии фреймворка Woodev и поэтому были отключены: %s.', 'woodev-plugin-framework' ),
	$names
);
```

Rule of thumb: in a Russian-source codebase, avoid `_n()` entirely — rephrase so the
noun phrase does not inflect with the count ("Обнаружены плагины: %s", "Записей: %d").

## Related
- [gotchas/license-need-vs-required.md](license-need-vs-required.md) — same licensing/notice area
- `woodev/bootstrap.php` `render_mixed_fleet_notice()` — where this was caught (PR #27)
