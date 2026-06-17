# Промт для следующей сессии (s19): OB-3 closeout на риге (browser-verify F8/F9 + payload для F1+F3) или новый кандидат

> Написан в s18 (17.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — замени на промт для s20.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Старт сессии
1. `docs-internal/CURRENT-STATE.md` — lean-состояние (фазы, открытые баги, next actions).
2. `docs-internal/SESSION-LOG.md` — верхняя запись (s18) — что сделано.
3. `docs-internal/GOTCHAS.md` — сканировать релевантные теги (**50**). Особо: `tooling/codex-shell-sandbox-broken-windows` (фоновый codex-rescue молча умирает на Windows-сэндбоксе → INLINE-бандл + проверять, что вердикт реально вернулся), `wp-safe-remote-request-local-rig` (все ловушки рига), и два новых из s18: `in-plugin-update-message-arg-shape`, `updater-cache-source-stamp-not-key`.
4. `docs-internal/FUTURE-BACKLOG.md` → «Operator backlog dump — s13» (остались OB-5/7/9) + «Technical Debt».
5. `docs-internal/reviews/ob3-plugin-updater-review-2026-06-14.md` — если продолжаем OB-3 (Step 3 = Findings F1/F3).

## Что сделано в s18 (PR #62 → main, `bcfd271`)
- **OB-3 Step 4 (contract-touching, operator sign-off per fix):**
  - **F8** — `in_plugin_update_message-{$file}` 2-й аргумент → response-объект (WP-конвенция). Параллельно **починен мёртвый consumer** `Woodev_Plugins_License::plugin_row_license_missing()` (читал `package` из arg1, который WP не заполняет → нотис «backup before updating» НИКОГДА не рендерился, даже на single-site). Хелпер `extract_update_field()`.
  - **F9** — changelog endpoint: `wp_unslash`+`sanitize_text_field` на всех `$_REQUEST`-чтениях + строгое `plugin === $this->name`. **Без nonce** (URL-shape changelog не меняется). Codex HOLD по `%2F` → защитный `rawurldecode()` перед sanitize.
  - **F10** — version-cache: штамп `source => api_url` внутри значения опции; mismatch/absent source = miss. **Frozen-ключ опции не тронут.**
  - Ни один data-контракт не сломан. +12 тестов (645 unit), Codex SHIP, CI зелёный. 2 новых gotcha.

## ⚠️ Висит (решения оператора / не сделано)
1. **v2.0.1 не вышел.** Не бампать VERSION. Новые символы → `@since 2.0.2`.
2. **Публичные docs/ — НЕ трогаем** до готовности фреймворка.
3. **НЕ browser-verified (s18, unit-only):** F8 multisite custom update-row + нотисы (`plugin_row_license_missing` теперь должен рендериться) и F9 changelog endpoint (`?woodev_action=view_plugin_changelog`).
4. **OB-2 follow-up:** живую страницу «Лицензии» проверить в браузере на риге.

## Задача s19 — рекомендуется: OB-3 closeout на риге (оператор присутствует → live-проверка по ходу)

**Двухстековый риг** (см. CURRENT-STATE «Local rig» + gotcha `wp-safe-remote-request-local-rig`): issuer = `woodev_theme` (`localhost:8090`), stand/consumer = framework wp-env (`localhost:8888`/`8080`). Драйв через `docker exec <cli> wp eval-file ...` (НЕ inline `wp eval` — кириллица/кавычки ломаются). НЕ запускать `do_action('admin_init')` в wp-cli (WC OrderAttributionController фаталит).

1. **Browser-verify s18-изменений** (multisite-стенд для F8): что custom update-row рендерится и нотис «Внимание! Сделайте бэкап…» + «Enter valid license key…» теперь показываются (раньше были мертвы). F9 — открыть `index.php?woodev_action=view_plugin_changelog&plugin=<basename>&slug=<slug>` под admin и под не-admin (403).
2. **Снять реальный store-payload `get_version`** с issuer-рига (`plugin_latest_version` vs `plugin_information`) → этим **разблокировать OB-3 Step 3 (F1+F3)**:
   - **F1** — `sections` нормализация (промоут секций в top-level + cast-to-array vs `show_update_notification()` читает `…->sections->changelog` как объект). Решить по фактической форме payload.
   - **F3** — shared cache key конфлейтит `get_repo_api_data()` и `plugins_api_filter()`: нормализовать на каждом чтении для `check_update()`, **ключ НЕ менять** (frozen). Открытый вопрос ревью: одинаковы ли payload-ы у `plugin_latest_version` и `plugin_information`?
   - TDD; `composer check` зелёный; Codex INLINE-бандлом.

**Альтернативы (если риг не берём):**
- **Большой ревью #4 (автономно-дружелюбно):** `array()`→`[]` (~797 в `woodev/`) + типизация везде + `@since`-свип + включить `Generic.Arrays.DisallowLongArraySyntax`. Крупный механический проход — autodev-loop.
- **Payment-gateway traits** (3 542 строки → ~10 трейтов) — отдельная autodev-сессия.
- **OB-5** — godaddy fork (Traits/Enums/Abilities) — GPT research-делегация.
- **OB-7** — модернизировать «Woodev → Плагины» (WP React) + аккаунт woodev.ru. *(крупная)*
- **OB-9** — нюансы модуля доставки — отдельная сессия.

## Подход и гигиена
- Каждый кусок — атомная задача (по возможности TDD), `composer check` зелёный после каждого.
- **Codex-критика INLINE-бандлом** на существенных изменениях (diff + контракты прямо в промте; «no shell, review pasted diff only»). Фоновый `codex:codex-rescue` молча умирает — если используешь, **проверь что вердикт реально вернулся** (читай финальное сообщение агента / транскрипт `~/.codex/sessions/.../rollout-*.jsonl`).
- Serena для PHP **если доступна** (в s17/s18 НЕ была загружена → Grep/Read/Edit). `tests/`, `.wp-env-stand/`, весь woodev_theme — Serena игнорит → Grep/Read/Edit.
- React/SCSS правки: `npm run build`, коммить и `src/` и `woodev/assets/build/`; build-parity CI (gotcha `build-artifacts-eol-lf-windows-parity`).
- Контракт-трогающие фиксы: аудит consumers + sign-off оператора + проверка 6 frozen-контрактов (CLAUDE.md «never break» list).
- Мердж PR: `gh pr merge <N> --squash --delete-branch` ТОЛЬКО после подтверждённо зелёного CI; НЕ `--auto`. Рекомендованная опция в AskUserQuestion — ПЕРВОЙ.
