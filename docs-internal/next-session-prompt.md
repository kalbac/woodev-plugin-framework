# Промт для следующей сессии (s17): OB-3 шаги 4–5 или новый кандидат

> Написан в s16 (16.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — замени на промт для s18.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Старт сессии
1. `docs-internal/CURRENT-STATE.md` — lean-состояние (фазы, открытые баги, next actions).
2. `docs-internal/SESSION-LOG.md` — верхняя запись (s16) — что сделано.
3. `docs-internal/GOTCHAS.md` — сканировать релевантные теги (48).
4. `docs-internal/FUTURE-BACKLOG.md` → «Operator backlog dump — s13» (остались OB-5/7/9) + «Technical Debt».
5. `docs-internal/reviews/ob3-plugin-updater-review-2026-06-14.md` — если продолжаем OB-3.

## Что сделано в s16 (PR #58/#59 → main, PR #60 → main)
- **OB-3 Step 2 (#58):** F2 `catch(\Throwable)` + `error_log` в `get_version_from_remote()`; F7 wiring-failure log для Acks/Dispatcher. Codex SHIP. 4 новых теста.
- **OB-3 Step 3 F5 (#59):** Удалён `string $_action` из `api_request()` — параметр всегда игнорировался. 3 новых теста.
- **OB-8 (#60):** Вкладка «Woodev» на `plugin-install.php` (паттерн WC `?tab=woo`). `Woodev_Plugin_Install_Tab`: `install_plugins_tabs` + `install_plugins_pre_woodev` + early-exit + admin-footer. Codex HOLD → добавил поведенческий тест через `ReflectionClass::newInstanceWithoutConstructor()`. 9 новых тестов.
- **Локальный rig:** фильтр переключён `woodev_licensing_api_url` → `woodev_license_base_url` (gitignored).

## ⚠️ Висит (решения оператора)
1. **v2.0.1 не вышел.** Не бампать VERSION. Новые символы → `@since 2.0.2`.
2. **Публичные docs/ — НЕ трогаем** до готовности фреймворка.
3. **OB-2 follow-up:** живую страницу «Лицензии» проверить в браузере на риге.
4. **OB-3 update-row** не верифицирован в браузере на риге (multisite).

## Задача s17 — оператор выбирает. Кандидаты:

**OB-3 продолжение (шаги 3–5 из review-doc):**
- **Шаг 3 — F1+F3 BLOCKED:** `sections` нормализация + cache key isolation. Нужно верифицировать payload shape с рига или знать структуру ответа store API.
- **Шаг 4 — Contract-touching (F8/F9/F10):** `in_plugin_update_message-{$file}` неправильный 2-й аргумент, nonce в changelog-endpoint, cache key без endpoint. **Аудит consumers + migration note + operator sign-off обязательны.**
- **Шаг 5 — MOVE** `woodev/plugin-updater/` → `woodev/licensing/updater/`. Autodev-session, data-preservation checklist для 6 frozen-контрактов.

**Другие кандидаты:**
- **OB-5** — изучить godaddy fork (Traits/Enums/Abilities) — GPT research-делегация.
- **OB-7** — модернизировать «Woodev → Плагины» (WP React) + интеграция аккаунта woodev.ru. *(крупная)*
- **OB-9** — нюансы модуля доставки — отдельная сессия.
- **Большой ревью #4:** `array()`→`[]` (~797 в `woodev/`) + типизация везде + `@since`-свип + включить `Generic.Arrays.DisallowLongArraySyntax`.
- **Payment-gateway traits** (3 542 строки → ~10 трейтов) — autodev-loop / отдельная сессия.

## Подход и гигиена
- Каждый кусок — атомная задача (по возможности TDD), `composer check` зелёный после каждого, Codex-критика INLINE-бандлом на существенных изменениях.
- Serena для PHP; `tests/`, `.wp-env-stand/`, весь woodev_theme — Serena игнорит → Grep/Read/Edit.
- React/SCSS правки: `npm run build`, коммить и `src/` и `woodev/assets/build/`; build-parity CI (gotcha `build-artifacts-eol-lf-windows-parity`).
- Мердж PR: `gh pr merge <N> --squash --delete-branch` ТОЛЬКО после подтверждённо зелёного CI; НЕ `--auto`. Рекомендованная опция в AskUserQuestion — ПЕРВОЙ.
