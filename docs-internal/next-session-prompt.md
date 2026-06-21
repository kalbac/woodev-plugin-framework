# Промт для следующей сессии (s28): реализовать Competitor Notification модуль

> Написан в конце s27 (2026-06-21). **Дизайн согласован с оператором, спека готова — реализацию начинаем с нуля в s28.** Версия НЕ бампнута (2.0.1 unreleased, `@since 2.0.2`).

## Старт сессии

- framework: `docs-internal/CURRENT-STATE.md`, `docs-internal/GOTCHAS.md`, этот промт.
- `main` синхронизирован, **729 unit**, всё зелёное (phpstan L3 + phpcs).
- **Серена есть** — для PHP-навигации (`find_symbol`/`get_symbols_overview`), для правок существующих PHP — встроенный `Edit` (Serena `replace_content` ломает EOL).

## 🎯 Задача s28 — Competitor Notification модуль (рерайт v1 → v2)

**Спека готова и согласована:** `docs-internal/specs/2026-06-21-competitor-notification-design.md`. Первый шаг — **`writing-plans`** по этой спеке, затем реализация по TDD.

Суть (5 решений из s27-брейншторма):
1. Два режима через **декларативные правила** (`mode: recommend|conflict`); плагин отдаёт `get_competitor_rules(): array`, движок прогоняет.
2. **Нейтральный движок + рендереры** (`Woodev\Framework\Competitor\…`): WC-Admin Notes когда есть WC, иначе admin-notice фоллбэк. **Выбор по `class_exists(Note::class)`** — фикс гочи `is-enhanced-admin-available-always-true`.
3. **Умный таргет ссылки** recommend через account-экосистему (s24–s26): подключён+куплено → ведём на `admin.php?page=woodev-extensions` (install-кнопка #8); иначе → `our_url`. Деградация ок.
4. **Дефолтные i18n-шаблоны** во фреймворке (per mode), маппинг «конкурент→продукт» — в плагине. Без центрального реестра.
5. **Уважать закрытие**, авто-удаление ноты при деактивации конкурента, без форс-всплытия.

Референс реальной логики (v1, для рерайта): `woocommerce-yandex-delivery/woodev/handlers/competitor-notification.php` + `…/includes/class-plugin-competitor-notices.php`. Субстрат в v2: `Woodev_Notes_Helper` (`woodev/admin/class-notes-helper.php`). Подключение как подсистема — паттерн `init_*_handler()` в `class-plugin.php` (opt-in, как setup wizard).

Открытые plan-time развилки (спека §10): точный accessor «куплено по download_id» на `Woodev_Account_Purchases`; нужен ли `?highlight={id}` на странице extensions; точка триггера (`admin_init` vs wc-admin hook, не на фронте).

Вне scope: миграция yandex-подкласса (при переписывании плагина), реальная install-механика (#8 готова), Setup Wizard (OB-10).

## Гигиена

`@since 2.0.2`. Публичные `docs/` не трогаем. **Composer только в dev/тестах, не в плагинах.** После добавления класса фреймворка — `php bin/generate-class-map.php` + коммит карты (гоча `framework-classmap-autoload-vendored-boot`). Новый код PSR-4 (`Woodev\Framework\*`). Мерж: ветка → PR → зелёный CI (проверь, что unit-матрица реально прошла) → `--squash --delete-branch` (НЕ `--auto`). Codex-ревью на архитектурно-чувствительное; находки в неавтономном режиме не автофиксить — спрашивать.

## Прочие кандидаты (после competitor, на выбор)

- **Shipping module** (большой, нужно участие оператора; он отдельно напишет черновик `SHIPPING-PLANS.md`) — аудит против 3 референсов → закрыть дыры → пилот yandex.
- **OB-10 Setup Wizard** — отдельный брейншторм (сначала аудит состояния).
- payment-gateway trait extraction · review #4 (`array()`→`[]` + typing) · OB-6 dead-file sweep — кандидаты на возврат **autodev-loop**.
