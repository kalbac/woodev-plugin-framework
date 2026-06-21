# Промт для следующей сессии (s29)

> Написан в конце s28 (2026-06-21). **s28 = Competitor Notification модуль — SHIPPED (PR #79 `f96e9ce`).** Версия НЕ бампнута (2.0.1 unreleased, `@since 2.0.2`).

## Старт сессии

- framework: `docs-internal/CURRENT-STATE.md`, `docs-internal/GOTCHAS.md`, этот промт.
- `main` синхронизирован (`f96e9ce`), **760 unit**, phpcs чисто.
- ⚠️ **PHPStan локально на Windows падает** с `-1073741819` (нативный segfault, **не ошибка кода**) — гоча `phpstan-windows-parallel-worker-segfault`. Авторитетный гейт — **Linux CI** («Run PHPStan» в job Lint). Локально гоняй `composer phpcs` + `composer test:unit`.
- **Серена есть** — для PHP-навигации (`find_symbol`/`get_symbols_overview`), для правок существующих PHP — встроенный `Edit` (Serena `replace_content` ломает EOL).

## Что сделано в s28 (контекст)

Competitor Notification модуль: нейтральный движок `Competitor_Notification_Handler` + декларативные правила `Competitor_Rule` + рендереры (`WC_Admin_Notes_Renderer` по `class_exists(Note::class)`, `Admin_Notice_Renderer` фоллбэк). Opt-in через `Woodev_Plugin::get_competitor_notification_handler()` (default null) на `current_screen`. Умная recommend-ссылка через кэш покупок. Codex-ревью (3 HIGH/3 MED/1 LOW) пофикшено + re-critic. Спека/план: `docs-internal/specs|plans/2026-06-21-competitor-notification*`.

**Вне scope (на будущее):** миграция yandex-подкласса на новый API — при переписывании плагина на v2 (тогда же удалить старый `woodev/handlers/competitor-notification.php` из плагина). Реестр конкурентов — отклонён (YAGNI).

## 🎯 Кандидаты на s29 (выбор за оператором)

- **Shipping module** (большой, нужно участие оператора; он отдельно напишет черновик `SHIPPING-PLANS.md`) — скелет богатый, но **не валидирован реальным плагином**: `admin/views/html-admin-shipping-method-status.php` ~30% стаб, нет setup-wizard, **нет абстракции label/export**, JS/CSS не проверены, webhook не валидирован на yandex. План: conformance-аудит против 3 референсов → закрыть дыры → пилот yandex (ПВЗ). Хороший autodev-loop кандидат.
- **OB-10 Setup Wizard** — отдельный брейншторм (сначала аудит состояния `Woodev_Plugin_Setup_Wizard`).
- **payment-gateway trait extraction** (`class-payment-gateway.php` ~3,542 строк) — autodev-loop.
- **review #4** — `array()`→`[]` (~797) + type declarations + `@since` sweep + включить `Generic.Arrays.DisallowLongArraySyntax`.
- **OB-6 dead-file sweep**.

## Гигиена

`@since 2.0.2`. Публичные `docs/` не трогаем. **Composer только в dev/тестах, не в плагинах.** После добавления класса фреймворка — `php bin/generate-class-map.php` + коммит карты (гоча `framework-classmap-autoload-vendored-boot`). Новый код PSR-4 (`Woodev\Framework\*`). Мерж: ветка → PR → зелёный CI (проверь, что unit-матрица **и** «Run PHPStan» реально прошли) → `--squash --delete-branch` (НЕ `--auto`). Codex-ревью на архитектурно-чувствительное; находки в неавтономном режиме не автофиксить — спрашивать.
