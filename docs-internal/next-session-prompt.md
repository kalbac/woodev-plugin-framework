# Промт следующей сессии: Зависимые поля (conditional fields) — brainstorm → spec → impl

> Обновлён 01.07.2026 после **SP-3 POLISH SHIPPED** (PR #97 `1ea2be9`). Оператор зариг-тестил SP-3, попросил polish-тройку (placeholder + `validate`-колбэк + scroll/snackbar) — **зашипено** — и **явно назвал следующее: зависимые поля** (показать поле Б, если в поле А выбрано X). Это и есть приоритет следующей сессии (оператор согласился делать отдельно, subagent-driven). После — SP-4 (DaData seam).

## 🎯 Задача — Зависимые/условные поля (conditional visibility)

**Оператор-реквест (01.07.2026):** возможность показывать/скрывать поле в зависимости от значения другого (напр. `auth_mode = key` → показать «API-ключ»; `calc_type = fixed` → показать «ставка»). Реально нужно для карьерных плагинов (в фикстуре «Карьер» уже есть `mode` test/live и `calc_type` fixed/dynamic — ровно такие случаи). **Моя оценка (дана оператору): нужная фича, не хотелка**, но первую версию держать МИНИМАЛЬНОЙ.

**Способ:** `brainstorming` (skill, заземляясь на реальном коде — `Field_Schema`/`ControlField`/`SectionView`/`validate_values`/settings `app.js`) → `writing-plans` → **subagent-driven** (fresh agent на задачу, two-stage spec+code-quality review) → Codex-критик → **обязательная моя браузерная e2e на `:8888` перед мерджем**.

### Ключевые дизайн-вопросы для брейншторма (добить с оператором)
1. **Объявление зависимости:** декларативно `'show_if' => [ 'setting' => 'auth_mode', 'value' => 'key' ]` (одно условие equals/in), или колбэк? Рекомендация — начать с минимального декларативного equals/in, **без AND/OR/вложенности**, пока реальный плагин (Яндекс-пилот) не докажет необходимость (риск спроектировать API вслепую — см. UK-CFR-отсрочку).
2. **Главная нетривиальная часть — пропуск скрытых полей в валидации:** скрытое required-поле НЕ должно блокировать Save/«Продолжить». Пропускать скрытые в ОБОИХ гейтах — клиентский (`validateFields`/onSave gather) И серверный (`validate_values`). Сервер должен знать видимость → либо схема несёт условие, либо плагин-сайд резолвит. Это стык с SP-3 — спроектировать явно.
3. **Клиент:** `ControlField`/`SectionView` реактивно скрывают поля по текущим значениям (re-render на change). Скрытие целой секции/подвкладки — в скоупе или нет?
4. Где хранится условие (на `Woodev_Setting`? в `Settings_Section`?) и как эмитится в схему.

## Возобновление (ОБЯЗАТЕЛЬНО)
1. `docs-internal/CURRENT-STATE.md` («Last session context» — s39-polish + s39) + `docs-internal/GOTCHAS.md` (71 готча).
2. SP-3 спека/план + SP-3-polish план (`docs-internal/plans/2026-07-01-sp3-polish-plan.md`) — для стиля и понимания валидации.
3. **Риг:** wp-env dev `:8888` (`npx wp-env start`). admin/password. «Карьер» содержит SP-3 + polish демо (placeholder'ы, `support_phone` с 11-значным validate-колбэком). На риге могут быть тестовые значения от меня/оператора (`+79009` оставлен с ошибкой как демо колбэка — оператор поправит).
4. Версию НЕ бампать (`@since 2.0.2`).

## Процесс/уроки (применить)
- **Codex-критик:** inline-bundle ≤~12KB (11.8KB завис на 10 мин). `node <codex-plugin>/scripts/codex-companion.mjs task "$(cat bundle)" --json`. Re-critic свои фиксы (нашёл MED `(bool)`→`true===` fail-closed в polish).
- **Браузер:** Claude-in-Chrome НЕ подключён → **Playwright MCP** (admin/password). REST через `?rest_route=%2Fwoodev%2Fv1...` — фильтровать сеть по `woodev`. `browser_evaluate` удобен для точной проверки placeholder/error-state.
- **subagent-driven + two-stage review** ловит реальные баги (в polish: enum-bypass docblock, scroll-не-перезапускается-gen-counter, fail-closed bool). Имплементеру: built-in `Edit` (не Serena), не бампать VERSION, гонять unit+phpcs+build.
- **Мерж:** всё коммитить на фиче-ветке (не на main), иначе divergence после squash (готча `git-squash-onto-stale-origin-main-diverge`).
- **Валидаторы:** guard `is_string()`; колбэк-результат — строго `true ===` (fail-closed).

## Прочее / бэклог
- **SP-4 (DaData seam)** — после зависимых полей. Framework=mechanism+hooks, DaData-специфика в плагине.
- **SP-2-DEF:** wipe-secret/disconnect affordance.
- **UK-3/UK-4 wizard** — последняя не-kit поверхность.
- **MCP:** Supermemory не подключён; Obsidian работает (`sessions/latest-context.md` обновлён).
- **s40-аудит доков** — если давно не делали, предложить.
