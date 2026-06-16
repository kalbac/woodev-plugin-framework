# Промт для следующей сессии (s16): OB-3 шаги 2–5 или новый кандидат

> Написан в s15 (16.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — замени на промт для s17.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Старт сессии
1. `docs-internal/CURRENT-STATE.md` — lean-состояние (фазы, открытые баги, next actions).
2. `docs-internal/SESSION-LOG.md` — верхняя запись (s15) — что сделано.
3. `docs-internal/GOTCHAS.md` — сканировать релевантные теги (48).
4. `docs-internal/FUTURE-BACKLOG.md` → «Operator backlog dump — s13» (остались OB-4/5/7/8/9) + «Technical Debt».
5. `docs-internal/reviews/ob3-plugin-updater-review-2026-06-14.md` — если выбираем OB-3 продолжение.

## Что сделано в s15 (PR #57 → main, CI зелёный)
- **OB-3 safe subset** (#57): F11 tested-guard (`count($tested_parts) < 2` перед доступом к `[1]`); F12 типизация 8 из 9 свойств + сужение 3 методов public→private (ADR-005); F13 `esc_attr()` в printf update-row. 8 новых тестов (`UpdaterSafeSubsetTest`). Codex BLOCK на F12 → оператор одобрил.

## ⚠️ Висит (решения/действия оператора)
1. **Релиз v2.0.1 — не вышел.** `VERSION` = 2.0.1 в коде, не релизим. НЕ бампать VERSION на каждое изменение. Новые символы → `@since 2.0.2`.
2. **Публичные доки (`docs/`) — НЕ трогаем** до полной готовности фреймворка.
3. **Локальный rig** `.wp-env-stand/` (gitignored) — переключить фильтр `woodev_licensing_api_url` → `woodev_license_base_url` (с s13).
4. **OB-2 follow-up:** живую страницу «Лицензии» проверить в браузере на риге (s14 верифицировал standalone-превью, не реальный WP).
5. **OB-3 update-row** не верифицирован в браузере на риге (multisite). Если rig включат — проверить до мержа или сразу после.

## Задача s16 — оператор выбирает. Кандидаты:

**OB-3 продолжение (шаги 2–5 из review-doc):**
- **Шаг 2 — Robustness (F2+F6+F7):** error handling (`catch (Exception)` → `catch (Throwable)` + логирование), backoff dead-code (endpoint-wide key — уточнить intention), wiring-failure logging. Нужны integration tests на реальном WP. Решить endpoint-wide-key вопрос сначала.
- **Шаг 3 — Normalization (F1+F3+F5):** `sections` нормализация, общий cache key, `api_request` false-multi-action. Нужно верифицировать payload store перед правкой.
- **Шаг 4 — Contract-touching (F8/F9/F10):** `in_plugin_update_message-{$file}` неправильный 2-й аргумент, nonce в changelog-endpoint, cache key без endpoint. **Аудит consumers + migration note + operator sign-off обязательны.**
- **Шаг 5 — MOVE** `woodev/plugin-updater/` → `woodev/licensing/updater/`. Autodev-session, data-preservation checklist для 6 frozen-контрактов.

**Другие кандидаты:**
- **OB-8** — вкладка «Woodev marketplace» на `plugin-install.php` (как WC `?tab=woo`). *(средняя)*
- **OB-7** — модернизировать «Woodev → Плагины» (WP React) + интеграция аккаунта woodev.ru. *(крупная)*
- **OB-5** — изучить godaddy fork (Traits/Enums/Abilities) — GPT research-делегация.
- **OB-9** — нюансы модуля доставки — отдельная сессия.
- **Большой ревью #4:** `array()`→`[]` (~797 в `woodev/`) + типизация везде + `@since`-свип + включить `Generic.Arrays.DisallowLongArraySyntax`.
- **Payment-gateway traits** (3 542 строки → ~10 трейтов) — autodev-loop / отдельная сессия.

## Подход и гигиена
- Каждый кусок — атомная задача (по возможности TDD), `composer check` зелёный после каждого, Codex-критика INLINE-бандлом на существенных изменениях.
- Serena для PHP; `tests/`, `.wp-env-stand/`, весь woodev_theme — Serena игнорит → Grep/Read/Edit.
- React/SCSS правки: `npm run build`, коммить и `src/` и `woodev/assets/build/`; build-parity CI (gotcha `build-artifacts-eol-lf-windows-parity`).
- Мердж PR: `gh pr merge <N> --squash --delete-branch` ТОЛЬКО после подтверждённо зелёного CI; НЕ `--auto`. Рекомендованная опция в AskUserQuestion — ПЕРВОЙ.
