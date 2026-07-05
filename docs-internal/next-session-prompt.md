# Промт следующей сессии: SP-4 (DaData seam) — brainstorm → spec → impl

> Обновлён 05.07.2026 после **CONDITIONAL FIELDS (show_if) SHIPPED** (PR #98 `32b58ff`). Зависимые поля зашипены и браузер-верифицированы мной на `:8888` (все 4 кейса вживую). Следующий приоритет по программе shipping-модуля — **SP-4 «DaData seam»**.

## Возобновление (ОБЯЗАТЕЛЬНО)
1. `docs-internal/CURRENT-STATE.md` («Last session context» — s40 + s39-polish) + `docs-internal/GOTCHAS.md` (72 готчи).
2. Для стиля/паттернов Settings API: `docs-internal/specs/2026-07-05-conditional-fields-design.md` + `…/plans/2026-07-05-conditional-fields-plan.md` + **ADR-008** (контракт зависимых полей). SP-2/SP-3 спеки — для auth-сшивок и валидации.
3. **Риг:** wp-env dev `:8888` (`npx wp-env start`). admin/password. «Карьер» содержит демо show_if: mode→api_key (required, скрыт при test), calc_type→rate/formula (callback + not_in). На риге могли остаться мои тестовые значения (mode=test и т.п.) — оператор поправит.
4. Версию НЕ бампать (`@since 2.0.2`).

## 🎯 Задача — SP-4 «DaData seam»

По декомпозиции shipping-модуля (`docs-internal/specs/2026-06-25-shipping-module-decisions.md`), после SP-1 (страница настроек), SP-2 (auth+секреты), SP-3 (валидация) и зависимых полей — **SP-4 = сервисный seam для DaData** (§9 SP-1-спеки: SP-1 дал только заглушку сервисного seam; DaData-специфика откладывалась сюда).

**Принцип (оператор повторял, я помню):** framework = mechanism + contract + hooks; **доменная специфика DaData (endpoints, формат ответа, токены) живёт в ПЛАГИНЕ, а не во фреймворке.** Фреймворк даёт seam/интерфейс + место для регистрации, плагин реализует.

**Способ:** `brainstorming` (skill, заземляясь на реальном коде — Settings API, auth-сшивки SP-2, как плагин объявляет сервис) → `writing-plans` → **subagent-driven** (fresh agent на задачу, two-stage spec+code-quality review) → Codex-критик + re-critic → **обязательная моя браузерная e2e на `:8888` перед мерджем**.

### Ключевые дизайн-вопросы для брейншторма (добить с оператором)
1. Что именно DaData делает в контексте shipping (address autocomplete? нормализация? подсказки ФИАС?) и где это всплывает в UI (checkout / admin / оба).
2. Форма seam: интерфейс на хендлере (как `Woodev_Settings_Connection_Test` в SP-2), или отдельный сервис-реестр? Как плагин регистрирует свою реализацию.
3. Клиентская часть: если это autocomplete-виджет — PHP-driven переиспользуемый JS (принцип `feedback_php_based_reusable_js`, готовый PVZ-map прецедент) vs фиксированный React.
4. Секреты (DaData API key) — уже покрыто SP-2 masking/constant_name; как seam их потребляет.

## Процесс/уроки (применить)
- **Codex-критик:** inline-bundle ≤~12KB (11.8KB завис на 10 мин; 8.2KB в s40 отработал штатно). `node <codex-plugin>/scripts/codex-companion.mjs task "$(cat bundle)" --json`; follow-up на ту же тему → `--resume <threadId>`. **Re-critic свои фиксы** (в s40 нашёл order-dependence и fail-closed parity в моих же правках).
- **Browser:** Playwright MCP (admin/password), :8888. `browser_snapshot` по ref секции — точечно; кастомный SelectField = кнопка+портал-popover (кликнуть кнопку, потом опцию в `listbox`).
- **subagent-driven + two-stage review** реально ловит баги (s40: краш REST на незарегистрированном id; регрессия Mockery-моков). Имплементеру: built-in `Edit` (НЕ Serena — EOL flip), не бампать VERSION, гонять unit+phpcs+build, **и полный `composer test:unit`, а не только точечный/интеграционный тест** (готча `mockery-mock-new-method-full-suite`).
- **Мерж:** всё коммитить на фиче-ветке; после squash проверить `git rev-list --count main..origin/main`==0. Каждый CI job = pass + `mergeStateStatus: CLEAN` отдельным шагом. Сетевой `ECONNRESET` на npm-install = флейк → `gh run rerun <id> --failed`.

## Прочее / бэклог
- **conditional-fields v2** (FUTURE-BACKLOG): доп. операторы `>`/`<`/`empty`/`contains`, вложенность, section/subtab-hiding, `apply_show_if` хелпер — по ADR-008, когда пилот докажет нужду.
- **SP-2-DEF:** wipe-secret/disconnect affordance.
- **UK-3/UK-4 wizard** — последняя не-kit поверхность.
- **MCP:** Supermemory не подключён; Obsidian — синкнуть `sessions/latest-context.md` при сохранении сессии.
- **s40-аудит доков** — s40 не кратно 10; следующий триггер s50 (или по запросу).
