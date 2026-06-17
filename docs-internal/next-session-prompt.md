# Промт для следующей сессии (s18): OB-3 Step 4 (contract-touching, sign-off) или новый кандидат

> Написан в s17 (17.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — замени на промт для s19.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Старт сессии
1. `docs-internal/CURRENT-STATE.md` — lean-состояние (фазы, открытые баги, next actions).
2. `docs-internal/SESSION-LOG.md` — верхняя запись (s17) — что сделано.
3. `docs-internal/GOTCHAS.md` — сканировать релевантные теги (48). Особо: `tooling/codex-shell-sandbox-broken-windows` (фоновый codex-rescue молча умирает на Windows-сэндбоксе → проверять транскрипт + слать INLINE-бандл).
4. `docs-internal/FUTURE-BACKLOG.md` → «Operator backlog dump — s13» (остались OB-5/7/9) + «Technical Debt».
5. `docs-internal/reviews/ob3-plugin-updater-review-2026-06-14.md` — если продолжаем OB-3 (Step 4 = Findings 8/9/10).

## Что сделано в s17 (PR #61 → main)
- **OB-3 Step 5 (#61, `829420d`):** MOVE `woodev/plugin-updater/` → `woodev/licensing/updater/`. Чистый byte-identical rename, без shim (ADR-005). Все 6 frozen-контрактов сохранены; все ссылки перенаправлены (require, classmap, phpstan ignore, 6 тестов, .pot/.po, INVARIANTS, AGENTS map). CI зелёный, Codex inline-bundle SHIP. 633 теста.

## ⚠️ Висит (решения оператора)
1. **v2.0.1 не вышел.** Не бампать VERSION. Новые символы → `@since 2.0.2`.
2. **Публичные docs/ — НЕ трогаем** до готовности фреймворка.
3. **OB-2 follow-up:** живую страницу «Лицензии» проверить в браузере на риге.
4. **OB-3 update-row** не верифицирован в браузере на риге (multisite).

## Задача s18 — рекомендуется OB-3 Step 4 (оператор присутствует → sign-off по ходу)

**OB-3 Step 4 — Contract-touching (Findings F8/F9/F10 из review-doc):**
- **F8 — `in_plugin_update_message-{$file}` неправильный 2-й аргумент** (`~324`). Подтверждён реальным: `do_action("in_plugin_update_message-{$file}", $plugin, $plugin)` передаёт plugin-data дважды; WP-конвенция `($plugin_data, $response_object)`. ⚠️ Hook-argument shape = installed-site contract → **аудит consumers обязателен** перед фиксом.
- **F9 — слабая валидация changelog-endpoint** (`~508`): capability-check есть, но нет nonce/unslash/sanitize, и `plugin` проверяется только на non-empty (не `=== $this->name`). ⚠️ Добавление nonce меняет **URL-shape** changelog (контракт) → migration note.
- **F10 — version cache key игнорирует licensing endpoint** (`~760`): ключ не учитывает `woodev_license_base_url` → до 3ч stale cross-store данных. ⚠️ **Не менять ключ**; инвалидировать явно или штамповать+валидировать source-метаданные внутри значения опции.
- **Обязательно:** аудит consumers + migration note + **operator sign-off** перед каждым фиксом. TDD; `composer check` зелёный; Codex INLINE-бандлом (не фоновый rescue — он умирает на сэндбоксе).

**Альтернатива (если Step 4 не берём):**
- **OB-3 Step 3 — F1+F3 BLOCKED:** `sections` нормализация + cache key isolation. Нужно верифицировать payload shape с рига / структуру ответа store API.
- **OB-5** — изучить godaddy fork (Traits/Enums/Abilities) — GPT research-делегация.
- **OB-7** — модернизировать «Woodev → Плагины» (WP React) + интеграция аккаунта woodev.ru. *(крупная)*
- **OB-9** — нюансы модуля доставки — отдельная сессия.
- **Большой ревью #4:** `array()`→`[]` (~797 в `woodev/`) + типизация везде + `@since`-свип + включить `Generic.Arrays.DisallowLongArraySyntax`.
- **Payment-gateway traits** (3 542 строки → ~10 трейтов) — autodev-loop / отдельная сессия.

## Подход и гигиена
- Каждый кусок — атомная задача (по возможности TDD), `composer check` зелёный после каждого.
- **Codex-критика INLINE-бандлом** на существенных изменениях (diff + контракты прямо в промте; «no shell, review pasted diff only»). Фоновый `codex:codex-rescue` на этом боксе молча умирает — если используешь, **проверь транскрипт** `~/.codex/sessions/.../rollout-*.jsonl` что вердикт реально пришёл.
- Serena для PHP **если доступна** (в s17 НЕ была загружена → Grep/Read/Edit). `tests/`, `.wp-env-stand/`, весь woodev_theme — Serena игнорит → Grep/Read/Edit.
- React/SCSS правки: `npm run build`, коммить и `src/` и `woodev/assets/build/`; build-parity CI (gotcha `build-artifacts-eol-lf-windows-parity`).
- Мердж PR: `gh pr merge <N> --squash --delete-branch` ТОЛЬКО после подтверждённо зелёного CI; НЕ `--auto`. Рекомендованная опция в AskUserQuestion — ПЕРВОЙ.
