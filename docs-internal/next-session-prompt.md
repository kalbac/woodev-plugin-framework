# Промт для следующей сессии (s14): причёсывание фреймворка — выбор из бэклога OB-1..9

> Написан в s13 (13.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — удали этот файл.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Старт сессии
1. `docs-internal/CURRENT-STATE.md` — lean-состояние (фазы, открытые баги, next actions).
2. `docs-internal/platform-v2-program-tracker.md` — программный статус (S0–S3 DONE).
3. `docs-internal/GOTCHAS.md` — сканировать релевантные теги.
4. `docs-internal/FUTURE-BACKLOG.md` → раздел **«Operator backlog dump — s13»** (OB-1..9) — главный источник задач.

## Что сделано в s13 (PR #48 + #49, в main)
- **Аудит доков:** факты приведены к коду (`class-payment-gateway.php` = 3 542 строки; `phpstan-baseline.neon` НЕ существует; clean-break Phase 3 шимы удалены, `register_plugin()` = B-1 tombstone); `CURRENT-STATE` ужат с ~72 КБ; 6 review-пакетов → `archive/`; AGENT-RULES Rule 0 → clean-break/ADR-005; GOTCHAS 45→46; DOCS-INDEX перетряхнут.
- **licensing-API:** единый фильтр `woodev_license_base_url` в `Woodev_Licensing_API::get_url()` (дубль `woodev_licensing_api_url` удалён; теперь и `Woodev_Plugin_Updater` уважает override). Тип-хардненинг класса.
- `composer check` зелёный: PHPCS 162/162, PHPStan 0, PHPUnit 607 (65 baseline-skip).

## ⚠️ Висит (решения/действия оператора)
1. **Релиз v2.0.1 — не вышел.** `VERSION` = 2.0.1 в коде, не релизим. **НЕ бампать VERSION на каждое изменение** (правило оператора). Новые символы помечаем `@since 2.0.2`.
2. **Публичные доки (`docs/`) — НЕ трогаем** до полной готовности фреймворка (оператор — единственный потребитель). Зафиксировано: примеры регистрации учат удалённому v1-API `register_plugin()` (теперь это tombstone) — переписать на `register_loader_definition([...])` потом.
3. **Локальный rig** `.wp-env-stand/` (gitignored) — переключить фильтр `woodev_licensing_api_url` → `woodev_license_base_url`.

## Задача s14 — продолжаем grooming: оператор выбирает из OB-1..9
**ПЕРВЫМ ДЕЛОМ:** запросить у оператора, какой пункт берём. Кандидаты (детали — в `FUTURE-BACKLOG.md`):

- **OB-1** — bootstrap молча отдаёт приоритет v1-плагину и не грузит v2 без нотиса → показывать нотис («у вас плагин X с фреймворком v1…»). Обратная сторона B-1 tombstone. *(средняя, соло/codex)*
- **OB-2** — React-страница «Woodev → Лицензии» визуально кривая → стайлинг-проход. *(средняя)*
- **OB-3** — ревью `Woodev_Plugin_Updater` (сейчас singleton) + рассмотреть перенос в модуль Licensing. *(средняя, + Codex)*
- **OB-4** — принцип: переиспользуемый JS фреймворка делать PHP-driven (карта ПВЗ и т.п.); фикс-админка React — исключение. *(принцип, применять по ходу)*
- **OB-5** — изучить godaddy fork (Traits/Enums/Abilities) — кандидат на GPT-5.x research-делегацию.
- **OB-6** — свип «мёртвых» файлов v2 (не используются нигде). *(средняя; пара к #4/трейтам)*
- **OB-7** — модернизировать «Woodev → Плагины» (WP React) + будущая интеграция аккаунта woodev.ru (реф: WC extensions screen). *(крупная)*
- **OB-8** — вкладка «Woodev marketplace» на `plugin-install.php` (как WC `?tab=woo`). *(средняя)*
- **OB-9** — нюансы модуля доставки (`shipping-method/`) — отдельная сессия (оператор накопил список).

**Крупные (autodev-loop / отдельная сессия, не соло):**
- **Трейты `class-payment-gateway.php`** (3 542 строки → ~10 трейтов: Refunds/Voids/Tokenization/Capture-Authorization/Customer-ID/Card-Types/CSC/Environments/Debug/Order-Meta/Settings). Чистая реорганизация, контракты не трогаем.
- **Большой ревью #4:** `array()`→`[]` (~797 в `woodev/`) + **типизация везде** (legacy + new) + `@since`-свип vs git + включить `Generic.Arrays.DisallowLongArraySyntax` в phpcs.

**Edostavka-пилот — ОТЛОЖЕН** (оператор: «ещё рано»). Когда дойдём — audit-first, см. `docs-internal/migration/edostavka-data-preservation-checklist.md`.

## Подход и гигиена
- Каждый кусок — атомная задача (по возможности TDD), `composer check` зелёный после каждого, Codex-критика INLINE-бандлом на существенных изменениях (shell-sandbox сломан на Windows — gotcha `codex-shell-sandbox-broken-windows`).
- Serena для PHP; `tests/`, `.wp-env-stand/`, весь woodev_theme — Serena игнорит → Grep/Read/Edit.
- Мердж PR: `gh pr merge <N> --squash --delete-branch` ТОЛЬКО после подтверждённо зелёного CI; НЕ `--auto`. Рекомендованная опция в AskUserQuestion — ПЕРВОЙ.
- Локальный rig (s11/s12): issuer woodev_theme `:8090`, stand framework `:8888` — детали в `CURRENT-STATE.md` → «Local rig».
