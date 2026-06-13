# Промт для следующей сессии (s15): продолжаем grooming — выбор из остатка бэклога

> Написан в s14 (14.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — замени на промт для s16.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Старт сессии
1. `docs-internal/CURRENT-STATE.md` — lean-состояние (фазы, открытые баги, next actions).
2. `docs-internal/SESSION-LOG.md` — верхняя запись (s14) — что сделано.
3. `docs-internal/GOTCHAS.md` — сканировать релевантные теги (теперь 48, добавлен `[admin-ui/*]`).
4. `docs-internal/FUTURE-BACKLOG.md` → «Operator backlog dump — s13» (остались OB-4/5/7/8/9) + «Technical Debt».

## Что сделано в s14 (автономно, PRs #51–#54 в main, CI зелёный)
- **OB-1** (#51): mixed-fleet dormant-нотис теперь best-effort называет конфликтующий v1-плагин (X), деградирует до общего текста; mixed-fleet-pure, never-fatal; Codex BLOCK→SHIP.
- **OB-6** (#52): удалён 1 проверенно-мёртвый файл (`Admin_User_Edit_Handler`; аудит 163 файлов нашёл только 1).
- **OB-3** (#53): ревью `Woodev_Plugin_Updater` — **записано, не фикшено** (`reviews/ob3-plugin-updater-review-2026-06-14.md`). Рекомендация: MOVE в `woodev/licensing/updater/`, оставить per-plugin + idempotent; НЕ сливать в `Woodev_Licensing_API`. 4 BLOCK + 13 MINOR/NOTE, 5-шаговый порядок.
- **OB-2** (#54): страница «Лицензии» — добавлен `.wrap`/`<h1>`, секция быстрых ссылок (`html-settings-section.php`) больше не без стилей (стили перенесены в бандл `style.scss`). Before/after скриншоты были в транскрипте s14.

## ⚠️ Висит (решения/действия оператора)
1. **Релиз v2.0.1 — не вышел.** `VERSION` = 2.0.1 в коде, не релизим. НЕ бампать VERSION на каждое изменение. Новые символы → `@since 2.0.2`.
2. **Публичные доки (`docs/`) — НЕ трогаем** до полной готовности фреймворка (примеры учат удалённому v1-API `register_plugin()` — переписать на `register_loader_definition([...])` потом).
3. **Локальный rig** `.wp-env-stand/` (gitignored) — переключить фильтр `woodev_licensing_api_url` → `woodev_license_base_url` (с s13).
4. **OB-2 follow-up:** живую страницу «Лицензии» проверить в браузере на риге (s14 верифицировал standalone-превью, не реальный WP).

## Задача s15 — оператор выбирает. Кандидаты:

**Соло / Codex-friendly:**
- **OB-3 impl (safe subset)** — Findings 11 (`tested`-guard) + 13 (`esc_attr`) + 12 (type/visibility hardening) из ревью апдейтера. Низкий риск, без контрактов; браузер-проверка update-row. Остальное (8/9/10 + MOVE) — с одобрения оператора.
- **OB-8** — вкладка «Woodev marketplace» на `plugin-install.php` (как WC `?tab=woo`). *(средняя)*
- **OB-4** — принцип PHP-driven reusable JS (применять по ходу; не отдельная задача).

**Крупные (autodev-loop / отдельная сессия, не соло):**
- **OB-7** — модернизировать «Woodev → Плагины» (WP React) + интеграция аккаунта woodev.ru (реф: WC extensions screen). *(крупная)*
- **OB-3 MOVE** — перенос апдейтера в `woodev/licensing/updater/` + извлечение licensing-transport-коллаборатора; сохранить 6 frozen-контрактов byte-for-byte (см. ревью).
- **Трейты `class-payment-gateway.php`** (3 542 строки → ~10 трейтов). Чистая реорганизация.
- **Большой ревью #4:** `array()`→`[]` (~797 в `woodev/`) + типизация везде + `@since`-свип vs git + включить `Generic.Arrays.DisallowLongArraySyntax` в phpcs.

**Исследование:**
- **OB-5** — изучить godaddy fork (Traits/Enums/Abilities) — кандидат на GPT-5.x research-делегацию.
- **OB-9** — нюансы модуля доставки (`shipping-method/`) — отдельная сессия (оператор накопил список). Пара к отложенному edostavka-пилоту (audit-first).

## Подход и гигиена
- Каждый кусок — атомная задача (по возможности TDD), `composer check` зелёный после каждого, Codex-критика INLINE-бандлом на существенных изменениях (shell-sandbox сломан на Windows — gotcha `codex-shell-sandbox-broken-windows`).
- Serena для PHP; `tests/`, `.wp-env-stand/`, весь woodev_theme — Serena игнорит → Grep/Read/Edit.
- React/SCSS правки: `npm run build`, коммить и `src/` и `woodev/assets/build/`; build-parity CI (gotcha `build-artifacts-eol-lf-windows-parity`).
- Мердж PR: `gh pr merge <N> --squash --delete-branch` ТОЛЬКО после подтверждённо зелёного CI; НЕ `--auto`. Рекомендованная опция в AskUserQuestion — ПЕРВОЙ.
